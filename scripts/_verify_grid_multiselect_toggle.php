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

$gridJs = (string) file_get_contents($root . '/public/assets/grid.js');
$detailJs = (string) file_get_contents($root . '/public/assets/grid-row-detail.js');

$start = strpos($gridJs, "} else if (type === 'multiple_select') {");
$end = $start === false ? false : strpos($gridJs, '} else {', $start);
$blok = ($start !== false && $end !== false) ? substr($gridJs, $start, $end - $start) : '';

echo "A) Coklu secim kutusu Ctrl olmadan da coklu secim yapiyor\n";

check('A) buildInput multiple_select dali bulundu', $blok !== '');
check('A) liste kutusu multiple', strpos($blok, 'input.multiple = true;') !== false);
check('A) secenege mousedown dinleyicisi var', strpos($blok, "input.addEventListener('mousedown'") !== false);
check('A) yalnizca sol tik ve <option> hedefi', strpos($blok, 'e.button !== 0') !== false && strpos($blok, "tagName !== 'OPTION'") !== false);
check('A) tarayicinin "tek secim" davranisi engelleniyor (preventDefault)', strpos($blok, 'e.preventDefault();') !== false);
check('A) secim tersine cevriliyor (toggle)', strpos($blok, 'e.target.selected = !e.target.selected;') !== false);
check('A) odak kutuda kaliyor (blur -> erken kayit olmasin)', strpos($blok, 'listbox.focus();') !== false);
check('A) kaydirma konumu korunuyor', strpos($blok, 'listbox.scrollTop = scrollTop;') !== false);
check('A) change olayi yayiliyor', strpos($blok, "new Event('change'") !== false);

echo "\nB) Kayit yollari bozulmadi\n";

check('B) grid: kayit hala blur/Enter ile, secili tum secenekler JSON', strpos($gridJs, "input.addEventListener('blur', commit);") !== false
    && strpos($gridJs, 'value = JSON.stringify(selectedOptions);') !== false);
check('B) detay paneli ayni buildInput i kullaniyor', strpos($detailJs, 'window.BCC_GRID.buildInput(field.field_type') !== false);
check('B) detay paneli coklu secimde change ile KAYDETMIYOR (yalnizca blur)', strpos($detailJs, "input.tagName === 'SELECT' && !input.multiple") !== false);

$gecti = count(array_filter($results));
$toplam = count($results);
echo "\n==== SONUC: " . $gecti . '/' . $toplam . " ====\n";
exit($gecti === $toplam ? 0 : 1);
