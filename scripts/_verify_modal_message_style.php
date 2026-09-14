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
    $needle = $selector . ' {';
    $pos = strpos($css, $needle);
    if ($pos === false) {
        $needle = $selector . '{';
        $pos = strpos($css, $needle);
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

echo "--- A) Uyari metninin kendi sinifi var ---\n";

$msg = css_rule_body($css, '.home-modal-message');
check('.home-modal-message kurali tanimli', $msg !== null);

if ($msg !== null) {
    check('ust margin SIFIRLANMIS (p varsayilan 1em ust bosluk metni asagi kaydiriyordu)',
        preg_match('/margin:\s*0\s+0\s+[0-9.]+rem/', $msg) === 1,
        trim($msg));
    check('alt bosluk var (dugmelere yapismiyor)',
        preg_match('/margin:\s*0\s+0\s+(?!0)[0-9.]+rem/', $msg) === 1);
    check('satir araligi acik yazilmis (cok satirli uyari sikismasin)',
        strpos($msg, 'line-height:') !== false);
    check('normal govde punto (etiket puntosu degil)',
        strpos($msg, 'font-size: 0.875rem') !== false);
    check('kalinlik verilmemis -> normal agirlik (etiket gibi kalin degil)',
        strpos($msg, 'font-weight') === false, trim($msg));
    check('okunur metin rengi (soluk etiket rengi degil)',
        strpos($msg, 'var(--bcc-text)') !== false && strpos($msg, 'text-muted') === false);
}

echo "\n--- B) Etiket sinifi bozulmadi ---\n";

$label = css_rule_body($css, '.home-modal-label');
check('.home-modal-label hala duruyor (form etiketleri onu kullaniyor)', $label !== null);
if ($label !== null) {
    check('etiket hala kalin ve kucuk',
        strpos($label, 'font-weight: 600') !== false && strpos($label, 'font-size: 0.8rem') !== false);
}

echo "\n--- C) Uyari paragraflari yeni sinifi kullaniyor ---\n";

$confirmJs = (string) file_get_contents($root . '/public/assets/confirm-modal.js');
$gridSrc   = (string) file_get_contents($root . '/public/grid.php');

check('bcc_confirm mesaji .home-modal-message kullaniyor',
    strpos($confirmJs, 'class="home-modal-message" data-confirm-message') !== false);
check('tablo silme ozeti .home-modal-message kullaniyor',
    strpos($gridSrc, '<p class="home-modal-message" id="gs-table-delete-summary">') !== false);
check('yapistirma ozeti .home-modal-message kullaniyor',
    strpos($gridSrc, '<p class="home-modal-message" id="gs-paste-summary">') !== false);

echo "\n--- D) Geriye kalan <p class=\"home-modal-label\"> YOK ---\n";

$taranan = array(
    'public/assets/confirm-modal.js',
    'public/grid.php',
    'public/interface.php',
    'public/kanban.php',
    'src/partials/create_base_modal.php',
    'src/partials/create_team_modal.php',
);
$kacak = array();
foreach ($taranan as $rel) {
    $src = @file_get_contents($root . '/' . $rel);
    if ($src === false) {
        continue;
    }
    if (preg_match('/<p[^>]*class="[^"]*home-modal-label/', $src)) {
        $kacak[] = $rel;
    }
}
check('hicbir <p> etiket sinifini tasimiyor (span/label kullanimlari serbest)',
    empty($kacak), implode(', ', $kacak));

$total = count($results);
$passed = count(array_filter($results));
echo "\n" . $passed . '/' . $total . ($passed === $total ? " GECTI\n" : " -- BAZI KONTROLLER KALDI\n");
exit($passed === $total ? 0 : 1);
