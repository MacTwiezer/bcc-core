<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

define('SAHTE', 'kotu.example');

echo "A) Yapilandirma gercekten bir taban adres veriyor\n";

$taban = bcc_app_base_url();
check('A) bcc_app_base_url() bos donmuyor', $taban !== '', var_export($taban, true));
check('A) http(s) ile basliyor', preg_match('#^https?://#', $taban) === 1, $taban);

echo "\nB) SAHTE Host basligi altinda link ZEHIRLENMIYOR\n";

$yedek = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;

$_SERVER['HTTP_HOST'] = SAHTE;
$link = bcc_slack_app_url('/interface.php?base_id=1&table_id=2');
check('B) ⭐ link sahte Host icermiyor', strpos($link, SAHTE) === false, $link);
check('B) link yapilandirilmis tabandan uretildi',
    strpos($link, $taban . '/interface.php') === 0, $link);

$_SERVER['HTTP_HOST'] = 'a|b>c.example';
$link2 = bcc_slack_app_url('/grid.php?table_id=3');
check('B) Host icindeki "|" ve ">" linke sizmiyor',
    strpos($link2, '|') === false && strpos($link2, '>') === false, $link2);

unset($_SERVER['HTTP_HOST']);
$link3 = bcc_slack_app_url('/grid.php?table_id=4');
check('B) Host hic yokken de gecerli link uretiliyor',
    strpos($link3, $taban . '/grid.php') === 0, $link3);

if ($yedek === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $yedek; }

echo "\nC) Kaynak: hicbir bildirim yolu KENDI host hesabini yapmiyor\n";

$kod = '';
foreach (token_get_all(file_get_contents(__DIR__ . '/../src/slack.php')) as $t) {
    if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { continue; }
    $kod .= is_array($t) ? $t[1] : $t;
}
check('C) src/slack.php kodunda HTTP_HOST gecmiyor',
    strpos($kod, 'HTTP_HOST') === false);
check('C) bcc_slack_app_url tek adres kaynagi olarak duruyor',
    substr_count($kod, 'bcc_slack_app_url(') >= 5,
    'gecis sayisi: ' . substr_count($kod, 'bcc_slack_app_url('));

echo "\nD) CANLI: Apache sahte Host basligini kabul ediyor (vektor gercekti)\n";

$ch = curl_init('http://localhost/login.php');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array('Host: ' . SAHTE),
    CURLOPT_FOLLOWLOCATION => false,
));
$govde = curl_exec($ch);
$durum = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('D) sahte Host ile de sayfa servis ediliyor (200)', $durum === 200, 'HTTP ' . $durum);
check('D) yine de sayfa kaynaginda sahte host GECMIYOR',
    is_string($govde) && strpos($govde, SAHTE) === false);

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
