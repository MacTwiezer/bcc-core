<?php
// Grup View-Kanban dogrulamasi.
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_view_kanban.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'kanban.owner@bcc-test.local');
define('VIEWER_EMAIL', 'kanban.viewer@bcc-test.local');
define('COMMENTER_EMAIL', 'kanban.commenter@bcc-test.local');
define('TEST_PASS', 'KanbanTest!2026');

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

function http_request($method, $path, $cookie = null, $postFields = null, $follow = true)
{
    $headers = array();
    if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));
    if (!$follow) { $options['http']['follow_location'] = 0; $options['http']['max_redirects'] = 1; }
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }
    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $status = 0; $newCookie = null; $location = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
            if (stripos($h, 'Set-Cookie:') === 0) { $p = explode(';', substr($h, 11)); $newCookie = trim($p[0]); }
            if (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        }
    }

    return array('body' => (string) $body, 'cookie' => $newCookie, 'status' => $status, 'location' => $location);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array('email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf($r['body'])));
    return $r['cookie'] ? $r['cookie'] : $c;
}

$emails = array(OWNER_EMAIL, VIEWER_EMAIL, COMMENTER_EMAIL);
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

    $ownerId = $mkUser(OWNER_EMAIL, 'Kanban Owner', 'owner');
    $viewerId = $mkUser(VIEWER_EMAIL, 'Kanban Viewer', 'viewer');
    $commenterId = $mkUser(COMMENTER_EMAIL, 'Kanban Commenter', 'commenter');

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'Kanban Test', ':u' => $ownerId));
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
    $mkRecord = function ($tableId, $pos) use ($ownerId) {
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
            array(':t' => $tableId, ':p' => $pos, ':u' => $ownerId));
        return (int) bcc_last_insert_id();
    };
    $setCell = function ($rid, $fid, $col, $val) {
        bcc_execute("INSERT INTO cell_values (record_id, field_id, {$col}) VALUES (:r, :f, :v)",
            array(':r' => $rid, ':f' => $fid, ':v' => $val));
    };

    // --- Tablo: single_select'li -------------------------------------------
    $tA = $mkTable('Kanban Tablosu');
    $fAd = $mkField($tA, 'Ad', 'single_line_text', 0);
    $selOpts = json_encode(array('choices' => array('Yapilacak', 'Devam', 'Bitti')), JSON_UNESCAPED_UNICODE);
    $fDurum = $mkField($tA, 'Durum', 'single_select', 1, $selOpts);
    $fNot = $mkField($tA, 'Not', 'single_line_text', 2);
    $fCoklu = $mkField($tA, 'Etiket', 'multiple_select', 3, json_encode(array('choices' => array('X', 'Y'))));

    $r1 = $mkRecord($tA, 0); $setCell($r1, $fAd, 'value_text', 'Kayit 1'); $setCell($r1, $fDurum, 'value_text', 'Yapilacak'); $setCell($r1, $fNot, 'value_text', 'not1');
    $r2 = $mkRecord($tA, 1); $setCell($r2, $fAd, 'value_text', 'Kayit 2'); $setCell($r2, $fDurum, 'value_text', 'Bitti');
    $r3 = $mkRecord($tA, 2); $setCell($r3, $fAd, 'value_text', 'Kayit 3'); // Durum hucresi YOK -> Atanmamis
    $r4 = $mkRecord($tA, 3); $setCell($r4, $fAd, 'value_text', 'Kayit 4'); $setCell($r4, $fDurum, 'value_text', 'ESKI_SECENEK'); // choices'ta YOK
    $r5 = $mkRecord($tA, 4); $setCell($r5, $fAd, 'value_text', 'Silinmis'); $setCell($r5, $fDurum, 'value_text', 'Devam');
    bcc_execute('UPDATE records SET deleted_at = NOW(), deleted_by = :u WHERE id = :r', array(':u' => $ownerId, ':r' => $r5));

    $cookie = login(OWNER_EMAIL);
    check('Giris yapildi (owner)', $cookie !== null);

    // =======================================================================
    // A) ORTAK TEMEL
    // =======================================================================
    echo "\n--- A) Ortak temel ---\n";
    check('A) BCC_VIEW_TYPES kanban iceriyor', isset($GLOBALS['BCC_VIEW_TYPES']['kanban']));
    check('A) BCC_VIEW_ROUTES haritasi var', isset($GLOBALS['BCC_VIEW_ROUTES']) && count($GLOBALS['BCC_VIEW_ROUTES']) === 3,
        isset($GLOBALS['BCC_VIEW_ROUTES']) ? implode(',', array_keys($GLOBALS['BCC_VIEW_ROUTES'])) : 'YOK');
    check('A) route(kanban) -> kanban.php', bcc_view_route_for('kanban', 1, 2) === '/kanban.php?table_id=1&view_id=2');
    check('A) bilinmeyen tur grid e duser', bcc_view_route_for('calendar', 1, 2) === '/grid.php?table_id=1&view_id=2');
    check('A) sadece single_select sutunlanabilir',
        bcc_field_allowed_for_kanban('single_select') === true
        && bcc_field_allowed_for_kanban('multiple_select') === false
        && bcc_field_allowed_for_kanban('checkbox') === false
        && bcc_field_allowed_for_kanban('user') === false);

    $themeCss = file_get_contents(__DIR__ . '/../public/assets/theme.css');
    foreach (array_keys($GLOBALS['BCC_VIEW_TYPES']) as $vt) {
        check("A) theme.css .view-type-badge--{$vt} ikonu",
            preg_match('/\.view-type-badge--' . $vt . '\b[^{]*\{[^}]*--view-icon:/', $themeCss) === 1);
    }

    // =======================================================================
    // B) OLUSTURMA + VARSAYILAN ALAN
    // =======================================================================
    echo "\n--- B) Kanban olusturma ---\n";
    $g = http_request('GET', "/grid.php?table_id={$tA}", $cookie);
    $csrf = extract_csrf($g['body']);
    check('B) Tip secicide kanban var', strpos($g['body'], 'data-view-type="kanban"') !== false);

    $resp = http_request('POST', '/api/view_create.php', $cookie, array('csrf_token' => $csrf, 'table_id' => $tA, 'view_type' => 'kanban'));
    $j = json_decode($resp['body'], true);
    check('B) Kanban gorunumu olusturuldu', is_array($j) && !empty($j['ok']), $resp['body']);
    $kanbanViewId = (int) $j['view_id'];
    check('B) Ad "Kanban 1"', $j['name'] === 'Kanban 1', $j['name']);
    check('B) redirect_url kanban.php', strpos($j['redirect_url'], '/kanban.php') === 0, $j['redirect_url']);

    $cfg = bcc_kanban_config_from_view(bcc_fetch_one('SELECT config FROM views WHERE id = :i', array(':i' => $kanbanViewId)));
    check('B) Varsayilan kanban_field_id = ilk single_select', $cfg['kanban_field_id'] === $fDurum,
        'beklenen ' . $fDurum . ' bulunan ' . $cfg['kanban_field_id']);

    // =======================================================================
    // C) YONLENDIRME
    // =======================================================================
    echo "\n--- C) Yonlendirme ---\n";
    $resp = http_request('GET', "/grid.php?table_id={$tA}&view_id={$kanbanViewId}", $cookie, null, false);
    check('C) grid.php -> 302 kanban.php',
        $resp['status'] === 302 && strpos((string) $resp['location'], '/kanban.php') !== false,
        'durum ' . $resp['status'] . ' konum ' . $resp['location']);

    // =======================================================================
    // D) SUTUNLAR
    // =======================================================================
    echo "\n--- D) Sutunlar ---\n";
    $kb = http_request('GET', "/kanban.php?table_id={$tA}&view_id={$kanbanViewId}", $cookie);
    check('D) kanban.php acildi', $kb['status'] === 200, 'durum ' . $kb['status']);

    preg_match_all('/data-column-value="([^"]*)"/', $kb['body'], $cm);
    $colValues = isset($cm[1]) ? $cm[1] : array();
    check('D) Sutunlar: Atanmamis EN BASTA + choices sirasi',
        $colValues === array('', 'Yapilacak', 'Devam', 'Bitti'), implode(' | ', $colValues));

    $cardOf = function ($html, $rid) {
        if (preg_match('/<article[^>]*data-record-id="' . $rid . '"[^>]*>(.*?)<\/article>/s', $html, $m)) { return $m[0]; }
        return '';
    };
    $columnOf = function ($html, $rid) {
        // Kartin icinde bulundugu sutunun data-column-value'su
        $parts = preg_split('/<section class="kanban-column"[^>]*data-column-value="([^"]*)"/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i < count($parts); $i += 2) {
            if (strpos($parts[$i + 1], 'data-record-id="' . $rid . '"') !== false) { return $parts[$i]; }
        }
        return null;
    };

    check('D) Kayit 1 -> Yapilacak sutununda', $columnOf($kb['body'], $r1) === 'Yapilacak', var_export($columnOf($kb['body'], $r1), true));
    check('D) Kayit 2 -> Bitti sutununda', $columnOf($kb['body'], $r2) === 'Bitti', var_export($columnOf($kb['body'], $r2), true));
    check('D) Kayit 3 (hucresi YOK) -> Atanmamis', $columnOf($kb['body'], $r3) === '', var_export($columnOf($kb['body'], $r3), true));
    check('D) Kayit 4 (choices DISI deger) -> Atanmamis', $columnOf($kb['body'], $r4) === '', var_export($columnOf($kb['body'], $r4), true));
    check('D) Kayit 4 HAM DEGERI kartta gorunuyor', strpos($cardOf($kb['body'], $r4), 'ESKI_SECENEK') !== false, $cardOf($kb['body'], $r4));
    check('D) SOFT-DELETE edilmis kayit tahtada YOK', strpos($kb['body'], 'data-record-id="' . $r5 . '"') === false);
    check('D) Kayit sayisi 4 (silinmis haric)', strpos($kb['body'], '>4 kayıt<') !== false || substr_count($kb['body'], 'data-kanban-card') === 4,
        'kart sayisi: ' . substr_count($kb['body'], 'data-kanban-card'));

    // =======================================================================
    // E) KART ALANLARI
    // =======================================================================
    echo "\n--- E) Kart alanlari ---\n";
    check('E) Varsayilan: yalnizca birincil alan (Not gorunmuyor)',
        strpos($cardOf($kb['body'], $r1), 'not1') === false, $cardOf($kb['body'], $r1));

    $resp = http_request('POST', '/api/kanban_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $kanbanViewId,
        'kanban_field_id' => $fDurum, 'kanban_card_fields' => array($fNot, $fAd, $fDurum, 99999),
    ));
    check('E) Kart alanlari kaydedildi', !empty(json_decode($resp['body'], true)['ok']), $resp['body']);
    $cfg = bcc_kanban_config_from_view(bcc_fetch_one('SELECT config FROM views WHERE id = :i', array(':i' => $kanbanViewId)));
    check('E) Birincil alan/sutun alani/yabanci id ELENDI', $cfg['kanban_card_fields'] === array($fNot),
        implode(',', $cfg['kanban_card_fields']));

    $kb2 = http_request('GET', "/kanban.php?table_id={$tA}&view_id={$kanbanViewId}", $cookie);
    check('E) Kartta artik Not alani gorunuyor', strpos($cardOf($kb2['body'], $r1), 'not1') !== false);

    // config'in DIGER anahtarlari ezilmedi mi
    bcc_update_view_config($kanbanViewId, array('frozen_column_count' => 3));
    http_request('POST', '/api/kanban_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $kanbanViewId, 'kanban_field_id' => $fDurum,
    ));
    $raw = json_decode(bcc_fetch_column('SELECT config FROM views WHERE id = :i', array(':i' => $kanbanViewId)), true);
    check('E) Diger config anahtarlari EZILMEDI (frozen_column_count durdu)',
        isset($raw['frozen_column_count']) && (int) $raw['frozen_column_count'] === 3, json_encode($raw));

    // =======================================================================
    // F) SURUKLE-BIRAK = cell_update.php
    // =======================================================================
    echo "\n--- F) Kart tasima ---\n";
    $before = bcc_fetch_one('SELECT updated_at, updated_by FROM records WHERE id = :r', array(':r' => $r1));
    sleep(1); // updated_at farkinin gorunmesi icin
    $resp = http_request('POST', '/api/cell_update.php', $cookie, array(
        'csrf_token' => $csrf, 'record_id' => $r1, 'field_id' => $fDurum, 'value' => 'Bitti',
    ));
    $j = json_decode($resp['body'], true);
    check('F) Kart tasima istegi basarili', is_array($j) && !empty($j['ok']), $resp['body']);
    check('F) DB degeri guncellendi',
        bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $r1, ':f' => $fDurum)) === 'Bitti');
    $after = bcc_fetch_one('SELECT updated_at, updated_by FROM records WHERE id = :r', array(':r' => $r1));
    check('F) updated_at degisti', $after['updated_at'] !== $before['updated_at'], $before['updated_at'] . ' -> ' . $after['updated_at']);
    check('F) updated_by owner oldu', (int) $after['updated_by'] === $ownerId, var_export($after['updated_by'], true));
    check('F) Yanitta display_chips var (rozet yeniden cizimi)', isset($j['display_chips']), json_encode($j));

    $resp = http_request('POST', '/api/cell_update.php', $cookie, array(
        'csrf_token' => $csrf, 'record_id' => $r1, 'field_id' => $fDurum, 'value' => 'OLMAYAN_SECENEK',
    ));
    check('F) GECERSIZ secenek 422 ile REDDEDILDI', $resp['status'] === 422, 'durum ' . $resp['status'] . ' ' . $resp['body']);
    check('F) Reddedilen deger DB ye yazilmadi',
        bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $r1, ':f' => $fDurum)) === 'Bitti');

    // =======================================================================
    // G) YETKI
    // =======================================================================
    echo "\n--- G) Yetki ---\n";
    foreach (array('viewer' => VIEWER_EMAIL, 'commenter' => COMMENTER_EMAIL) as $roleName => $email) {
        $rc = login($email);
        $page = http_request('GET', "/kanban.php?table_id={$tA}&view_id={$kanbanViewId}", $rc);
        check("G) {$roleName} tahtayi GOREBILIYOR", $page['status'] === 200, 'durum ' . $page['status']);
        check("G) {$roleName} icin data-can-edit=0", strpos($page['body'], 'data-can-edit="0"') !== false);
        check("G) {$roleName} icin Sutunlama paneli YOK", strpos($page['body'], 'data-kanban-settings') === false);
        check("G) {$roleName} kartlari surukleyemez (is-draggable YOK)", strpos($page['body'], 'is-draggable') === false);

        // BACKEND BYPASS
        $rcsrf = extract_csrf($page['body']);
        $resp = http_request('POST', '/api/cell_update.php', $rc, array(
            'csrf_token' => $rcsrf, 'record_id' => $r2, 'field_id' => $fDurum, 'value' => 'Devam',
        ));
        check("G) {$roleName} backend bypass ENGELLENDI", $resp['status'] === 403 || $resp['status'] === 401,
            'durum ' . $resp['status'] . ' ' . substr($resp['body'], 0, 120));
        check("G) {$roleName} bypass sonrasi DB degismedi",
            bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $r2, ':f' => $fDurum)) === 'Bitti');

        $resp = http_request('POST', '/api/kanban_config_update.php', $rc, array(
            'csrf_token' => $rcsrf, 'view_id' => $kanbanViewId, 'kanban_field_id' => $fDurum,
        ));
        check("G) {$roleName} sutunlama ayarini DEGISTIREMEZ", $resp['status'] === 403, 'durum ' . $resp['status']);
    }

    $cookie = login(OWNER_EMAIL);

    // =======================================================================
    // H) AYAR DOGRULAMASI
    // =======================================================================
    echo "\n--- H) Ayar dogrulamasi ---\n";
    $g = http_request('GET', "/kanban.php?table_id={$tA}&view_id={$kanbanViewId}", $cookie);
    $csrf = extract_csrf($g['body']);

    $resp = http_request('POST', '/api/kanban_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $kanbanViewId, 'kanban_field_id' => $fCoklu,
    ));
    check('H) multiple_select ile sutunlama REDDEDILDI (422)', $resp['status'] === 422, 'durum ' . $resp['status'] . ' ' . $resp['body']);

    $resp = http_request('POST', '/api/kanban_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $kanbanViewId, 'kanban_field_id' => 999999,
    ));
    check('H) Yabanci field_id REDDEDILDI (422)', $resp['status'] === 422, 'durum ' . $resp['status']);

    // grid gorunumune kanban ayari yazilamaz
    $gridViewId = (int) bcc_fetch_column("SELECT id FROM views WHERE table_id = :t AND view_type = 'grid' ORDER BY id LIMIT 1", array(':t' => $tA));
    $resp = http_request('POST', '/api/kanban_config_update.php', $cookie, array(
        'csrf_token' => $csrf, 'view_id' => $gridViewId, 'kanban_field_id' => $fDurum,
    ));
    check('H) grid gorunumune kanban ayari yazilamaz (422)', $resp['status'] === 422, 'durum ' . $resp['status']);

    // =======================================================================
    // I) BOS DURUM
    // =======================================================================
    echo "\n--- I) Bos durum (single_select YOK) ---\n";
    $tB = $mkTable('Secimsiz Tablo');
    $mkField($tB, 'Ad', 'single_line_text', 0);
    $gb = http_request('GET', "/grid.php?table_id={$tB}", $cookie);
    $csrfB = extract_csrf($gb['body']);
    $resp = http_request('POST', '/api/view_create.php', $cookie, array('csrf_token' => $csrfB, 'table_id' => $tB, 'view_type' => 'kanban'));
    $jb = json_decode($resp['body'], true);
    check('I) single_select YOKKEN Kanban yine olusturulabildi', is_array($jb) && !empty($jb['ok']), $resp['body']);
    $emptyViewId = (int) $jb['view_id'];
    $cfgB = bcc_kanban_config_from_view(bcc_fetch_one('SELECT config FROM views WHERE id = :i', array(':i' => $emptyViewId)));
    check('I) kanban_field_id 0 (secilmemis)', $cfgB['kanban_field_id'] === 0, (string) $cfgB['kanban_field_id']);

    $eb = http_request('GET', "/kanban.php?table_id={$tB}&view_id={$emptyViewId}", $cookie);
    check('I) Bos durum sayfasi 200', $eb['status'] === 200, 'durum ' . $eb['status']);
    check('I) Yonlendirici mesaj var', strpos($eb['body'], 'Tekli seçim') !== false);
    check('I) "alan olustur" baglantisi var', strpos($eb['body'], '/table_fields.php?table_id=' . $tB) !== false);
    check('I) Tahta render EDILMEDI', strpos($eb['body'], 'data-kanban-board') === false);

    // =======================================================================
    // J) STATIK KONTROLLER
    // =======================================================================
    echo "\n--- J) Statik kontroller ---\n";
    $kanbanJs = file_get_contents(__DIR__ . '/../public/assets/kanban.js');
    check('J) Surukleme ortak iskeleti kullaniyor (bcc_bindColumnDrag)',
        strpos($kanbanJs, 'window.bcc_bindColumnDrag') !== false);
    check('J) Tasima cell_update.php ye gidiyor (yeni endpoint YOK)',
        strpos($kanbanJs, "/api/cell_update.php") !== false);
    check('J) Kart tiklamasi DERIN LINK (grid.php?record_id)',
        strpos($kanbanJs, "'/grid.php?table_id='") !== false && strpos($kanbanJs, 'record_id=') !== false);
    check('J) "kanban_move" gibi ozel bir endpoint OLUSTURULMADI',
        !is_file(__DIR__ . '/../public/api/kanban_move.php') && !is_file(__DIR__ . '/../public/api/card_move.php'));

    $schemaSrc = file_get_contents(__DIR__ . '/../src/schema.php');
    check('J) bcc_update_view_config ortak yardimcisi var', strpos($schemaSrc, 'function bcc_update_view_config') !== false);
    check('J) bcc_config_field_id_list ortak yardimcisi var', strpos($schemaSrc, 'function bcc_config_field_id_list') !== false);
    $vcu = file_get_contents(__DIR__ . '/../public/api/view_config_update.php');
    check('J) view_config_update ortak yardimciya bagli', strpos($vcu, 'bcc_update_view_config(') !== false);
    $fe = file_get_contents(__DIR__ . '/../public/form_edit.php');
    check('J) form_edit ortak yardimciya bagli', strpos($fe, 'bcc_update_view_config(') !== false);

    check('J) DDL YOK: views semasi degismedi',
        bcc_fetch_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'views'
                       AND COLUMN_NAME LIKE 'kanban%'") === false);

    echo "\n";
} catch (Exception $e) {
    echo "\nISTISNA: " . $e->getMessage() . "\n";
    $results[] = false;
}

$cleanup();
echo "Temizlik tamam (test kullanicilari/base'i silindi).\n";

$passed = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($passed === $total ? 'GECTI' : 'KALDI') . " ({$passed}/{$total})\n";
echo "==================================\n";
exit($passed === $total ? 0 : 1);
