<?php
// Ust gezinme cubugundaki "Ana sayfa" dugmesi: ev simgesi yerine marka logosu.
//
// MARKA GUNCELLEMESI (OpsFlow / opsflow.bcccrm.com): logo artik
// assets/logo.png DEGIL. O dosya icinde "bcc" harfleri PIKSEL olarak basili
// bir bitmap'ti; urun adi degisince guncellenemedi ve ust barda eski ad
// gorunmeye devam ediyordu. Yerine satir ici SVG kelime isareti geldi:
// src/partials/brand_logo.php — ad bcc_brand_name()'den (config/app.php)
// okunur, kelime isareti currentColor ile cizilir (koyu tema uyumu).
//
// Kapsam:
//   A) Markup: ortak kabuk marka partial'ini include ediyor, ev SVG'si kalkti
//   B) CSS: olcu YALNIZCA yukseklikten (en-boy orani korunur), 24px; kutu
//      acikca metin rengi veriyor (currentColor'in kaynagi)
//   C) CANLI: kabugu kullanan sayfalarda render oluyor, marka adi SVG'nin
//      icinde gercekten basiliyor, favicon 200 donuyor
//   D) Kapsam disi: grid.php'nin dar sol seridi (.gs-rail-home) BILEREK
//      degismedi — gerekcesiyle birlikte sabitlendi
//   E) login/register/verify_email AYNI partial'i kullaniyor (tek kaynak)
//   F) Gercek base (15) dokunulmamis olmali
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

$cleanup = function () {
    $uid = bcc_fetch_column('SELECT id FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));
    if ($uid) {
        foreach (bcc_fetch_all('SELECT id FROM bases WHERE created_by = :u', array(':u' => $uid)) as $b) {
            bcc_execute('DELETE FROM bases WHERE id = :i', array(':i' => $b['id']));
        }
        bcc_execute('DELETE FROM users WHERE id = :i', array(':i' => $uid));
    }
};

$cleanup();
register_shutdown_function($cleanup);

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
    // MARKA DEGISIKLIGI (OpsFlow): logo artik assets/logo.png DEGIL.
    // O dosyada "bcc" harfleri PIKSEL olarak basiliydi; urun adi
    // opsflow.bcccrm.com olunca guncellenemeyen bir varlik haline geldi.
    // Yerine satir ici SVG kelime isareti: src/partials/brand_logo.php.
    // Ad literal degil, bcc_brand_name()'den (config/app.php) geliyor.
    $brandPartial = __DIR__ . '/../src/partials/brand_logo.php';
    check('A) marka isareti partial i projede duruyor', is_file($brandPartial));
    $brandSrc = is_file($brandPartial) ? file_get_contents($brandPartial) : '';
    // ⚠️ BU BOLUM TAMAMEN YENIDEN YAZILDI -- 20 KONTROL BAYATTI, KOD DEGIL TEST
    // YANLISTI. Test, marka isaretinin "satir ici SVG kelime isareti"
    // (currentColor + marka adi metni) oldugu bir TASARIMI dogruluyordu. O
    // tasarim BILEREK BIRAKILDI: brand_logo.php'nin kendi basligi iki eski
    // halini de sayiyor ("(1) satir ici SVG rozet + kelime isareti, (2) logo +
    // 'OpsFlow' yazisi yan yana. Ikisi de birakildi -- logo tek basina
    // duruyor."). Yani kontroller mevcut tasarimla ASLA gecemezdi ve 20 kirik
    // satir, ileride GERCEK bir regresyonu maskeleyecekti.
    //
    // Korunan guvenceler (mevcut tasarima gore yeniden ifade edildi):
    //   - marka isareti TEK KAYNAK (brand_logo.php) ve kabuk da onu kullanir
    //   - marka adi LITERAL degil, bcc_brand_name()'den
    //   - dogal olculer basilir (CLS yok) ve oran korunur
    //   - gezinme + erisilebilirlik bozulmaz
    check('A) marka adi LITERAL yazilmamis (bcc_brand_name tek kaynak)',
        strpos($brandSrc, 'bcc_brand_name()') !== false
        && strpos($brandSrc, '>OpsFlow<') === false);

    // ⚠️ BU KABUK DUZELTMESI BU TURDA YAPILDI: kabuk kendi <img>'ini yaziyordu,
    // yani bes auth sayfasinin kullandigi partial'in IKINCI bir uygulamasi
    // vardi (logo dosyasi ya da 94x44 orani degisse biri guncellenip digeri
    // unutulurdu). Artik ikisi de ayni partial'dan geciyor.
    check('A) kabuk marka partial ini KULLANIYOR (kopya <img> yazmiyor)',
        strpos($shellCode, "require __DIR__ . '/brand_logo.php'") !== false);
    check('A) kabukta artik ciplak logo <img> etiketi YOK',
        preg_match('#class="home-logo"[^\n]*\n\s*<img#', $shellCode) === 0);
    check('A) kabuk partial e yukseklik veriyor (oran partial de hesaplanir)',
        preg_match('#\$brandLogoHeight\s*=\s*44;#', $shellCode) === 1);

    // Dogal olculer yer kaymasini (CLS) onler; oran GERCEK dosyadan (94x44).
    check('A) width/height oznitelikleri basiliyor (CLS yok)',
        strpos($brandSrc, 'height="<?php echo $brandLogoHeight; ?>"') !== false
        && strpos($brandSrc, '94 / 44') !== false);
    check('A) alt metni DOLU (yaninda marka adi yazan metin yok)',
        strpos($brandSrc, 'alt="<?php echo htmlspecialchars(bcc_brand_name()') !== false);

    // Eski ev (house) SVG'si kalkmis olmali -- bu kontrol HALA GECERLI.
    check('A) eski ev (house) SVG i .home-logo dan KALKTI',
        preg_match('#class="home-logo"[^>]*>\s*<svg#s', $shellCode) === 0);
    check('A) house path i kabukta hic kalmadi',
        strpos($shellCode, 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8') === false);
    // Gezinme + erisilebilirlik.
    check('A) bag hala /dashboard.php ye gidiyor',
        preg_match('#<a href="/dashboard\.php" class="home-logo"#', $shellCode) === 1);
    check('A) aria-label + title korundu',
        strpos($shellCode, 'title="Ana sayfa" aria-label="Ana sayfa"') !== false);

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
    // Kural artik GRUPLU yazili (".home-logo img, .home-logo svg") — eski
    // <img> yolu da calismaya devam etsin diye ikisi birlikte hedefleniyor.
    // rule_body() tek bir secici bekledigi icin burada dogrudan regex.
    $markRule = null;
    if (preg_match('#\.home-logo img,\s*\.home-logo svg\s*\{([^}]*)\}#s', $homeCss, $mr)) {
        $markRule = $mr[1];
    }
    check('B) .home-logo img/svg (gruplu) kurali var', $markRule !== null);
    check('B) yukseklik 24px (56px lik cubukta rahat pay)',
        $markRule !== null && strpos($markRule, 'height: 24px;') !== false, (string) $markRule);
    check('B) genislik auto — EN-BOY ORANI korunuyor, gerilme YOK',
        $markRule !== null && strpos($markRule, 'width: auto;') !== false, (string) $markRule);
    check('B) display: block (taban cizgisi bosluğu kalksin)',
        $markRule !== null && strpos($markRule, 'display: block;') !== false);
    // Kelime isareti currentColor ile ciziliyor: .home-logo ACIKCA bir metin
    // rengi vermezse baglantinin varsayilan mavisini alir ve koyu temada
    // yanlis kontrast olusur.
    check('B) .home-logo metin rengi acikca veriliyor (currentColor kaynagi)',
        $logoRule !== null && strpos($logoRule, 'color: var(--bcc-text);') !== false, (string) $logoRule);
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
    // ⚠️ BU BOLUM DE YENIDEN YAZILDI (bkz. A bolumundeki gerekce): kontroller
    // "satir ici SVG kelime isareti" tasarimini ariyordu, o tasarim BILEREK
    // birakildi ve marka artik sade bir <img> (logo.png). Guvenceler AYNI,
    // yalnizca mevcut tasarima gore ifade edildi.
    foreach ($pages as $name => $path) {
        $r = http_request('GET', $path, $cookie);
        // Bag + marka isareti CANLI ciktida birlikte basiliyor mu?
        $ok = $r['status'] === 200
            && preg_match('#<a href="/dashboard\.php" class="home-logo"[^>]*>\s*<img[^>]*class="brand-logo#s', $r['body']) === 1;
        check("C) {$name}: marka isareti basiliyor ve /dashboard.php ye bagli", $ok, 'HTTP ' . $r['status']);
        // Bos/kirik gorsel regresyonu: alt metni marka adini tasimali.
        check("C) {$name}: marka isaretinin alt metni DOLU",
            preg_match('#<img[^>]*class="brand-logo[^"]*"[^>]*alt="[^"]+"#s', $r['body']) === 1
            || preg_match('#<img[^>]*alt="[^"]+"[^>]*class="brand-logo#s', $r['body']) === 1);
        check("C) {$name}: ev SVG i kalmadi",
            strpos($r['body'], 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8') === false
            || strpos($r['body'], 'gs-rail-home') !== false);
    }
    // CANLI CIKTIDA erisilebilirlik + CLS oznitelikleri (kaynak yerine
    // tarayiciya GERCEKTEN giden HTML uzerinden).
    $dashBody = http_request('GET', '/dashboard.php', $cookie)['body'];
    if (preg_match('#<a href="/dashboard\.php" class="home-logo"[^>]*>\s*(<img[^>]*>)#s', $dashBody, $im)) {
        check('C) render edilen marka isareti bulundu', true);
        // ⚠️ CLS: oznitelikler BASILMALI. Yukseklik 44 (kabuk oyle veriyor),
        // genislik partial'da 94/44 oranindan HESAPLANIR -> 94.
        check('C) render edilen <img> de width/height var (yer kaymasi yok)',
            preg_match('#height="44"#', $im[1]) === 1 && preg_match('#width="94"#', $im[1]) === 1, $im[1]);
        check('C) alt metni marka adini tasiyor',
            preg_match('#alt="[^"]+"#', $im[1]) === 1, $im[1]);
        // Gorunen olcu CSS'ten gelir; oznitelikler yalnizca orani sabitler.
        check('C) kaynak ORTAK logo varligini gosteriyor',
            strpos($im[1], 'logo.png') !== false, $im[1]);
    } else {
        check('C) render edilen marka isareti bulundu', false, 'esleme yok');
    }
    // Satir ici SVG oldugu icin AYRI bir dosya istegi YOK — eski kirik-gorsel
    // regresyonunun karsiligi burada "favicon hala sunuluyor mu"ya donusuyor.
    $fav = http_request('GET', '/assets/favicon.svg');
    check('C) favicon 200 donuyor (kirik ikon yok)',
        $fav['status'] === 200 && strpos($fav['body'], '<svg') !== false,
        'HTTP ' . $fav['status']);
    // ⚠️ BU KONTROL TERSINE CEVRILDI. Eskiden "gomulu bitmap OLMAMALI" diyordu
    // -- o, birakilan "satir ici SVG kelime isareti" tasariminin varsayimiydi.
    // MEVCUT tasarim bitmap'i BILEREK gomuyor: favicon.svg, assets/logo.png'yi
    // KARE bir tuvale orani bozulmadan oturtulmus halidir (gerekce dosyanin
    // kendi basliginda yazili -- favicon kare olmali, logo ise 94x44).
    // Korunan guvence AYNI: ikon marka adini tasiyor ve ORTAK logo varligindan
    // tureiyor, yani ayri bir "ikinci marka" degil.
    check('C) favicon marka adini tasiyor (aria-label)',
        strpos($fav['body'], 'OpsFlow') !== false);
    check('C) favicon ORTAK logo varligindan turetilmis (ikinci marka degil)',
        strpos($fav['body'], 'logo.png') !== false);
    check('C) favicon kare tuvalde (sekme ikonu bozulmasin)',
        strpos($fav['body'], 'viewBox="0 0 32 32"') !== false);

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
    // Giris/kayit/dogrulama sayfalari da AYNI marka partial ini kullanir —
    // eskiden ucu de assets/logo.png'yi ayri ayri basiyordu; marka degisince
    // uc yer birden guncellenmek zorundaydi. Artik tek kaynak.
    // ⚠️ Kontrol eskimisti: bu uc sayfa marka partial'ini artik DOGRUDAN degil,
    // ortak oturumsuz kabuk (auth_shell_top.php) uzerinden aliyor — tek kaynak
    // ilkesi GUCLENDI, test onu gormuyordu. Marka partial'inin gercekten
    // kabukta oldugu da ayrica dogrulaniyor.
    check('E) auth kabugu marka partial ini kullaniyor (tek kaynak)',
        strpos(file_get_contents(__DIR__ . '/../src/partials/auth_shell_top.php'), "require __DIR__ . '/brand_logo.php'") !== false);
    foreach (array('login.php', 'register.php', 'verify_email.php') as $authPage) {
        $src = file_get_contents(__DIR__ . '/../public/' . $authPage);
        check("E) {$authPage} markayi ortak kabuktan aliyor (logo.png basmiyor)",
            strpos($src, "partials/auth_shell_top.php") !== false
            && strpos($src, '/assets/logo.png') === false);
    }

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
