<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

$results = array();
function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) { echo '         detay: ' . $detail . "\n"; }
}

function strip_js_comments($src)
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#^\s*//.*$#m', '', $src);
    return $src;
}
function strip_css_comments($src)
{
    return preg_replace('#/\*.*?\*/#s', '', $src);
}

function specificity($selector)
{
    $s = trim($selector);
    $classes = preg_match_all('/\.[A-Za-z_][-\w]*/', $s);
    $attrs = preg_match_all('/\[[^\]]+\]/', $s);
    $ids = preg_match_all('/#[A-Za-z_][-\w]*/', $s);
    $bare = preg_replace('/\.[A-Za-z_][-\w]*|\[[^\]]+\]|#[A-Za-z_][-\w]*|::?[a-z-]+(\([^)]*\))?/', ' ', $s);
    $elements = preg_match_all('/\b[A-Za-z][\w-]*\b/', $bare);
    return array($ids, $classes + $attrs, $elements);
}
function spec_cmp($a, $b)
{
    for ($i = 0; $i < 3; $i++) {
        if ($a[$i] !== $b[$i]) { return $a[$i] < $b[$i] ? -1 : 1; }
    }
    return 0;
}

$root = dirname(__DIR__);
$dpJs = strip_js_comments(file_get_contents($root . '/public/assets/dismissable-panel.js'));
$gridJs = strip_js_comments(file_get_contents($root . '/public/assets/grid.js'));
$css = strip_css_comments(file_get_contents($root . '/public/assets/style.css'));

echo "\n--- A) Ortak yardimci ---\n";
check('A) bcc_raiseFloatingHost tanimli',
    strpos($dpJs, 'window.bcc_raiseFloatingHost = function') !== false);
check('A) barindiran hucreyi closest("th, td") ile buluyor',
    strpos($dpJs, "closest('th, td')") !== false);

$raiseBody = '';
if (preg_match('#window\.bcc_raiseFloatingHost\s*=\s*function[^{]*\{(.*?)\n    \};#s', $dpJs, $rb)) {
    $raiseBody = $rb[1];
}
check('A) fonksiyon govdesi ayristirilabildi', $raiseBody !== '');
check('A) panel gecersizse erken cikiyor',
    strpos($raiseBody, 'return;') !== false);
check('A) hucre YOKSA sessizce cikiyor (grid disi cagrilar etkilenmesin)',
    strpos($raiseBody, 'if (host)') !== false,
    trim(preg_replace('/\s+/', ' ', $raiseBody)));
check('A) sinifi ekliyor/kaldiriyor (toggle)',
    strpos($dpJs, "classList.toggle('grid-floating-host'") !== false);

echo "\n--- B) <details> tabanli UC menu ortak baglayicidan ---\n";
check('B) ortak baglayici acilista yukseltiyor',
    preg_match('#bcc_raiseFloatingHost\(panel,\s*true\)#', $dpJs) === 1);
check('B) kapanista geri aliyor',
    preg_match('#bcc_raiseFloatingHost\(panel,\s*false\)#', $dpJs) === 1);
foreach (array(
    'grid-column-menu.js' => 'sutun basligi ▾',
    'grid-add-field.js' => '+ alan ekle',
    'grid-view-manage.js' => '+ yeni gorunum',
) as $file => $label) {
    $src = strip_js_comments(file_get_contents($root . '/public/assets/' . $file));
    check("B) $label ortak baglayiciyi kullaniyor ($file)",
        strpos($src, 'bcc_bindFloatingPanel(') !== false);
    check("B) $label KENDI yukseltmesini yazmiyor (kopya yok)",
        strpos($src, 'bcc_raiseFloatingHost') === false);
}

echo "\n--- C) grid.js'in IKI hucre popover'i ---\n";

$openCount = preg_match_all('#bcc_raiseFloatingHost\(popover,\s*true\)#', $gridJs);
$closeCount = preg_match_all('#bcc_raiseFloatingHost\(popover,\s*false\)#', $gridJs);
check('C) iki popover da acilista yukseltiyor', $openCount === 2, 'adet: ' . $openCount);
check('C) iki popover da kapanista geri aliyor', $closeCount === 2, 'adet: ' . $closeCount);
check('C) konum matematigi hala ORTAK yardimcidan (kopya yok)',
    substr_count($gridJs, 'bcc_positionFloating(popover') === 2);

echo "\n--- D) CSS kurali ve z-index bandi ---\n";
$hostRule = null;
if (preg_match('#([^{}]*\.grid-floating-host[^{}]*)\{([^}]*)\}#s', $css, $m)) {
    $hostSelectors = $m[1];
    $hostRule = $m[2];
}
check('D) .grid-floating-host kurali var', $hostRule !== null);
$z = null;
if ($hostRule !== null && preg_match('/z-index:\s*(\d+)/', $hostRule, $zm)) { $z = (int) $zm[1]; }
check('D) z-index tanimli', $z !== null);

check('D) grid kromunun USTUNDE (>15)', $z !== null && $z > 15, 'z=' . var_export($z, true));
check('D) modal ailesinin ALTINDA (<60)', $z !== null && $z < 60, 'z=' . var_export($z, true));

echo "\n--- E) OZGULLUK TUZAGI: donuk BASLIK kuralini yeniyor mu? ---\n";

$frozenSpec = specificity('table.grid thead th.grid-frozen-cell');
check('E) donuk baslik kurali hala z-index:3 yaziyor (premis dogrulandi)',
    preg_match('#table\.grid\s+thead\s+th\.grid-frozen-cell\s*\{[^}]*z-index:\s*3#s', $css) === 1);

$beatsFrozen = false;
$best = null;
if ($hostRule !== null) {
    foreach (explode(',', $hostSelectors) as $sel) {
        $sel = trim($sel);
        if ($sel === '') { continue; }
        $sp = specificity($sel);
        if ($best === null || spec_cmp($sp, $best) > 0) { $best = $sp; }

        if (strpos($sel, 'thead') !== false && strpos($sel, 'grid-frozen-cell') !== false
            && spec_cmp($sp, $frozenSpec) > 0) {
            $beatsFrozen = true;
        }
    }
}
check('E) donuk baslik icin KESIN olarak daha ozgul bir secici var',
    $beatsFrozen,
    'en yuksek secici: ' . var_export($best, true) . ' / frozen: ' . var_export($frozenSpec, true));
check('E) govde hucreleri (td) de kapsaniyor',
    $hostRule !== null && strpos($hostSelectors, 'td.grid-floating-host') !== false);

echo "\n--- F) Sizinti yok: acilis/kapanis dengeli ---\n";
$allOpen = preg_match_all('#bcc_raiseFloatingHost\([^,]+,\s*true\)#', $dpJs . $gridJs);
$allClose = preg_match_all('#bcc_raiseFloatingHost\([^,]+,\s*false\)#', $dpJs . $gridJs);
check('F) her yukseltmenin bir geri alma esi var', $allOpen === $allClose,
    "acilis=$allOpen kapanis=$allClose");
check('F) toplam uc cagri yeri (ortak baglayici + iki popover)', $allOpen === 3, 'adet: ' . $allOpen);

echo "\n--- G) CANLI: grid sayfasi ---\n";

$gridPhp = file_get_contents($root . '/public/grid.php');
check('G) grid.php style.css yukluyor (kural oradan geliyor)',
    strpos($gridPhp, "bcc_asset_url('style.css')") !== false);
check('G) sutun basligi menusu markup i yerinde',
    strpos($gridPhp, 'grid-th-menu-panel') !== false);
check('G) dismissable-panel.js grid.php de yukleniyor (yardimci gelsin)',
    strpos($gridPhp, "dismissable-panel.js") !== false);

$posHelper = strpos($gridPhp, 'dismissable-panel.js');
$posGrid = strpos($gridPhp, "bcc_asset_url('grid.js')");
$posColMenu = strpos($gridPhp, 'grid-column-menu.js');
check('G) yardimci grid.js ten ONCE yukleniyor',
    $posHelper !== false && $posGrid !== false && $posHelper < $posGrid,
    "helper@$posHelper grid@$posGrid");
check('G) yardimci grid-column-menu.js ten ONCE yukleniyor',
    $posHelper !== false && $posColMenu !== false && $posHelper < $posColMenu,
    "helper@$posHelper colmenu@$posColMenu");

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($pass === $total ? 'GECTI' : 'KALDI') . " ($pass/$total)\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
