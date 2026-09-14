<?php

/*
 * Ana sayfa base kartinin dugmeleri SONRADAN eklenen kartta da calisiyor mu —
 * 2026-09-14.
 *
 * Cop kutusundan geri yuklenen kart sayfaya innerHTML ile enjekte ediliyor
 * (account-menu.js insertRestoredCard). Yildiz, "Tabloya git"/"Duyuru" ve "..."
 * menusu dugmelere sayfa yuklenirken TEK TEK baglaniyordu; enjekte karta hic
 * baglanmiyordu. Kart bir <a> oldugu icin tiklama karta dusup kullaniciyi
 * base'e goturuyordu (tarayicida olculdu: yildiz istegi yok, Duyuru yerine base).
 *
 * Bu betik kaynak duzeyinde kilitler: dinleyiciler belge seviyesinde, menu
 * baglama fonksiyonu tekrar cagrilabilir ve cift baglamaya karsi korumali,
 * geri yukleme kodu olayi yayinliyor, JS'in dayandigi isaretleme sunucuda var.
 * Davranisin kendisi tarayicida olculdu (gunluk 2026-09-14 §11).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

$root = dirname(__DIR__);
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

function strip_js_comments($src)
{
    $out = '';
    $len = strlen($src);
    $i = 0;
    $inLine = false;
    $inBlock = false;
    $quote = '';
    while ($i < $len) {
        $c = $src[$i];
        $n = ($i + 1 < $len) ? $src[$i + 1] : '';
        if ($inLine) {
            if ($c === "\n") { $inLine = false; $out .= $c; }
            $i++;
            continue;
        }
        if ($inBlock) {
            if ($c === '*' && $n === '/') { $inBlock = false; $i += 2; continue; }
            $i++;
            continue;
        }
        if ($quote !== '') {
            $out .= $c;
            if ($c === '\\') { $out .= $n; $i += 2; continue; }
            if ($c === $quote) { $quote = ''; }
            $i++;
            continue;
        }
        if ($c === '/' && $n === '/') { $inLine = true; $i += 2; continue; }
        if ($c === '/' && $n === '*') { $inBlock = true; $i += 2; continue; }
        if ($c === '"' || $c === "'") { $quote = $c; $out .= $c; $i++; continue; }
        $out .= $c;
        $i++;
    }
    return $out;
}

$home = strip_js_comments((string) file_get_contents($root . '/public/assets/home.js'));
$menuJs = strip_js_comments((string) file_get_contents($root . '/public/assets/account-menu.js'));
$schema = (string) file_get_contents($root . '/src/schema.php');

echo "--- A) Yildiz: belge seviyesinde ---\n";

check('eski "her yildiz dugmesine tek tek bagla" deseni YOK',
    strpos($home, "querySelectorAll('.home-base-star-btn')") === false);
check('tiklama belgede closest(\'.home-base-star-btn\') ile yakalaniyor',
    strpos($home, "closest('.home-base-star-btn')") !== false);
check('kartin <a> gezinmesi iptal ediliyor',
    preg_match("/closest\('\.home-base-star-btn'\).*?e\.preventDefault\(\)/s", $home) === 1);
check('yildiz isi ayri fonksiyonda (handleStarClick)',
    strpos($home, 'function handleStarClick(btn)') !== false);
check('istek hala star_base.php\'ye gidiyor', strpos($home, "'/api/star_base.php'") !== false);

echo "\n--- B) Tabloya git / Duyuru: belge seviyesinde ---\n";

check('eski "her data-nav-href dugmesine tek tek bagla" deseni YOK',
    strpos($home, "querySelectorAll('[data-nav-href]')") === false);
check('tiklama belgede closest(\'[data-nav-href]\') ile yakalaniyor',
    strpos($home, "closest('[data-nav-href]')") !== false);
check('hedef dugmenin kendi data-nav-href degeri (kartin href\'i DEGIL)',
    strpos($home, "window.location.href = btn.getAttribute('data-nav-href')") !== false);
check('gezinmeden once <a> varsayilani iptal',
    preg_match("/closest\('\[data-nav-href\]'\).*?e\.preventDefault\(\).*?data-nav-href/s", $home) === 1);

echo "\n--- C) \"...\" menusu: tekrar cagrilabilir baglama ---\n";

check('baglama fonksiyonu var (wireMoreMenu)', strpos($home, 'function wireMoreMenu(menu)') !== false);
check('cift baglamaya karsi isaret (data-menu-wired)',
    strpos($home, "hasAttribute('data-menu-wired')") !== false && strpos($home, "setAttribute('data-menu-wired', '1')") !== false);
check('sayfa yuklenirken var olan menuler bu fonksiyondan geciyor',
    strpos($home, "querySelectorAll('.home-base-more-menu'), wireMoreMenu)") !== false);
check('eski tek seferlik "moreMenus.forEach" kalmadi', strpos($home, 'moreMenus') === false);
check('disari tiklayinca kapatma YALNIZCA yeni girislere baglaniyor (eskiler iki kez baglanmasin)',
    strpos($home, 'menuEntries.slice(ilkYeniGiris)') !== false);
check('sonradan eklenen kart icin olay dinleniyor',
    strpos($home, "document.addEventListener('bcc:base-card-inserted'") !== false);
check('olay dinleyicisi kartin menulerini wireMoreMenu\'dan geciriyor',
    preg_match("/bcc:base-card-inserted.*?querySelectorAll\('\.home-base-more-menu'\), wireMoreMenu\)/s", $home) === 1);

echo "\n--- D) Geri yukleme olayi yayinliyor ---\n";

$insPos = strpos($menuJs, 'function insertRestoredCard(data)');
$insBlok = $insPos === false ? '' : substr($menuJs, $insPos, 6000);
check('insertRestoredCard duruyor', $insPos !== false);
check('kart eklendikten SONRA bcc:base-card-inserted yayinlaniyor',
    preg_match("/grid\.appendChild\(kart\);.*?dispatchEvent\(new CustomEvent\('bcc:base-card-inserted', \{ detail: \{ card: kart \} \}\)\)/s", $insBlok) === 1);

echo "\n--- D2) Geri yuklemede grup sayaci ve kartin DOGRU yere dusmesi (§12) ---\n";

$css = (string) file_get_contents($root . '/public/assets/home.css');

check('ortak sayac fonksiyonu var (grubuEsitle)', strpos($home, 'function grubuEsitle(grid)') !== false);
check('geri yuklenen kart olayi sayaci esitliyor',
    (bool) preg_match("/bcc:base-card-inserted.*?grubuEsitle\(card\.parentElement\)/s", $home));
check('bosalan grup gizleniyor, kaldirilmiyor (baslik)', strpos($home, 'head.hidden = kalan === 0') !== false);
check('gizli izgara/baslik CSS ile gercekten gizleniyor (kendi display degerleri [hidden]\'i ezer)',
    (bool) preg_match('/\.home-base-grid\[hidden\],\s*\.home-section-head\[hidden\]\s*\{\s*display:\s*none;/', $css));

check('sunucu gruplu duzende izgaraya ekip kimligi basiyor (data-team-grid)',
    strpos($schema, 'data-team-grid="') !== false && strpos($schema, '$tableCounts, true, $tid);') !== false);

$p1 = strpos($insBlok, "'.home-base-grid .home-base-card[data-team-id=\"'");
$p2 = strpos($insBlok, "'.home-base-grid[data-team-grid=\"'");
$p3 = strpos($insBlok, "!g.hasAttribute('data-team-grid')");
check('hedef sirasi: 1) ayni ekipten kart, 2) ekibin kendi izgarasi, 3) ekip kimligi TASIMAYAN ana izgara',
    $p1 !== false && $p2 !== false && $p3 !== false && $p1 < $p2 && $p2 < $p3,
    var_export(array($p1, $p2, $p3), true));
check('3. adim baska ekibin izgarasini SECEMEZ (eski "tek izgara" dususu kalkti)',
    $p3 !== false);
check('yer bulunamazsa YALNIZCA base listesi olan sayfada yenileniyor',
    strpos($insBlok, "if (document.querySelector('.home-empty, .home-base-grid'))") !== false
    && strpos($insBlok, 'window.location.reload()') !== false);
check('Yildizlilar sayfasina yildizsiz kart eklenmiyor',
    strpos($insBlok, "indexOf('/starred.php') !== -1 && !kart.classList.contains('is-starred')") !== false);

echo "\n--- E) Silme (§6) bozulmadi ---\n";

check('silme hala belge seviyesinde', strpos($home, "closest('[data-base-delete]')") !== false);
check('silme temizligi duruyor', strpos($home, 'function baseKartiniTemizle(') !== false);

echo "\n--- F) JS'in dayandigi isaretleme sunucuda var ---\n";

check('kart <a class="home-base-card"', strpos($schema, 'class="home-base-card') !== false);
check('yildiz dugmesi .home-base-star-btn', strpos($schema, 'class="home-base-star-btn"') !== false);
check('"..." menusu .home-base-more-menu', strpos($schema, 'class="home-base-more-menu"') !== false);
check('Tabloya git data-nav-href', strpos($schema, 'class="home-base-data-btn" data-nav-href=') !== false);
check('Duyuru data-nav-href (interface.php)', strpos($schema, 'data-nav-href="/interface.php?base_id=') !== false);
check('geri yukleme ucu kart HTML\'ini ayni fonksiyonla uretiyor',
    strpos((string) file_get_contents($root . '/public/api/base_restore.php'), 'bcc_render_home_base_card(') !== false);

$total = count($results);
$passed = count(array_filter($results));
echo "\n" . $passed . '/' . $total . ($passed === $total ? " GECTI\n" : " -- BAZI KONTROLLER KALDI\n");
exit($passed === $total ? 0 : 1);
