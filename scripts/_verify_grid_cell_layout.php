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

function css_rule($css, $selectorPart)
{
    $pos = strpos($css, $selectorPart);
    if ($pos === false) {
        return null;
    }
    $open = strpos($css, '{', $pos);
    $close = $open === false ? false : strpos($css, '}', $open);

    return ($open !== false && $close !== false) ? substr($css, $open + 1, $close - $open - 1) : null;
}

$css = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . '/public/assets/style.css'));
$schema = (string) file_get_contents($root . '/src/schema.php');

echo "A) Ortalanan sutunlar\n";

$ortala = css_rule($css, 'table.grid tbody td.grid-cell[data-field-type="time"] {');
check('A) ortalama kurali var', $ortala !== null && strpos($ortala, 'text-align: center;') !== false);
foreach (array('number', 'checkbox', 'date', 'single_select', 'multiple_select', 'time') as $tip) {
    check('A) ' . $tip . ' ortalaniyor',
        preg_match('/td\.grid-cell\[data-field-type="' . $tip . '"\],?[^{]*\{\s*text-align: center;/s', $css) === 1);
}
check('A) td data-field-type attribute u hala basiliyor (seciciler buna bagli)',
    strpos($schema, 'data-field-type="<?php echo htmlspecialchars($f[\'field_type\']') !== false);

echo "\nB) Coklu secim ve dosya eki alt alta, satir icerige gore uzuyor\n";

$liste = css_rule($css, 'table.grid tbody td.grid-cell[data-field-type="attachment"] .cell-view {');
check('B) ortak kural bulundu', $liste !== null);
foreach (array('display: flex;', 'flex-direction: column;', 'height: auto;', 'white-space: normal;', 'overflow: visible;', '-webkit-line-clamp: none;') as $bildirim) {
    check('B) ' . $bildirim, $liste !== null && strpos($liste, $bildirim) !== false);
}
check('B) ayni kural coklu secimi de kapsiyor',
    strpos($css, "td.grid-cell[data-field-type=\"multiple_select\"] .cell-view,\ntable.grid tbody td.grid-cell[data-field-type=\"attachment\"] .cell-view {") !== false
    || strpos($css, "td.grid-cell[data-field-type=\"multiple_select\"] .cell-view,\r\ntable.grid tbody td.grid-cell[data-field-type=\"attachment\"] .cell-view {") !== false);
$ek = css_rule($css, 'table.grid tbody td.grid-cell[data-field-type="attachment"] .attachment-name {');
check('B) ek adi sutun genisligine sigiyor (110px siniri kalkti)', $ek !== null && strpos($ek, 'max-width: none;') !== false && strpos($ek, 'min-width: 0;') !== false);
check('B) cip genisligi hucreyi asmiyor',
    ($c = css_rule($css, 'td.grid-cell[data-field-type="multiple_select"] .choice-chip {')) !== null && strpos($c, 'max-width: 100%;') !== false);

echo "\nC) Tum hucreler satir icinde DIKEYDE ortali (2026-09-15 karari)\n";

$orta = css_rule($css, 'table.grid tbody tr[data-record-id] > td.grid-rownum {');
check('C) veri satiri hucreleri + sira numarasi vertical-align: middle', $orta !== null && strpos($orta, 'vertical-align: middle;') !== false);
check('C) eski "ustte hizala" kurali kalmadi', strpos($css, 'vertical-align: top;') === false);
$gorunum = css_rule($css, 'table.grid tbody tr[data-record-id] > td.grid-cell > .cell-view {');
check('C) .cell-view hucreyi boydan kaplamiyor (height: auto) -> icerik ortaya oturuyor',
    $gorunum !== null && strpos($gorunum, 'height: auto;') !== false);
$hover = css_rule($css, 'table.grid tbody tr[data-record-id] > td.grid-cell.editable:not(.editing):hover {');
check('C) uzerine gelme zemini tum hucreyi kapliyor (td uzerinde)',
    $hover !== null && strpos($hover, 'background-color: var(--bcc-surface-hover);') !== false);
check('C) grup basligi satirlari etkilenmiyor (tr[data-record-id] ile sinirli)',
    preg_match('/table\.grid tbody td\s*\{[^}]*vertical-align/s', $css) === 0);

$gecti = count(array_filter($results));
$toplam = count($results);
echo "\n==== SONUC: " . $gecti . '/' . $toplam . " ====\n";
exit($gecti === $toplam ? 0 : 1);
