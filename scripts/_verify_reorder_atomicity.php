<?php
// bcc_reorder_sibling() + rename/move aksiyonlarinin ATOMIKLIGI.
//
// ⚠️ /browse KULLANILMAZ — sunucu-tarafli HTTP (gercek oturum, gercek uc noktalar).
// ⚠️ IZOLE: kendi test base'ini yaratir; GERCEK uretim base'ine (15) dokunmaz,
//    once/sonra sayaclariyla kanitlanir.
//
// EN KRITIK TEST: "yarim takas" senaryosu. bcc_reorder_sibling() IKI ayri UPDATE
// yapiyor; ikisinden yalnizca biri kalici olursa IKI SATIR AYNI position'da kalir.
// Asagida transaction icinde iki UPDATE + kasitli bir hata tetiklenip HER IKI
// UPDATE'in de geri alindigi gercek InnoDB uzerinde dogrulaniyor.
//
// Calistirma: C:\php73\php.exe scripts\_verify_reorder_atomicity.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER', 'reorder.owner@bcc-test.local');
define('PASS', 'Reorder!2026');
define('REAL_BASE_ID', 15);

$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

function req($m, $p, $c = null, $f = null)
{
    $h = array();
    if ($c !== null) { $h[] = 'Cookie: ' . $c; }
    $o = array('http' => array('method' => $m, 'ignore_errors' => true));
    if ($m === 'POST') {
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
        $o['http']['content'] = http_build_query($f);
    }
    $o['http']['header'] = implode("\r\n", $h);
    $b = @file_get_contents(BASE_URL . $p, false, stream_context_create($o));
    $st = 0; $nc = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $x) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $x, $m2)) { $st = (int) $m2[1]; }
            if (stripos($x, 'Set-Cookie:') === 0) { $q = explode(';', substr($x, 11)); $nc = trim($q[0]); }
        }
    }
    return array('body' => (string) $b, 'cookie' => $nc, 'status' => $st);
}
function csrf($h) { return preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $h, $m) ? $m[1] : null; }

bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER));
$cleanup = function () {
    foreach (array_column(bcc_fetch_all('SELECT b.id FROM bases b JOIN users u ON u.id = b.created_by WHERE u.email = :e', array(':e' => OWNER)), 'id') as $bid) {
        if ((int) $bid === REAL_BASE_ID) { continue; } // gercek base'e ASLA
        bcc_execute('DELETE FROM bases WHERE id = :i', array(':i' => $bid));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER));
};
$cleanup();

// GERCEK base referans olcumu
$realBefore = array(
    'tablo' => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = ' . REAL_BASE_ID),
    'kayit' => (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id IN (SELECT id FROM tables_meta WHERE base_id = ' . REAL_BASE_ID . ')'),
    'pozisyon' => (string) bcc_fetch_column('SELECT GROUP_CONCAT(CONCAT(id, ":", position) ORDER BY position, id) FROM tables_meta WHERE base_id = ' . REAL_BASE_ID),
);

try {
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => 'Reorder Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array(':t' => $teamId, ':n' => 'Reorder Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();

    $tids = array();
    foreach (array('Alfa', 'Beta', 'Gama') as $i => $n) {
        bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, :p)', array(':b' => $baseId, ':n' => $n, ':p' => $i));
        $tids[$n] = (int) bcc_last_insert_id();
    }

    // Siralamayi "id:position" olarak dondurur
    $order = function () use ($baseId) {
        $out = array();
        foreach (bcc_fetch_all('SELECT id, name, position FROM tables_meta WHERE base_id = :b ORDER BY position, id', array(':b' => $baseId)) as $r) {
            $out[] = $r['name'] . ':' . $r['position'];
        }
        return implode(' ', $out);
    };
    // Ayni position'i paylasan satir var mi
    $dupPositions = function () use ($baseId) {
        return (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM (SELECT position FROM tables_meta WHERE base_id = :b GROUP BY position HAVING COUNT(*) > 1) x',
            array(':b' => $baseId)
        );
    };

    $r = req('GET', '/login.php');
    $cookie = $r['cookie'];
    $r = req('POST', '/login.php', $cookie, array('email' => OWNER, 'password' => PASS, 'csrf_token' => csrf($r['body'])));
    if ($r['cookie']) { $cookie = $r['cookie']; }
    check('Giris yapildi (owner)', $cookie !== null);

    // =======================================================================
    echo "\n--- A) rename_table ---\n";
    $page = req('GET', "/base_tables.php?base_id={$baseId}", $cookie);
    $auditBefore = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'table.update' AND entity_id = :t", array(':t' => $tids['Beta']));

    $resp = req('POST', '/base_tables.php', $cookie, array(
        'csrf_token' => csrf($page['body']), 'action' => 'rename_table',
        'base_id' => $baseId, 'table_id' => $tids['Beta'],
        'name' => 'Beta Yeni', 'description' => 'aciklama',
    ));
    check('A) Yeniden adlandirma basarili', strpos($resp['body'], 'Tablo güncellendi') !== false, 'durum ' . $resp['status']);
    $row = bcc_fetch_one('SELECT name, description FROM tables_meta WHERE id = :t', array(':t' => $tids['Beta']));
    check('A) DB adi guncellendi', $row['name'] === 'Beta Yeni', $row['name']);
    check('A) DB aciklamasi guncellendi', $row['description'] === 'aciklama', var_export($row['description'], true));
    check('A) audit satiri yazildi',
        (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'table.update' AND entity_id = :t", array(':t' => $tids['Beta'])) === $auditBefore + 1);

    // =======================================================================
    echo "\n--- B) move_table (normal akis) ---\n";
    check('B) Baslangic sirasi', $order() === 'Alfa:0 Beta Yeni:1 Gama:2', $order());

    $page = req('GET', "/base_tables.php?base_id={$baseId}", $cookie);
    $auditBefore = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'table.reorder' AND entity_id = :t", array(':t' => $tids['Gama']));
    $resp = req('POST', '/base_tables.php', $cookie, array(
        'csrf_token' => csrf($page['body']), 'action' => 'move_table',
        'base_id' => $baseId, 'table_id' => $tids['Gama'], 'direction' => 'up',
    ));
    check('B) Tasima istegi 200', $resp['status'] === 200, 'durum ' . $resp['status']);
    check('B) Gama yukari tasindi (iki komsu takas edildi)', $order() === 'Alfa:0 Gama:1 Beta Yeni:2', $order());
    check('B) HICBIR satir ayni position u paylasmiyor', $dupPositions() === 0, 'cakisan position sayisi: ' . $dupPositions());
    check('B) reorder audit satiri yazildi',
        (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'table.reorder' AND entity_id = :t", array(':t' => $tids['Gama'])) === $auditBefore + 1);

    // Geri tasi (down) — ters yon de calisiyor mu
    $page = req('GET', "/base_tables.php?base_id={$baseId}", $cookie);
    req('POST', '/base_tables.php', $cookie, array(
        'csrf_token' => csrf($page['body']), 'action' => 'move_table',
        'base_id' => $baseId, 'table_id' => $tids['Gama'], 'direction' => 'down',
    ));
    check('B) Asagi tasima da calisiyor (baslangica dondu)', $order() === 'Alfa:0 Beta Yeni:1 Gama:2', $order());

    // Sinir: ilk elemani yukari tasima -> degisiklik YOK, hata YOK
    $page = req('GET', "/base_tables.php?base_id={$baseId}", $cookie);
    $resp = req('POST', '/base_tables.php', $cookie, array(
        'csrf_token' => csrf($page['body']), 'action' => 'move_table',
        'base_id' => $baseId, 'table_id' => $tids['Alfa'], 'direction' => 'up',
    ));
    check('B) SINIR: ilk elemani yukari -> sira degismedi', $order() === 'Alfa:0 Beta Yeni:1 Gama:2', $order());
    check('B) SINIR: hata mesaji YOK', strpos($resp['body'], 'taşınamadı') === false);

    // =======================================================================
    echo "\n--- C) ATOMIKLIK: YARIM TAKAS SENARYOSU (en kritik) ---\n";
    // bcc_reorder_sibling()'in KENDISI cagrilir (base_tables.php'nin kullandigi
    // AYNI fonksiyon), transaction cagiran tarafta acilir ve iki UPDATE'ten
    // SONRA kasitli bir hata tetiklenir. Beklenen: IKI UPDATE de geri alinir.
    $orderBefore = $order();
    $posBefore = bcc_fetch_all('SELECT id, position FROM tables_meta WHERE base_id = :b ORDER BY id', array(':b' => $baseId));
    $rolledBack = false;
    $midOrder = null;

    try {
        bcc_begin_transaction();

        $moved = bcc_reorder_sibling('tables_meta', 'base_id', $baseId, $tids['Gama'], 'up');
        $midOrder = $order(); // transaction ICINDE takas gorunuyor mu

        // log_audit()'in patlamasini taklit et (gecersiz kolon -> mysqli istisnasi)
        bcc_execute('INSERT INTO audit_log (olmayan_kolon) VALUES (1)');

        bcc_commit();
    } catch (Throwable $e) {
        bcc_rollback();
        $rolledBack = true;
    }

    check('C) Transaction ICINDE takas gerceklesti', $midOrder === 'Alfa:0 Gama:1 Beta Yeni:2', (string) $midOrder);
    check('C) Kasitli hata ISTISNA firlatti (mysqli STRICT)', $rolledBack);
    check('C) ⭐ HER IKI UPDATE de GERI ALINDI (yarim takas YOK)', $order() === $orderBefore,
        'once: ' . $orderBefore . ' | sonra: ' . $order());

    $posAfter = bcc_fetch_all('SELECT id, position FROM tables_meta WHERE base_id = :b ORDER BY id', array(':b' => $baseId));
    check('C) ⭐ Her satirin position u BIREBIR eski degerinde',
        json_encode($posBefore) === json_encode($posAfter),
        json_encode($posBefore) . ' -> ' . json_encode($posAfter));
    check('C) ⭐ Hicbir satir ayni position u paylasmiyor (cakisma YOK)', $dupPositions() === 0);

    // =======================================================================
    echo "\n--- D) ATOMIKLIK: rename geri alinabiliyor mu ---\n";
    $nameBefore = bcc_fetch_column('SELECT name FROM tables_meta WHERE id = :t', array(':t' => $tids['Alfa']));
    $rb2 = false;
    try {
        bcc_begin_transaction();
        bcc_execute('UPDATE tables_meta SET name = :n WHERE id = :t', array(':n' => 'ROLLBACK OLMALI', ':t' => $tids['Alfa']));
        bcc_execute('INSERT INTO audit_log (olmayan_kolon) VALUES (1)');
        bcc_commit();
    } catch (Throwable $e) {
        bcc_rollback();
        $rb2 = true;
    }
    check('D) rename istisnasi yakalandi', $rb2);
    check('D) Ad GERI ALINDI', bcc_fetch_column('SELECT name FROM tables_meta WHERE id = :t', array(':t' => $tids['Alfa'])) === $nameBefore,
        'beklenen: ' . $nameBefore);
    check('D) "ROLLBACK OLMALI" adli tablo DB de YOK',
        bcc_fetch_one('SELECT id FROM tables_meta WHERE name = "ROLLBACK OLMALI"') === false);

    // =======================================================================
    // bcc_reorder_sibling()'in SOZLESMESI degistigi icin (transaction artik
    // cagiranin sorumlulugu) DORT cagri yerinin DORDU de canli dogrulanmali —
    // yalnizca base_tables.php'yi test etmek digerlerinin sessizce bozulmasina
    // izin verirdi.
    echo "\n--- D2) DIGER UC CAGRI YERI (canli regresyon) ---\n";

    // --- move_field (table_fields.php) ---
    $fids = array();
    foreach (array('A', 'B', 'C') as $i => $n) {
        bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, "single_line_text", :p)',
            array(':t' => $tids['Alfa'], ':n' => $n, ':p' => $i));
        $fids[$n] = (int) bcc_last_insert_id();
    }
    $fOrder = function () use ($tids) {
        $o = array();
        foreach (bcc_fetch_all('SELECT name, position FROM fields WHERE table_id = :t ORDER BY position, id', array(':t' => $tids['Alfa'])) as $r) {
            $o[] = $r['name'] . ':' . $r['position'];
        }
        return implode(' ', $o);
    };
    $page = req('GET', "/table_fields.php?table_id={$tids['Alfa']}", $cookie);
    $resp = req('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => csrf($page['body']), 'action' => 'move_field',
        'table_id' => $tids['Alfa'], 'field_id' => $fids['C'], 'direction' => 'up',
    ));
    check('D2) move_field istegi 200', $resp['status'] === 200, 'durum ' . $resp['status']);
    check('D2) Alan sirasi takas edildi', $fOrder() === 'A:0 C:1 B:2', $fOrder());
    check('D2) Alanlarda position cakismasi YOK',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM (SELECT position FROM fields WHERE table_id = :t GROUP BY position HAVING COUNT(*) > 1) x', array(':t' => $tids['Alfa'])) === 0);
    check('D2) field.reorder audit yazildi',
        (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'field.reorder' AND entity_id = :f", array(':f' => $fids['C'])) === 1);

    // --- view_reorder (api/view_reorder.php) ---
    req('GET', "/grid.php?table_id={$tids['Alfa']}", $cookie); // varsayilan gorunum olussun
    $g = req('GET', "/grid.php?table_id={$tids['Alfa']}", $cookie);
    $gridCsrf = csrf($g['body']);
    req('POST', '/api/view_create.php', $cookie, array('csrf_token' => $gridCsrf, 'table_id' => $tids['Alfa'], 'view_type' => 'grid'));
    $views = bcc_fetch_all('SELECT id, position FROM views WHERE table_id = :t ORDER BY position, id', array(':t' => $tids['Alfa']));
    check('D2) Iki gorunum olustu', count($views) === 2, count($views) . ' gorunum');
    $vOrder = function () use ($tids) {
        $o = array();
        foreach (bcc_fetch_all('SELECT id, position FROM views WHERE table_id = :t ORDER BY position, id', array(':t' => $tids['Alfa'])) as $r) {
            $o[] = $r['id'] . ':' . $r['position'];
        }
        return implode(' ', $o);
    };
    $vBefore = $vOrder();
    $secondView = (int) $views[1]['id'];
    $resp = req('POST', '/api/view_reorder.php', $cookie, array('csrf_token' => $gridCsrf, 'view_id' => $secondView, 'direction' => 'up'));
    $j = json_decode($resp['body'], true);
    check('D2) view_reorder basarili', is_array($j) && !empty($j['ok']), $resp['body']);
    check('D2) Gorunum sirasi degisti', $vOrder() !== $vBefore, $vBefore . ' -> ' . $vOrder());
    check('D2) Gorunumlerde position cakismasi YOK',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM (SELECT position FROM views WHERE table_id = :t GROUP BY position HAVING COUNT(*) > 1) x', array(':t' => $tids['Alfa'])) === 0);
    check('D2) view.reorder audit yazildi',
        (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'view.reorder' AND entity_id = :v", array(':v' => $secondView)) === 1);

    // --- slack kural sirasi (slack_settings.php) ---
    // slack_routing_rules.webhook_id bir FK — once gercek bir webhook satiri
    // gerekiyor (semadan dogrulandi: value/webhook_id kolonlari, match_value DEGIL).
    bcc_execute('INSERT INTO slack_webhooks (team_id, table_id, webhook_url, channel_name, is_active)
                 VALUES (:tm, :t, "https://hooks.slack.com/services/T/B/x", "#test", 1)',
        array(':tm' => $teamId, ':t' => $tids['Alfa']));
    $webhookId = (int) bcc_last_insert_id();
    foreach (array('X', 'Y') as $i => $n) {
        bcc_execute('INSERT INTO slack_routing_rules (team_id, table_id, field_id, operator, value, webhook_id, is_active, position)
                     VALUES (:tm, :t, :f, "equals", :v, :w, 1, :p)',
            array(':tm' => $teamId, ':t' => $tids['Alfa'], ':f' => $fids['A'], ':v' => $n, ':w' => $webhookId, ':p' => $i));
    }
    $rules = bcc_fetch_all('SELECT id, position FROM slack_routing_rules WHERE table_id = :t ORDER BY position, id', array(':t' => $tids['Alfa']));
    $rOrder = function () use ($tids) {
        $o = array();
        foreach (bcc_fetch_all('SELECT id, position FROM slack_routing_rules WHERE table_id = :t ORDER BY position, id', array(':t' => $tids['Alfa'])) as $r) {
            $o[] = $r['id'] . ':' . $r['position'];
        }
        return implode(' ', $o);
    };
    $rBefore = $rOrder();
    $sp = req('GET', "/slack_settings.php?table_id={$tids['Alfa']}", $cookie);
    $resp = req('POST', '/slack_settings.php', $cookie, array(
        'csrf_token' => csrf($sp['body']), 'action' => 'move_routing_rule',
        'table_id' => $tids['Alfa'], 'rule_id' => (int) $rules[1]['id'], 'direction' => 'up',
    ));
    check('D2) slack kural tasima 200', $resp['status'] === 200, 'durum ' . $resp['status']);
    check('D2) Kural sirasi degisti', $rOrder() !== $rBefore, $rBefore . ' -> ' . $rOrder());
    check('D2) Kurallarda position cakismasi YOK',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM (SELECT position FROM slack_routing_rules WHERE table_id = :t GROUP BY position HAVING COUNT(*) > 1) x', array(':t' => $tids['Alfa'])) === 0);

    // =======================================================================
    echo "\n--- E) KOD INCELEMESI ---\n";
    $schemaSrc = file_get_contents(__DIR__ . '/../src/schema.php');
    preg_match('/function bcc_reorder_sibling.*?\n}/s', $schemaSrc, $fn);
    $fnBody = isset($fn[0]) ? $fn[0] : '';
    check('E) bcc_reorder_sibling ARTIK kendi transaction ini ACMIYOR',
        substr_count($fnBody, 'bcc_begin_transaction') === 0 && substr_count($fnBody, 'bcc_commit') === 0,
        'begin: ' . substr_count($fnBody, 'bcc_begin_transaction') . ' commit: ' . substr_count($fnBody, 'bcc_commit'));
    check('E) Iki UPDATE hala fonksiyonun icinde', substr_count($fnBody, 'UPDATE {$tableName}') === 2);
    check('E) Sozlesme yorumda yazili (cagiran transaction acmali)',
        strpos($fnBody, 'çağıran taraf') !== false || strpos($schemaSrc, 'ARTIK KENDİ transaction') !== false);

    // DORT cagri yerinin DORDU de transaction aciyor mu
    $callers = array(
        'base_tables.php' => __DIR__ . '/../public/base_tables.php',
        'table_fields.php' => __DIR__ . '/../public/table_fields.php',
        'slack_settings.php' => __DIR__ . '/../public/slack_settings.php',
        'api/view_reorder.php' => __DIR__ . '/../public/api/view_reorder.php',
    );
    foreach ($callers as $label => $path) {
        $src = file_get_contents($path);
        check("E) {$label}: bcc_reorder_sibling transaction ICINDE",
            preg_match('/bcc_begin_transaction.*?bcc_reorder_sibling.*?bcc_commit/s', $src) === 1);
        check("E) {$label}: begin/commit/rollback simetrik",
            substr_count($src, 'bcc_begin_transaction') === substr_count($src, 'bcc_commit')
            && substr_count($src, 'bcc_commit') === substr_count($src, 'bcc_rollback'),
            substr_count($src, 'bcc_begin_transaction') . '/' . substr_count($src, 'bcc_commit') . '/' . substr_count($src, 'bcc_rollback'));
    }

    $btSrc = file_get_contents(__DIR__ . '/../public/base_tables.php');
    foreach (array('create_table', 'rename_table', 'delete_table', 'move_table') as $act) {
        check("E) base_tables.php {$act} transaction ile sarili",
            preg_match('/' . $act . '.*?bcc_begin_transaction.*?bcc_commit.*?bcc_rollback/s', $btSrc) === 1);
    }

    echo "\n";
} catch (Exception $e) {
    echo "\nISTISNA: " . $e->getMessage() . "\n";
    $results[] = false;
}

$cleanup();

// =======================================================================
echo "--- TEMIZLIK + GERCEK VERI KONTROLU ---\n";
$realAfter = array(
    'tablo' => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = ' . REAL_BASE_ID),
    'kayit' => (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id IN (SELECT id FROM tables_meta WHERE base_id = ' . REAL_BASE_ID . ')'),
    'pozisyon' => (string) bcc_fetch_column('SELECT GROUP_CONCAT(CONCAT(id, ":", position) ORDER BY position, id) FROM tables_meta WHERE base_id = ' . REAL_BASE_ID),
);
check('Test verisi silindi', (int) bcc_fetch_column("SELECT COUNT(*) FROM users WHERE email LIKE '%@bcc-test.local'") === 0);
check('GERCEK base (15) tablo sayisi DEGISMEDI', $realBefore['tablo'] === $realAfter['tablo'], "{$realBefore['tablo']} -> {$realAfter['tablo']}");
check('GERCEK base (15) kayit sayisi DEGISMEDI', $realBefore['kayit'] === $realAfter['kayit'], "{$realBefore['kayit']} -> {$realAfter['kayit']}");
check('GERCEK base (15) tablo SIRALAMASI DEGISMEDI', $realBefore['pozisyon'] === $realAfter['pozisyon'],
    $realBefore['pozisyon'] . ' -> ' . $realAfter['pozisyon']);

$passed = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($passed === $total ? 'GECTI' : 'KALDI') . " ({$passed}/{$total})\n";
echo "==================================\n";
exit($passed === $total ? 0 : 1);
