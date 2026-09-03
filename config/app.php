<?php

$APP_BASE_URL = 'https://opsflow.bcccrm.com';

function bcc_brand_name()
{
    return 'OpsFlow';
}

function bcc_brand_domain()
{
    return 'opsflow.bcccrm.com';
}

// Sayfa başlığı / ana başlık gibi markanın TAM hâliyle görünmesi istenen
// yerler için: "OpsFlow — opsflow.bcccrm.com".
function bcc_brand_full()
{
    return bcc_brand_name() . ' — ' . bcc_brand_domain();
}

// $BCC_DEMO_LOGIN: login.php'deki "Hızlı Demo Girişi" butonları (sabit
// e-posta/şifre çiftlerini forma dolduran yardımcılar, bkz. src/demo_accounts.php).
//
// VARSAYILAN false — KAPALI olmalı: açıkken sayfa kaynağında demo hesapların
// şifreleri düz metin görünür. Bu, yerel rol testini kolaylaştırmak içindir,
// canlı bir kuruluma ASLA bu şekilde çıkmamalıdır. Yerel makinede açmak için
// config/app.local.php'ye (git'e girmez) `$BCC_DEMO_LOGIN = true;` yazın —
// aşağıdaki require o dosyayı bu satırdan SONRA yüklediği için yerel değer
// buradaki varsayılanı ezer.
$BCC_DEMO_LOGIN = false;

// $BCC_DEMO_PASSWORD: demo hesaplarının ortak şifresi.
//
// BURADA BİLEREK TANIMSIZ. Şifre bu depoda (açık/public bir GitHub deposu)
// LİTERAL OLARAK BULUNMAZ — güvenlik denetiminde src/demo_accounts.php'den
// çıkarıldı, çünkü o hesaplar veritabanında gerçekten var ve aktif; şifreyi
// yayınlamak, $BCC_DEMO_LOGIN kapalı olsa bile (hesaplar normal giriş
// formundan da denenebilir) çalışan bir kimlik bilgisini herkese açmak
// olurdu. Yerel makinede config/app.local.php'ye yazın.
//
// Tanımsız bırakılırsa: bcc_demo_accounts() BOŞ liste döner, login.php demo
// butonlarını hiç basmaz, scripts/seed_demo_users.php çalışmayı reddeder.

$bcc_localAppConfigPath = __DIR__ . '/app.local.php';
if (is_file($bcc_localAppConfigPath)) {
    require $bcc_localAppConfigPath;
}
unset($bcc_localAppConfigPath);

/**
 * E-postaya gömülecek mutlak bağlantıların tabanını döndürür.
 * $APP_BASE_URL doluysa O kullanılır; boşsa isteğin şeması + HTTP_HOST'una
 * düşer (eski davranış — yerel geliştirmede çalışmaya devam etsin diye).
 *
 * ⚠️ HTTP_HOST YEDEĞİ DOĞRULANIR. Host başlığını İSTEMCİ gönderir ve bu
 * fonksiyonun ürettiği adres parola sıfırlama (forgot-password.php) ve e-posta
 * doğrulama (register.php) bağlantılarının tabanıdır. Yedeğe düşülen bir
 * kurulumda saldırgan, kurbanın adresi için sıfırlama isteyip "Host: kotu.example"
 * yollayabilir; kurbanın kutusuna GERÇEK jetonu taşıyan ama saldırganın alan
 * adına giden bir bağlantı düşer ve tıklandığında jeton saldırgana gider —
 * yani hesap devralma. Bu yüzden yalnızca bir ana makine adında GEÇERLİ olan
 * karakterler kabul edilir (harf, rakam, nokta, tire, port için iki nokta,
 * IPv6 için köşeli parantez); başka bir şey içeriyorsa 'localhost'a düşülür.
 *
 * NOT: bu kurulumda yedek ERİŞİLEBİLİR DEĞİL — $APP_BASE_URL yukarıda dolu
 * geliyor ve config/app.local.php de dolu bir değer yazıyor. Doğrulama, ayarın
 * bir gün boşaltılması hâlinde en değerli bağlantının sessizce zehirlenebilir
 * olmaması için var (savunma katmanı, yaşayan bir açık kapatmıyor).
 */
function bcc_app_base_url()
{
    global $APP_BASE_URL;

    if (is_string($APP_BASE_URL) && $APP_BASE_URL !== '') {
        return rtrim($APP_BASE_URL, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';

    if ($host === '' || strlen($host) > 253 || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost';
    }

    return $scheme . '://' . $host;
}
