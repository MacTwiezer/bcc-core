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

function strip_css_comments($src)
{
    return preg_replace('#/\*.*?\*/#s', '', $src);
}

function css_rule_body($css, $selector)
{
    $pos = strpos($css, $selector . ' {');
    if ($pos === false) {
        $pos = strpos($css, $selector . '{');
    }
    if ($pos === false) {
        return null;
    }
    $start = strpos($css, '{', $pos);
    $end = strpos($css, '}', $start);
    if ($start === false || $end === false) {
        return null;
    }
    return substr($css, $start + 1, $end - $start - 1);
}

$css = strip_css_comments((string) file_get_contents($root . '/public/assets/home.css'));
$markup = (string) file_get_contents($root . '/src/partials/account_menu.php');

echo "--- A) Tek kaydirma kutusu: iki liste de ONUN icinde ---\n";

check('.bcc-trash-body sarmalayicisi isaretlemede var',
    strpos($markup, '<div class="bcc-trash-body">') !== false);

$bodyPos = strpos($markup, '<div class="bcc-trash-body">');
$basePos = strpos($markup, 'data-trash-list');
$kayitPos = strpos($markup, 'data-trash-record-list');

check('base listesi sarmalayicinin ICINDE',
    $bodyPos !== false && $basePos !== false && $bodyPos < $basePos);
check('kayit listesi de sarmalayicinin ICINDE',
    $bodyPos !== false && $kayitPos !== false && $bodyPos < $kayitPos);

$body = css_rule_body($css, '.bcc-trash-body');
check('.bcc-trash-body kurali tanimli', $body !== null);

if ($body !== null) {
    check('kaydirmayi O yapiyor', strpos($body, 'overflow-y: auto') !== false);
    check('kalan alani kapliyor (flex: 1 1 auto)', strpos($body, 'flex: 1 1 auto') !== false);
    check('min-height: 0 — flex cocugunun kucululebilmesi icin SART',
        strpos($body, 'min-height: 0') !== false, trim($body));
}

echo "\n--- B) Listelerin KENDI kaydirmasi kalmadi (asil kusur buydu) ---\n";

$list = css_rule_body($css, '.bcc-trash-list');
check('.bcc-trash-list kurali duruyor', $list !== null);
if ($list !== null) {
    check('listede artik overflow YOK — iki ayri kaydirma kutusu kisa bolumu kirpiyordu',
        strpos($list, 'overflow') === false, trim($list));
    check('liste dikey yigin olarak kaliyor',
        strpos($list, 'flex-direction: column') !== false);
}

echo "\n--- C) Bolum basligi kaydirirken kayboluyor mu ---\n";

$title = css_rule_body($css, '.bcc-trash-section-title');
check('.bcc-trash-section-title kurali duruyor', $title !== null);
if ($title !== null) {
    check('baslik yapiskan', strpos($title, 'position: sticky') !== false);
    check('ust kenara yapisiyor', strpos($title, 'top: 0') !== false);
    check('ZEMINI VAR — saydam birakilirsa altindan gecen satirlar basligin icinden gorunur',
        strpos($title, 'background: var(--bcc-surface)') !== false, trim($title));
    check('altindaki satirin ustune ciziliyor (z-index)',
        strpos($title, 'z-index') !== false);
    check('ust bosluk margin degil PADDING — yapiskan bir ogede margin zemin birakmaz',
        strpos($title, 'padding: 0.6rem') !== false && strpos($title, 'margin: 0 0') !== false,
        trim($title));
}

echo "\n--- D) Modalin kendisi ---\n";

$modal = css_rule_body($css, '.bcc-trash-modal');
check('.bcc-trash-modal kurali duruyor', $modal !== null);
if ($modal !== null) {
    check('dikey flex — basliK sabit, govde esner',
        strpos($modal, 'flex-direction: column') !== false);
    check('yukseklik gorunur alana bagli (--bcc-vh, mobil adres cubugu icin)',
        strpos($modal, 'var(--bcc-vh)') !== false);
}

echo "\n--- E) JS in dayandigi kancalar bozulmadi ---\n";

$js = (string) file_get_contents($root . '/public/assets/account-menu.js');
check('base listesi kancasi: data-trash-list', strpos($js, 'data-trash-list') !== false);
check('kayit listesi kancasi: data-trash-record-list', strpos($js, 'data-trash-record-list') !== false);
check('satir sinifi: .bcc-trash-item', strpos($js, "'.bcc-trash-item'") !== false);
check('sarmalayici JS tarafindan aranmiyor (yalnizca duzen degisti)',
    strpos($js, 'bcc-trash-body') === false);

$total = count($results);
$passed = count(array_filter($results));
echo "\n" . $passed . '/' . $total . ($passed === $total ? " GECTI\n" : " -- BAZI KONTROLLER KALDI\n");
exit($passed === $total ? 0 : 1);
