<?php
// Grup A (URL / E-posta / Telefon) dogrulamasi. curl KULLANILMAZ — PHP'nin
// http:// stream sarmalayicisiyla gercek oturum cerezi alinip gercek uc
// noktalara istek atilir. Kendi test verisini kurar, dogrular, sonunda temizler.
//
// Ayrica bu turda duzeltilen MEVCUT BUG'i da dogrular:
// BCC_GROUP_DIR_LABELS'in Grup B1/B2/C1/C2 tiplerini icermemesi
// (public/grid.php'nin korumasiz okumasi -> "Undefined index" + bos etiket).
//
// On kosul: Apache ayakta olmali (DocumentRoot = public, localhost:80).
// Calistirma: C:\php73\php.exe scripts\_verify_group_a.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'groupa.test.owner@bcc-test.local');
define('TEST_PASS', 'GroupATest!2026');

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

// Bir alanin <td>'sinin TAM HTML'ini dondurur (ic icerik dahil).
function cell_html($html, $fieldId)
{
    $pattern = '/<td\b[^>]*data-field-id="' . preg_quote((string) $fieldId, '/') . '"[^>]*>(.*?)<\/td>/s';
    if (preg_match($pattern, $html, $m)) {
        return $m[0];
    }
    return '';
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
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'GrupA Test Owner'));
    $userId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array(':t' => $teamId, ':n' => 'GrupA Test', ':u' => $userId));
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

    // Hucreye deger yazan yardimci — gercek cell_update.php ucnoktasi.
    $setCell = function ($recordId, $fieldId, $value) use ($cookie, &$csrfMain) {
        return http_request('POST', '/api/cell_update.php', $cookie, array(
            'csrf_token' => $csrfMain, 'record_id' => $recordId, 'field_id' => $fieldId, 'value' => $value,
        ));
    };

    // =======================================================================
    // A) ALAN OLUSTURMA — sihirbazdan gercekten olusturulabiliyor mu
    // =======================================================================
    $tA = $mkTable('A Linkler');
    $adA = $mkField($tA, 'Ad', 'single_line_text', 0);
    $resp = http_request('GET', "/table_fields.php?table_id={$tA}", $cookie);
    $csrfA = extract_csrf($resp['body']);
    $csrfMain = $csrfA;

    foreach (array('url' => 'Site', 'email' => 'Eposta', 'phone' => 'Telefon') as $ft => $fname) {
        http_request('POST', '/table_fields.php', $cookie, array(
            'csrf_token' => $csrfA, 'action' => 'create_field', 'table_id' => $tA,
            'name' => $fname, 'field_type' => $ft,
        ));
    }
    $fUrl = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'Site'", array(':t' => $tA));
    $fMail = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'Eposta'", array(':t' => $tA));
    $fTel = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'Telefon'", array(':t' => $tA));
    check('A) Uc tip de sihirbazdan olusturuldu', $fUrl > 0 && $fMail > 0 && $fTel > 0);
    check('A) Uc tip de value_text kullaniyor (DDL yok)',
        $GLOBALS['BCC_FIELD_VALUE_COLUMN']['url'] === 'value_text'
        && $GLOBALS['BCC_FIELD_VALUE_COLUMN']['email'] === 'value_text'
        && $GLOBALS['BCC_FIELD_VALUE_COLUMN']['phone'] === 'value_text');

    $r1 = $mkRecord($tA, 0);

    // =======================================================================
    // B) GECERLI DEGERLER -> LINK IKONU
    // =======================================================================
    $setCell($r1, $fUrl, 'https://example.com');
    $setCell($r1, $fMail, 'ali@ornek.com');
    $setCell($r1, $fTel, '0212 555 00 00');

    $g = http_request('GET', "/grid.php?table_id={$tA}", $cookie)['body'];
    $cUrl = cell_html($g, $fUrl);
    $cMail = cell_html($g, $fMail);
    $cTel = cell_html($g, $fTel);

    check('B) URL hucresinde link ikonu var', strpos($cUrl, 'class="cell-link-icon"') !== false, $cUrl);
    check('B) URL href dogru', strpos($cUrl, 'href="https://example.com"') !== false, $cUrl);
    check('B) URL target=_blank + rel=noopener noreferrer',
        strpos($cUrl, 'target="_blank"') !== false && strpos($cUrl, 'rel="noopener noreferrer"') !== false, $cUrl);
    check('B) Email mailto: linki', strpos($cMail, 'href="mailto:ali@ornek.com"') !== false, $cMail);
    check('B) Telefon tel: linki rakamlara indirgendi', strpos($cTel, 'href="tel:02125550000"') !== false, $cTel);
    check('B) Telefon METNI ham haliyle korundu (indirgeme sadece href icin)',
        strpos($cTel, '>0212 555 00 00<') !== false, $cTel);
    check('B) DB\'de saklanan telefon degeri ham',
        bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
            array(':r' => $r1, ':f' => $fTel)) === '0212 555 00 00');

    // =======================================================================
    // C) DUZENLENEBILIRLIK — Grup B/C'nin salt-okunur zorlamasi burada YOK
    // =======================================================================
    check('C) URL hucresi editable (tiklayinca duzenleme acilir)',
        strpos($cUrl, 'grid-cell editable') !== false, $cUrl);
    check('C) Email hucresi editable', strpos($cMail, 'grid-cell editable') !== false);
    check('C) Telefon hucresi editable', strpos($cTel, 'grid-cell editable') !== false);
    check('C) Metnin KENDISI link DEGIL (tiklama duzenlemeyi acsin diye)',
        strpos($cUrl, '<span class="cell-link-text">https://example.com</span>') !== false, $cUrl);
    // Hucrede TEK bir <a> olmali (ikon) — metin sarmalayan ikinci bir <a> YOK.
    check('C) Hucrede yalnizca IKON linki var (metin sarmalayan <a> yok)',
        substr_count($cUrl, '<a ') === 1, 'a sayisi: ' . substr_count($cUrl, '<a '));

    // =======================================================================
    // D) YUMUSAK DOGRULAMA — gecersiz degerler REDDEDILMEZ, sadece linklesmez
    // =======================================================================
    $r2 = $mkRecord($tA, 1);
    $resp = $setCell($r2, $fUrl, 'abc');
    $j = json_decode($resp['body'], true);
    check('D) Gecersiz URL ("abc") KABUL EDILDI (reddedilmedi)', is_array($j) && !empty($j['ok']), $resp['body']);
    check('D) Gecersiz URL icin display_link null (ikon olmayacak)',
        is_array($j) && array_key_exists('display_link', $j) && $j['display_link'] === null, $resp['body']);
    check('D) DB\'ye "abc" gercekten yazildi',
        bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
            array(':r' => $r2, ':f' => $fUrl)) === 'abc');

    $resp = $setCell($r2, $fMail, 'gecersiz-eposta');
    check('D) Gecersiz e-posta KABUL EDILDI', !empty(json_decode($resp['body'], true)['ok']));
    $resp = $setCell($r2, $fTel, '555');
    check('D) Kisa telefon ("555") KABUL EDILDI', !empty(json_decode($resp['body'], true)['ok']));

    $g = http_request('GET', "/grid.php?table_id={$tA}", $cookie)['body'];
    $rows = explode('data-record-id="' . $r2 . '"', $g);
    $row2 = isset($rows[1]) ? $rows[1] : '';
    check('D) "abc" duz metin kaldi, IKON YOK',
        strpos(cell_html($row2, $fUrl), 'cell-link-icon') === false, cell_html($row2, $fUrl));
    check('D) Gecersiz e-posta icin IKON YOK',
        strpos(cell_html($row2, $fMail), 'cell-link-icon') === false);
    check('D) "555" icin tel IKONU YOK (7 rakam esigi)',
        strpos(cell_html($row2, $fTel), 'cell-link-icon') === false);

    // =======================================================================
    // E) XSS — kotu niyetli deger linklesmemeli, kacirilmali
    // =======================================================================
    $r3 = $mkRecord($tA, 2);
    $xss = 'javascript:alert(1)';
    $resp = $setCell($r3, $fUrl, $xss);
    check('E) javascript: degeri KABUL EDILDI (yumusak dogrulama)', !empty(json_decode($resp['body'], true)['ok']));
    check('E) display_link null (istemciye guvenli olmayan href GITMEDI)',
        json_decode($resp['body'], true)['display_link'] === null, $resp['body']);

    $g = http_request('GET', "/grid.php?table_id={$tA}", $cookie)['body'];
    $rows = explode('data-record-id="' . $r3 . '"', $g);
    $row3 = isset($rows[1]) ? $rows[1] : '';
    $cX = cell_html($row3, $fUrl);
    check('E) javascript: LINKLESMEDI (hic <a> yok)', strpos($cX, '<a ') === false, $cX);
    check('E) javascript: metin olarak duz duruyor', strpos($cX, 'javascript:alert(1)') !== false, $cX);
    check('E) Sayfada hicbir yerde href="javascript: YOK', strpos($g, 'href="javascript:') === false);

    // Attribute kacisi denemesi
    $setCell($r3, $fMail, 'a@b.com" onmouseover="alert(1)');
    $g = http_request('GET', "/grid.php?table_id={$tA}", $cookie)['body'];
    check('E) Attribute kacisi ENGELLENDI (onmouseover enjekte olmadi)',
        strpos($g, 'onmouseover="alert(1)"') === false);
    // Etiket enjeksiyonu denemesi
    $setCell($r3, $fUrl, '<script>alert(1)</script>');
    $g = http_request('GET', "/grid.php?table_id={$tA}", $cookie)['body'];
    check('E) <script> etiketi kacirildi (ham script yok)', strpos($g, '<script>alert(1)</script>') === false);

    // =======================================================================
    // F) DETAY MODALI — salt-okunur yol canli <td>'yi kopyaladigi icin bedava
    // =======================================================================
    // Panel, gizli alanlar icin data-fields JSON'unu kullanir; gorunur alanlarda
    // canli <td>'nin innerHTML'ini AYNEN kopyalar (grid-row-detail.js:186).
    // Sunucu tarafinda dogrulanabilen kisim: <td> icerigi ikonu tasiyor mu.
    $g = http_request('GET', "/grid.php?table_id={$tA}", $cookie)['body'];
    $rows = explode('data-record-id="' . $r1 . '"', $g);
    $row1 = isset($rows[1]) ? $rows[1] : '';
    check('F) Detay panelinin kopyalayacagi <td> ikonu iceriyor',
        strpos(cell_html($row1, $fUrl), 'cell-link-icon') !== false);
    check('F) Salt-okunur panelde ikon her zaman gorunur (CSS kurali var)',
        strpos(file_get_contents(__DIR__ . '/../public/assets/style.css'),
            '.grid-detail-field-value-readonly .cell-link-icon') !== false);

    // =======================================================================
    // G) REGRESYON — Excel ve Slack DUZ METIN almali (HTML SIZMAMALI)
    // =======================================================================
    foreach (array('url', 'email', 'phone') as $ft) {
        $row = array('value_text' => 'https://example.com', 'value_number' => null, 'value_date' => null, 'value_json' => null);
        check("G) cell_display_text('{$ft}') DUZ METIN dondurdu (HTML yok)",
            cell_display_text($ft, $row) === 'https://example.com', cell_display_text($ft, $row));
    }

    $viewId = (int) bcc_fetch_column('SELECT id FROM views WHERE table_id = :t ORDER BY id LIMIT 1', array(':t' => $tA));
    $xlsx = http_request('GET', "/api/view_export_xlsx.php?table_id={$tA}&view_id={$viewId}", $cookie)['body'];
    check('G) XLSX indirildi', strlen($xlsx) > 0 && substr($xlsx, 0, 2) === 'PK', 'uzunluk: ' . strlen($xlsx));
    check('G) XLSX icinde <a href / cell-link-icon YOK (HTML sizmadi)',
        strpos($xlsx, 'cell-link-icon') === false && strpos($xlsx, '<a href') === false);

    // Slack: bcc_notify_slack_new_record() gercek webhook ister; bunun yerine
    // Slack'in KULLANDIGI fonksiyonun ciktisi dogrudan dogrulaniyor (ayni yol).
    $slackRow = array('value_text' => 'https://example.com', 'value_number' => null, 'value_date' => null, 'value_json' => null);
    $slackText = cell_display_text('url', $slackRow, array(), null);
    check('G) Slack birincil-alan metni HTML icermiyor',
        strpos($slackText, '<') === false && $slackText === 'https://example.com', $slackText);

    // =======================================================================
    // H) FILTRE — empty / not_empty bos hucreleri dogru buluyor mu
    // =======================================================================
    $tH = $mkTable('H Filtre');
    $adH = $mkField($tH, 'Ad', 'single_line_text', 0);
    $uH = $mkField($tH, 'Site', 'url', 1);
    $h1 = $mkRecord($tH, 0);   // dolu
    $h2 = $mkRecord($tH, 1);   // hic hucresi yok (NULL)
    $h3 = $mkRecord($tH, 2);   // bos string ('') hucresi VAR
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
        array(':r' => $h1, ':f' => $uH, ':v' => 'https://a.com'));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
        array(':r' => $h3, ':f' => $uH, ':v' => ''));

    $countRows = function ($html) {
        return substr_count($html, 'class="grid-row"') ?: substr_count($html, 'data-record-id="');
    };
    $g = http_request('GET', "/grid.php?table_id={$tH}&filter_field_1={$uH}&filter_cond_1=empty", $cookie)['body'];
    $emptyIds = array();
    if (preg_match_all('/data-record-id="(\d+)"/', $g, $m)) { $emptyIds = array_unique($m[1]); }
    sort($emptyIds);
    check('H) empty filtresi HEM NULL HEM bos-string kaydi buldu (2 kayit)',
        $emptyIds === array((string) $h2, (string) $h3), 'bulunan: ' . implode(',', $emptyIds));

    $g = http_request('GET', "/grid.php?table_id={$tH}&filter_field_1={$uH}&filter_cond_1=not_empty", $cookie)['body'];
    $notEmptyIds = array();
    if (preg_match_all('/data-record-id="(\d+)"/', $g, $m)) { $notEmptyIds = array_unique($m[1]); }
    check('H) not_empty filtresi yalnizca gercekten dolu kaydi buldu',
        array_values($notEmptyIds) === array((string) $h1), 'bulunan: ' . implode(',', $notEmptyIds));

    // =======================================================================
    // I) MEVCUT BUG FIX — BCC_GROUP_DIR_LABELS eksik girisleri
    //    Gruplama panelinde bu tiplere gore gruplamak "Undefined index"
    //    notice'i ve BOS yon etiketleri uretiyordu.
    // =======================================================================
    $tI = $mkTable('I Gruplama');
    $adI = $mkField($tI, 'Ad', 'single_line_text', 0);
    $groupTypes = array(
        'currency' => '1 → 9', 'percent' => '1 → 9', 'rating' => 'Az → Çok',
        'autonumber' => '1 → 9', 'url' => 'A → Z', 'email' => 'A → Z', 'phone' => 'A → Z',
    );
    $pos = 1;
    $groupFieldIds = array();
    foreach ($groupTypes as $ft => $expectedLabel) {
        $groupFieldIds[$ft] = $mkField($tI, 'G_' . $ft, $ft, $pos++);
    }
    $gi1 = $mkRecord($tI, 0);
    $gi2 = $mkRecord($tI, 1);

    foreach ($groupTypes as $ft => $expectedLabel) {
        $fid = $groupFieldIds[$ft];
        $g = http_request('GET', "/grid.php?table_id={$tI}&group_field_1={$fid}&group_dir_1=asc", $cookie)['body'];
        check("I) '{$ft}' ile gruplama: 'Undefined index' notice YOK",
            stripos($g, 'Undefined index') === false && stripos($g, 'Undefined variable') === false,
            'notice bulundu');
        check("I) '{$ft}' yon etiketi dogru ('{$expectedLabel}')",
            strpos($g, '>' . $expectedLabel . '</option>') !== false, 'etiket bulunamadi');
    }

    // Koruma katmani: dizide OLMAYAN bir tip artik notice uretmemeli.
    check('I) grid.php okumasi isset() ile korundu (gelecekteki tipler icin)',
        strpos(file_get_contents(__DIR__ . '/../public/grid.php'),
            "isset(\$GLOBALS['BCC_GROUP_DIR_LABELS'][\$activeRule['field_type']])") !== false);
    check('I) BCC_GROUP_DIR_LABELS artik attachment DISINDA tum tipleri kapsiyor',
        count(array_diff(array_keys($GLOBALS['BCC_FIELD_TYPES']), array_keys($GLOBALS['BCC_GROUP_DIR_LABELS']))) === 1,
        'eksik: ' . implode(',', array_diff(array_keys($GLOBALS['BCC_FIELD_TYPES']), array_keys($GLOBALS['BCC_GROUP_DIR_LABELS']))));

    // =======================================================================
    // J) REGRESYON — mevcut tipler bozulmadi mi
    // =======================================================================
    $tJ = $mkTable('J Regresyon');
    $adJ = $mkField($tJ, 'Ad', 'single_line_text', 0);
    $nJ = $mkField($tJ, 'Sayi', 'number', 1);
    $cJ = $mkField($tJ, 'Fiyat', 'currency', 2, json_encode(array('currency_symbol' => '₺', 'decimal_places' => 2)));
    $pJ = $mkField($tJ, 'Oran', 'percent', 3, json_encode(array('decimal_places' => 0)));
    $rJ = $mkField($tJ, 'Puan', 'rating', 4, json_encode(array('max_rating' => 5)));
    $ctJ = $mkField($tJ, 'Olusturma', 'created_time', 5);
    $cbJ = $mkField($tJ, 'Olusturan', 'created_by', 6);
    $ltJ = $mkField($tJ, 'Uzun', 'long_text', 8);
    $j1 = $mkRecord($tJ, 0);

    $resp = http_request('GET', "/table_fields.php?table_id={$tJ}", $cookie);
    $csrfJ = extract_csrf($resp['body']);
    // autonumber alani GERCEK ucnoktadan olusturulur — $mkField dogrudan INSERT
    // yaptigi icin bcc_create_field()'in backfill'ini atlar ve hucre BOS kalirdi
    // (urun hatasi degil, fikstur hatasi). Kayit ZATEN var, yani bu ayni zamanda
    // Grup C2'nin backfill yolunu da tekrar dogrular.
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => $csrfJ, 'action' => 'create_field', 'table_id' => $tJ,
        'name' => 'No', 'field_type' => 'autonumber',
    ));
    $anJ = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND name = 'No'", array(':t' => $tJ));

    $resp = http_request('GET', "/grid.php?table_id={$tJ}", $cookie);
    $csrfJ = extract_csrf($resp['body']);
    $csrfMain = $csrfJ;
    $setCell($j1, $adJ, 'Metin');
    $setCell($j1, $nJ, '10');
    $setCell($j1, $cJ, '1234.5');
    $setCell($j1, $pJ, '45');
    $setCell($j1, $rJ, '5');
    $setCell($j1, $ltJ, '<b>kalin</b> ve <a href="https://x.com">link</a>');

    $g = http_request('GET', "/grid.php?table_id={$tJ}", $cookie)['body'];
    $txt = function ($fid) use ($g) {
        $c = cell_html($g, $fid);
        return trim(strip_tags($c));
    };
    check('J) REGRESYON single_line_text bozulmadi', $txt($adJ) === 'Metin', $txt($adJ));
    check('J) REGRESYON single_line_text hucresinde link ikonu YOK',
        strpos(cell_html($g, $adJ), 'cell-link-icon') === false);
    check('J) REGRESYON number "10"', $txt($nJ) === '10', $txt($nJ));
    check('J) REGRESYON currency "₺1.234,50"', $txt($cJ) === '₺1.234,50', $txt($cJ));
    check('J) REGRESYON percent "%45"', $txt($pJ) === '%45', $txt($pJ));
    check('J) REGRESYON rating 5 yildiz', substr_count(cell_html($g, $rJ), 'data-rating-star=') === 5);
    check('J) REGRESYON created_time tarih', preg_match('/\d{2}\.\d{2}\.\d{4}/', $txt($ctJ)) === 1, $txt($ctJ));
    check('J) REGRESYON created_by kullanici adi', $txt($cbJ) === 'GrupA Test Owner', $txt($cbJ));
    check('J) REGRESYON autonumber "1"', $txt($anJ) === '1', $txt($anJ));
    check('J) REGRESYON autonumber HALA salt-okunur (editable class YOK)',
        strpos(cell_html($g, $anJ), 'editable') === false);
    check('J) REGRESYON long_text zengin metni HALA HTML olarak render ediliyor',
        strpos(cell_html($g, $ltJ), '<b>kalin</b>') !== false, cell_html($g, $ltJ));
    check('J) REGRESYON long_text linki HALA <a> (whitelist genislemedi)',
        strpos(cell_html($g, $ltJ), 'href="https://x.com"') !== false);

    // Zengin metin whitelist'i GENISLEMEDI — mailto hala soyulmali.
    check('J) REGRESYON zengin metinde mailto HALA soyuluyor',
        bcc_sanitize_rich_text('<a href="mailto:a@b.com">x</a>') === 'x',
        bcc_sanitize_rich_text('<a href="mailto:a@b.com">x</a>'));
    check('J) REGRESYON zengin metinde javascript: HALA soyuluyor',
        bcc_sanitize_rich_text('<a href="javascript:alert(1)">x</a>') === 'x');

    // =======================================================================
    // K) STATIK KONTROLLER
    // =======================================================================
    $themeCss = file_get_contents(__DIR__ . '/../public/assets/theme.css');
    foreach (array('url', 'email', 'phone') as $ft) {
        check("K) theme.css: .field-type-badge--{$ft} ikonu tanimli",
            preg_match('/\.field-type-badge--' . $ft . '\b[^{]*\{[^}]*--field-icon:/', $themeCss) === 1);
    }
    $gridJs = file_get_contents(__DIR__ . '/../public/assets/grid.js');
    check('K) grid.js: ikon tiklamasi duzenlemeyi ACMIYOR (stopPropagation)',
        strpos($gridJs, "e.target.closest('.cell-link-icon')") !== false
        && strpos($gridJs, 'e.stopPropagation();') !== false);
    check('K) grid.js: ikon createElementNS ile kuruluyor (innerHTML YOK)',
        strpos($gridJs, 'buildExternalLinkIcon') !== false
        && strpos($gridJs, 'createElementNS') !== false);
    check('K) grid.js: display_link anahtarin VARLIGINA bakiyor (null da islenmeli)',
        strpos($gridJs, "hasOwnProperty.call(data, 'display_link')") !== false);
    check('K) grid.js: mobil klavye icin url/email/tel input tipleri',
        strpos($gridJs, "input.type = (type === 'phone') ? 'tel' : type;") !== false);
    $schemaPhp = file_get_contents(__DIR__ . '/../src/schema.php');
    check('K) schema.php: zengin metin link blogu KOPYALANMADI, ortak fonksiyona bagli',
        substr_count($schemaPhp, 'rel="noopener noreferrer">') === 1,
        'sayi: ' . substr_count($schemaPhp, 'rel="noopener noreferrer">'));
    check('K) DDL yok: fields/cell_values semasi degismedi (yeni kolon yok)',
        bcc_fetch_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cell_values'
                       AND COLUMN_NAME NOT IN ('id','record_id','field_id','value_text','value_number','value_date','value_json','updated_at')") === false);

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
