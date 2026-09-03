<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();
bcc_test_purge_own_audit();

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'xpaste.owner@bcc-test.local');
define('TEST_PASS', 'XPaste!2026');

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

function eq($label, $actual, $expected)
{
    check($label, $actual === $expected,
        'beklenen ' . var_export($expected, true) . ', gelen ' . var_export($actual, true));
}

function http_request($method, $path, $cookie = null, $postFields = null)
{
    $headers = array();
    if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }
    $options = array('http' => array('method' => $method, 'ignore_errors' => true));
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }
    $options['http']['header'] = implode("\r\n", $headers);
    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));
    $status = 0; $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
            if (stripos($h, 'Set-Cookie:') === 0) { $p = explode(';', substr($h, 11)); $newCookie = trim($p[0]); }
        }
    }
    return array('body' => (string) $body, 'cookie' => $newCookie, 'status' => $status);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function raw_of($html, $fieldId)
{
    if (preg_match('#<td\b[^>]*data-field-id="' . (int) $fieldId . '"[^>]*data-value="([^"]*)"#', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return null;
}

function display_of($html, $fieldId)
{
    if (preg_match('#<td\b[^>]*data-field-id="' . (int) $fieldId . '"[^>]*>(.*?)</td>#s', $html, $m)) {
        $inner = $m[1];
        if (preg_match('#<div class="cell-view[^"]*">(.*?)</div>#s', $inner, $v)) {
            return trim(html_entity_decode(strip_tags($v[1]), ENT_QUOTES, 'UTF-8'));
        }
        if (strpos($inner, 'type="checkbox"') !== false) {
            return strpos($inner, 'checked') !== false ? 'Evet' : '';
        }
    }
    return null;
}

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => TEST_EMAIL)
    ), 'id');
    foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));
};
$cleanup();
register_shutdown_function($cleanup);

try {
    $team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$team) { echo "HATA: TY ekibi yok.\n"; exit(1); }
    $teamId = (int) $team['id'];

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'XPaste Owner'));
    $userId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'XPaste Test', ':u' => $userId));
    $baseId = (int) bcc_last_insert_id();

    $selOpts = json_encode(array('choices' => array('Acik', 'Kapali', 'Beklemede')), JSON_UNESCAPED_UNICODE);
    $fieldDefs = array(
        array('Ad',      'single_line_text', null),
        array('Adet',    'number',           null),
        array('Tarih',   'date',             null),
        array('Onay',    'checkbox',         null),
        array('Oran',    'percent',          null),
        array('Durum',   'single_select',    $selOpts),
        array('Etiket',  'multiple_select',  $selOpts),
    );

    $tables = array();
    foreach (array('Kaynak', 'Hedef') as $ti => $tname) {
        bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, :p)',
            array(':b' => $baseId, ':n' => 'XPaste ' . $tname, ':p' => $ti));
        $tid = (int) bcc_last_insert_id();
        $fids = array();
        foreach ($fieldDefs as $pos => $def) {
            bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, :o, :p)',
                array(':t' => $tid, ':n' => $def[0], ':ft' => $def[1], ':o' => $def[2], ':p' => $pos));
            $fids[$def[0]] = (int) bcc_last_insert_id();
        }
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)',
            array(':t' => $tid, ':u' => $userId));
        $tables[$tname] = array('id' => $tid, 'fields' => $fids, 'record' => (int) bcc_last_insert_id());
    }

    $cookie = login(TEST_EMAIL);
    $src = $tables['Kaynak'];
    $dst = $tables['Hedef'];

    $gridSrc = http_request('GET', '/grid.php?table_id=' . $src['id'], $cookie);
    check('0) kaynak grid acildi', $gridSrc['status'] === 200, 'HTTP ' . $gridSrc['status']);
    $csrf = extract_csrf($gridSrc['body']);
    check('0) CSRF token bulundu', $csrf !== null);

    $seed = array(
        'Ad'     => 'Acme A.S.',
        'Adet'   => '42',
        'Tarih'  => '2000-03-12',
        'Onay'   => '1',
        'Oran'   => '45',
        'Durum'  => 'Beklemede',
        'Etiket' => '["Acik","Kapali"]',
    );

    function bulk_write($tableId, $recordId, $fieldIds, $values, $csrf, $cookie)
    {
        $updates = array();
        foreach ($values as $name => $v) {
            $updates[] = array('r' => $recordId, 'f' => $fieldIds[$name], 'v' => $v);
        }
        return http_request('POST', '/api/cells_bulk_update.php', $cookie, array(
            'csrf_token' => $csrf,
            'table_id' => $tableId,
            'payload' => json_encode(array('updates' => $updates, 'creates' => array()), JSON_UNESCAPED_UNICODE),
        ));
    }

    $w = bulk_write($src['id'], $src['record'], $src['fields'], $seed, $csrf, $cookie);
    $wd = json_decode($w['body'], true);
    check('0) kaynak tabloya yazildi', !empty($wd['ok']), $w['body']);
    eq('0) kaynakta atlanan hucre yok', isset($wd['skipped_cells']) ? (int) $wd['skipped_cells'] : -1, 0);

    $gridSrc = http_request('GET', '/grid.php?table_id=' . $src['id'], $cookie);
    $srcHtml = $gridSrc['body'];

    echo "\n--- A) Ham deger KANONIK mi (data-bcc-raw kanali) ---\n";

    eq('A) metin', raw_of($srcHtml, $src['fields']['Ad']), 'Acme A.S.');
    eq('A) sayi', raw_of($srcHtml, $src['fields']['Adet']), '42');

    eq('A) tarih Y-m-d (saat kismi YOK)', raw_of($srcHtml, $src['fields']['Tarih']), '2000-03-12');
    eq('A) checkbox', raw_of($srcHtml, $src['fields']['Onay']), '1');

    eq('A) yuzde kullanici bicimi (0.45 DEGIL 45)', raw_of($srcHtml, $src['fields']['Oran']), '45');
    eq('A) tek secim', raw_of($srcHtml, $src['fields']['Durum']), 'Beklemede');
    eq('A) cok secim JSON', raw_of($srcHtml, $src['fields']['Etiket']), '["Acik","Kapali"]');

    echo "\n--- B) Gorunen metin ham degerden FARKLI (Excel kanali) ---\n";

    $dTarih = display_of($srcHtml, $src['fields']['Tarih']);
    check('B) tarih GORUNENI hamdan farkli (gg.aa.yyyy)',
        $dTarih !== null && $dTarih !== '2000-03-12', 'gorunen: ' . var_export($dTarih, true));
    $dOran = display_of($srcHtml, $src['fields']['Oran']);
    check('B) yuzde GORUNENI hamdan farkli (% isaretli)',
        $dOran !== null && $dOran !== '45', 'gorunen: ' . var_export($dOran, true));

    echo "\n--- C) Tablo A -> Tablo B: HAM yapistirma ---\n";

    $copied = array();
    foreach ($fieldDefs as $def) {
        $copied[$def[0]] = raw_of($srcHtml, $src['fields'][$def[0]]);
    }

    $w2 = bulk_write($dst['id'], $dst['record'], $dst['fields'], $copied, $csrf, $cookie);
    $w2d = json_decode($w2['body'], true);
    check('C) hedef tabloya yazildi', !empty($w2d['ok']), $w2['body']);
    eq('C) HIC hucre atlanmadi (ham deger sunucuca kabul edildi)',
        isset($w2d['skipped_cells']) ? (int) $w2d['skipped_cells'] : -1, 0);

    $dstHtml = http_request('GET', '/grid.php?table_id=' . $dst['id'], $cookie)['body'];
    foreach ($fieldDefs as $def) {
        eq('C) ' . $def[0] . ' (' . $def[1] . ') bozulmadan gitti',
            raw_of($dstHtml, $dst['fields'][$def[0]]), $copied[$def[0]]);
    }

    echo "\n--- D) Yabanci (Excel) bicimleri kabul ediliyor mu ---\n";

    $foreign = array(
        'Ad'    => 'Excel A.S.',
        'Adet'  => '1234.56',
        'Tarih' => '2000-03-12',
        'Onay'  => '1',
        'Oran'  => '45',
    );
    $w3 = bulk_write($dst['id'], $dst['record'], $dst['fields'], $foreign, $csrf, $cookie);
    $w3d = json_decode($w3['body'], true);
    eq('D) donusturulmus Excel degerleri atlanmadan yazildi',
        isset($w3d['skipped_cells']) ? (int) $w3d['skipped_cells'] : -1, 0);
    $dstHtml2 = http_request('GET', '/grid.php?table_id=' . $dst['id'], $cookie)['body'];
    eq('D) Turkce ondalik sayi dogru saklandi', raw_of($dstHtml2, $dst['fields']['Adet']), '1234.56');
    eq('D) Turkce tarih dogru saklandi', raw_of($dstHtml2, $dst['fields']['Tarih']), '2000-03-12');
    eq('D) "Evet" checkbox dogru saklandi', raw_of($dstHtml2, $dst['fields']['Onay']), '1');
    eq('D) "%45" yuzde dogru saklandi', raw_of($dstHtml2, $dst['fields']['Oran']), '45');

    echo "\n--- E) Tip uyusmazligi GUVENLI mi ---\n";

    $bad = bulk_write($dst['id'], $dst['record'], $dst['fields'],
        array('Adet' => 'bu bir metin', 'Tarih' => '32.13.2000'), $csrf, $cookie);
    $badD = json_decode($bad['body'], true);
    eq('E1) hepsi gecersizken HTTP 422', $bad['status'], 422);
    check('E1) ok=false donuyor', isset($badD['ok']) && $badD['ok'] === false, $bad['body']);
    check('E1) hata mesaji anlamli',
        isset($badD['error']) && strpos($badD['error'], 'gecerli hücre') !== false
        || (isset($badD['error']) && mb_strpos($badD['error'], 'geçerli hücre') !== false),
        isset($badD['error']) ? $badD['error'] : 'YOK');

    $dstHtml3 = http_request('GET', '/grid.php?table_id=' . $dst['id'], $cookie)['body'];
    eq('E1) onceki sayi degeri KORUNDU (uzerine yazilmadi)',
        raw_of($dstHtml3, $dst['fields']['Adet']), '1234.56');
    eq('E1) onceki tarih degeri KORUNDU', raw_of($dstHtml3, $dst['fields']['Tarih']), '2000-03-12');

    $mixed = bulk_write($dst['id'], $dst['record'], $dst['fields'],
        array('Ad' => 'Kismi Test', 'Adet' => 'sayi degil'), $csrf, $cookie);
    $mixedD = json_decode($mixed['body'], true);
    check('E2) kismen gecersizde istek BASARILI', !empty($mixedD['ok']), $mixed['body']);
    eq('E2) gecersiz hucre sayisi bildirildi',
        isset($mixedD['skipped_cells']) ? (int) $mixedD['skipped_cells'] : -1, 1);
    eq('E2) gecerli hucre yazildi',
        isset($mixedD['written_cells']) ? (int) $mixedD['written_cells'] : -1, 1);
    $dstHtml4 = http_request('GET', '/grid.php?table_id=' . $dst['id'], $cookie)['body'];
    eq('E2) gecerli deger gercekten yazildi', raw_of($dstHtml4, $dst['fields']['Ad']), 'Kismi Test');
    eq('E2) gecersiz sutun DOKUNULMADAN kaldi', raw_of($dstHtml4, $dst['fields']['Adet']), '1234.56');

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo ($pass === $total ? "SONUC: GECTI ($pass/$total)" : "SONUC: $pass/$total") . "\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
