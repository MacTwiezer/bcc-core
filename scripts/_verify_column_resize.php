<?php
// Sutun genisligi surukle-boyutlandirma dogrulamasi.
//
// Kapsam: (A) savunmaci okuyucu/kirpici yardimcilar, (B) view_config_update.php
// ucnoktasi (iki ozelligin birbirini EZMEDIGI dahil), (C) grid.php render'i
// (opt-in davranisi: kayitli genislik YOKSA hicbir sey degismemeli),
// (D) dondurma tutamaciyla cakismama, (E) export/print regresyonu.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_column_resize.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/xlsx_reader.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'colresize.owner@bcc-test.local');
define('VIEWER_EMAIL', 'colresize.viewer@bcc-test.local');
define('TEST_PASS', 'ColResize!2026');
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

function extract_csrf_field($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function extract_csrf_meta($html)
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array('email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf_field($r['body'])));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function view_config($viewId)
{
    $raw = bcc_fetch_column('SELECT config FROM views WHERE id = :id', array(':id' => $viewId));
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

$emails = array(OWNER_EMAIL, VIEWER_EMAIL);
foreach ($emails as $e) { bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $e)); }

$cleanup = function () use ($emails) {
    foreach ($emails as $e) {
        $baseIds = array_column(bcc_fetch_all(
            'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
            array(':e' => $e)
        ), 'id');
        foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $e));
    }
};

$realBefore = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

try {
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

    $mkUser = function ($email, $name, $role) use ($teamId) {
        bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
            array(':e' => $email, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => $name));
        $uid = (int) bcc_last_insert_id();
        bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
            array(':t' => $teamId, ':u' => $uid, ':r' => $role));
        return $uid;
    };

    $ownerId = $mkUser(OWNER_EMAIL, 'ColResize Owner', 'owner');
    $mkUser(VIEWER_EMAIL, 'ColResize Viewer', 'viewer');

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'ColResize Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array(':b' => $baseId, ':n' => 'Genislik'));
    $tableId = (int) bcc_last_insert_id();

    $mkField = function ($name, $pos) use ($tableId) {
        bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)',
            array(':t' => $tableId, ':n' => $name, ':ft' => 'single_line_text', ':p' => $pos));
        return (int) bcc_last_insert_id();
    };
    $fAd = $mkField('Ad', 0);
    $fKod = $mkField('Kod', 1);
    $fNot = $mkField('Not', 2);

    for ($i = 0; $i < 4; $i++) {
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
            array(':t' => $tableId, ':p' => $i, ':u' => $ownerId));
        $rid = (int) bcc_last_insert_id();
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
            array(':r' => $rid, ':f' => $fAd, ':v' => 'Satir ' . ($i + 1)));
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
            array(':r' => $rid, ':f' => $fKod, ':v' => 'K-' . ($i + 1)));
    }

    // BASKA bir tablonun alani — sunucu whitelist'inin gercekten calistigini
    // gostermek icin (istemci uydurma bir field_id gonderirse dusmeli).
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 1)', array(':b' => $baseId, ':n' => 'Yabanci'));
    $otherTableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $otherTableId, ':n' => 'Yabanci Alan', ':ft' => 'single_line_text'));
    $foreignFieldId = (int) bcc_last_insert_id();

    $cookie = login(OWNER_EMAIL);
    check('Giris yapildi (owner)', $cookie !== null);

    // grid.php ilk acilis: view lazy olusuyor
    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    check('grid.php 200', $g['status'] === 200, 'HTTP ' . $g['status']);
    $viewId = (int) bcc_fetch_column('SELECT id FROM views WHERE table_id = :t ORDER BY id LIMIT 1', array(':t' => $tableId));
    check('Varsayilan gorunum olustu', $viewId > 0);
    $csrf = extract_csrf_meta($g['body']);
    check('CSRF meta okundu', $csrf !== null);

    $assetsDir = __DIR__ . '/../public/assets';

    // =====================================================================
    // A) SAVUNMACI YARDIMCILAR (bcc_get_column_widths / sanitize / clamp)
    // =====================================================================
    echo "\n--- A) Savunmaci yardimcilar ---\n";
    $vf = array(array('id' => $fAd), array('id' => $fKod), array('id' => $fNot));

    check('A) NULL config -> bos', bcc_get_column_widths(null, $vf) === array());
    check('A) bos dizgi -> bos', bcc_get_column_widths('', $vf) === array());
    check('A) bozuk JSON -> bos', bcc_get_column_widths('{bozuk', $vf) === array());
    check('A) column_widths anahtari yok -> bos',
        bcc_get_column_widths('{"frozen_column_count":2}', $vf) === array());
    check('A) column_widths dizi degil -> bos',
        bcc_get_column_widths('{"column_widths":"abc"}', $vf) === array());

    $json = json_encode(array('column_widths' => array('row' => 120, 'f' . $fAd => 300)));
    $read = bcc_get_column_widths($json, $vf);
    check('A) gecerli harita okunuyor', $read === array('row' => 120, 'f' . $fAd => 300), json_encode($read));

    $json = json_encode(array('column_widths' => array('f' . $fAd => 10, 'f' . $fKod => 5000)));
    $read = bcc_get_column_widths($json, $vf);
    check('A) min alti 80e, max ustu 800e kirpiliyor',
        $read === array('f' . $fAd => 80, 'f' . $fKod => 800), json_encode($read));

    $json = json_encode(array('column_widths' => array('f' . $fAd => 'genis', 'f' . $fKod => 200)));
    $read = bcc_get_column_widths($json, $vf);
    check('A) sayisal olmayan deger atlaniyor', $read === array('f' . $fKod => 200), json_encode($read));

    // Gizli/silinmis alanin eski genisligi colgroup'u kaydirmasin
    $json = json_encode(array('column_widths' => array('f' . $fAd => 300, 'f' . $fNot => 250)));
    $read = bcc_get_column_widths($json, array(array('id' => $fAd)));
    check('A) gorunur olmayan alan filtreleniyor', $read === array('f' . $fAd => 300), json_encode($read));

    $fieldsById = array($fAd => true, $fKod => true, $fNot => true);
    $san = bcc_sanitize_column_widths(array('row' => 100, 'f' . $fAd => 200, 'f' . $foreignFieldId => 300, 'xyz' => 150, 'f0' => 90), $fieldsById);
    check('A) sanitize: yalnizca row + BU tablonun alanlari kaliyor',
        $san === array('row' => 100, 'f' . $fAd => 200), json_encode($san));
    check('A) sanitize: dizi olmayan girdi -> bos', bcc_sanitize_column_widths('abc', $fieldsById) === array());
    check('A) clamp sinirlari', bcc_clamp_column_width(1) === 80 && bcc_clamp_column_width(9999) === 800 && bcc_clamp_column_width(250) === 250);

    // =====================================================================
    // B) UCNOKTA
    // =====================================================================
    echo "\n--- B) view_config_update.php ---\n";

    // Once dondurma sayisini 2 yap (asagidaki "ezilmiyor mu" testinin zemini)
    $r = http_request('POST', '/api/view_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $viewId, 'frozen_column_count' => 2, 'state_query_string' => '',
    ));
    check('B) frozen_column_count yazildi', $r['status'] === 200 && view_config($viewId)['frozen_column_count'] === 2,
        $r['body']);

    $widths = array('row' => 120, 'f' . $fAd => 300, 'f' . $fKod => 150);
    $r = http_request('POST', '/api/view_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $viewId, 'column_widths' => json_encode($widths), 'state_query_string' => '',
    ));
    $cfg = view_config($viewId);
    check('B) column_widths yazildi', $r['status'] === 200 && $cfg['column_widths'] == $widths,
        json_encode(isset($cfg['column_widths']) ? $cfg['column_widths'] : null));

    // REGRESYON — bulunan gercek bug: ucnokta frozen_column_count'u isset
    // kontrolu olmadan `: 1` varsayilaniyla HER ISTEKTE yaziyordu; yalnizca
    // genislik gonderen bu istek dondurma ayarini sessizce 1'e dusururdu.
    check('B) YALNIZCA genislik gonderince frozen_column_count EZILMIYOR (bug regresyonu)',
        isset($cfg['frozen_column_count']) && $cfg['frozen_column_count'] === 2,
        'frozen=' . (isset($cfg['frozen_column_count']) ? $cfg['frozen_column_count'] : 'YOK'));

    $r = http_request('POST', '/api/view_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $viewId, 'frozen_column_count' => 1, 'state_query_string' => '',
    ));
    $cfg = view_config($viewId);
    check('B) tersi de dogru: yalnizca dondurma gonderince genislikler EZILMIYOR',
        $cfg['frozen_column_count'] === 1 && $cfg['column_widths'] == $widths);

    // Istemciye guvenilmiyor: sunucu yeniden kirpiyor
    $r = http_request('POST', '/api/view_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $viewId,
        'column_widths' => json_encode(array('f' . $fAd => 5, 'f' . $fKod => 4000, 'f' . $foreignFieldId => 200)),
        'state_query_string' => '',
    ));
    $cfg = view_config($viewId);
    check('B) sunucu min/max kirpmasi (istemci 5/4000 gonderdi)',
        $cfg['column_widths'] == array('f' . $fAd => 80, 'f' . $fKod => 800),
        json_encode($cfg['column_widths']));
    check('B) baska tablonun field_id si sunucuda dusuruldu',
        !isset($cfg['column_widths']['f' . $foreignFieldId]));

    // Bos harita = otomatik yerlesime don (anahtar SILINIR)
    $r = http_request('POST', '/api/view_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $viewId, 'column_widths' => '{}', 'state_query_string' => '',
    ));
    $cfg = view_config($viewId);
    check('B) bos harita -> column_widths anahtari SILINIYOR (otomatik yerlesime donus)',
        !isset($cfg['column_widths']) && isset($cfg['frozen_column_count']),
        json_encode($cfg));

    $r = http_request('POST', '/api/view_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $viewId, 'state_query_string' => '',
    ));
    check('B) hicbir ayar gonderilmezse 400', $r['status'] === 400, 'HTTP ' . $r['status']);

    $r = http_request('POST', '/api/view_config_update.php', $cookie, array(
        'view_id' => $viewId, 'column_widths' => json_encode(array('f' . $fAd => 200)),
    ));
    check('B) CSRF yoksa 403', $r['status'] === 403, 'HTTP ' . $r['status']);

    $viewerCookie = login(VIEWER_EMAIL);
    $vg = http_request('GET', '/grid.php?table_id=' . $tableId, $viewerCookie);
    $viewerCsrf = extract_csrf_meta($vg['body']);
    $r = http_request('POST', '/api/view_config_update.php', $viewerCookie, array(
        'csrf_token' => $viewerCsrf, 'view_id' => $viewId, 'column_widths' => json_encode(array('f' . $fAd => 200)),
    ));
    check('B) viewer 403 (editor rolu gerekiyor)', $r['status'] === 403, 'HTTP ' . $r['status']);
    check('B) viewer in denemesi config i degistirmedi', !isset(view_config($viewId)['column_widths']));

    $auditCount = (int) bcc_fetch_column(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'view.config_update' AND entity_id = :v",
        array(':v' => $viewId)
    );
    check('B) audit satirlari yazildi', $auditCount >= 4, 'sayi=' . $auditCount);

    // =====================================================================
    // C) RENDER — OPT-IN davranisi
    // =====================================================================
    echo "\n--- C) grid.php render (opt-in) ---\n";

    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    $html = $g['body'];
    check('C) genislik YOKKEN <colgroup> BASILMIYOR', strpos($html, '<colgroup>') === false);
    check('C) genislik YOKKEN grid-has-col-widths sinifi YOK', strpos($html, 'grid-has-col-widths') === false);
    check('C) genislik YOKKEN tabloya inline width YAZILMIYOR',
        preg_match('/<table class="grid[^"]*"\s+style="width:/', $html) === 0);
    check('C) th lerde data-col-key var (her zaman)',
        substr_count($html, 'data-col-key="f') >= 3);
    check('C) alan adi .grid-th-label ile sariliyor', substr_count($html, 'class="grid-th-label"') === 3);
    check('C) grid-column-resize.js sayfaya dahil',
        preg_match('#<script src="/assets/grid-column-resize\.js\?v=\d+" defer>#', $html) === 1);
    check('C) resize script i freeze scriptinden SONRA yukleniyor',
        strpos($html, 'grid-freeze-columns.js') < strpos($html, 'grid-column-resize.js'));
    check('C) sinirlar istemciye sunucudan geciyor',
        strpos($html, 'var BCC_MIN_COLUMN_WIDTH = 80;') !== false
        && strpos($html, 'var BCC_MAX_COLUMN_WIDTH = 800;') !== false);

    // Simdi genislik kaydet ve yeniden render et
    $widths = array('row' => 120, 'f' . $fAd => 300, 'f' . $fKod => 150);
    http_request('POST', '/api/view_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $viewId, 'column_widths' => json_encode($widths), 'state_query_string' => '',
    ));

    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    $html = $g['body'];
    check('C) genislik VARKEN grid-has-col-widths sinifi var', strpos($html, 'grid-has-col-widths') !== false);
    check('C) <colgroup> basiliyor', strpos($html, '<colgroup>') !== false);

    preg_match('#<colgroup>(.*?)</colgroup>#s', $html, $cg);
    $colCount = isset($cg[1]) ? substr_count($cg[1], '<col ') : 0;
    // rownum + 3 gorunur alan + "+" sutunu (owner) = 5
    check('C) col sayisi = rownum + gorunur alanlar + "+" sutunu', $colCount === 5, 'col=' . $colCount);
    check('C) kaydedilen genislikler colgroup a yansiyor',
        strpos($cg[1], 'width: 120px') !== false
        && strpos($cg[1], 'width: 300px') !== false
        && strpos($cg[1], 'width: 150px') !== false);
    check('C) haritada OLMAYAN alan varsayilan 180px aliyor',
        strpos($cg[1], 'width: 180px') !== false);
    // toplam = 120 + 300 + 150 + 180 (Not) + 40 ("+") = 790
    check('C) tabloya toplam genislik inline yaziliyor',
        strpos($html, 'style="width: 790px;"') !== false,
        preg_match('/<table class="grid[^"]*"\s+style="([^"]*)"/', $html, $tm) ? $tm[1] : 'YOK');

    // Gizli sutun colgroup a girmemeli
    $gh = http_request('GET', '/grid.php?table_id=' . $tableId . '&hidden_fields=' . $fKod, $cookie);
    preg_match('#<colgroup>(.*?)</colgroup>#s', $gh['body'], $cg2);
    check('C) gizli alan colgroup a GIRMIYOR',
        isset($cg2[1]) && substr_count($cg2[1], '<col ') === 4 && strpos($cg2[1], 'width: 150px') === false,
        isset($cg2[1]) ? trim(preg_replace('/\s+/', ' ', $cg2[1])) : 'YOK');

    // =====================================================================
    // D) DONDURMA TUTAMACIYLA CAKISMAMA
    // =====================================================================
    echo "\n--- D) Iki tutamac ayrisiyor mu ---\n";
    $styleCss = file_get_contents($assetsDir . '/style.css');
    $exportCss = file_get_contents($assetsDir . '/grid-export.css');
    $shellCss = file_get_contents($assetsDir . '/grid-shell.css');
    $resizeJs = file_get_contents($assetsDir . '/grid-column-resize.js');
    $freezeJs = file_get_contents($assetsDir . '/grid-freeze-columns.js');

    check('D) genislik tutamaci cursor:col-resize',
        preg_match('/\.grid-col-resize-handle \{[^}]*cursor: col-resize;/s', $styleCss) === 1);
    check('D) dondurma tutamaci HALA cursor:grab (degistirilmedi)',
        preg_match('/\.grid-freeze-handle \{[^}]*cursor: grab;/s', $styleCss) === 1);
    check('D) iki tutamac farkli renkte cizgi kullaniyor',
        preg_match('/\.grid-freeze-handle::after \{[^}]*background: var\(--bcc-accent\);/s', $styleCss) === 1
        && preg_match('/\.grid-col-resize-handle::after \{[^}]*background: var\(--bcc-border-strong\);/s', $styleCss) === 1);
    check('D) donmus kenarda genislik tutamaci kaydiriliyor (ust uste binmiyor)',
        strpos($styleCss, '.grid-frozen-edge .grid-col-resize-handle { right: 9px; }') !== false);
    check('D) genislik tutamaci z-index dondurmanin ALTINDA',
        preg_match('/\.grid-col-resize-handle \{[^}]*z-index: 9;/s', $styleCss) === 1
        && preg_match('/\.grid-freeze-handle \{[^}]*z-index: 10;/s', $styleCss) === 1);
    check('D) grid-freeze-columns.js e DOKUNULMADI (genislik kodu icermiyor)',
        stripos($freezeJs, 'col-resize') === false && stripos($freezeJs, 'column_widths') === false);
    check('D) resize dosyasi ORTAK surukleme iskeletini kullaniyor (kopya yok)',
        strpos($resizeJs, 'bcc_bindColumnDrag') !== false
        && strpos($resizeJs, 'addEventListener(\'mousedown\'') === false);
    check('D) resize, dondurma ofsetlerini ortak kancayla tazeliyor',
        strpos($resizeJs, 'window.BCC_reapplyFreeze') !== false);
    check('D) resize, frozen_column_count GONDERMIYOR',
        strpos($resizeJs, 'frozen_column_count:') === false);

    // =====================================================================
    // E) EXPORT / PRINT REGRESYONU
    // =====================================================================
    echo "\n--- E) Export / print ---\n";
    check('E) export CSS her IKI tutamaci da gizliyor',
        strpos($exportCss, '.grid-col-resize-handle,') !== false
        && strpos($exportCss, '.grid-freeze-handle {') !== false);
    check('E) print te sabit yerlesim cozuluyor (table-layout:auto)',
        preg_match('/table\.grid\.grid-has-col-widths \{\s*table-layout: auto !important;/', $shellCss) === 1);
    check('E) print te col genislikleri sifirlaniyor',
        preg_match('/table\.grid col \{\s*width: auto !important;/', $shellCss) === 1);

    // Veri kapsami: genislik ayarliyken Excel ile grid ayni kalmali
    $r = http_request('GET', '/api/view_export_xlsx.php?table_id=' . $tableId, $cookie);
    $tmp = sys_get_temp_dir() . '/bcc_colresize_' . getmypid() . '.xlsx';
    file_put_contents($tmp, $r['body']);
    $rows = bcc_xlsx_read_first_sheet($tmp);
    @unlink($tmp);
    check('E) genislik ayarliyken xlsx basliklari degismedi',
        is_array($rows) && $rows[0] === array('Ad', 'Kod', 'Not'),
        is_array($rows) ? implode('|', (array) $rows[0]) : 'okunamadi');
    check('E) genislik ayarliyken xlsx satir sayisi degismedi',
        is_array($rows) && count($rows) - 1 === 4, is_array($rows) ? (count($rows) - 1) : 'okunamadi');

    preg_match_all('/<tr\s[^>]*data-record-id="(\d+)"/', $html, $rm);
    check('E) genislik ayarliyken grid satir sayisi degismedi', count($rm[1]) === 4, 'satir=' . count($rm[1]));

    // Kapsam siniri
    foreach (array('kanban.php', 'form.php') as $other) {
        $src = file_get_contents(__DIR__ . '/../public/' . $other);
        check("E) {$other} sutun genisligi kodu ICERMIYOR",
            stripos($src, 'col-resize') === false && stripos($src, 'column_widths') === false
            && stripos($src, 'grid-column-resize') === false);
    }
    check('E) kanban.js dokunulmadi',
        stripos(file_get_contents($assetsDir . '/kanban.js'), 'column_widths') === false);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

echo "\n--- Gercek base (id " . REAL_BASE_ID . ") dokunulmadi mi ---\n";
$realAfter = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);
foreach ($realBefore as $k => $before) {
    check("Base " . REAL_BASE_ID . " {$k} sayisi degismedi ({$before})", $realAfter[$k] === $before,
        "once={$before} sonra={$realAfter[$k]}");
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
