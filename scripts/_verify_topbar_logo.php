<?php
// Ust gezinme cubugundaki "Ana sayfa" dugmesi: ev simgesi yerine marka logosu.
//
// Kapsam:
//   A) Markup: ortak kabukta logo.png, ev SVG'si kalkti
//   B) CSS: olcu YALNIZCA yukseklikten (en-boy orani korunur), 24px
//   C) CANLI: kabugu kullanan sayfalarda render oluyor, gorsel 200 donuyor,
//      komsu kontrolleri itmiyor
//   D) Kapsam disi: grid.php'nin dar sol seridi (.gs-rail-home) BILEREK
//      degismedi — gerekcesiyle birlikte sabitlendi
//   E) Gercek base (15) dokunulmamis olmali
//
// OLCUM NOTU: /browse ile 1440x900'de olculdu — logo 51x24 (dogal 94x44,
// oran korunuyor), kutu 32px yuksek ve 56px'lik cubukta dikey ortali
// (ust 12px / alt 12px), .home-topbar-left hala 240px yani hicbir kontrol
// itilmedi. Acik ve koyu temada da okunur (logo cok renkli, tek renk murekkep
// degil). Tiklama /dashboard.php'ye gidiyor.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_topbar_logo.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'logo.owner@bcc-test.local');
define('TEST_PASS', 'Logo!2026');
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

function php_code_only($src)
{
    $src = preg_replace('#<!--.*?-->#s', '', $src);
    $src = preg_replace('#/\*.*?\*/#s', '', $src);

    return preg_replace('#^\s*//.*$#m', '', $src);
}

bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));

$cleanup = function () {
    $uid = bcc_fetch_column('SELECT id FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));
    if ($uid) {
        foreach (bcc_fetch_all('SELECT id FROM bases WHERE created_by = :u', array(':u' => $uid)) as $b) {
            bcc_execute('DELETE FROM bases WHERE id = :i', array(':i' => $b['id']));
        }
        bcc_execute('DELETE FROM users WHERE id = :i', array(':i' => $uid));
    }
};

$realBefore = array(
    'tablo' => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit' => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

try {
    $assetsDir = __DIR__ . '/../public/assets';
    $shellPhp = file_get_contents(__DIR__ . '/../src/partials/home_shell_top.php');
    $shellCode = php_code_only($shellPhp);
    $homeCss = css_rules(file_get_contents($assetsDir . '/home.css'));

    // =====================================================================
    // A) MARKUP
    // =====================================================================
    echo "--- A) Ortak kabuk markup ---\n";
    check('A) logo dosyasi projede duruyor', is_file($assetsDir . '/logo.png'));
    $size = @getimagesize($assetsDir . '/logo.png');
    check('A) logo okunabilir bir gorsel', is_array($size), 'getimagesize basarisiz');
    check('A) .home-logo icinde <img> var',
        preg_match('#class="home-logo"[^>]*>\s*<img#s', $shellCode) === 1);
    check('A) kaynak PROJENIN mevcut logosu (assets/logo.png)',
        strpos($shellCode, "bcc_asset_url('logo.png')") !== false);
    check('A) onbellek kirma yardimcisi kullanilmis (?v=filemtime)',
        strpos($shellCode, 'bcc_asset_url(') !== false);
    // Ev SVG'si tamamen kalkmali.
    check('A) eski ev (house) SVG i .home-logo dan KALKTI',
        preg_match('#class="home-logo"[^>]*>\s*<svg#s', $shellCode) === 0);
    check('A) house path i kabukta hic kalmadi',
        strpos($shellCode, 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8') === false);
    // Gezinme + erisilebilirlik.
    check('A) bag hala /dashboard.php ye gidiyor',
        preg_match('#<a href="/dashboard\.php" class="home-logo"#', $shellCode) === 1);
    check('A) aria-label + title korundu',
        strpos($shellCode, 'title="Ana sayfa" aria-label="Ana sayfa"') !== false);
    // ⚠️ Bu iki kontrol KAYNAKTA "[^>]*" ile yazilamaz: <img> etiketinin src
    // degeri bir PHP blogu iceriyor ve o blogun KAPANIS etiketindeki ">"
    // karakteri sinifi ERKEN sonlandirir. Etiketin bulundugu SATIR aliniyor;
    // asil dogrulama zaten asagida CANLI ciktida yapiliyor.
    //
    // (Not: bu yorumda PHP kapanis etiketi HARFI HARFINE yazilamaz — bir
    // "//" yorumunun icinde bile PHP modunu KAPATIR ve dosyanin geri kalani
    // duz HTML'e doner. Ilk yazimda tam olarak bu oldu: "unexpected end of
    // file" parse hatasi. CSS'teki "yorum icinde kapanis dizilimi" tuzaginin
    // PHP karsiligi.)
    $imgLine = '';
    foreach (explode("\n", $shellCode) as $line) {
        if (strpos($line, '<img') !== false && strpos($line, 'logo.png') !== false) { $imgLine = $line; break; }
    }
    check('A) <img> satiri bulundu', $imgLine !== '');
    // alt="" BILEREK: <a> zaten aria-label tasiyor, alt metni verilseydi
    // ekran okuyucu bagi IKI KEZ okurdu.
    check('A) img alt="" (bag zaten aria-label tasiyor)',
        strpos($imgLine, 'alt=""') !== false, trim($imgLine));
    // Dogal olculer yer kaymasini (CLS) onler.
    check('A) width/height oznitelikleri dogal olcude basilmis',
        strpos($imgLine, 'width="94"') !== false && strpos($imgLine, 'height="44"') !== false
        && is_array($size) && (int) $size[0] === 94 && (int) $size[1] === 44,
        (is_array($size) ? "gercek={$size[0]}x{$size[1]} | " : '') . trim($imgLine));

    // =====================================================================
    // B) CSS
    // =====================================================================
    echo "\n--- B) Olculendirme ---\n";
    $logoRule = rule_body($homeCss, '.home-logo');
    check('B) .home-logo kurali var', $logoRule !== null);
    check('B) kutu yuksekligi sabit (dokunma hedefi 32px)',
        $logoRule !== null && strpos($logoRule, 'height: 32px;') !== false, (string) $logoRule);
    // ⚠️ Kare kutu (width:32px) GERILME/EZILME demekti — logo 94x44, yani ~2.14:1.
    check('B) kutu genisligi auto (kare kutuya sikistirilmiyor)',
        $logoRule !== null && strpos($logoRule, 'width: auto;') !== false
        && strpos($logoRule, 'width: 32px;') === false, (string) $logoRule);
    check('B) daralan cubukta sikismiyor (flex-shrink: 0)',
        $logoRule !== null && strpos($logoRule, 'flex-shrink: 0;') !== false);
    $imgRule = rule_body($homeCss, '.home-logo img');
    check('B) .home-logo img kurali var', $imgRule !== null);
    check('B) yukseklik 24px (56px lik cubukta rahat pay)',
        $imgRule !== null && strpos($imgRule, 'height: 24px;') !== false, (string) $imgRule);
    check('B) genislik auto — EN-BOY ORANI korunuyor, gerilme YOK',
        $imgRule !== null && strpos($imgRule, 'width: auto;') !== false, (string) $imgRule);
    check('B) display: block (taban cizgisi bosluğu kalksin)',
        $imgRule !== null && strpos($imgRule, 'display: block;') !== false);
    check('B) hover geri bildirimi korundu',
        preg_match('#\.home-logo:hover \{[^}]*background: var\(--bcc-surface-hover\);#s', $homeCss) === 1);
    check('B) klavye odagi halkasi korundu',
        preg_match('#\.home-logo:focus-visible \{[^}]*outline:#s', $homeCss) === 1);

    // =====================================================================
    // C) CANLI
    // =====================================================================
    echo "\n--- C) Canli render ---\n";
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'Logo Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'Logo Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Kayitlar'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));

    $cookie = login(OWNER_EMAIL);
    check('C) Giris yapildi', $cookie !== null);

    // Kabugu kullanan SAYFA AILESINDEN ornekler — logo hepsinde ayni.
    $pages = array(
        'dashboard.php'    => '/dashboard.php',
        'workspaces.php'   => '/workspaces.php',
        'starred.php'      => '/starred.php',
        'bases.php'        => '/bases.php',
        'table_fields.php' => '/table_fields.php?table_id=' . $tableId,
        'account.php'      => '/account.php',
    );
    $logoUrl = null;
    foreach ($pages as $name => $path) {
        $r = http_request('GET', $path, $cookie);
        $ok = $r['status'] === 200
            && preg_match('#<a href="/dashboard\.php" class="home-logo"[^>]*>\s*<img src="(/assets/logo\.png[^"]*)"#s', $r['body'], $m) === 1;
        check("C) {$name}: logo basiliyor ve /dashboard.php ye bagli", $ok, 'HTTP ' . $r['status']);
        if ($ok && $logoUrl === null) { $logoUrl = $m[1]; }
        check("C) {$name}: ev SVG i kalmadi",
            strpos($r['body'], 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8') === false
            || strpos($r['body'], 'gs-rail-home') !== false);
    }
    // CANLI CIKTIDA erisilebilirlik + CLS oznitelikleri (kaynak yerine
    // tarayiciya GERCEKTEN giden HTML uzerinden).
    if (preg_match('#<a href="/dashboard\.php" class="home-logo"[^>]*>\s*(<img[^>]*>)#s', $dashForImg = http_request('GET', '/dashboard.php', $cookie)['body'], $im)) {
        check('C) render edilen <img> de alt="" (cift okuma yok)',
            strpos($im[1], 'alt=""') !== false, $im[1]);
        check('C) render edilen <img> de width/height var (yer kaymasi yok)',
            strpos($im[1], 'width="94"') !== false && strpos($im[1], 'height="44"') !== false, $im[1]);
        check('C) render edilen src onbellek damgasi tasiyor',
            preg_match('#src="/assets/logo\.png\?v=\d+"#', $im[1]) === 1, $im[1]);
    } else {
        check('C) render edilen <img> etiketi bulundu', false, 'esleme yok');
    }

    // Gorsel GERCEKTEN sunuluyor mu (kirik ikon regresyonu).
    check('C) logo URL i cozuldu', $logoUrl !== null, (string) $logoUrl);
    if ($logoUrl !== null) {
        $img = http_request('GET', $logoUrl);
        check('C) logo dosyasi 200 donuyor (kirik gorsel yok)',
            $img['status'] === 200 && strlen($img['body']) > 1000,
            'HTTP ' . $img['status'] . ' bayt=' . strlen($img['body']));
    }

    // =====================================================================
    // D) KAPSAM DISI: grid.php'nin dar sol seridi
    // =====================================================================
    echo "\n--- D) Kapsam disi birakilan: grid.php sol serit ---\n";
    // ⚠️ BILINCLI KARAR: .gs-rail 52px GENIS bir DIKEY serit; logo 94x44
    // yani 24px yukseklikte ~51px genisler — seridi kenardan kenara doldurur,
    // 28px'te (60px) tasar. Ustelik istek "yan menu dugmesinin YANINDAKI ust
    // gezinme cubugu" diyor; serit o degil. Ev simgesi orada KALDI.
    $gridPhp = file_get_contents(__DIR__ . '/../public/grid.php');
    check('D) grid.php sol seridindeki ev simgesi KORUNDU (dar serit, 52px)',
        strpos($gridPhp, 'class="gs-rail-home"') !== false
        && strpos($gridPhp, 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8') !== false);
    $railRule = rule_body(css_rules(file_get_contents($assetsDir . '/grid-shell.css')), '.gs-rail');
    check('D) serit genisligi hala 52px (logonun sigmama gerekcesi)',
        $railRule !== null && strpos($railRule, 'width: 52px;') !== false, (string) $railRule);
    check('D) serit ev dugmesi de /dashboard.php ye gidiyor (davranis ayni)',
        preg_match('#<a href="/dashboard\.php" class="gs-rail-home"#', $gridPhp) === 1);

    // =====================================================================
    // E) REGRESYON: ust cubugun geri kalani
    // =====================================================================
    echo "\n--- E) Ust cubugun geri kalani ---\n";
    $dash = http_request('GET', '/dashboard.php', $cookie);
    check('E) yan menu ac/kapat dugmesi duruyor',
        strpos($dash['body'], 'id="home-sidebar-toggle"') !== false);
    check('E) genel arama duruyor', strpos($dash['body'], 'home-topbar-center') !== false);
    check('E) bildirim + hesap menusu duruyor',
        strpos($dash['body'], 'home-topbar-right') !== false
        && strpos($dash['body'], 'home-account') !== false);
    check('E) sol bolge genisligi degismedi (240px, yan panelle hizali)',
        preg_match('#\.home-topbar-left \{[^}]*width: 240px;#s', $homeCss) === 1);
    // Giris/kayit sayfalari logoyu ZATEN kimlik olarak basiyor; dokunulmadi.
    check('E) login/register/verify_email logosu dokunulmadan duruyor',
        strpos(file_get_contents(__DIR__ . '/../public/login.php'), '/assets/logo.png') !== false
        && strpos(file_get_contents(__DIR__ . '/../public/register.php'), '/assets/logo.png') !== false);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

echo "\n--- F) Gercek base (id " . REAL_BASE_ID . ") dokunulmadi mi ---\n";
$realAfter = array(
    'tablo' => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit' => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);
foreach ($realBefore as $k => $before) {
    check("Base " . REAL_BASE_ID . " {$k} sayisi degismedi ({$before})", $realAfter[$k] === $before,
        "once={$before} sonra={$realAfter[$k]}");
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
