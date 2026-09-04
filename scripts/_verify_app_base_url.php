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

$hostYedek = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
$ayarYedek = isset($GLOBALS['APP_BASE_URL']) ? $GLOBALS['APP_BASE_URL'] : null;

echo "A) Bu kurulumun GERCEK hali: ayar dolu, yedek hic calismiyor\n";

check('A) $APP_BASE_URL dolu', is_string($ayarYedek) && $ayarYedek !== '',
    var_export($ayarYedek, true));

$_SERVER['HTTP_HOST'] = 'kotu.example';
$taban = bcc_app_base_url();
check('A) sahte Host taban adrese SIZMIYOR', strpos($taban, 'kotu.example') === false, $taban);
check('A) taban http(s) ile basliyor', preg_match('#^https?://#', $taban) === 1, $taban);
check('A) sondaki egik cizgi kirpiliyor', substr($taban, -1) !== '/', $taban);

echo "\nB) Ayar BOSALTILIRSA: yedek Host DOGRULANIYOR\n";

$GLOBALS['APP_BASE_URL'] = '';

$gecerli = array(
    'ornek.com'      => 'http://ornek.com',
    'ornek.com:8080' => 'http://ornek.com:8080',
    '[2001:db8::1]'  => 'http://[2001:db8::1]',
    'alt.ornek.com'  => 'http://alt.ornek.com',
);
foreach ($gecerli as $host => $beklenen) {
    $_SERVER['HTTP_HOST'] = $host;
    check('B) gecerli ad korunuyor: "' . $host . '"', bcc_app_base_url() === $beklenen, bcc_app_base_url());
}

$kotu = array(
    'kotu.example/@ele.gecir',
    "ornek.com\r\nX-Enjekte: 1",
    'a|b>c.example',
    'ornek.com"onmouseover="alert(1)',
    'ornek.com?x=1',
    'ornek.com#parca',
    'kullanici@kotu.example',
    ' bosluklu.example ',
    '',
);
foreach ($kotu as $host) {
    $_SERVER['HTTP_HOST'] = $host;
    $u = bcc_app_base_url();
    $etiket = str_replace(array("\r", "\n"), array('\\r', '\\n'), $host);
    check('B) reddediliyor: "' . $etiket . '"', $u === 'http://localhost', $u);
}

unset($_SERVER['HTTP_HOST']);
check('B) Host hic yokken de gecerli adres', bcc_app_base_url() === 'http://localhost', bcc_app_base_url());

$_SERVER['HTTP_HOST'] = str_repeat('a', 260) . '.example';
check('B) 253 karakterden uzun ad reddediliyor', bcc_app_base_url() === 'http://localhost', bcc_app_base_url());

$_SERVER['HTTP_HOST'] = 'ornek.com';
$_SERVER['HTTPS'] = 'on';
check('B) HTTPS acikken sema https', bcc_app_base_url() === 'https://ornek.com', bcc_app_base_url());
$_SERVER['HTTPS'] = 'off';
check('B) HTTPS "off" ise sema http', bcc_app_base_url() === 'http://ornek.com', bcc_app_base_url());
unset($_SERVER['HTTPS']);

$GLOBALS['APP_BASE_URL'] = $ayarYedek;
if ($hostYedek === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $hostYedek; }

echo "\nC) Kaynak: mutlak baglanti ureten HER yol bu fonksiyondan geciyor\n";

$kod = function ($yol) {
    $out = '';
    foreach (token_get_all(file_get_contents(__DIR__ . '/../' . $yol)) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};

foreach (array('public/forgot-password.php', 'public/register.php', 'src/slack.php') as $dosya) {
    $k = $kod($dosya);
    check('C) ' . $dosya . ' bcc_app_base_url() kullaniyor',
        strpos($k, 'bcc_app_base_url()') !== false);
    check('C) ' . $dosya . ' KENDI HTTP_HOST hesabini yapmiyor',
        strpos($k, 'HTTP_HOST') === false);
}

echo "\nD) Bu betik gercekten e-posta GONDERMEDI\n";

$mailIz = (int) bcc_fetch_column(
    "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'mail.%' AND created_at >= (NOW() - INTERVAL 2 MINUTE)"
);
check('D) son 2 dakikada mail denetim izi yok', $mailIz === 0, (string) $mailIz);

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
