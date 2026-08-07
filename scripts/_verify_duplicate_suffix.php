<?php
// Kayit cogaltmada " copy" ekinin TIP WHITELIST'ine
// ($GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES']) gore uygulandigini dogrular.
//
// Eskiden karar KOLON bazliydi ($primaryColumn === 'value_text') ve value_text'i
// YEDI tip paylastigi icin bicim sozlesmesi OLAN tiplerin degerini bozuyordu:
//   url -> link uretiliyor ama bosluk HOST'a karisiyordu (var olmayan alan adi)
//   email -> mailto: linki tamamen kayboluyordu
//   single_select -> choices listesinde OLMAYAN bir deger yaziliyordu
//   time -> gecerli olmayan bir saat olusuyordu
// Bu betik hem o dort bug'in kapandigini hem de serbest metin tiplerinin
// (single_line_text/long_text) " copy" ekini HALA aldigini dogrular.
//
// On kosul: Apache ayakta olmali (DocumentRoot = public, localhost:80).
// Calistirma: C:\php73\php.exe scripts\_verify_duplicate_suffix.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'dupsuffix.test.owner@bcc-test.local');
define('TEST_PASS', 'DupSuffixTest!2026');

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

    $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $parts = explode(';', substr($h, 11));
                $newCookie = trim($parts[0]);
            }
        }
    }

    return array('body' => (string) $body, 'cookie' => $newCookie);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
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
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'DupSuffix Test Owner'));
    $userId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array(':t' => $teamId, ':n' => 'DupSuffix Test', ':u' => $userId));
    $baseId = (int) bcc_last_insert_id();

    // --- Oturum -----------------------------------------------------------
    $resp = http_request('GET', '/login.php');
    $csrf = extract_csrf($resp['body']);
    $cookie = $resp['cookie'];
    $resp = http_request('POST', '/login.php', $cookie, array('email' => TEST_EMAIL, 'password' => TEST_PASS, 'csrf_token' => $csrf));
    if ($resp['cookie']) { $cookie = $resp['cookie']; }
    check('Giris yapildi (owner)', $cookie !== null);

    // Bir tip icin: BIRINCIL alani o tip olan bir tablo kurar, tek kayit yazar,
    // gercek /api/record_duplicate.php ucnoktasindan cogaltir ve kopyanin
    // birincil degerini dondurur.
    $duplicateWithPrimary = function ($fieldType, $storedValue, $options = null) use ($baseId, $userId, $cookie) {
        bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
            array(':b' => $baseId, ':n' => 'T_' . $fieldType));
        $tableId = (int) bcc_last_insert_id();

        // position 0 = BIRINCIL alan.
        bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, :o, 0)',
            array(':t' => $tableId, ':n' => 'Birincil', ':ft' => $fieldType, ':o' => $options));
        $fieldId = (int) bcc_last_insert_id();

        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)',
            array(':t' => $tableId, ':u' => $userId));
        $recordId = (int) bcc_last_insert_id();

        if ($storedValue !== null) {
            bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
                array(':r' => $recordId, ':f' => $fieldId, ':v' => $storedValue));
        }

        $page = http_request('GET', "/grid.php?table_id={$tableId}", $cookie);
        $pageCsrf = extract_csrf($page['body']);

        $resp = http_request('POST', '/api/record_duplicate.php', $cookie, array(
            'csrf_token' => $pageCsrf, 'record_id' => $recordId, 'state_query_string' => '',
        ));
        $json = json_decode($resp['body'], true);

        $copyValue = null;
        if (is_array($json) && !empty($json['ok'])) {
            $copyValue = bcc_fetch_column(
                'SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
                array(':r' => (int) $json['record_id'], ':f' => $fieldId)
            );
            if ($copyValue === false) { $copyValue = null; }
        }

        return array(
            'ok' => is_array($json) && !empty($json['ok']),
            'body' => $resp['body'],
            'orijinal' => $storedValue,
            'kopya' => $copyValue,
            'newRecordId' => is_array($json) && isset($json['record_id']) ? (int) $json['record_id'] : 0,
            'rowHtml' => is_array($json) && isset($json['row_html']) ? $json['row_html'] : '',
            'tableId' => $tableId,
            'fieldId' => $fieldId,
        );
    };

    // =======================================================================
    // A) BICIM SOZLESMESI OLAN TIPLER — " copy" eki ALMAMALI
    // =======================================================================
    echo "\n--- A) Ek ALMAMASI gereken tipler ---\n";

    // url
    $r = $duplicateWithPrimary('url', 'https://example.com');
    check('A) url: cogaltma basarili', $r['ok'], $r['body']);
    check('A) url: kopya DEGISMEDI (ek yok)', $r['kopya'] === 'https://example.com', 'kopya: ' . var_export($r['kopya'], true));
    check('A) url: link HALA uretiliyor', bcc_cell_link_href('url', $r['kopya']) === 'https://example.com',
        'href: ' . var_export(bcc_cell_link_href('url', $r['kopya']), true));
    check('A) url: kopya satirinin HTML href\'i dogru',
        strpos($r['rowHtml'], 'href="https://example.com"') !== false);
    check('A) url: href\'te BOSLUK YOK (host bozulmasi kapandi)',
        strpos($r['rowHtml'], 'href="https://example.com copy"') === false);

    // email
    $r = $duplicateWithPrimary('email', 'user@example.com');
    check('A) email: kopya DEGISMEDI', $r['kopya'] === 'user@example.com', 'kopya: ' . var_export($r['kopya'], true));
    check('A) email: mailto linki HALA calisiyor',
        bcc_cell_link_href('email', $r['kopya']) === 'mailto:user@example.com',
        'href: ' . var_export(bcc_cell_link_href('email', $r['kopya']), true));
    check('A) email: kopya satirinda mailto href var',
        strpos($r['rowHtml'], 'href="mailto:user@example.com"') !== false);

    // phone
    $r = $duplicateWithPrimary('phone', '0212 555 00 00');
    check('A) phone: kopya DEGISMEDI', $r['kopya'] === '0212 555 00 00', 'kopya: ' . var_export($r['kopya'], true));
    check('A) phone: tel linki dogru', bcc_cell_link_href('phone', $r['kopya']) === 'tel:02125550000');

    // time
    $r = $duplicateWithPrimary('time', '14:30');
    check('A) time: kopya DEGISMEDI', $r['kopya'] === '14:30', 'kopya: ' . var_export($r['kopya'], true));
    $t = DateTime::createFromFormat('H:i', (string) $r['kopya']);
    check('A) time: kopya GECERLI bir saat', $t !== false && $t->format('H:i') === '14:30', 'kopya: ' . var_export($r['kopya'], true));

    // single_select
    $selOptions = json_encode(array('choices' => array('Acik', 'Kapali')), JSON_UNESCAPED_UNICODE);
    $r = $duplicateWithPrimary('single_select', 'Acik', $selOptions);
    check('A) single_select: kopya DEGISMEDI', $r['kopya'] === 'Acik', 'kopya: ' . var_export($r['kopya'], true));
    $choices = select_choices_from_options($selOptions);
    check('A) single_select: kopya GECERLI bir choices degeri',
        in_array((string) $r['kopya'], $choices, true), 'kopya: ' . var_export($r['kopya'], true) . ' / choices: ' . implode(',', $choices));

    // =======================================================================
    // B) SERBEST METIN TIPLERI — " copy" ekini HALA ALMALI (REGRESYON)
    // =======================================================================
    echo "\n--- B) Ek ALMASI gereken tipler (regresyon) ---\n";

    $r = $duplicateWithPrimary('single_line_text', 'Musteri A');
    check('B) single_line_text: HALA " copy" eki aliyor', $r['kopya'] === 'Musteri A copy', 'kopya: ' . var_export($r['kopya'], true));

    $r = $duplicateWithPrimary('long_text', 'Uzun bir not');
    check('B) long_text: HALA " copy" eki aliyor', $r['kopya'] === 'Uzun bir not copy', 'kopya: ' . var_export($r['kopya'], true));

    // Bos birincil deger: whitelist'teki tip 'copy' alir, digerleri bos kalir.
    $r = $duplicateWithPrimary('single_line_text', '');
    check('B) single_line_text bos deger -> "copy"', $r['kopya'] === 'copy', 'kopya: ' . var_export($r['kopya'], true));

    $r = $duplicateWithPrimary('url', '');
    check('B) url bos deger -> BOS kalir ("copy" YAZILMAZ)',
        $r['kopya'] === '' || $r['kopya'] === null, 'kopya: ' . var_export($r['kopya'], true));

    // =======================================================================
    // C) REGRESYON — autonumber birincil alan HALA taze numara aliyor (Grup C2)
    // =======================================================================
    echo "\n--- C) Regresyon: autonumber birincil alan ---\n";
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array(':b' => $baseId, ':n' => 'T_autonumber'));
    $tC = (int) bcc_last_insert_id();
    $pageC = http_request('GET', "/table_fields.php?table_id={$tC}", $cookie);
    $csrfC = extract_csrf($pageC['body']);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfC, 'action' => 'create_field', 'table_id' => $tC, 'name' => 'No', 'field_type' => 'autonumber',
    ));
    $fC = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tC));
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)', array(':t' => $tC, ':u' => $userId));
    $recC = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, 1)', array(':r' => $recC, ':f' => $fC));
    bcc_execute('UPDATE fields SET autonumber_next = 2 WHERE id = :f', array(':f' => $fC));

    $pageC = http_request('GET', "/grid.php?table_id={$tC}", $cookie);
    $csrfC2 = extract_csrf($pageC['body']);
    $resp = http_request('POST', '/api/record_duplicate.php', $cookie, array(
        'csrf_token' => $csrfC2, 'record_id' => $recC, 'state_query_string' => '',
    ));
    $jC = json_decode($resp['body'], true);
    check('C) autonumber birincil: cogaltma basarili', is_array($jC) && !empty($jC['ok']), $resp['body']);
    $copyNum = (int) bcc_fetch_column('SELECT value_number FROM cell_values WHERE record_id = :r AND field_id = :f',
        array(':r' => (int) $jC['record_id'], ':f' => $fC));
    check('C) autonumber birincil: kopya TAZE numara (2) aldi, 1 tasinmadi', $copyNum === 2, 'bulunan: ' . $copyNum);
    $copyText = bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
        array(':r' => (int) $jC['record_id'], ':f' => $fC));
    check('C) autonumber birincil: value_text\'e "copy" SIZMADI',
        $copyText === null || $copyText === false || $copyText === '', 'value_text: ' . var_export($copyText, true));

    // =======================================================================
    // D) STATIK KONTROLLER
    // =======================================================================
    echo "\n--- D) Statik kontroller ---\n";
    check('D) BCC_DUPLICATE_SUFFIX_FIELD_TYPES tanimli',
        isset($GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES']) && is_array($GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES']));
    check('D) Whitelist YALNIZCA single_line_text + long_text',
        $GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES'] === array('single_line_text', 'long_text'),
        implode(',', $GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES']));
    $dupPhp = file_get_contents(__DIR__ . '/../public/api/record_duplicate.php');
    check('D) record_duplicate.php artik KOLON bazli karar VERMIYOR',
        strpos($dupPhp, "\$primaryColumn === 'value_text'") === false
        || strpos($dupPhp, "// Bulunan gerçek buglar") !== false);
    check('D) record_duplicate.php whitelist\'i kullaniyor',
        strpos($dupPhp, "in_array(\$primaryFieldType, \$GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES'], true)") !== false);
    // value_text'i paylasan AMA whitelist'te OLMAYAN tipler gercekten ek almamali.
    $textTypes = array();
    foreach ($GLOBALS['BCC_FIELD_VALUE_COLUMN'] as $t => $c) {
        if ($c === 'value_text' && !in_array($t, $GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES'], true)) {
            $textTypes[] = $t;
        }
    }
    check('D) value_text paylasan 5 tip whitelist DISINDA (url/email/phone/time/single_select)',
        $textTypes === array('single_select', 'time', 'url', 'email', 'phone'), implode(',', $textTypes));

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
