<?php
// Grup C1 (Currency/Percent/Rating) doğrulaması. curl KULLANILMAZ — PHP'nin
// http:// stream sarmalayıcısıyla gerçek oturum çerezi alınıp gerçek
// table_fields.php / grid.php / api'lere istek atılır.
// Kendi test verisini kurar, doğrular, sonunda temizler.
//
// Ön koşul: Apache ayakta olmalı (DocumentRoot = public, localhost:80).
// Çalıştırma: C:\php73\php.exe scripts\_verify_group_c1.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'groupc1.test.owner@bcc-test.local');
define('TEST_PASS', 'GroupC1Test!2026');

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

    $context = stream_context_create($options);
    $body = @file_get_contents(BASE_URL . $path, false, $context);

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

// Bir alan id'sine ait hücrenin ham data-value'sunu döndürür.
function extract_field_values($html, $fieldId)
{
    $pattern = '/data-field-id="' . preg_quote((string) $fieldId, '/') . '"[^>]*data-value="([^"]*)"/';
    preg_match_all($pattern, $html, $m);
    return isset($m[1]) ? $m[1] : array();
}

// Bir alan id'sine ait <td>'nin TAM HTML'ini döndürür (yıldız span'ları,
// .cell-view metni gibi görüntüleme katmanını doğrulamak için).
function extract_field_cell_html($html, $fieldId)
{
    $pattern = '/<td\s[^>]*data-field-id="' . preg_quote((string) $fieldId, '/') . '"[\s\S]*?<\/td>/';
    preg_match_all($pattern, $html, $m);
    return isset($m[0]) ? $m[0] : array();
}

function field_options_row($fieldId)
{
    $r = bcc_fetch_one('SELECT options FROM fields WHERE id = :id', array(':id' => $fieldId));
    return $r ? $r['options'] : null;
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
    if (!$team) {
        echo "HATA: TY ekibi bulunamadi.\n";
        exit(1);
    }
    $teamId = (int) $team['id'];

    $hash = password_hash(TEST_PASS, PASSWORD_DEFAULT);
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :name, 0, 1)', array(':email' => TEST_EMAIL, ':hash' => $hash, ':name' => 'GrupC1 Test Owner'));
    $userId = (int) bcc_last_insert_id();

    // table_fields.php alan olusturma/duzenleme icin 'owner' rolu ister.
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:tid, :uid, :role)', array(':tid' => $teamId, ':uid' => $userId, ':role' => 'owner'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:tid, :name, :uid)', array(':tid' => $teamId, ':name' => 'GrupC1 Test', ':uid' => $userId));
    $baseId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:bid, :name, 0)', array(':bid' => $baseId, ':name' => 'C1 Test'));
    $tableId = (int) bcc_last_insert_id();

    // Birincil alan (metin) + REGRESYON alanlari: number ve dort otomatik tip.
    $insertFieldSql = 'INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:tid, :name, :type, :options, :pos)';
    $baseDefs = array(
        array('Ad', 'single_line_text', null),
        array('Miktar', 'number', null),
        array('OlusturmaZamani', 'created_time', null),
        array('Olusturan', 'created_by', null),
        array('SonDegisiklik', 'last_modified_time', null),
        array('SonDegistiren', 'last_modified_by', null),
    );
    $fieldIds = array();
    foreach ($baseDefs as $i => $d) {
        bcc_execute($insertFieldSql, array(':tid' => $tableId, ':name' => $d[0], ':type' => $d[1], ':options' => $d[2], ':pos' => $i));
        $fieldIds[$d[0]] = (int) bcc_last_insert_id();
    }

    // Iki kayit (regresyon + filtre/siralama icin).
    $recordIds = array();
    for ($i = 0; $i < 2; $i++) {
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)', array(':tid' => $tableId, ':pos' => $i, ':uid' => $userId));
        $recordIds[] = (int) bcc_last_insert_id();
    }
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:rid, :fid, :val)', array(':rid' => $recordIds[0], ':fid' => $fieldIds['Ad'], ':val' => 'Elma'));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:rid, :fid, :val)', array(':rid' => $recordIds[1], ':fid' => $fieldIds['Ad'], ':val' => 'Armut'));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:rid, :fid, :val)', array(':rid' => $recordIds[0], ':fid' => $fieldIds['Miktar'], ':val' => 10));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:rid, :fid, :val)', array(':rid' => $recordIds[1], ':fid' => $fieldIds['Miktar'], ':val' => 20));

    echo "Kurulum tamam: table_id={$tableId}, kayitlar=" . implode(',', $recordIds) . "\n\n";

    // --- Oturum ac ---------------------------------------------------------
    $resp = http_request('GET', '/login.php');
    $csrf = extract_csrf($resp['body']);
    $cookie = $resp['cookie'];
    $resp = http_request('POST', '/login.php', $cookie, array('email' => TEST_EMAIL, 'password' => TEST_PASS, 'csrf_token' => $csrf));
    if ($resp['cookie']) {
        $cookie = $resp['cookie'];
    }
    check('Giris yapildi (owner)', $cookie !== null);

    // CSRF token'i alan sayfasindan al.
    $resp = http_request('GET', "/table_fields.php?table_id={$tableId}", $cookie);
    $csrf = extract_csrf($resp['body']);
    check('table_fields.php acildi, csrf alindi', $csrf !== null);

    // =======================================================================
    // 1) ALAN OLUSTURMA — ozel ayarlarla (A + B: isim eslesmesi burada sinaniyor)
    // =======================================================================
    $resp = http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrf, 'action' => 'create_field', 'table_id' => $tableId,
        'name' => 'Fiyat', 'field_type' => 'currency',
        'currency_symbol' => '$', 'currency_decimal_places' => '3',
        // Gizli satirlarin input'lari da forma dahil olur — birbirine karismamali:
        'percent_decimal_places' => '4', 'max_rating' => '9',
    ));
    $f = bcc_fetch_one("SELECT id, options FROM fields WHERE table_id = :t AND name = 'Fiyat'", array(':t' => $tableId));
    $fieldIds['Fiyat'] = $f ? (int) $f['id'] : 0;
    check('Currency alani olusturuldu, options dogru ($ / 3 basamak)',
        $f && $f['options'] === json_encode(array('currency_symbol' => '$', 'decimal_places' => 3), JSON_UNESCAPED_UNICODE),
        'options: ' . ($f ? $f['options'] : 'YOK'));

    $resp = http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrf, 'action' => 'create_field', 'table_id' => $tableId,
        'name' => 'Oran', 'field_type' => 'percent',
        'currency_symbol' => '$', 'currency_decimal_places' => '3',
        'percent_decimal_places' => '1', 'max_rating' => '9',
    ));
    $f = bcc_fetch_one("SELECT id, options FROM fields WHERE table_id = :t AND name = 'Oran'", array(':t' => $tableId));
    $fieldIds['Oran'] = $f ? (int) $f['id'] : 0;
    check('Percent alani olusturuldu, options dogru (1 basamak, currency ile karismadi)',
        $f && $f['options'] === json_encode(array('decimal_places' => 1), JSON_UNESCAPED_UNICODE),
        'options: ' . ($f ? $f['options'] : 'YOK'));

    $resp = http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrf, 'action' => 'create_field', 'table_id' => $tableId,
        'name' => 'Puan', 'field_type' => 'rating',
        'currency_symbol' => '$', 'currency_decimal_places' => '3',
        'percent_decimal_places' => '1', 'max_rating' => '7',
    ));
    $f = bcc_fetch_one("SELECT id, options FROM fields WHERE table_id = :t AND name = 'Puan'", array(':t' => $tableId));
    $fieldIds['Puan'] = $f ? (int) $f['id'] : 0;
    check('Rating alani olusturuldu, options dogru (max_rating=7)',
        $f && $f['options'] === json_encode(array('max_rating' => 7), JSON_UNESCAPED_UNICODE),
        'options: ' . ($f ? $f['options'] : 'YOK'));

    $fiyatId = $fieldIds['Fiyat'];
    $oranId = $fieldIds['Oran'];
    $puanId = $fieldIds['Puan'];
    $miktarId = $fieldIds['Miktar'];

    // =======================================================================
    // 2) HUCRE YAZMA — gercek cell_update.php API'si uzerinden
    // =======================================================================
    $cellUpdate = function ($recordId, $fieldId, $value) use ($cookie, $csrf) {
        return http_request('POST', '/api/cell_update.php', $cookie, array(
            'csrf_token' => $csrf, 'record_id' => $recordId, 'field_id' => $fieldId, 'value' => $value,
        ));
    };

    // Currency: 1234.5 -> "$1.234,500"
    $resp = $cellUpdate($recordIds[0], $fiyatId, '1234.5');
    $json = json_decode($resp['body'], true);
    check('Currency yazildi, display "$1.234,500"',
        is_array($json) && isset($json['display']) && $json['display'] === '$1.234,500',
        'donen: ' . $resp['body']);
    $dbVal = bcc_fetch_one('SELECT value_number FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $recordIds[0], ':f' => $fiyatId));
    check('Currency DB ham sayi olarak duruyor (1234.5)', $dbVal && (float) $dbVal['value_number'] === 1234.5, 'DB: ' . ($dbVal ? $dbVal['value_number'] : 'YOK'));

    $cellUpdate($recordIds[1], $fiyatId, '20');

    // Percent: "45" -> DB 0.45, ekran "%45,0"
    $resp = $cellUpdate($recordIds[0], $oranId, '45');
    $json = json_decode($resp['body'], true);
    $dbVal = bcc_fetch_one('SELECT value_number FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $recordIds[0], ':f' => $oranId));
    check('Percent DB ONDALIK olarak yazildi (0.45)',
        $dbVal && abs((float) $dbVal['value_number'] - 0.45) < 0.000001,
        'DB: ' . ($dbVal ? $dbVal['value_number'] : 'YOK'));
    check('Percent ekranda "%45,0" (Turkce: isaret onde, 1 ondalik)',
        is_array($json) && isset($json['display']) && $json['display'] === '%45,0',
        'donen: ' . $resp['body']);
    check('Percent raw (edit kutusu) kullanicinin yazdigi "45" degerini gosteriyor',
        is_array($json) && isset($json['raw']) && (float) $json['raw'] === 45.0,
        'raw: ' . (is_array($json) && isset($json['raw']) ? $json['raw'] : '?'));

    $cellUpdate($recordIds[1], $oranId, '80');

    // Rating: 5 (max 7) kabul
    $resp = $cellUpdate($recordIds[0], $puanId, '5');
    $json = json_decode($resp['body'], true);
    check('Rating 5 kabul edildi (max 7)', is_array($json) && !empty($json['ok']), 'donen: ' . $resp['body']);
    $dbVal = bcc_fetch_one('SELECT value_number FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $recordIds[0], ':f' => $puanId));
    check('Rating DB tam sayi (5)', $dbVal && (int) $dbVal['value_number'] === 5, 'DB: ' . ($dbVal ? $dbVal['value_number'] : 'YOK'));

    // Rating: 9 (max 7) REDDEDILMELI
    $resp = $cellUpdate($recordIds[1], $puanId, '9');
    $json = json_decode($resp['body'], true);
    check('Rating 9 REDDEDILDI (max_rating=7 disi)',
        is_array($json) && empty($json['ok']) && isset($json['error']) && strpos($json['error'], '0 ile 7') !== false,
        'donen: ' . $resp['body']);

    // Rating: negatif REDDEDILMELI
    $resp = $cellUpdate($recordIds[1], $puanId, '-1');
    $json = json_decode($resp['body'], true);
    check('Rating -1 REDDEDILDI', is_array($json) && empty($json['ok']), 'donen: ' . $resp['body']);

    $cellUpdate($recordIds[1], $puanId, '3');

    // =======================================================================
    // 3) GRID RENDER — yildizlar, CSS class'lari, data-options
    // =======================================================================
    $resp = http_request('GET', "/grid.php?table_id={$tableId}", $cookie);
    $gridHtml = $resp['body'];
    $puanCells = extract_field_cell_html($gridHtml, $puanId);
    $firstPuanCell = isset($puanCells[0]) ? $puanCells[0] : '';

    check('Rating hucresi 7 yildiz span iceriyor (max_rating=7)',
        substr_count($firstPuanCell, 'data-rating-star=') === 7,
        'bulunan: ' . substr_count($firstPuanCell, 'data-rating-star='));
    check('Rating hucresinde 5 DOLU yildiz var (deger=5)',
        substr_count($firstPuanCell, 'rating-star-filled') === 5,
        'bulunan: ' . substr_count($firstPuanCell, 'rating-star-filled'));
    check('Rating hucresi duzenlenebilir (.rating-view-editable)',
        strpos($firstPuanCell, 'rating-view-editable') !== false);
    check('Rating td data-options="{"max_rating":7}" tasiyor',
        strpos($firstPuanCell, '&quot;max_rating&quot;:7') !== false,
        substr($firstPuanCell, 0, 300));

    $fiyatCells = extract_field_cell_html($gridHtml, $fiyatId);
    check('Currency grid hucresinde "$1.234,500" gorunuyor',
        isset($fiyatCells[0]) && strpos($fiyatCells[0], '$1.234,500') !== false,
        isset($fiyatCells[0]) ? strip_tags($fiyatCells[0]) : 'YOK');

    $oranCells = extract_field_cell_html($gridHtml, $oranId);
    check('Percent grid hucresinde "%45,0" gorunuyor',
        isset($oranCells[0]) && strpos($oranCells[0], '%45,0') !== false,
        isset($oranCells[0]) ? strip_tags($oranCells[0]) : 'YOK');

    // CSS gercekten yuklu mu (style.css'te .rating-star tanimli mi)
    $cssPath = __DIR__ . '/../public/assets/style.css';
    $css = file_get_contents($cssPath);
    check('style.css .rating-star / .rating-star-filled / cursor tanimlari iceriyor',
        strpos($css, '.rating-star') !== false
        && strpos($css, '.rating-star-filled') !== false
        && strpos($css, '.rating-view-editable .rating-star') !== false);

    // =======================================================================
    // 4) MADDE D — mevcut alani DUZENLE, ayar DEGISMEDEN korunuyor mu
    // =======================================================================
    $resp = http_request('GET', "/table_fields.php?table_id={$tableId}&edit={$fiyatId}", $cookie);
    $editHtml = $resp['body'];
    $csrfEdit = extract_csrf($editHtml);
    check('Duzenleme formu currency_symbol input\'unu KAYITLI degerle ($) on-dolduruyor',
        preg_match('/name="currency_symbol"[^>]*value="\$"/', $editHtml) === 1);
    check('Duzenleme formu currency_decimal_places\'i KAYITLI degerle (3) on-dolduruyor',
        preg_match('/name="currency_decimal_places"[^>]*value="3"/', $editHtml) === 1);

    // D testi 1: SADECE adi degistir, ayarlara DOKUNMA -> ayarlar korunmali.
    $resp = http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfEdit, 'action' => 'update_field', 'table_id' => $tableId,
        'field_id' => $fiyatId, 'name' => 'Fiyat TL', 'field_type' => 'currency',
        'currency_symbol' => '$', 'currency_decimal_places' => '3',
    ));
    check('MADDE D: sadece ad degisti -> currency options KORUNDU ($ / 3)',
        field_options_row($fiyatId) === json_encode(array('currency_symbol' => '$', 'decimal_places' => 3), JSON_UNESCAPED_UNICODE),
        'options: ' . field_options_row($fiyatId));

    // D testi 2: ayari GERCEKTEN degistir -> kaydedilmeli.
    $resp = http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfEdit, 'action' => 'update_field', 'table_id' => $tableId,
        'field_id' => $fiyatId, 'name' => 'Fiyat TL', 'field_type' => 'currency',
        'currency_symbol' => '€', 'currency_decimal_places' => '0',
    ));
    check('MADDE D: ayar degistirildi -> yeni options kaydedildi (€ / 0)',
        field_options_row($fiyatId) === json_encode(array('currency_symbol' => '€', 'decimal_places' => 0), JSON_UNESCAPED_UNICODE),
        'options: ' . field_options_row($fiyatId));

    // Rating duzenleme: sadece ad -> max_rating korunmali.
    $resp = http_request('GET', "/table_fields.php?table_id={$tableId}&edit={$puanId}", $cookie);
    $csrfEdit2 = extract_csrf($resp['body']);
    check('Duzenleme formu max_rating\'i KAYITLI degerle (7) on-dolduruyor',
        preg_match('/name="max_rating"[^>]*value="7"/', $resp['body']) === 1);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfEdit2, 'action' => 'update_field', 'table_id' => $tableId,
        'field_id' => $puanId, 'name' => 'Puanim', 'field_type' => 'rating', 'max_rating' => '7',
    ));
    check('MADDE D: rating adi degisti -> max_rating KORUNDU (7)',
        field_options_row($puanId) === json_encode(array('max_rating' => 7), JSON_UNESCAPED_UNICODE),
        'options: ' . field_options_row($puanId));

    // Percent duzenleme
    $resp = http_request('GET', "/table_fields.php?table_id={$tableId}&edit={$oranId}", $cookie);
    $csrfEdit3 = extract_csrf($resp['body']);
    check('Duzenleme formu percent_decimal_places\'i KAYITLI degerle (1) on-dolduruyor',
        preg_match('/name="percent_decimal_places"[^>]*value="1"/', $resp['body']) === 1);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfEdit3, 'action' => 'update_field', 'table_id' => $tableId,
        'field_id' => $oranId, 'name' => 'Oranim', 'field_type' => 'percent', 'percent_decimal_places' => '1',
    ));
    check('MADDE D: percent adi degisti -> decimal_places KORUNDU (1)',
        field_options_row($oranId) === json_encode(array('decimal_places' => 1), JSON_UNESCAPED_UNICODE),
        'options: ' . field_options_row($oranId));

    // =======================================================================
    // 5) REGRESYON — number + dort otomatik tip hala dogru mu
    // =======================================================================
    $resp = http_request('GET', "/grid.php?table_id={$tableId}", $cookie);
    $gridHtml = $resp['body'];

    $miktarVals = extract_field_values($gridHtml, $miktarId);
    check('REGRESYON: number alani hala dogru (10, 20)', $miktarVals === array('10', '20'), 'bulunan: ' . implode(',', $miktarVals));
    $miktarCells = extract_field_cell_html($gridHtml, $miktarId);
    check('REGRESYON: number GORUNTUSU formatsiz duz sayi ("10", sembol/yuzde YOK)',
        isset($miktarCells[0]) && trim(strip_tags($miktarCells[0])) === '10',
        isset($miktarCells[0]) ? trim(strip_tags($miktarCells[0])) : 'YOK');

    foreach (array('OlusturmaZamani' => 'created_time', 'SonDegisiklik' => 'last_modified_time') as $fname => $ftype) {
        $cells = extract_field_cell_html($gridHtml, $fieldIds[$fname]);
        $txt = isset($cells[0]) ? trim(strip_tags($cells[0])) : '';
        check("REGRESYON: {$ftype} tarih/saat gosteriyor", preg_match('/\d{2}\.\d{2}\.\d{4}/', $txt) === 1, 'bulunan: ' . $txt);
        check("REGRESYON: {$ftype} salt-okunur (editable class YOK)",
            isset($cells[0]) && strpos($cells[0], 'grid-cell editable') === false);
    }
    foreach (array('Olusturan' => 'created_by', 'SonDegistiren' => 'last_modified_by') as $fname => $ftype) {
        $cells = extract_field_cell_html($gridHtml, $fieldIds[$fname]);
        $txt = isset($cells[0]) ? trim(strip_tags($cells[0])) : '';
        check("REGRESYON: {$ftype} kullanici adini gosteriyor", $txt === 'GrupC1 Test Owner', 'bulunan: ' . $txt);
    }

    // =======================================================================
    // 6) FILTRE — yeni tipler
    // =======================================================================
    $adId = $fieldIds['Ad'];

    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$fiyatId}&filter_cond_1=gt&filter_value_1=100", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('FILTRE currency ">100" -> sadece Elma (1234.5)', $names === array('Elma'), 'bulunan: ' . implode(',', $names));

    // percent: kullanici "50" yazar, DB'de 0.45/0.80 var -> ">50" sadece Armut (0.80)
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$oranId}&filter_cond_1=gt&filter_value_1=50", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('FILTRE percent ">50" (kullanici olcegi) -> sadece Armut (0.80)', $names === array('Armut'), 'bulunan: ' . implode(',', $names));

    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$puanId}&filter_cond_1=gte&filter_value_1=5", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('FILTRE rating ">=5" -> sadece Elma (5)', $names === array('Elma'), 'bulunan: ' . implode(',', $names));

    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$miktarId}&filter_cond_1=gt&filter_value_1=15", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('REGRESYON FILTRE: number ">15" -> sadece Armut', $names === array('Armut'), 'bulunan: ' . implode(',', $names));

    // =======================================================================
    // 7) SIRALAMA — yeni tipler
    // =======================================================================
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&sort_field_1={$fiyatId}&sort_dir_1=asc", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('SIRALAMA currency artan -> Armut(20), Elma(1234.5)', $names === array('Armut', 'Elma'), 'bulunan: ' . implode(',', $names));

    $resp = http_request('GET', "/grid.php?table_id={$tableId}&sort_field_1={$puanId}&sort_dir_1=desc", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('SIRALAMA rating azalan -> Elma(5), Armut(3)', $names === array('Elma', 'Armut'), 'bulunan: ' . implode(',', $names));

    // =======================================================================
    // 8) GRUPLAMA — grup basligi cell_display_text($rule['options']) kullaniyor
    // =======================================================================
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&group_field_1={$puanId}", $cookie);
    check('GRUPLAMA rating: grup basligi yildizla gosteriyor (★ ve ☆)',
        strpos($resp['body'], '★') !== false, 'grup basligi yildiz icermiyor');
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&group_field_1={$fiyatId}", $cookie);
    check('GRUPLAMA currency: grup basligi "€1.235" formatli (duzenlenen ayar: € / 0)',
        strpos($resp['body'], '€1.235') !== false || strpos($resp['body'], '€1.234') !== false,
        'grup basligi euro formatli degil');

    // =======================================================================
    // 9) EXPORT (xlsx) — cell_display_text options ile cagriliyor mu
    // =======================================================================
    $resp = http_request('GET', "/api/view_export_xlsx.php?table_id={$tableId}", $cookie);
    $xlsxBytes = $resp['body'];
    check('EXPORT: xlsx indirildi (ZIP imzasi PK)', substr($xlsxBytes, 0, 2) === 'PK', 'ilk baytlar: ' . bin2hex(substr($xlsxBytes, 0, 4)));
    $tmpXlsx = sys_get_temp_dir() . '/bcc_c1_export.xlsx';
    file_put_contents($tmpXlsx, $xlsxBytes);
    // src/xlsx_writer.php TUM hucreleri "inlineStr" olarak yazar — ayri bir
    // sharedStrings.xml YOK, metin dogrudan sheet1.xml'in icinde.
    $zip = new ZipArchive();
    $sheetXml = '';
    if ($zip->open($tmpXlsx) === true) {
        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
    }
    @unlink($tmpXlsx);
    check('EXPORT: currency formatli (€1.235) xlsx icinde', strpos($sheetXml, '€1.235') !== false || strpos($sheetXml, '€1.234') !== false, 'sheet1.xml uzunluk: ' . strlen($sheetXml));
    check('EXPORT: percent formatli (%45,0) xlsx icinde', strpos($sheetXml, '%45,0') !== false);
    check('EXPORT: rating yildizla (★) xlsx icinde', strpos($sheetXml, '★') !== false);

    // =======================================================================
    // 10) SLACK — bcc_notify_slack_new_record() options'i okuyabiliyor mu
    //     (webhook YOK; birincil alan sorgusu options seciyor mu diye bakilir)
    // =======================================================================
    $slackSrc = file_get_contents(__DIR__ . '/../src/slack.php');
    check('SLACK: birincil alan sorgusu options seciyor',
        strpos($slackSrc, 'SELECT id, field_type, options FROM fields') !== false);
    check('SLACK: cell_display_text options ile cagriliyor',
        strpos($slackSrc, "cell_display_text(\$primaryField['field_type'], \$cellRow, \$usersById, \$primaryField['options'])") !== false);

    // =======================================================================
    // 11) FORM ISIM TUTARLILIGI — A/D kok nedeni bir daha olusmasin
    // =======================================================================
    $wizardSrc = file_get_contents(__DIR__ . '/../src/partials/field_type_wizard_fields.php');
    $editSrc = file_get_contents(__DIR__ . '/../public/table_fields.php');
    $schemaSrc = file_get_contents(__DIR__ . '/../src/schema.php');
    $allNamesOk = true;
    foreach (array('currency_symbol', 'currency_decimal_places', 'percent_decimal_places', 'max_rating') as $n) {
        if (strpos($wizardSrc, 'name="' . $n . '"') === false) { $allNamesOk = false; }
        if (strpos($editSrc, 'name="' . $n . '"') === false) { $allNamesOk = false; }
        if (strpos($schemaSrc, "\$extraPost['" . $n . "']") === false) { $allNamesOk = false; }
    }
    check('ISIM TUTARLILIGI: 4 input adi olusturma + duzenleme + okuyucuda BIREBIR ayni', $allNamesOk);
    check('ISIM TUTARLILIGI: eski/ortak "decimal_places" anahtari artik OKUNMUYOR',
        strpos($schemaSrc, "\$extraPost['decimal_places']") === false);

    // Sihirbaz JS uc satiri da aciyor mu
    $wizJs = file_get_contents(__DIR__ . '/../public/assets/field-type-wizard.js');
    check('SIHIRBAZ JS: currency/percent/rating satirlarini tipe gore aciyor',
        strpos($wizJs, "new-field-currency-row") !== false
        && strpos($wizJs, "new-field-percent-row") !== false
        && strpos($wizJs, "new-field-rating-row") !== false
        && strpos($wizJs, "type !== 'currency'") !== false);

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
