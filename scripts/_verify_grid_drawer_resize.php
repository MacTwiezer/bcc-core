<?php
// Grid'deki gorunum panelinin (.gs-view-drawer) GENISLIK AYARI.
//
// Panelle grid arasindaki ince serit surukleneRek panel genisletilip
// daraltilabilir; deger --gs-drawer-w CSS degiskeninde tasinir ve
// localStorage'a yazilir.
//
// Kapsam:
//   A) Tutamac sayfada basiliyor ve panelin HEMEN ARDINDA (kardes)
//   B) Erisilebilirlik: role/aria/tabindex — fare kullanamayan da ayarlayabilsin
//   C) Genislik CSS DEGISKENIYLE veriliyor (satir ici width DEGIL) —
//      satir ici width ".is-collapsed { width: 0 }" kuralini ezip daraltmayi
//      KALICI OLARAK bozardi
//   D) Daraltilinca tutamac gizleniyor
//   E) Betik SENKRON yukleniyor (FOUC: panel once 260px cizilip sonra
//      kayitli degere sicramasin)
//   F) Disa aktarma/yazdirma: tutamac gizlenenler listesinde
//   G) Surukleme sirasinda width gecisi kapaniyor (lastikli his olmasin)
//   H) Sinirlar ve varsayilana donus mevcut
//
// ⚠️ GERCEK VERIYE DOKUNMAZ: kendi kullanicisini/base'ini yaratir, siler.
//
// On kosul: Apache ayakta. Calistirma:
//   C:\php73\php.exe scripts\_verify_grid_drawer_resize.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'drawer.owner@bcc-test.local');
define('TEST_PASS', 'Drawer!2026');

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

function extract_csrf($html)
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
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

// CSS yorumlarini soyar — bu projede testler daha once aciklama YORUMLARINA
// takilip yanlis "GECTI" verdi.
function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
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

try {
    $team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$team) { echo "HATA: TY ekibi yok.\n"; exit(1); }
    $teamId = (int) $team['id'];

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'Drawer Owner'));
    $userId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'Drawer Test', ':u' => $userId));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Drawer Tablo'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, NULL, 0)',
        array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));

    $cookie = login(TEST_EMAIL);
    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    check('0) grid acildi', $g['status'] === 200, 'HTTP ' . $g['status']);
    $html = $g['body'];

    $shellCss = css_rules(file_get_contents(__DIR__ . '/../public/assets/grid-shell.css'));
    $exportCss = css_rules(file_get_contents(__DIR__ . '/../public/assets/grid-export.css'));
    $js = file_get_contents(__DIR__ . '/../public/assets/grid-drawer-resize.js');

    // =====================================================================
    echo "\n--- A) Tutamac basiliyor ve panelin KARDESI ---\n";
    // =====================================================================
    check('A) tutamac sayfada var', strpos($html, 'id="gs-view-drawer-resizer"') !== false);
    // ⚠️ KARDES OLMALI: CSS'teki gizleme kurali bitisik-kardes seciciyle
    // (".is-collapsed + .gs-view-drawer-resizer") yazildi. Tutamac panelin
    // ICINE tasinirsa o kural sessizce calismaz.
    check('A) tutamac panelin HEMEN ardinda (bitisik kardes)',
        preg_match('#</div>\s*(?:<\?php.*?\?>\s*)?<div class="gs-view-drawer-resizer"#s', $html) === 1
        || preg_match('#id="gs-view-drawer"[\s\S]*?<div class="gs-view-drawer-resizer"[\s\S]*?<main class="gs-main"#', $html) === 1);
    check('A) tutamac <main> ten ONCE (yani .gs-body-row icinde)',
        strpos($html, 'gs-view-drawer-resizer') < strpos($html, '<main class="gs-main"'));

    // =====================================================================
    echo "\n--- B) Erisilebilirlik ---\n";
    // =====================================================================
    check('B) role="separator"', strpos($html, 'role="separator"') !== false);
    check('B) aria-orientation="vertical"', strpos($html, 'aria-orientation="vertical"') !== false);
    check('B) aria-label var', strpos($html, 'aria-label="Görünüm panelinin genişliğini ayarla"') !== false);
    check('B) tabindex="0" (klavyeyle odaklanabilir)',
        preg_match('#gs-view-drawer-resizer[\s\S]{0,300}tabindex="0"#', $html) === 1);
    check('B) ok tuslariyla ayarlanabiliyor',
        strpos($js, "ArrowLeft") !== false && strpos($js, "ArrowRight") !== false);

    // =====================================================================
    echo "\n--- C) Genislik CSS DEGISKENIYLE (satir ici width DEGIL) ---\n";
    // =====================================================================
    // ⚠️ ASIL TUZAK: satir ici bir width, ".gs-view-drawer.is-collapsed
    // { width: 0 }" kuralini ezer ve panel bir kez suruklendikten sonra
    // hamburger dugmesiyle bir daha DARALMAZDI.
    check('C) panel genisligi var(--gs-drawer-w) okuyor',
        preg_match('#\.gs-view-drawer\s*\{[^}]*width:\s*var\(--gs-drawer-w#s', $shellCss) === 1);
    check('C) JS satir ici width YAZMIYOR',
        strpos($js, '.style.width') === false);
    check('C) JS CSS degiskenini guncelliyor',
        strpos($js, "setProperty('--gs-drawer-w'") !== false);
    check('C) daraltma kurali hala width:0 veriyor',
        preg_match('#\.gs-view-drawer\.is-collapsed\s*\{[^}]*width:\s*0#s', $shellCss) === 1);

    // =====================================================================
    echo "\n--- D) Daraltilinca tutamac gizleniyor ---\n";
    // =====================================================================
    check('D) .is-collapsed + tutamac -> display:none',
        preg_match('#\.gs-view-drawer\.is-collapsed\s*\+\s*\.gs-view-drawer-resizer\s*\{[^}]*display:\s*none#s', $shellCss) === 1);

    // =====================================================================
    echo "\n--- E) FOUC: betik SENKRON yukleniyor ---\n";
    // =====================================================================
    // defer edilseydi panel once 260px cizilir, sonra kayitli genislige
    // sicrardi — gorunur titreme.
    check('E) grid-drawer-resize.js sayfada',
        strpos($html, 'grid-drawer-resize.js') !== false);
    check('E) defer YOK (senkron)',
        preg_match('#<script src="[^"]*grid-drawer-resize\.js[^"]*"\s*defer#', $html) === 0);
    check('E) kayitli genislik DOMContentLoaded BEKLEMEDEN uygulaniyor',
        preg_match('#getItem\(STORAGE_KEY\)[\s\S]{0,200}apply\(saved\)#', $js) === 1);
    check('E) localStorage kapaliysa cokmuyor (try/catch)',
        preg_match('#try\s*\{[\s\S]{0,200}localStorage[\s\S]{0,200}\}\s*catch#', $js) === 1);

    // =====================================================================
    echo "\n--- F) Disa aktarma / yazdirma ---\n";
    // =====================================================================
    // Panel gizleniyor ama tutamac AYRI bir kardes oge — ayrica listelenmeli,
    // yoksa 5px'lik bos serit kagida/PNG'ye duserdi.
    check('F) tutamac gizlenenler listesinde',
        strpos($exportCss, '.gs-view-drawer-resizer') !== false);

    // =====================================================================
    echo "\n--- G) Surukleme hissi ---\n";
    // =====================================================================
    check('G) surukleme sirasinda width gecisi kapaniyor',
        preg_match('#body\.gs-drawer-resizing\s+\.gs-view-drawer\s*\{[^}]*transition:\s*none#s', $shellCss) === 1);
    check('G) surukleme sirasinda imlec tum sayfada col-resize',
        preg_match('#body\.gs-drawer-resizing\s*\{[^}]*cursor:\s*col-resize#s', $shellCss) === 1);
    check('G) tutamac imleci col-resize',
        preg_match('#\.gs-view-drawer-resizer\s*\{[^}]*cursor:\s*col-resize#s', $shellCss) === 1);

    // =====================================================================
    echo "\n--- H) Sinirlar ve varsayilana donus ---\n";
    // =====================================================================
    check('H) alt/ust sinir var (kullanilamaz genislik olusmasin)',
        preg_match('#MIN\s*=\s*\d+#', $js) === 1 && preg_match('#MAX\s*=\s*\d+#', $js) === 1);
    check('H) deger sinirlara kirpiliyor',
        preg_match('#function clamp[\s\S]{0,200}Math\.max\(MIN,\s*Math\.min\(MAX#', $js) === 1);
    check('H) cift tiklama varsayilana donduruyor',
        strpos($js, "'dblclick'") !== false);
    check('H) genislik localStorage a yaziliyor',
        strpos($js, 'setItem(STORAGE_KEY') !== false);

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
