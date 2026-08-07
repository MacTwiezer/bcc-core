<?php
// Grup C2 (Autonumber) doğrulaması. curl KULLANILMAZ — PHP'nin http:// stream
// sarmalayıcısıyla gerçek oturum çerezi alınıp gerçek uçnoktalara istek atılır.
// Kendi test verisini kurar, doğrular, sonunda temizler.
//
// Ön koşul: Apache ayakta olmalı (DocumentRoot = public, localhost:80) VE
// migrations/014_fields_autonumber_next.sql uygulanmış olmalı.
// Çalıştırma: C:\php73\php.exe scripts\_verify_group_c2.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/xlsx_writer.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'groupc2.test.owner@bcc-test.local');
define('TEST_PASS', 'GroupC2Test!2026');

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

function http_request($method, $path, $cookie = null, $postFields = null, $rawBody = null, $contentType = null)
{
    $headers = array();
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));

    if ($rawBody !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
        $options['http']['content'] = $rawBody;
    } elseif ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }

    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $parts = explode(';', substr($h, 11));
                $newCookie = trim($parts[0]);
            }
        }
    }

    return array('body' => $body, 'cookie' => $newCookie);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

function extract_field_values($html, $fieldId)
{
    $pattern = '/data-field-id="' . preg_quote((string) $fieldId, '/') . '"[^>]*data-value="([^"]*)"/';
    preg_match_all($pattern, $html, $m);
    return isset($m[1]) ? $m[1] : array();
}

// Bir alanın DB'deki numaralarını kayıt sırasına göre döndürür.
function nums($fieldId)
{
    $rows = bcc_fetch_all(
        'SELECT r.id, CAST(cv.value_number AS UNSIGNED) n
         FROM records r LEFT JOIN cell_values cv ON cv.record_id = r.id AND cv.field_id = :fid
         WHERE r.table_id = (SELECT table_id FROM fields WHERE id = :fid2)
         ORDER BY r.position, r.id',
        array(':fid' => $fieldId, ':fid2' => $fieldId)
    );
    return array_map(function ($r) { return $r['n'] === null ? 'NULL' : (int) $r['n']; }, $rows);
}

function counter($fieldId)
{
    return (int) bcc_fetch_column('SELECT autonumber_next FROM fields WHERE id = :f', array(':f' => $fieldId));
}

bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => TEST_EMAIL)
    ), 'id');
    foreach ($baseIds as $baseId) {
        bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $baseId));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));
};

try {
    $team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$team) { echo "HATA: TY ekibi bulunamadi.\n"; exit(1); }
    $teamId = (int) $team['id'];

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'GrupC2 Test Owner'));
    $userId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array(':t' => $teamId, ':n' => 'GrupC2 Test', ':u' => $userId));
    $baseId = (int) bcc_last_insert_id();

    $mkTable = function ($name) use ($baseId) {
        bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array(':b' => $baseId, ':n' => $name));
        return (int) bcc_last_insert_id();
    };
    $mkField = function ($tableId, $name, $type, $pos, $options = null) {
        bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, :o, :p)',
            array(':t' => $tableId, ':n' => $name, ':ft' => $type, ':o' => $options, ':p' => $pos));
        return (int) bcc_last_insert_id();
    };
    $mkRecord = function ($tableId, $pos) use ($userId) {
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)', array(':t' => $tableId, ':p' => $pos, ':u' => $userId));
        return (int) bcc_last_insert_id();
    };

    // --- Oturum -----------------------------------------------------------
    $resp = http_request('GET', '/login.php');
    $csrf = extract_csrf($resp['body']);
    $cookie = $resp['cookie'];
    $resp = http_request('POST', '/login.php', $cookie, array('email' => TEST_EMAIL, 'password' => TEST_PASS, 'csrf_token' => $csrf));
    if ($resp['cookie']) { $cookie = $resp['cookie']; }
    check('Giris yapildi (owner)', $cookie !== null);

    // =======================================================================
    // A) BOS TABLO — alan olustur, sonra kayit ekle -> 1, 2, 3
    // =======================================================================
    $tA = $mkTable('A Bos');
    $adA = $mkField($tA, 'Ad', 'single_line_text', 0);

    $resp = http_request('GET', "/table_fields.php?table_id={$tA}", $cookie);
    $csrfA = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfA, 'action' => 'create_field', 'table_id' => $tA,
        'name' => 'No', 'field_type' => 'autonumber',
    ));
    $noA = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tA));
    check('A) Bos tabloda autonumber alani olusturuldu', $noA > 0);
    check('A) Bos tabloda sayac 1de basliyor', counter($noA) === 1, 'sayac: ' . counter($noA));

    // record_add.php ile UC kayit
    for ($i = 0; $i < 3; $i++) {
        http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfA, 'table_id' => $tA));
    }
    check('A) record_add.php -> numaralar 1,2,3', nums($noA) === array(1, 2, 3), 'bulunan: ' . implode(',', nums($noA)));
    check('A) sayac 4te', counter($noA) === 4, 'sayac: ' . counter($noA));

    // grid.php create_record (JS'siz form yolu — DORDUNCU nokta)
    $resp = http_request('GET', "/grid.php?table_id={$tA}", $cookie);
    $csrfGrid = extract_csrf($resp['body']);
    http_request('POST', "/grid.php?table_id={$tA}", $cookie, array('csrf_token' => $csrfGrid, 'action' => 'create_record'));
    check('A) grid.php create_record -> 4 aldi', nums($noA) === array(1, 2, 3, 4), 'bulunan: ' . implode(',', nums($noA)));

    // record_duplicate.php -> YENI numara
    $firstRec = (int) bcc_fetch_column('SELECT id FROM records WHERE table_id = :t ORDER BY position, id LIMIT 1', array(':t' => $tA));
    $resp = http_request('POST', '/api/record_duplicate.php', $cookie, array('csrf_token' => $csrfA, 'record_id' => $firstRec));
    $dupNums = nums($noA);
    check('A) Cogaltma -> kopya YENI numara (5) aldi, orijinali (1) tasimadi',
        $dupNums === array(1, 5, 2, 3, 4), 'bulunan: ' . implode(',', $dupNums));
    check('A) Cogaltma sonrasi sayac 6', counter($noA) === 6, 'sayac: ' . counter($noA));

    // Soft-delete -> sayac GERI SARMAZ
    $lastRec = (int) bcc_fetch_column('SELECT id FROM records WHERE table_id = :t ORDER BY position DESC, id DESC LIMIT 1', array(':t' => $tA));
    http_request('POST', '/api/record_soft_delete.php', $cookie, array('csrf_token' => $csrfA, 'record_id' => $lastRec));
    check('A) Soft-delete sonrasi sayac GERI SARMADI (6)', counter($noA) === 6, 'sayac: ' . counter($noA));
    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfA, 'table_id' => $tA));
    $afterDel = (int) bcc_fetch_column(
        'SELECT CAST(cv.value_number AS UNSIGNED) FROM cell_values cv INNER JOIN records r ON r.id = cv.record_id
         WHERE cv.field_id = :f ORDER BY r.id DESC LIMIT 1', array(':f' => $noA));
    check('A) Silmeden SONRAKI kayit 6 aldi (numara yeniden kullanilmadi)', $afterDel === 6, 'bulunan: ' . $afterDel);

    // Backend bypass -> 422
    $anyRec = (int) bcc_fetch_column('SELECT id FROM records WHERE table_id = :t LIMIT 1', array(':t' => $tA));
    $resp = http_request('POST', '/api/cell_update.php', $cookie, array(
        'csrf_token' => $csrfA, 'record_id' => $anyRec, 'field_id' => $noA, 'value' => '999'));
    $j = json_decode($resp['body'], true);
    check('A) Backend bypass REDDEDILDI (otomatik doldurulur)',
        is_array($j) && empty($j['ok']) && isset($j['error']) && strpos($j['error'], 'otomatik doldurulur') !== false,
        'donen: ' . $resp['body']);

    // Grid'de salt-okunur + dogru gorunum
    $resp = http_request('GET', "/grid.php?table_id={$tA}", $cookie);
    $gridA = $resp['body'];
    preg_match('/<td\s[^>]*data-field-id="' . $noA . '"[\s\S]*?<\/td>/', $gridA, $tdM);
    check('A) Grid hucresi salt-okunur (editable class YOK)',
        isset($tdM[0]) && strpos($tdM[0], 'grid-cell editable') === false, isset($tdM[0]) ? substr($tdM[0], 0, 160) : 'YOK');
    check('A) Grid hucresi biciimlendirilmemis tam sayi gosteriyor',
        isset($tdM[0]) && preg_match('/>\s*1\s*</', $tdM[0]) === 1, isset($tdM[0]) ? trim(strip_tags($tdM[0])) : 'YOK');

    // =======================================================================
    // B) DOLU TABLO — backfill (position, id sirasi + SILINMIS kayitlar dahil)
    // =======================================================================
    $tB = $mkTable('B Dolu');
    $adB = $mkField($tB, 'Ad', 'single_line_text', 0);
    // position sirasi id sirasindan FARKLI olsun ki hangi sirayla numaralandigi kanitlansin
    $rB1 = $mkRecord($tB, 2); // id kucuk, position buyuk
    $rB2 = $mkRecord($tB, 0);
    $rB3 = $mkRecord($tB, 1);
    // Bir kaydi cope at — backfill onu da numaralamali
    bcc_execute('UPDATE records SET deleted_at = NOW() WHERE id = :r', array(':r' => $rB3));

    $resp = http_request('GET', "/table_fields.php?table_id={$tB}", $cookie);
    $csrfB = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfB, 'action' => 'create_field', 'table_id' => $tB,
        'name' => 'No', 'field_type' => 'autonumber'));
    $noB = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tB));

    $b1 = (int) bcc_fetch_column('SELECT CAST(value_number AS UNSIGNED) FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $rB1, ':f' => $noB));
    $b2 = (int) bcc_fetch_column('SELECT CAST(value_number AS UNSIGNED) FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $rB2, ':f' => $noB));
    $b3 = (int) bcc_fetch_column('SELECT CAST(value_number AS UNSIGNED) FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $rB3, ':f' => $noB));
    check('B) Backfill POSITION sirasiyla numaraladi (pos0=1, pos1=2, pos2=3)',
        $b2 === 1 && $b3 === 2 && $b1 === 3, "pos0={$b2} pos1={$b3} pos2={$b1}");
    check('B) SILINMIS kayit da numaralandi (geri yuklenince bos kalmasin)', $b3 === 2, 'bulunan: ' . $b3);
    check('B) Backfill sonrasi sayac 4ten devam', counter($noB) === 4, 'sayac: ' . counter($noB));

    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfB, 'table_id' => $tB));
    $newB = (int) bcc_fetch_column(
        'SELECT CAST(cv.value_number AS UNSIGNED) FROM cell_values cv INNER JOIN records r ON r.id = cv.record_id
         WHERE cv.field_id = :f ORDER BY r.id DESC LIMIT 1', array(':f' => $noB));
    check('B) Backfillden sonraki yeni kayit 4 aldi', $newB === 4, 'bulunan: ' . $newB);

    // =======================================================================
    // C) TIP DEGISTIRME — mevcut alani autonumber'a cevir, sonra ileri/geri
    // =======================================================================
    $tC = $mkTable('C Tip');
    $adC = $mkField($tC, 'Ad', 'single_line_text', 0);
    $numC = $mkField($tC, 'Sayi', 'number', 1);
    $rC1 = $mkRecord($tC, 0);
    $rC2 = $mkRecord($tC, 1);

    $resp = http_request('GET', "/table_fields.php?table_id={$tC}&edit={$numC}", $cookie);
    $csrfC = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfC, 'action' => 'update_field', 'table_id' => $tC,
        'field_id' => $numC, 'name' => 'Sayi', 'field_type' => 'autonumber'));
    check('C) Tip autonumbera cevrildi -> backfill calisti (1,2)', nums($numC) === array(1, 2), 'bulunan: ' . implode(',', nums($numC)));
    check('C) Sayac 3te', counter($numC) === 3, 'sayac: ' . counter($numC));

    // autonumber -> number -> autonumber: numaralar KORUNMALI, sayac SIFIRLANMAMALI
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfC, 'action' => 'update_field', 'table_id' => $tC,
        'field_id' => $numC, 'name' => 'Sayi', 'field_type' => 'number'));
    check('C) autonumber -> number: sayac SIFIRLANMADI (3)', counter($numC) === 3, 'sayac: ' . counter($numC));
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfC, 'action' => 'update_field', 'table_id' => $tC,
        'field_id' => $numC, 'name' => 'Sayi', 'field_type' => 'autonumber'));
    check('C) number -> autonumber geri: ESKI numaralar KORUNDU (1,2)', nums($numC) === array(1, 2), 'bulunan: ' . implode(',', nums($numC)));
    check('C) Geri cevrimde sayac hala 3 (yeniden numaralanmadi)', counter($numC) === 3, 'sayac: ' . counter($numC));

    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfC, 'table_id' => $tC));
    check('C) Cevrim sonrasi yeni kayit 3 aldi (cakisma yok)', nums($numC) === array(1, 2, 3), 'bulunan: ' . implode(',', nums($numC)));

    // =======================================================================
    // D) IKI AUTONUMBER ALANI — bagimsiz sayaclar
    // =======================================================================
    $tD = $mkTable('D Ikili');
    $adD = $mkField($tD, 'Ad', 'single_line_text', 0);
    $resp = http_request('GET', "/table_fields.php?table_id={$tD}", $cookie);
    $csrfD = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array('csrf_token' => $csrfD, 'action' => 'create_field', 'table_id' => $tD, 'name' => 'NoBir', 'field_type' => 'autonumber'));
    // Iki kayit ekle (yalnizca NoBir varken)
    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfD, 'table_id' => $tD));
    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfD, 'table_id' => $tD));
    // SONRA ikinci autonumber alani ekle -> backfill onu 1,2 yapar
    http_request('POST', '/table_fields.php', $cookie, array('csrf_token' => $csrfD, 'action' => 'create_field', 'table_id' => $tD, 'name' => 'NoIki', 'field_type' => 'autonumber'));
    $d1 = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'NoBir'", array(':t' => $tD));
    $d2 = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'NoIki'", array(':t' => $tD));
    check('D) Iki autonumber alani ayni tabloda birlikte var', $d1 > 0 && $d2 > 0);
    check('D) NoBir 1,2 / NoIki 1,2 (bagimsiz backfill)', nums($d1) === array(1, 2) && nums($d2) === array(1, 2),
        'NoBir=' . implode(',', nums($d1)) . ' NoIki=' . implode(',', nums($d2)));
    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfD, 'table_id' => $tD));
    check('D) Yeni kayit HER IKI alandan da 3 aldi (iki sayac bagimsiz ilerledi)',
        nums($d1) === array(1, 2, 3) && nums($d2) === array(1, 2, 3),
        'NoBir=' . implode(',', nums($d1)) . ' NoIki=' . implode(',', nums($d2)));
    check('D) Iki sayac da 4te', counter($d1) === 4 && counter($d2) === 4, counter($d1) . ' / ' . counter($d2));

    // =======================================================================
    // E) HIZLI ARDISIK EKLEME — cakisan numara olmamali
    // =======================================================================
    $tE = $mkTable('E Yaris');
    $adE = $mkField($tE, 'Ad', 'single_line_text', 0);
    $resp = http_request('GET', "/table_fields.php?table_id={$tE}", $cookie);
    $csrfE = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array('csrf_token' => $csrfE, 'action' => 'create_field', 'table_id' => $tE, 'name' => 'No', 'field_type' => 'autonumber'));
    $noE = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tE));

    // 15 istegi PARALEL soketlerle ayni anda baslat (ardisik degil — gercek yaris)
    $sockets = array();
    $body = http_build_query(array('csrf_token' => $csrfE, 'table_id' => $tE));
    for ($i = 0; $i < 15; $i++) {
        $s = @stream_socket_client('tcp://127.0.0.1:80', $errno, $errstr, 5);
        if ($s === false) { continue; }
        $req = "POST /api/record_add.php HTTP/1.1\r\nHost: localhost\r\nCookie: {$cookie}\r\n"
             . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
        fwrite($s, $req);
        $sockets[] = $s;
    }
    foreach ($sockets as $s) { while (!feof($s)) { fread($s, 8192); } fclose($s); }

    $eNums = bcc_fetch_all('SELECT CAST(value_number AS UNSIGNED) n FROM cell_values WHERE field_id = :f', array(':f' => $noE));
    $eList = array_map(function ($r) { return (int) $r['n']; }, $eNums);
    sort($eList);
    $expected = range(1, count($eList));
    check('E) ' . count($eList) . ' paralel istek -> numara sayisi kayit sayisina esit',
        count($eList) === (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t', array(':t' => $tE)),
        'numara=' . count($eList));
    check('E) CAKISMA YOK — tum numaralar TEKIL', count($eList) === count(array_unique($eList)), 'liste: ' . implode(',', $eList));
    check('E) BOSLUK YOK — numaralar 1..N kesintisiz', $eList === $expected, 'liste: ' . implode(',', $eList));

    // =======================================================================
    // F) BIRINCIL ALAN AUTONUMBER -> " copy" eki YOK, numara TASINMAZ
    // =======================================================================
    $tF = $mkTable('F Birincil');
    $resp = http_request('GET', "/table_fields.php?table_id={$tF}", $cookie);
    $csrfF = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array('csrf_token' => $csrfF, 'action' => 'create_field', 'table_id' => $tF, 'name' => 'No', 'field_type' => 'autonumber'));
    $noF = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tF));
    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfF, 'table_id' => $tF));
    $recF = (int) bcc_fetch_column('SELECT id FROM records WHERE table_id = :t LIMIT 1', array(':t' => $tF));
    http_request('POST', '/api/record_duplicate.php', $cookie, array('csrf_token' => $csrfF, 'record_id' => $recF));
    check('F) Birincil alan autonumber: kopya YENI numara (1,2), tasima YOK', nums($noF) === array(1, 2), 'bulunan: ' . implode(',', nums($noF)));
    $textLeak = bcc_fetch_one('SELECT value_text FROM cell_values WHERE field_id = :f AND value_text IS NOT NULL LIMIT 1', array(':f' => $noF));
    check('F) " copy" eki EKLENMEDI (value_text bos kaldi)', $textLeak === false, 'sizinti: ' . ($textLeak ? $textLeak['value_text'] : ''));

    // =======================================================================
    // G) XLSX IMPORT — dorduncu olusturma noktasi
    // =======================================================================
    $tG = $mkTable('G Import');
    $adG = $mkField($tG, 'Ad', 'single_line_text', 0);
    $resp = http_request('GET', "/table_fields.php?table_id={$tG}", $cookie);
    $csrfG = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array('csrf_token' => $csrfG, 'action' => 'create_field', 'table_id' => $tG, 'name' => 'No', 'field_type' => 'autonumber'));
    $noG = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tG));

    // multipart/form-data gövdesini elle kuran tek yardımcı — J bölümü de AYNI
    // yolu kullanıyor, ikinci bir kopya YAZILMADI.
    $importXlsx = function ($tableId, $csrf, $header, $rows) use ($cookie) {
        $xlsxPath = bcc_xlsx_build_temp_file('Sayfa1', $header, $rows);
        $fileBytes = file_get_contents($xlsxPath);
        @unlink($xlsxPath);
        $boundary = '----bccC2' . bin2hex(random_bytes(8));
        $mp = '';
        foreach (array('csrf_token' => $csrf, 'table_id' => (string) $tableId) as $k => $v) {
            $mp .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
        }
        $mp .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"xlsx_file\"; filename=\"c2.xlsx\"\r\n"
             . "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n" . $fileBytes . "\r\n";
        $mp .= "--{$boundary}--\r\n";
        return http_request('POST', '/api/table_import_xlsx.php', $cookie, null, $mp, 'multipart/form-data; boundary=' . $boundary);
    };

    $resp = $importXlsx($tG, $csrfG, array('Ad'), array(array('Alfa'), array('Beta'), array('Gama')));
    $jg = json_decode($resp['body'], true);
    check('G) xlsx import basarili', is_array($jg) && !empty($jg['ok']), 'donen: ' . substr((string) $resp['body'], 0, 200));
    check('G) Import edilen 3 satir da numara aldi (1,2,3)', nums($noG) === array(1, 2, 3), 'bulunan: ' . implode(',', nums($noG)));
    check('G) Import sonrasi sayac 4', counter($noG) === 4, 'sayac: ' . counter($noG));

    // =======================================================================
    // H) REGRESYON — diger tipler etkilenmedi mi
    // =======================================================================
    $tH = $mkTable('H Regresyon');
    $adH = $mkField($tH, 'Ad', 'single_line_text', 0);
    $numH = $mkField($tH, 'Miktar', 'number', 1);
    $curH = $mkField($tH, 'Fiyat', 'currency', 2, json_encode(array('currency_symbol' => '₺', 'decimal_places' => 2)));
    $pctH = $mkField($tH, 'Oran', 'percent', 3, json_encode(array('decimal_places' => 0)));
    $ratH = $mkField($tH, 'Puan', 'rating', 4, json_encode(array('max_rating' => 5)));
    $ctH  = $mkField($tH, 'Olusturulma', 'created_time', 5);
    $cbH  = $mkField($tH, 'Olusturan', 'created_by', 6);
    $mtH  = $mkField($tH, 'SonDegisiklik', 'last_modified_time', 7);
    $mbH  = $mkField($tH, 'SonDegistiren', 'last_modified_by', 8);
    $rH = $mkRecord($tH, 0);
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)', array(':r' => $rH, ':f' => $adH, ':v' => 'Elma'));
    foreach (array($numH => 10, $curH => 1234.5, $pctH => 0.45, $ratH => 3) as $fid => $val) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r,:f,:v)', array(':r' => $rH, ':f' => $fid, ':v' => $val));
    }
    // autonumber alani GERCEK uc nokta uzerinden olusturulur ($mkField ile
    // dogrudan SQL DEGIL) — aksi halde bcc_create_field()'in backfill'i hic
    // calismaz, sayac 1'de kalir ve sonraki kayit CAKISAN 1 alirdi. Bu, urun
    // bugu degil fikstur hatasiydi: gercek kod yollarinin (bcc_create_field /
    // update_field / bcc_assign_autonumbers) hepsi sayaci dogru ilerletiyor.
    $resp = http_request('GET', "/table_fields.php?table_id={$tH}", $cookie);
    $csrfH = extract_csrf($resp['body']);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfH, 'action' => 'create_field', 'table_id' => $tH,
        'name' => 'No', 'field_type' => 'autonumber'));
    $noH = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tH));
    check('H) Mevcut kayitli tabloya autonumber eklendi -> backfill 1 verdi', nums($noH) === array(1), 'bulunan: ' . implode(',', nums($noH)));

    $resp = http_request('GET', "/grid.php?table_id={$tH}", $cookie);
    $gh = $resp['body'];
    $cellTxt = function ($html, $fid) {
        preg_match('/<td\s[^>]*data-field-id="' . $fid . '"[\s\S]*?<\/td>/', $html, $m);
        return isset($m[0]) ? trim(strip_tags($m[0])) : '';
    };
    check('H) REGRESYON number: "10" (bicimsiz)', $cellTxt($gh, $numH) === '10', 'bulunan: ' . $cellTxt($gh, $numH));
    check('H) REGRESYON currency: "₺1.234,50"', $cellTxt($gh, $curH) === '₺1.234,50', 'bulunan: ' . $cellTxt($gh, $curH));
    check('H) REGRESYON percent: "%45"', $cellTxt($gh, $pctH) === '%45', 'bulunan: ' . $cellTxt($gh, $pctH));
    check('H) REGRESYON rating: 5 yildiz span', substr_count($gh, 'data-rating-star=') === 5, 'bulunan: ' . substr_count($gh, 'data-rating-star='));
    check('H) REGRESYON created_time: tarih gosteriyor', preg_match('/\d{2}\.\d{2}\.\d{4}/', $cellTxt($gh, $ctH)) === 1, 'bulunan: ' . $cellTxt($gh, $ctH));
    check('H) REGRESYON created_by: kullanici adi', $cellTxt($gh, $cbH) === 'GrupC2 Test Owner', 'bulunan: ' . $cellTxt($gh, $cbH));
    check('H) REGRESYON last_modified_time: tarih gosteriyor', preg_match('/\d{2}\.\d{2}\.\d{4}/', $cellTxt($gh, $mtH)) === 1, 'bulunan: ' . $cellTxt($gh, $mtH));
    check('H) REGRESYON last_modified_by: kullanici adi', $cellTxt($gh, $mbH) === 'GrupC2 Test Owner', 'bulunan: ' . $cellTxt($gh, $mbH));
    check('H) autonumber "1" gosteriyor', $cellTxt($gh, $noH) === '1', 'bulunan: ' . $cellTxt($gh, $noH));

    // Filtre + siralama (ikinci kayit -> 2 almali)
    http_request('POST', '/api/record_add.php', $cookie, array('csrf_token' => $csrfH, 'table_id' => $tH));
    check('H) Ikinci kayit 2 aldi (backfill sonrasi sayac dogru)', nums($noH) === array(1, 2), 'bulunan: ' . implode(',', nums($noH)));
    $resp = http_request('GET', "/grid.php?table_id={$tH}&filter_field_1={$noH}&filter_cond_1=gt&filter_value_1=1", $cookie);
    check('H) FILTRE autonumber ">1" -> 1 kayit', count(extract_field_values($resp['body'], $noH)) === 1,
        'bulunan: ' . implode(',', extract_field_values($resp['body'], $noH)));
    $resp = http_request('GET', "/grid.php?table_id={$tH}&sort_field_1={$noH}&sort_dir_1=desc", $cookie);
    check('H) SIRALAMA autonumber azalan', extract_field_values($resp['body'], $noH) === array('2', '1'),
        'bulunan: ' . implode(',', extract_field_values($resp['body'], $noH)));

    // =======================================================================
    // J) EXPORT -> IMPORT TURU + is_required — bulunan iki GERCEK BUG
    //
    // view_export_xlsx.php GORUNUR ALANLARIN HEPSINI yazar, yani autonumber
    // sutunu da dosyaya girer. O dosya geri ice aktarilinca:
    //   (1) autonumber sutunu $fieldByName'de eslesiyordu -> normalize_cell_value()
    //       her satirda reddediyor -> "N hucre atlandi" uyarisi sisiyordu,
    //   (2) alan is_required=1 ise $filledFieldIds'e HIC giremedigi icin
    //       $missingRequired her satirda true -> ice aktarim SIFIR kayit.
    // Duzeltme: attachment ile AYNI mekanizma ($importIgnoredFieldTypes).
    // =======================================================================
    $tJ = $mkTable('J Import Turu');
    $adJ = $mkField($tJ, 'Ad', 'single_line_text', 0);
    $resp = http_request('GET', "/table_fields.php?table_id={$tJ}", $cookie);
    $csrfJ = extract_csrf($resp['body']);

    // is_required=1 BILEREK gonderiliyor — sunucu (bcc_normalize_is_required)
    // autonumber icin bunu 0'a zorlamali.
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfJ, 'action' => 'create_field', 'table_id' => $tJ,
        'name' => 'No', 'field_type' => 'autonumber', 'is_required' => '1',
    ));
    $noJ = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tJ));
    check('J) autonumber olustururken is_required=1 gonderildi -> 0a zorlandi',
        (int) bcc_fetch_column('SELECT is_required FROM fields WHERE id = :f', array(':f' => $noJ)) === 0,
        'bulunan: ' . bcc_fetch_column('SELECT is_required FROM fields WHERE id = :f', array(':f' => $noJ)));

    // Guncelleme yolu da AYNI kurali uygulamali.
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfJ, 'action' => 'update_field', 'table_id' => $tJ, 'field_id' => $noJ,
        'name' => 'No', 'field_type' => 'autonumber', 'is_required' => '1',
    ));
    check('J) update_field is_required=1 -> yine 0',
        (int) bcc_fetch_column('SELECT is_required FROM fields WHERE id = :f', array(':f' => $noJ)) === 0,
        'bulunan: ' . bcc_fetch_column('SELECT is_required FROM fields WHERE id = :f', array(':f' => $noJ)));

    // Export'un uretecegi dosyayla AYNI sekil: autonumber sutunu DA var, icinde
    // eski numaralar. Bu numaralar KULLANILMAMALI, satirlar taze numara almali.
    $resp = $importXlsx($tJ, $csrfJ, array('Ad', 'No'), array(
        array('Alfa', '99'), array('Beta', '98'), array('Gama', '97'),
    ));
    $jj = json_decode($resp['body'], true);
    check('J) Tur importu basarili', is_array($jj) && !empty($jj['ok']), 'donen: ' . substr((string) $resp['body'], 0, 200));
    check('J) 3 satirin UCU DE aktarildi (hicbiri elenmedi)',
        is_array($jj) && (int) $jj['imported'] === 3, 'imported: ' . (is_array($jj) ? $jj['imported'] : '?'));
    check('J) Dosyadaki ESKI numaralar (99,98,97) YOK SAYILDI -> taze 1,2,3',
        nums($noJ) === array(1, 2, 3), 'bulunan: ' . implode(',', nums($noJ)));
    check('J) "N hucre atlandi" uyarisi SISMEDI (skipped_cells = 0)',
        is_array($jj) && (int) $jj['skipped_cells'] === 0, 'skipped_cells: ' . (is_array($jj) ? $jj['skipped_cells'] : '?'));
    check('J) autonumber sutunu eslesmeyenler listesinde (attachment ile AYNI davranis)',
        is_array($jj) && in_array('No', (array) $jj['unmatched_columns'], true),
        'unmatched: ' . (is_array($jj) ? implode(',', (array) $jj['unmatched_columns']) : '?'));

    // ESKI VERI senaryosu: duzeltmeden ONCE olusturulmus, is_required=1 kalmis
    // bir autonumber alani. Import bu satirlarin HEPSINI elemeye devam ETMEMELI.
    bcc_execute('UPDATE fields SET is_required = 1 WHERE id = :f', array(':f' => $noJ));
    $resp = $importXlsx($tJ, $csrfJ, array('Ad', 'No'), array(array('Delta', '50')));
    $jj2 = json_decode($resp['body'], true);
    check('J) ESKI VERI: is_required=1 kalmis autonumber TUM satirlari elemiyor',
        is_array($jj2) && (int) $jj2['imported'] === 1 && (int) $jj2['skipped_rows'] === 0,
        'imported: ' . (is_array($jj2) ? $jj2['imported'] : '?') . ' skipped_rows: ' . (is_array($jj2) ? $jj2['skipped_rows'] : '?'));
    check('J) ESKI VERI: yeni satir 4 aldi', nums($noJ) === array(1, 2, 3, 4), 'bulunan: ' . implode(',', nums($noJ)));

    // =======================================================================
    // I) STATIK KONTROLLER
    // =======================================================================
    $wizJs = file_get_contents(__DIR__ . '/../public/assets/field-type-wizard.js');
    check('I) Sihirbaz JS: autonumberda "Zorunlu alan" gizleniyor',
        strpos($wizJs, "requiredRow.hidden = (type === 'autonumber')") !== false);
    $wizPhp = file_get_contents(__DIR__ . '/../src/partials/field_type_wizard_fields.php');
    check('I) Sihirbaz HTML: #new-field-required-row id tanimli', strpos($wizPhp, 'id="new-field-required-row"') !== false);
    $detailJs = file_get_contents(__DIR__ . '/../public/assets/grid-row-detail.js');
    check('I) Detay paneli: autonumber salt-okunur listesinde',
        strpos($detailJs, "field.field_type === 'autonumber'") !== false);
    // .field-type-badge'in background-image'i var(--field-icon) ve YEDEGI YOK —
    // tip icin --field-icon tanimli degilse rozet BOS bir kutu olarak cizilir.
    // Grup C1'in uc tipi (currency/percent/rating) bu yuzden ikonsuz kalmisti;
    // autonumber ayni hataya dusmesin diye dordu de kontrol ediliyor.
    $themeCss = file_get_contents(__DIR__ . '/../public/assets/theme.css');
    foreach (array('currency', 'percent', 'rating', 'autonumber') as $badgeType) {
        check('I) theme.css: .field-type-badge--' . $badgeType . ' ikonu tanimli',
            preg_match('/\.field-type-badge--' . $badgeType . '\b[^{]*\{[^}]*--field-icon:/', $themeCss) === 1);
    }

    $schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
    check('I) schema.sql: autonumber_next kolonu yansitildi', strpos($schemaSql, 'autonumber_next INT UNSIGNED NOT NULL DEFAULT 1') !== false);
    $colType = bcc_fetch_one("SELECT COLUMN_TYPE t, IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fields' AND COLUMN_NAME = 'autonumber_next'");
    check('I) DB: autonumber_next int unsigned NOT NULL DEFAULT 1',
        $colType && $colType['t'] === 'int(10) unsigned' && $colType['n'] === 'NO' && (int) $colType['d'] === 1,
        $colType ? json_encode($colType) : 'KOLON YOK');

    echo "\n";
} catch (Exception $e) {
    echo "\nISTISNA: " . $e->getMessage() . "\n";
    $results[] = false;
}

$cleanup();
echo "Temizlik tamam (test kullanicisi/base'i silindi).\n";

$passed = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($passed === $total ? 'GECTI' : 'KALDI') . " ({$passed}/{$total})\n";
echo "==================================\n";
exit($passed === $total ? 0 : 1);
