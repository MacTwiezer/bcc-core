<?php
// Toplu satir ekleme ("sayi gir, o kadar satir eklensin") dogrulamasi.
// curl KULLANILMAZ — PHP'nin http:// stream sarmalayicisiyla gercek oturum
// cerezi alinip gercek uc noktalara istek atilir (scripts/_verify_group_c2.php
// ile AYNI desen). Kendi izole takimini/base'ini kurar, dogrular, SONUNDA temizler.
// GERCEK verilere (mevcut takimlar, base'ler) DOKUNMAZ.
//
// On kosul: Apache + MySQL ayakta (XAMPP), DocumentRoot = public, localhost:80.
// Calistirma: C:\php73\php.exe scripts\_verify_bulk_row_add.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'bulkadd.test.owner@bcc-test.local');
define('TEST_PASS', 'BulkAddTest!2026');
define('TEST_TEAM', 'ZZ Bulk Add Test');

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

function http_request($method, $path, $cookie = null, $postFields = null)
{
    $headers = array();
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));

    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }

    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $status = 0;
    $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
            if (stripos($h, 'Set-Cookie:') === 0) {
                $parts = explode(';', substr($h, 11));
                $newCookie = trim($parts[0]);
            }
        }
    }

    return array('body' => $body, 'cookie' => $newCookie, 'status' => $status);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

// Bir tablonun canli kayit id'leri, GORUNEN sirayla.
function rec_ids($tableId)
{
    $rows = bcc_fetch_all(
        'SELECT id FROM records WHERE table_id = :t AND deleted_at IS NULL ORDER BY position, id',
        array(':t' => $tableId)
    );
    return array_map('intval', array_column($rows, 'id'));
}

function positions($tableId)
{
    $rows = bcc_fetch_all(
        'SELECT position FROM records WHERE table_id = :t AND deleted_at IS NULL ORDER BY position, id',
        array(':t' => $tableId)
    );
    return array_map('intval', array_column($rows, 'position'));
}

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => TEST_EMAIL)
    ), 'id');
    foreach ($baseIds as $baseId) {
        bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $baseId));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));
    bcc_execute('DELETE FROM teams WHERE name = :n', array(':n' => TEST_TEAM));
};

// Onceki yarim kalmis kosudan artik varsa temizle.
$cleanup();

try {
    // --- Izole fixture ----------------------------------------------------
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEST_TEAM));
    $teamId = (int) bcc_last_insert_id();

    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'BulkAdd Test Owner')
    );
    $userId = (int) bcc_last_insert_id();
    bcc_execute(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $userId, ':r' => 'owner')
    );

    bcc_execute(
        'INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'BulkAdd Test', ':u' => $userId)
    );
    $baseId = (int) bcc_last_insert_id();

    $mkTable = function ($name) use ($baseId) {
        bcc_execute(
            'INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
            array(':b' => $baseId, ':n' => $name)
        );
        return (int) bcc_last_insert_id();
    };
    $mkField = function ($tableId, $name, $type, $pos) {
        bcc_execute(
            'INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)',
            array(':t' => $tableId, ':n' => $name, ':ft' => $type, ':p' => $pos)
        );
        return (int) bcc_last_insert_id();
    };

    // --- Oturum -----------------------------------------------------------
    $resp = http_request('GET', '/login.php');
    $csrf = extract_csrf($resp['body']);
    $cookie = $resp['cookie'];
    $resp = http_request('POST', '/login.php', $cookie, array(
        'email' => TEST_EMAIL, 'password' => TEST_PASS, 'csrf_token' => $csrf,
    ));
    if ($resp['cookie']) {
        $cookie = $resp['cookie'];
    }
    check('Giris yapildi (owner)', $cookie !== null);

    // =====================================================================
    // 1) BOS TABLO + count=5
    // =====================================================================
    $t1 = $mkTable('T1 Toplu');
    $mkField($t1, 'Ad', 'single_line_text', 0);

    $resp = http_request('GET', "/grid.php?table_id={$t1}", $cookie);
    $csrf1 = extract_csrf($resp['body']);
    check(
        '1) grid.php toplu ekleme kutusunu basiyor',
        strpos($resp['body'], 'data-grid-add-bulk-btn') !== false
    );

    $resp = http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t1, 'count' => 5,
    ));
    $j = json_decode($resp['body'], true);
    check('1) count=5 -> ok:true', isset($j['ok']) && $j['ok'] === true, $resp['body']);
    check('1) count=5 -> DBde 5 kayit', count(rec_ids($t1)) === 5, 'bulunan: ' . count(rec_ids($t1)));
    check(
        '1) count=5 -> rows_html 5 satir',
        isset($j['rows_html']) && count($j['rows_html']) === 5,
        'bulunan: ' . (isset($j['rows_html']) ? count($j['rows_html']) : 'yok')
    );
    check('1) count=5 -> record_ids 5 id', isset($j['record_ids']) && count($j['record_ids']) === 5);
    check(
        '1) count=5 -> pozisyonlar 0,1,2,3,4',
        positions($t1) === array(0, 1, 2, 3, 4),
        'bulunan: ' . implode(',', positions($t1))
    );
    $htmlIds = array();
    foreach ($j['rows_html'] as $h) {
        if (preg_match('/data-record-id="(\d+)"/', $h, $m)) {
            $htmlIds[] = (int) $m[1];
        }
    }
    check(
        "1) rows_html icindeki id'ler record_ids ile ayni ve sirali",
        $htmlIds === array_map('intval', $j['record_ids']),
        'html: ' . implode(',', $htmlIds) . ' / ids: ' . implode(',', $j['record_ids'])
    );

    // =====================================================================
    // 2) GERIYE DONUK: count HIC gonderilmezse 1 kayit
    // =====================================================================
    $t2 = $mkTable('T2 Tekil');
    $mkField($t2, 'Ad', 'single_line_text', 0);
    $resp = http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t2,
    ));
    $j = json_decode($resp['body'], true);
    check('2) count YOK -> tek kayit', count(rec_ids($t2)) === 1, 'bulunan: ' . count(rec_ids($t2)));
    check(
        '2) count YOK -> row_html hala dolu (geriye donuk alan)',
        isset($j['row_html']) && strpos($j['row_html'], 'data-record-id') !== false
    );
    check(
        '2) count YOK -> record_id ilk kayit',
        isset($j['record_id']) && (int) $j['record_id'] === rec_ids($t2)[0]
    );

    // =====================================================================
    // 3) ARAYA EKLEME: after_record_id + count=3 (pozisyon kaydirmasi)
    // =====================================================================
    $t3 = $mkTable('T3 Araya');
    $mkField($t3, 'Ad', 'single_line_text', 0);
    http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t3, 'count' => 3,
    ));
    $before = rec_ids($t3);
    check('3) hazirlik: 3 kayit', count($before) === 3);

    $resp = http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t3, 'after_record_id' => $before[0], 'count' => 3,
    ));
    $j = json_decode($resp['body'], true);
    $after = rec_ids($t3);
    $expected = array_merge(
        array($before[0]),
        array_map('intval', $j['record_ids']),
        array($before[1], $before[2])
    );
    check(
        '3) 3 yeni kayit 1. kaydin HEMEN ALTINA girdi, digerleri sirasini korudu',
        $after === $expected,
        'bulunan: ' . implode(',', $after) . ' / beklenen: ' . implode(',', $expected)
    );
    check(
        '3) pozisyonlar cakisik DEGIL (hepsi tekil)',
        count(array_unique(positions($t3))) === 6,
        'pozisyonlar: ' . implode(',', positions($t3))
    );

    // =====================================================================
    // 4) SINIRLAR: count=0 -> 1'e kirpilir; count=501 -> 422 ve HIC kayit yok
    // =====================================================================
    $t4 = $mkTable('T4 Sinir');
    $mkField($t4, 'Ad', 'single_line_text', 0);
    http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t4, 'count' => 0,
    ));
    check('4) count=0 -> 1 kayit (asagi kirpma)', count(rec_ids($t4)) === 1, 'bulunan: ' . count(rec_ids($t4)));
    http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t4, 'count' => -7,
    ));
    check('4) count=-7 -> yine 1 kayit eklendi (toplam 2)', count(rec_ids($t4)) === 2, 'bulunan: ' . count(rec_ids($t4)));

    $resp = http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t4, 'count' => 501,
    ));
    check('4) count=501 -> 422 reddedildi', $resp['status'] === 422, 'status: ' . $resp['status'] . ' body: ' . $resp['body']);
    check('4) count=501 -> HIC kayit eklenmedi (hala 2)', count(rec_ids($t4)) === 2, 'bulunan: ' . count(rec_ids($t4)));

    // =====================================================================
    // 5) AUDIT: toplu ekleme TEK ozet satiri yazar, 500 satir degil
    // =====================================================================
    $t5 = $mkTable('T5 Audit');
    $mkField($t5, 'Ad', 'single_line_text', 0);
    $auditBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM audit_log WHERE team_id = :t', array(':t' => $teamId));
    http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t5, 'count' => 7,
    ));
    $auditAfter = (int) bcc_fetch_column('SELECT COUNT(*) FROM audit_log WHERE team_id = :t', array(':t' => $teamId));
    check(
        '5) 7 satirlik toplu ekleme audit_loga TEK satir yazdi',
        ($auditAfter - $auditBefore) === 1,
        'fark: ' . ($auditAfter - $auditBefore)
    );
    $lastAction = (string) bcc_fetch_column(
        'SELECT action FROM audit_log WHERE team_id = :t ORDER BY id DESC LIMIT 1',
        array(':t' => $teamId)
    );
    check('5) audit action = record.create_bulk', $lastAction === 'record.create_bulk', 'bulunan: ' . $lastAction);

    // =====================================================================
    // 6) AUTONUMBER: toplu eklemede ardisik numaralar
    // =====================================================================
    $t6 = $mkTable('T6 Autonumber');
    $mkField($t6, 'Ad', 'single_line_text', 0);
    $resp = http_request('GET', "/table_fields.php?table_id={$t6}", $cookie);
    $csrf6 = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrf6, 'action' => 'create_field', 'table_id' => $t6,
        'name' => 'No', 'field_type' => 'autonumber',
    ));
    $no6 = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $t6));
    if ($no6 > 0) {
        http_request('POST', '/api/record_add.php', $cookie, array(
            'csrf_token' => $csrf1, 'table_id' => $t6, 'count' => 4,
        ));
        $rows = bcc_fetch_all(
            'SELECT CAST(cv.value_number AS UNSIGNED) n FROM records r
             LEFT JOIN cell_values cv ON cv.record_id = r.id AND cv.field_id = :f
             WHERE r.table_id = :t AND r.deleted_at IS NULL ORDER BY r.position, r.id',
            array(':f' => $no6, ':t' => $t6)
        );
        $ns = array_map(function ($r) {
            return $r['n'] === null ? 'NULL' : (int) $r['n'];
        }, $rows);
        check('6) toplu eklemede autonumber 1,2,3,4', $ns === array(1, 2, 3, 4), 'bulunan: ' . implode(',', $ns));
    } else {
        check('6) autonumber alani olusturuldu', false, 'alan bulunamadi');
    }

    // =====================================================================
    // 7) RBAC: viewer toplu ekleme YAPAMAZ
    // =====================================================================
    bcc_execute(
        'UPDATE team_members SET role = :r WHERE team_id = :t AND user_id = :u',
        array(':r' => 'viewer', ':t' => $teamId, ':u' => $userId)
    );
    $t7count = count(rec_ids($t1));
    $resp = http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t1, 'count' => 50,
    ));
    check('7) viewer rolu toplu ekleme REDDEDILDI', $resp['status'] >= 400, 'status: ' . $resp['status']);
    check(
        '7) viewer denemesinde HIC kayit eklenmedi',
        count(rec_ids($t1)) === $t7count,
        'once: ' . $t7count . ' sonra: ' . count(rec_ids($t1))
    );
    bcc_execute(
        'UPDATE team_members SET role = :r WHERE team_id = :t AND user_id = :u',
        array(':r' => 'owner', ':t' => $teamId, ':u' => $userId)
    );

    // =====================================================================
    // 8) PERFORMANS: 200 satir tek istekte ne kadar suruyor (sunum kriteri)
    // =====================================================================
    $t8 = $mkTable('T8 Performans');
    $mkField($t8, 'Ad', 'single_line_text', 0);
    $mkField($t8, 'Not', 'long_text', 1);
    $mkField($t8, 'Sayi', 'number', 2);
    $started = microtime(true);
    $resp = http_request('POST', '/api/record_add.php', $cookie, array(
        'csrf_token' => $csrf1, 'table_id' => $t8, 'count' => 200,
    ));
    $elapsed = microtime(true) - $started;
    check('8) 200 satir eklendi', count(rec_ids($t8)) === 200, 'bulunan: ' . count(rec_ids($t8)));
    check('8) 200 satir 5 saniyenin altinda dondu', $elapsed < 5.0, 'sure: ' . round($elapsed, 2) . ' sn');
    echo '         -> 200 satir sunucu suresi: ' . round($elapsed, 2) . ' sn, yanit boyutu: '
        . round(strlen($resp['body']) / 1024, 1) . " KB\n";
    // =====================================================================
    // 9) REGRESYON (JS statik): renumberRows() satir no HUCRESININ kendisine
    //    yazmamali. Yazarsa hucrenin cocuklari (.grid-rownum-inner, secim
    //    kutusu .grid-row-select, genislet butonu .grid-row-expand) silinir ve
    //    bir silme/eklemeden SONRA ikinci bir satir secilemez hale gelirdi
    //    (kullanicinin bildirdigi gercek bug; sayfa yenileyince duzeliyordu).
    // =====================================================================
    $gridJs = file_get_contents(__DIR__ . '/../public/assets/grid.js');
    check(
        '9) renumberRows() numara SPAN\'ine yaziyor (.grid-rownum-number)',
        strpos($gridJs, "tr.querySelector('.grid-rownum-number')") !== false
    );
    check(
        '9) renumberRows() satir no HUCRESINE textContent YAZMIYOR (regresyon)',
        strpos($gridJs, "querySelector('.grid-rownum')") === false,
        'grid.js hala .grid-rownum hucresini secip iceriginin uzerine yaziyor'
    );
} catch (Throwable $e) {
    echo 'ISTISNA: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $results[] = false;
}

$cleanup();

$passed = count(array_filter($results));
$total = count($results);
echo "\n" . str_repeat('=', 60) . "\n";
echo "SONUC: {$passed}/{$total} gecti\n";
echo "Test verisi temizlendi (takim, kullanici, base, tablolar).\n";
exit($passed === $total ? 0 : 1);
