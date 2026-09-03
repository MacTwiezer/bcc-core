<?php
// bcc_app_base_url()'in uretttigi adresin ISTEMCI tarafindan yonlendirilemez
// oldugunu dogrular.
//
// NEDEN ONEMLI: bu fonksiyon parola sifirlama (public/forgot-password.php) ve
// e-posta dogrulama (public/register.php) baglantilarinin TABANIDIR; Slack
// bildirim baglantilari da (src/slack.php) buradan gecer.
//
// BULUNAN ACIK (savunma katmani): $APP_BASE_URL bos ise fonksiyon
// $_SERVER['HTTP_HOST']'a dusuyordu ve o basligi ISTEMCI gonderir. Yedege
// dusulen bir kurulumda saldirgan, kurbanin adresi icin sifirlama isteyip
// "Host: kotu.example" yollayabilir; kurbanin kutusuna GERCEK jetonu tasiyan
// ama saldirganin alan adina giden bir baglanti duser ve tiklandiginda jeton
// saldirgana gider - yani hesap devralma.
//
// DURUST KAPSAM: bu kurulumda yedek ERISILEBILIR DEGIL, cunku $APP_BASE_URL
// hem config/app.php'de hem config/app.local.php'de DOLU. Yani yasayan bir
// acik kapatilmadi; ayar bir gun bosaltilirsa en degerli baglantinin sessizce
// zehirlenmemesi icin dogrulama eklendi.
//
// ⚠️ BU BETIK E-POSTA GONDERMEZ. Duzeltme saf bir fonksiyonda; SMTP'ye
// dokunmak gercek bir mesaj ureterek testi yan etkili yapardi. Gonderim
// yapilmadigi asagida ayrica DOGRULANIR.
//
// Calistirma: C:\php73\php.exe scripts\_verify_app_base_url.php

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

// ---------------------------------------------------------------------------
echo "A) Bu kurulumun GERCEK hali: ayar dolu, yedek hic calismiyor\n";
// ---------------------------------------------------------------------------
check('A) $APP_BASE_URL dolu', is_string($ayarYedek) && $ayarYedek !== '',
    var_export($ayarYedek, true));

$_SERVER['HTTP_HOST'] = 'kotu.example';
$taban = bcc_app_base_url();
check('A) sahte Host taban adrese SIZMIYOR', strpos($taban, 'kotu.example') === false, $taban);
check('A) taban http(s) ile basliyor', preg_match('#^https?://#', $taban) === 1, $taban);
check('A) sondaki egik cizgi kirpiliyor', substr($taban, -1) !== '/', $taban);

// ---------------------------------------------------------------------------
echo "\nB) Ayar BOSALTILIRSA: yedek Host DOGRULANIYOR\n";
// ---------------------------------------------------------------------------
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

// Bunlarin HICBIRI adreste gorunmemeli - hepsi 'localhost'a dusmeli.
$kotu = array(
    'kotu.example/@ele.gecir',                 // egik cizgi: yol enjeksiyonu
    "ornek.com\r\nX-Enjekte: 1",               // CRLF: baslik enjeksiyonu
    'a|b>c.example',                           // Slack <url|metin> ayraclari
    'ornek.com"onmouseover="alert(1)',         // oznitelik kacisi denemesi
    'ornek.com?x=1',                           // sorgu dizesi
    'ornek.com#parca',                         // parca
    'kullanici@kotu.example',                  // userinfo hilesi
    ' bosluklu.example ',                      // bosluk
    '',                                        // bos
);
foreach ($kotu as $host) {
    $_SERVER['HTTP_HOST'] = $host;
    $u = bcc_app_base_url();
    $etiket = str_replace(array("\r", "\n"), array('\\r', '\\n'), $host);
    check('B) reddediliyor: "' . $etiket . '"', $u === 'http://localhost', $u);
}

unset($_SERVER['HTTP_HOST']);
check('B) Host hic yokken de gecerli adres', bcc_app_base_url() === 'http://localhost', bcc_app_base_url());

// Asiri uzun ad (DNS siniri 253) da reddedilmeli.
$_SERVER['HTTP_HOST'] = str_repeat('a', 260) . '.example';
check('B) 253 karakterden uzun ad reddediliyor', bcc_app_base_url() === 'http://localhost', bcc_app_base_url());

// HTTPS bayragi yedek yolda da dogru okunuyor mu.
$_SERVER['HTTP_HOST'] = 'ornek.com';
$_SERVER['HTTPS'] = 'on';
check('B) HTTPS acikken sema https', bcc_app_base_url() === 'https://ornek.com', bcc_app_base_url());
$_SERVER['HTTPS'] = 'off';
check('B) HTTPS "off" ise sema http', bcc_app_base_url() === 'http://ornek.com', bcc_app_base_url());
unset($_SERVER['HTTPS']);

// Yedekleri geri koy.
$GLOBALS['APP_BASE_URL'] = $ayarYedek;
if ($hostYedek === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $hostYedek; }

// ---------------------------------------------------------------------------
echo "\nC) Kaynak: mutlak baglanti ureten HER yol bu fonksiyondan geciyor\n";
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
echo "\nD) Bu betik gercekten e-posta GONDERMEDI\n";
// ---------------------------------------------------------------------------
// Gonderim olsaydi audit iz birakirdi; ayrica mailer fonksiyonu hic cagrilmadi.
$mailIz = (int) bcc_fetch_column(
    "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'mail.%' AND created_at >= (NOW() - INTERVAL 2 MINUTE)"
);
check('D) son 2 dakikada mail denetim izi yok', $mailIz === 0, (string) $mailIz);

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
