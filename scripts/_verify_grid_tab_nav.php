<?php

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

function eq($label, $actual, $expected)
{
    check($label, $actual === $expected,
        'beklenen ' . var_export($expected, true) . ', gelen ' . var_export($actual, true));
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

$jsPath = $root . '/public/assets/grid-cell-select.js';
$js = strip_js_comments((string) file_get_contents($jsPath));

echo "--- A) Tab dali kaynakta var ---\n";

check('grid-cell-select.js okunabiliyor', $js !== '', $jsPath);
check("Tab tusu ele aliniyor", strpos($js, "e.key === 'Tab'") !== false);
check('wrapCell yardimcisi tanimli', strpos($js, 'function wrapCell(') !== false);

$tabPos = strpos($js, "e.key === 'Tab'");
$tabBlok = $tabPos === false ? '' : substr($js, $tabPos, 900);

check('Tab dali varsayilan davranisi engelliyor (odak gridden kacmiyor)',
    strpos($tabBlok, 'e.preventDefault()') !== false);
check('yon shiftKey ile belirleniyor (Shift+Tab sola)',
    strpos($tabBlok, 'e.shiftKey ? -1 : 1') !== false);
check('sinirda wrapCell devreye giriyor',
    strpos($tabBlok, 'wrapCell(') !== false);
check('Tab secimi TEKE dusuruyor (focusTd temizleniyor)',
    strpos($tabBlok, 'focusTd = null') !== false);
check('hedef hucre gorunur alana kaydiriliyor',
    strpos($tabBlok, 'reveal(') !== false);

echo "\n--- B) Guvenlik agi: yanlis baglamda calismamali ---\n";

$guardPos = strpos($js, '!keyboardBelongsToGrid()');
check('klavye guardi Tab dalindan ONCE geliyor (hucre duzenlenirken Tab kacirilmaz)',
    $guardPos !== false && $tabPos !== false && $guardPos < $tabPos,
    'guard=' . var_export($guardPos, true) . ' tab=' . var_export($tabPos, true));

check('Tab dali yalnizca secili hucre varken calisiyor',
    strpos($js, "e.key === 'Tab' && anchorTd") !== false);

$escPos = strpos($js, "e.key === 'Escape'");
check('Escape hala secimi temizliyor (Tab tuzagindan cikis yolu)',
    $escPos !== false && $escPos < $tabPos);

echo "\n--- C) Mevcut davranis bozulmadi ---\n";

check('ok tuslari haritasi duruyor', strpos($js, 'var ARROWS = {') !== false);
check('ok tuslari Tab dalindan SONRA isleniyor',
    strpos($js, 'var delta = ARROWS[e.key];') > $tabPos);
check('Ctrl+A tumunu secme dali duruyor',
    strpos($js, "(e.key === 'a' || e.key === 'A')") !== false);

$wrapPos = strpos($js, 'function wrapCell(');
$wrapBlok = $wrapPos === false ? '' : substr($js, $wrapPos, 450);
check('ileri sarmalama sonraki satirin ILK hucresine gidiyor',
    strpos($wrapBlok, 'cells[0]') !== false);
check('geri sarmalama onceki satirin SON hucresine gidiyor',
    strpos($wrapBlok, 'cells[cells.length - 1]') !== false);
check('tablo disina tasma NULL donuyor (son hucrede Tab yerinde kaliyor)',
    strpos($wrapBlok, 'nextRow >= rows.length') !== false);

echo "\n--- D) Betik gercekten sayfaya yukleniyor ---\n";

$gridSrc = (string) file_get_contents($root . '/public/grid.php');
check('grid.php grid-cell-select.js etiketini basiyor',
    strpos($gridSrc, "bcc_asset_url('grid-cell-select.js')") !== false);

$total = count($results);
$passed = count(array_filter($results));
echo "\n" . $passed . '/' . $total . ($passed === $total ? " GECTI\n" : " -- BAZI KONTROLLER KALDI\n");
exit($passed === $total ? 0 : 1);
