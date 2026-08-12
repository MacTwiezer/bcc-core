<?php
// interface.php sol kenar cubugu: renk paleti, tek satirlik alt arac cubugu,
// ikon kutusu kenarliklari.
//
// Kapsam:
//   A) Renk paleti (uc hex, token uzerinden)
//   B) Alt arac cubugu TEK SATIR: "Paylas" satir ici, footer kabi zeminli
//   C) Workspace ikonu: canli renk kalkti, koyu kenarlik geldi
//   D) CSS YORUM BUTUNLUGU — bu isde bulunan gercek bug sinifi icin repo
//      genelinde koruma
//   E) Regresyon: daraltilmis hal, paylasim popover'lari, ikinci kopya yok
//   F) Gercek base (15) dokunulmamis olmali
//
// OLCUM NOTU: gorsel sonuclar TARAYICIDA olculdu (/browse, 1440x900):
// panel rgb(255,186,5); aktif oge rgb(243,172,4); footer rgb(255,193,30) ve
// genisliği panelle AYNI (220px acik, 64px daraltilmis); ikon 22x22, zemin
// rgba(255,255,255,.55), kenarlik 1.5px solid rgb(30,41,59); alt satir
// tasmiyor (196/196 px).
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_interface_nav_ui.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'ifnav.owner@bcc-test.local');
define('TEST_PASS', 'IfNav!2026');
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

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf_field($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

function rule_body($css, $selector)
{
    $q = preg_quote($selector, '#');
    if (preg_match('#(?:^|[};])\s*' . $q . '\s*\{([^}]*)\}#s', $css, $m)) { return $m[1]; }
    return null;
}

// Bir CSS dosyasindaki KACAK yorum sonlandiricilarini bulur. Yorum ICINDE
// gecen bir "*" + "/" ikilisi yorumu ORADA kapatir; kalan satirlar ham metne
// doner ve ayristirici ARDINDAN GELEN KURALI sessizce ATAR.
function stray_comment_terminators($css)
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $css);
    $hits = array();
    if (preg_match_all('#\*/#', $stripped, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $hits[] = substr_count(substr($stripped, 0, $hit[1]), "\n") + 1;
        }
    }

    return $hits;
}

bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => OWNER_EMAIL)
    ), 'id');
    foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));
};

$realBefore = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

try {
    $assetsDir = __DIR__ . '/../public/assets';
    $ifCssRaw = file_get_contents($assetsDir . '/interface.css');
    $ifCss = css_rules($ifCssRaw);
    $ifPhp = file_get_contents(__DIR__ . '/../public/interface.php');

    // =====================================================================
    // A) RENK PALETI
    // =====================================================================
    echo "--- A) Renk paleti ---\n";
    $navRule = rule_body($ifCss, '.if-nav');
    check('A) panel zemini #ffba05',
        $navRule !== null && strpos($navRule, '--if-nav-bg: #ffba05;') !== false, (string) $navRule);
    check('A) secili oge zemini #f3ac04',
        $navRule !== null && strpos($navRule, '--if-nav-active-bg: #f3ac04;') !== false, (string) $navRule);
    check('A) footer zemini #ffc11e',
        $navRule !== null && strpos($navRule, '--if-nav-footer-bg: #ffc11e;') !== false, (string) $navRule);
    // Hex'ler TEK yerde: kurallar token kullanmali, hex tekrar YAZILMAMALI.
    check('A) .if-nav zemini token uzerinden',
        $navRule !== null && strpos($navRule, 'background: var(--if-nav-bg);') !== false);
    check('A) aktif oge token uzerinden',
        preg_match('#\.if-nav-item\.is-active \{[^}]*background: var\(--if-nav-active-bg\);#s', $ifCss) === 1);
    check('A) footer token uzerinden',
        preg_match('#\.if-nav-bottom \{[^}]*background: var\(--if-nav-footer-bg\);#s', $ifCss) === 1);
    foreach (array('#ffba05' => 1, '#f3ac04' => 1, '#ffc11e' => 1) as $hex => $expected) {
        check("A) {$hex} dosyada YALNIZCA bir kez (tek kaynak)",
            substr_count(strtolower($ifCss), $hex) === $expected,
            'sayim=' . substr_count(strtolower($ifCss), $hex));
    }
    // Eski panel rengi tamamen kalkmali.
    check('A) eski #f6c343 zemini KALKTI', stripos($ifCss, '#f6c343') === false);

    // =====================================================================
    // B) ALT ARAC CUBUGU — TEK SATIR
    // =====================================================================
    echo "\n--- B) Alt arac cubugu tek satir ---\n";
    check('B) .if-nav-share-row sarmalayicisi markup tan KALKTI',
        strpos($ifPhp, 'if-nav-share-row') === false);
    check('B) .if-nav-share-row kurallari CSS ten de KALKTI',
        strpos($ifCss, '.if-nav-share-row') === false);
    // "Paylas" artik yardimci satirin ICINDE olmali: util-row acilisi ile
    // collab-share arasinda baska bir kapanis <div> olmamali.
    check('B) "Paylas" .if-nav-util-row un ICINDE',
        preg_match('#<div class="if-nav-util-row">\s*(?:<!--.*?-->\s*)?<details class="if-nav-collab-share#s', $ifPhp) === 1);
    // Alt satirin BES ogesi + avatar: sira `order` ile suruluyor.
    foreach (array('.if-account' => 1, '.if-nav-spacer' => 2, '.if-nav-collab-share' => 3,
                   '.if-nav-share' => 4, '.if-nav-util-row .home-notif' => 5,
                   '.if-nav-collapse-btn' => 6, '.if-nav-expand-btn' => 7) as $sel => $ord) {
        check("B) {$sel} order: {$ord}",
            preg_match('#' . preg_quote($sel, '#') . ' \{ order: ' . $ord . ';#', $ifCss) === 1);
    }
    // ⚠️ TASMA KORUMASI: etiketler gorunur olsaydi alti oge 220px'e sigmazdi
    // (dosyanin gecmisinde tam olarak bu yasandi).
    check('B) iki paylasim dugmesinin de METIN etiketi gizli (tasma korumasi)',
        preg_match('#\.if-nav-share \.if-nav-bottom-label,\s*\.if-nav-collab-share \.if-nav-bottom-label \{ display: none; \}#s', $ifCss) === 1);
    check('B) "Paylas" artik tam genislikte buton DEGIL',
        preg_match('#\.if-nav-collab-share-btn \{[^}]*width: 100%#s', $ifCss) === 0);
    // Footer KABI: zemin + panelin yatay padding ini geri alan negatif margin.
    check('B) footer kabi panel genisligini kapliyor (negatif margin)',
        preg_match('#\.if-nav-bottom \{[^}]*margin: auto -0\.75rem -1rem;#s', $ifCss) === 1);
    check('B) daraltilmisken footer dar raya gore yeniden hesaplaniyor',
        preg_match('#\.if-nav\.is-collapsed \.if-nav-bottom \{[^}]*margin: auto -0\.5rem -1rem;#s', $ifCss) === 1);
    // align-self: stretch olmadan footer 45px'lik bir ada olarak kaliyordu.
    check('B) daraltilmisken footer align-self: stretch ile geriliyor',
        preg_match('#\.if-nav\.is-collapsed \.if-nav-bottom \{[^}]*align-self: stretch;#s', $ifCss) === 1);
    check('B) erisilebilirlik: iki dugmede de title + aria-label duruyor',
        strpos($ifPhp, 'aria-label="Paylaş" title="Paylaş"') !== false
        && strpos($ifPhp, 'aria-label="Bağlantı" title="Bağlantı"') !== false);

    // =====================================================================
    // C) WORKSPACE IKONU
    // =====================================================================
    echo "\n--- C) Workspace ikonu ---\n";
    // ⚠️ Yorumlar SOYULARAK bakiliyor: fonksiyonun ADI, neden kaldirildigini
    // anlatan HTML yorumunda GECIYOR — cagrilmadigini dogrulamak istiyoruz,
    // adinin hic anilmadigini degil.
    $ifPhpCode = preg_replace('#<!--.*?-->#s', '', $ifPhp);
    $ifPhpCode = preg_replace('#/\*.*?\*/#s', '', $ifPhpCode);
    check('C) satir ici canli renk (bcc_base_icon_color) artik CAGRILMIYOR',
        strpos($ifPhpCode, 'bcc_base_icon_color') === false);
    check('C) ikon span inda satir ici style KALMADI',
        preg_match('#home-base-icon"\s+style=#', $ifPhp) === 0);
    check('C) kategori glifi KORUNDU (base ler ayirt edilebilir kalsin)',
        strpos($ifPhp, 'bcc_base_icon_svg(14, $base[\'name\'])') !== false);
    $iconRule = rule_body($ifCss, '.if-nav-back .home-base-icon');
    check('C) ikon kurali var', $iconRule !== null);
    check('C) zemin artik sakin (yari saydam beyaz)',
        $iconRule !== null && strpos($iconRule, 'background: rgba(255, 255, 255, 0.55);') !== false, (string) $iconRule);
    check('C) koyu kenarlik eklendi',
        $iconRule !== null && strpos($iconRule, 'border: 1.5px solid var(--if-nav-ink);') !== false, (string) $iconRule);
    check('C) glif koyu murekkebe cekildi (acik zeminde beyaz kaybolurdu)',
        $iconRule !== null && strpos($iconRule, 'color: var(--if-nav-ink);') !== false
        && strpos($iconRule, '#fff') === false, (string) $iconRule);
    check('C) kenarlik rengi token uzerinden (#1e293b)',
        $navRule !== null && strpos($navRule, '--if-nav-ink: #1e293b;') !== false);
    // "tum ikon rozetleri": bu panelde ikinci ikon kutusu avatardir.
    check('C) avatar da AYNI kenarligi aliyor',
        preg_match('#\.if-avatar \{[^}]*border: 1\.5px solid var\(--if-nav-ink\);#s', $ifCss) === 1);

    // =====================================================================
    // D) CSS YORUM BUTUNLUGU (bu iste bulunan gercek bug sinifi)
    // =====================================================================
    echo "\n--- D) CSS yorum butunlugu ---\n";
    // Yorum ICINDE gecen bir "*" + "/" ikilisi yorumu orada kapatir ve
    // ARDINDAN GELEN KURAL sessizce DUSER. Uc gercek ornek bulundu:
    //   interface.css  ".home-*[/].gs-*"  -> `* { box-sizing }` dusuyordu
    //   interface.css  ikon yorumu        -> .if-nav-back .home-base-icon dusuyordu
    //   home.css       "ws-*[/]tm-*"      -> .settings-breadcrumb dusuyordu
    $cssFiles = glob($assetsDir . '/*.css');
    check('D) taranacak CSS dosyasi bulundu', count($cssFiles) > 0, count($cssFiles) . ' dosya');
    $offenders = array();
    foreach ($cssFiles as $file) {
        $hits = stray_comment_terminators(file_get_contents($file));
        if ($hits) { $offenders[] = basename($file) . ' (~satir ' . implode(', ', $hits) . ')'; }
    }
    check('D) HICBIR CSS dosyasinda kacak yorum sonlandirici yok',
        count($offenders) === 0, implode(' | ', $offenders));
    // Dusen uc kuralin GERI GELDIGINI dogrula (yorum duzeldi -> kural ayristiriliyor).
    check('D) interface.css: `* { box-sizing }` yorumdan SONRA ve saglam',
        preg_match('#\*/\s*\*\s*\{\s*box-sizing: border-box;\s*\}#s', $ifCssRaw) === 1);
    check('D) home.css: .settings-breadcrumb yorumdan SONRA ve saglam',
        preg_match('#\*/\s*\.settings-breadcrumb \{#s', file_get_contents($assetsDir . '/home.css')) === 1);

    // =====================================================================
    // E) REGRESYON
    // =====================================================================
    echo "\n--- E) Regresyon ---\n";
    check('E) daraltilmis hal kurallari duruyor',
        preg_match('#\.if-nav\.is-collapsed \{[^}]*width: 64px;#s', $ifCss) === 1);
    check('E) daraltilmisken liste gizlenip klasor ikonu cikiyor',
        strpos($ifCss, '.if-nav.is-collapsed .if-nav-list-icon { display: flex; }') !== false);
    check('E) genislet (>>) yalnizca daraltilmisken gorunur',
        strpos($ifCss, '.if-nav-expand-btn { display: none; }') !== false
        && strpos($ifCss, '.if-nav.is-collapsed .if-nav-expand-btn { display: flex; order: 2; }') !== false);
    check('E) daraltilmisken "Paylas" ikon yiginin SONUNDA',
        strpos($ifCss, '.if-nav.is-collapsed .if-nav-collab-share { order: 5; }') !== false);
    // Paylasim mantigi ORTAK partial/JS te kalmali — ikinci kopya YOK.
    check('E) "Baglanti" hala ortak share_link_popover partial ini kullaniyor',
        strpos($ifPhp, "require __DIR__ . '/../src/partials/share_link_popover.php'") !== false);
    check('E) profil/bildirim hala ortak partial lardan geliyor',
        strpos($ifPhp, "require __DIR__ . '/../src/partials/account_menu.php'") !== false
        && strpos($ifPhp, "require __DIR__ . '/../src/partials/notifications_panel.php'") !== false);
    check('E) iki <details> de AYNI name ile (ayni anda yalnizca biri acik)',
        substr_count($ifPhp, 'name="if-nav-share"') === 2);

    // CANLI: sayfa gercekten aciliyor ve yeni yapi HTML de var mi.
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'IfNav Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'Demo CRM', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Musteriler'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));

    $cookie = login(OWNER_EMAIL);
    $page = http_request('GET', '/interface.php?base_id=' . $baseId . '&table_id=' . $tableId, $cookie);
    check('E) interface.php 200', $page['status'] === 200, 'HTTP ' . $page['status']);
    check('E) footer kabi HTML de tek satir olarak render ediliyor',
        preg_match('#<div class="if-nav-bottom">\s*<div class="if-nav-util-row">#s', $page['body']) === 1);
    check('E) base ikonu satir ici background OLMADAN basiliyor',
        strpos($page['body'], '<span class="home-base-icon">') !== false
        && preg_match('#home-base-icon" style="background#', $page['body']) === 0);
    check('E) her iki paylasim tetikleyicisi de sayfada',
        strpos($page['body'], 'collab-popover-trigger') !== false
        && strpos($page['body'], 'share-popover-trigger') !== false);
    check('E) aktif tablo ogesi isaretli',
        strpos($page['body'], 'if-nav-item is-active') !== false);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

echo "\n--- F) Gercek base (id " . REAL_BASE_ID . ") dokunulmadi mi ---\n";
$realAfter = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);
foreach ($realBefore as $k => $before) {
    check("Base " . REAL_BASE_ID . " {$k} sayisi degismedi ({$before})", $realAfter[$k] === $before,
        "once={$before} sonra={$realAfter[$k]}");
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
