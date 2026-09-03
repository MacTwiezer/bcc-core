<?php
// "Temsilci İnceleme Geçmişi" -> Excel indirme (api/note_view_export_xlsx.php)
// ve panelin ROL KAPISI doğrulaması.
//
// curl KULLANILMAZ — PHP'nin http:// stream sarmalayicisiyla gercek oturum
// cerezi alinip gercek uc noktalara istek atilir (_verify_group_c2.php deseni).
// Kendi izole takimini/kullanicilarini/base'ini kurar, dogrular, SONUNDA temizler.
// GERCEK verilere DOKUNMAZ.
//
// On kosul: Apache + MySQL ayakta (XAMPP), DocumentRoot = public, localhost:80.
// Calistirma: C:\php73\php.exe scripts\_verify_note_view_export.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('BASE_URL', 'http://localhost');
define('TEST_TEAM', 'ZZ Note Export Test');
define('EMAIL_SUFFIX', '@bcc-test.local');
define('TEST_PASS', 'NoteExport!2026');

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

// Ham gövde + durum kodu döner (xlsx ikili veri, json_decode edilmez).
// $followRedirect: varsayilan true. Oturum kontrolu testinde BILEREK false —
// require_login() 302 ile /login.php'ye atiyor ve stream sarmalayici bunu
// takip edip 200 (login SAYFASI) donduruyordu; o 200 "dosya indirildi"
// sanilirsa test yanlis yere bakar.
function http_request($method, $path, $cookie = null, $postFields = null, $followRedirect = true)
{
    $headers = array();
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));

    if (!$followRedirect) {
        $options['http']['follow_location'] = 0;
    }

    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }

    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $status = 0;
    $newCookie = null;
    $respHeaders = array();
    if (isset($http_response_header)) {
        $respHeaders = $http_response_header;
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

    return array('body' => $body, 'cookie' => $newCookie, 'status' => $status, 'headers' => $respHeaders);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

function login_as($email)
{
    $resp = http_request('GET', '/login.php');
    $csrf = extract_csrf($resp['body']);
    $cookie = $resp['cookie'];
    $resp = http_request('POST', '/login.php', $cookie, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => $csrf,
    ));
    return $resp['cookie'] ? $resp['cookie'] : $cookie;
}

// .xlsx bir ZIP'tir; sheet1.xml icindeki metinleri cikarir.
function xlsx_texts($binary)
{
    $tmp = tempnam(sys_get_temp_dir(), 'bcc_xlsx_check_');
    file_put_contents($tmp, $binary);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        unlink($tmp);
        return null;
    }
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($tmp);

    if ($xml === false) {
        return null;
    }

    preg_match_all('/<t xml:space="preserve">(.*?)<\/t>/s', $xml, $m);
    return array_map(function ($t) {
        return html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    }, $m[1]);
}

$cleanup = function () {
    $userIds = array_column(bcc_fetch_all(
        'SELECT id FROM users WHERE email LIKE :e',
        array(':e' => '%' . EMAIL_SUFFIX)
    ), 'id');
    foreach ($userIds as $uid) {
        $baseIds = array_column(bcc_fetch_all('SELECT id FROM bases WHERE created_by = :u', array(':u' => $uid)), 'id');
        foreach ($baseIds as $bid) {
            bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid));
        }
    }
    bcc_execute('DELETE FROM users WHERE email LIKE :e', array(':e' => '%' . EMAIL_SUFFIX));
    bcc_execute('DELETE FROM teams WHERE name = :n', array(':n' => TEST_TEAM));
};

$cleanup();
register_shutdown_function($cleanup);

try {
    // --- Izole fikstur ----------------------------------------------------
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEST_TEAM));
    $teamId = (int) bcc_last_insert_id();

    $uid = array();
    foreach (array('owner', 'editor', 'commenter', 'viewer') as $role) {
        $email = $role . EMAIL_SUFFIX;
        bcc_execute(
            'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
            array(':e' => $email, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'NX ' . ucfirst($role))
        );
        $uid[$role] = (int) bcc_last_insert_id();
        bcc_execute(
            'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
            array(':t' => $teamId, ':u' => $uid[$role], ':r' => $role)
        );
    }

    // Platform admini: takimda HIC uyeligi YOK — sanal 'owner' rolunu
    // (src/auth.php current_user_role_in_team) dogrulamak icin.
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 1, 1)',
        array(':e' => 'admin' . EMAIL_SUFFIX, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'NX Admin')
    );
    $uid['admin'] = (int) bcc_last_insert_id();

    bcc_execute(
        'INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'NoteExport Test', ':u' => $uid['owner'])
    );
    $baseId = (int) bcc_last_insert_id();

    bcc_execute(
        'INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Notlar')
    );
    $tableId = (int) bcc_last_insert_id();

    bcc_execute(
        'INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Baslik', ':ft' => 'single_line_text')
    );
    $fieldId = (int) bcc_last_insert_id();

    bcc_execute(
        'INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)',
        array(':t' => $tableId, ':u' => $uid['owner'])
    );
    $recordId = (int) bcc_last_insert_id();
    bcc_execute(
        'INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
        array(':r' => $recordId, ':f' => $fieldId, ':v' => 'Onemli Musteri Notu')
    );

    // Inceleme kayitlari: biri tamamlanmis, biri acik (closed_at NULL),
    // biri de 15 GUNDEN ESKI (rapora GIRMEMELI).
    $mkView = function ($userId, $role, $openedAt, $closedAt, $duration) use ($recordId, $teamId) {
        bcc_execute(
            'INSERT INTO record_view_log (record_id, user_id, team_id, role_at_view, opened_at, closed_at, duration_seconds)
             VALUES (:r, :u, :t, :ro, :o, :c, :d)',
            array(':r' => $recordId, ':u' => $userId, ':t' => $teamId, ':ro' => $role,
                  ':o' => $openedAt, ':c' => $closedAt, ':d' => $duration)
        );
    };
    $mkView($uid['commenter'], 'commenter', date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 3480), 120);
    $mkView($uid['commenter'], 'commenter', date('Y-m-d H:i:s', time() - 600), null, 45);
    $mkView($uid['commenter'], 'commenter', date('Y-m-d H:i:s', time() - (20 * 86400)), date('Y-m-d H:i:s', time() - (20 * 86400) + 60), 60);

    // =====================================================================
    // A) ROL KAPISI — panelin HTML'i ve export ucnoktasi
    // =====================================================================
    echo "\n=== A) Rol kapisi ===\n";

    $ifPath = "/interface.php?base_id={$baseId}&table_id={$tableId}";

    foreach (array('owner', 'admin') as $role) {
        $cookie = login_as($role . EMAIL_SUFFIX);
        $resp = http_request('GET', $ifPath, $cookie);
        check(
            "A) {$role}: panel HTML'i BASILIYOR",
            strpos($resp['body'], 'id="if-audit"') !== false,
            'status: ' . $resp['status']
        );
        check(
            "A) {$role}: Excel indirme dugmesi BASILIYOR",
            strpos($resp['body'], 'data-audit-export') !== false
        );
    }

    foreach (array('editor', 'commenter', 'viewer') as $role) {
        $cookie = login_as($role . EMAIL_SUFFIX);
        $resp = http_request('GET', $ifPath, $cookie);
        $sayfaAcildi = $resp['status'] === 200;
        check("A) {$role}: interface sayfasini acabiliyor (kontrol anlamli olsun)", $sayfaAcildi, 'status: ' . $resp['status']);
        check(
            "A) {$role}: panel HTML'i HIC BASILMIYOR (CSS ile gizlenmis degil)",
            strpos($resp['body'], 'id="if-audit"') === false
        );
        check(
            "A) {$role}: Excel dugmesi HIC BASILMIYOR",
            strpos($resp['body'], 'data-audit-export') === false
        );

        $resp = http_request('GET', "/api/note_view_export_xlsx.php?record_id={$recordId}", $cookie);
        check(
            "A) {$role}: export ucnoktasi 403 (URL dogrudan denense bile)",
            $resp['status'] === 403,
            'status: ' . $resp['status']
        );
    }

    // =====================================================================
    // B) EXCEL ICERIGI
    // =====================================================================
    echo "\n=== B) Excel icerigi ===\n";

    $cookie = login_as('owner' . EMAIL_SUFFIX);
    $resp = http_request('GET', "/api/note_view_export_xlsx.php?record_id={$recordId}", $cookie);
    check('B) owner: 200 doner', $resp['status'] === 200, 'status: ' . $resp['status']);

    $headerBlob = implode("\n", $resp['headers']);
    check('B) Content-Type xlsx', stripos($headerBlob, 'spreadsheetml.sheet') !== false);
    check('B) attachment olarak iniyor', stripos($headerBlob, 'attachment;') !== false);
    check(
        'B) dosya adinda donemin IKI ucu da var',
        preg_match('/filename="temsilci_inceleme_(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})\.xlsx"/', $headerBlob, $fm) === 1,
        $headerBlob
    );

    $texts = xlsx_texts($resp['body']);
    check('B) gecerli bir .xlsx (ZIP + sheet1.xml okunabiliyor)', $texts !== null);

    if ($texts !== null) {
        $blob = implode(' | ', $texts);

        check('B) rapor basligi var', in_array('Temsilci İnceleme Raporu', $texts, true), $blob);
        check('B) notun basligi yaziyor', in_array('Onemli Musteri Notu', $texts, true), $blob);
        check('B) "Dönem başlangıcı" satiri var', in_array('Dönem başlangıcı', $texts, true), $blob);
        check('B) "Dönem bitişi" satiri var', in_array('Dönem bitişi', $texts, true), $blob);
        check('B) donem uzunlugu 15 gun', in_array('15 gün', $texts, true), $blob);

        // Donem baslangici = bitis - 15 gun olmali (Excel icindeki tarihler).
        $bi = array_search('Dönem başlangıcı', $texts, true);
        $ei = array_search('Dönem bitişi', $texts, true);
        $okAralik = false;
        if ($bi !== false && $ei !== false && isset($texts[$bi + 1], $texts[$ei + 1])) {
            $bas = DateTime::createFromFormat('d.m.Y H:i', $texts[$bi + 1]);
            $bit = DateTime::createFromFormat('d.m.Y H:i', $texts[$ei + 1]);
            if ($bas && $bit) {
                $farkGun = ($bit->getTimestamp() - $bas->getTimestamp()) / 86400;
                $okAralik = abs($farkGun - 15) < 0.01;
            }
        }
        check('B) baslangic ile bitis arasi TAM 15 gun', $okAralik,
            isset($texts[$bi + 1], $texts[$ei + 1]) ? ($texts[$bi + 1] . ' -> ' . $texts[$ei + 1]) : 'tarih okunamadi');

        check('B) sutun basliklari var', in_array('İnceleyen', $texts, true) && in_array('Süre', $texts, true)
            && in_array('Süre (saniye)', $texts, true), $blob);
        check('B) inceleyen kisi satirda', in_array('NX Commenter', $texts, true), $blob);
        check('B) tamamlanmis inceleme suresi "2 dk 00 sn"', in_array('2 dk 00 sn', $texts, true), $blob);
        check('B) acik inceleme "en az 45 sn" olarak isaretli', in_array('en az 45 sn', $texts, true), $blob);
        check('B) ham saniye kolonu dolu (Excelde toplanabilsin)', in_array('120', $texts, true), $blob);
        check('B) toplam inceleme 2 (15 gunden ESKI satir DISARIDA)',
            in_array('Toplam inceleme', $texts, true) && in_array('2', $texts, true), $blob);
    }

    // =====================================================================
    // C) PANEL ILE EXCEL AYNI VERIYI GOSTERIYOR MU (tek kaynak)
    // =====================================================================
    echo "\n=== C) Panel <-> Excel tutarliligi ===\n";

    $resp = http_request('GET', "/api/note_view_list.php?record_id={$recordId}", $cookie);
    $j = json_decode($resp['body'], true);
    check('C) note_view_list.php hala calisiyor (refactor sonrasi)',
        isset($j['ok']) && $j['ok'] === true, $resp['body']);
    check('C) panel de 2 satir gosteriyor (Excel ile ayni)',
        isset($j['views']) && count($j['views']) === 2,
        'bulunan: ' . (isset($j['views']) ? count($j['views']) : 'yok'));

    // =====================================================================
    // D) HATALI GIRDI
    // =====================================================================
    echo "\n=== D) Hatali girdi ===\n";
    $resp = http_request('GET', '/api/note_view_export_xlsx.php?record_id=99999999', $cookie);
    check('D) olmayan record_id -> 404', $resp['status'] === 404, 'status: ' . $resp['status']);
    $resp = http_request('GET', '/api/note_view_export_xlsx.php', $cookie);
    check('D) record_id yok -> 404', $resp['status'] === 404, 'status: ' . $resp['status']);
    $resp = http_request('GET', "/api/note_view_export_xlsx.php?record_id={$recordId}", null, null, false);
    $loginaAtti = $resp['status'] === 302
        && stripos(implode("\n", $resp['headers']), 'Location: /login.php') !== false;
    check('D) oturumsuz -> 302 ile /login.php', $loginaAtti, 'status: ' . $resp['status']);
    // Asil guvence: donen sey bir .xlsx OLMAMALI (ZIP imzasi "PK").
    check('D) oturumsuz -> govde .xlsx DEGIL',
        substr((string) $resp['body'], 0, 2) !== 'PK',
        'ilk baytlar: ' . bin2hex(substr((string) $resp['body'], 0, 4)));

    // =====================================================================
    // E) DENETIM IZI — rapor indirmenin kendisi de loglanmali
    // =====================================================================
    echo "\n=== E) Denetim izi ===\n";
    $n = (int) bcc_fetch_column(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'note_view.export_xlsx' AND entity_id = :r",
        array(':r' => $recordId)
    );
    check('E) her indirme audit_loga yaziliyor', $n >= 1, 'bulunan: ' . $n);

    // Rapor indirmek VERI SILMEMELI (firsatci temizlik yalnizca listelemede).
    $exportJs = file_get_contents(__DIR__ . '/../public/api/note_view_export_xlsx.php');
    check('E) export ucnoktasi temizlik/DELETE calistirmiyor',
        strpos($exportJs, 'sweep') === false && stripos($exportJs, 'DELETE') === false);
} catch (Throwable $e) {
    echo 'ISTISNA: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $results[] = false;
}

$cleanup();

$passed = count(array_filter($results));
$total = count($results);
echo "\n" . str_repeat('=', 60) . "\n";
echo "SONUC: {$passed}/{$total} gecti\n";
echo "Test verisi temizlendi.\n";
exit($passed === $total ? 0 : 1);
