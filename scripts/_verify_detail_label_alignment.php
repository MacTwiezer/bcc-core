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

$js = (string) file_get_contents($root . '/public/assets/grid-row-detail.js');
$css = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . '/public/assets/style.css'));

echo "A) Kayit detayinda etiket, degerin ILK SATIRIYLA hizalaniyor\n";

check('A) firstLineCenter() tanimli', strpos($js, 'function firstLineCenter(root)') !== false);
check('A) gizli alt agaclar atlaniyor (getClientRects bos -> FILTER_REJECT)',
    strpos($js, 'if (!node.getClientRects().length) {') !== false && strpos($js, 'return NodeFilter.FILTER_REJECT;') !== false);
check('A) metin dugumunde ilk satir kutusunun ortasi', strpos($js, 'return textRects[0].top + textRects[0].height / 2;') !== false);
check('A) textarea / coklu select: ust kenar + kenarlik + dolgu + yarim satir',
    strpos($js, "if (tag === 'TEXTAREA' || (tag === 'SELECT' && (node.multiple || node.size > 1))) {") !== false);
check('A) coklu select satir yuksekligi gercek secenek yuksekliginden',
    strpos($js, '(node.scrollHeight - padTop - (parseFloat(cs.paddingBottom) || 0)) / node.options.length') !== false);
check('A) tek satirlik input/select, onay kutusu, resim: kutunun ortasi',
    strpos($js, "if (tag === 'INPUT' || tag === 'SELECT' || tag === 'IMG' || tag === 'svg') {") !== false);

check('A) alignDetailLabels(): once sifirla, sonra oku, sonra yaz (tek yeniden yerlesim)',
    preg_match("/function alignDetailLabels\(\) \{.*?setProperty\('--grid-detail-label-offset', '0px'\).*?var offsets = rows\.map.*?rows\.forEach\(function \(row, i\)/s", $js) === 1);
check('A) deger etiketten yukarida kalirsa negatif dolgu verilmiyor', strpos($js, 'Math.max(0, Math.round((valueCenter - labelCenter) * 2) / 2)') !== false);
check('A) olculemeyen satir CSS varsayilanina donuyor', strpos($js, "row.style.removeProperty('--grid-detail-label-offset');") !== false);
check('A) kayit acilinca hizalaniyor', preg_match('/overlay\.hidden = false;\s*fieldsContainer\.querySelectorAll\(\'\.grid-detail-textarea\'\)\.forEach\(autoGrowTextarea\);\s*alignDetailLabels\(\);/', $js) === 1);
check('A) icerik degisince (ek eklendi/silindi) yeniden hizalaniyor',
    strpos($js, 'new window.MutationObserver(scheduleAlignDetailLabels).observe(fieldsContainer, { childList: true, subtree: true });') !== false);
check('A) pencere boyutu degisince yeniden hizalaniyor', strpos($js, "window.addEventListener('resize', scheduleAlignDetailLabels);") !== false);

echo "\nB) CSS\n";

check('B) etiket ust dolgusu satirdaki degiskenden, yoksa eski 0.65rem',
    preg_match('/\.grid-detail-field-inline \.grid-detail-field-label \{[^}]*padding-top: var\(--grid-detail-label-offset, 0\.65rem\);/s', $css) === 1);
check('B) degisken SATIRA yaziliyor, etikete degil (Kaydi gonder onizlemesi etiketi kopyaliyor, kopya etkilenmesin)',
    strpos($js, "row.style.setProperty('--grid-detail-label-offset'") !== false
    && strpos($js, 'label.style.paddingTop') === false);

$gecti = count(array_filter($results));
$toplam = count($results);
echo "\n==== SONUC: " . $gecti . '/' . $toplam . " ====\n";
exit($gecti === $toplam ? 0 : 1);
