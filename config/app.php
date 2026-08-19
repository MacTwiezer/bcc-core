<?php
// opsflow.bcccrm.com — uygulama geneli ayarlar.
//
// $APP_BASE_URL: E-POSTA İÇİNE KONACAK bağlantıların tabanı (ör. doğrulama
// linki). Boş bırakılırsa isteğin kendi $_SERVER['HTTP_HOST']'una düşer —
// tarayıcıdan gelen istekler için doğru, AMA e-posta için TEHLİKELİ:
//
//   Bulunan gerçek kusur: register.php doğrulama linkini HTTP_HOST'tan
//   kuruyordu. Uygulama localhost'ta çalıştığı için gönderilen maildeki
//   bağlantı "http://localhost/verify_email.php?token=..." oluyordu ve
//   ALICI BUNA ERİŞEMİYORDU — yani mail teknik olarak gidiyor ama işlevsiz.
//   Ayrıca HTTP_HOST istemciden gelen bir başlıktır; e-postaya gömülen bir
//   bağlantıyı ona dayandırmak host-header enjeksiyonuna da açık kapı bırakır.
//
// CANLI VARSAYILAN ARTIK DOLU: aşağıdaki değer canlı adrestir
// (https://opsflow.bcccrm.com). Sunucuda config/app.local.php OLMADIĞI için
// e-postalara gömülen bağlantılar doğrudan bu değeri kullanır.
//
// YEREL GELİŞTİRMEDE: config/app.local.php (git'e girmez, bkz. .gitignore)
// bu dosyadan SONRA yüklenir ve değeri ezer — bu makinede hâlâ
// http://localhost yazıyor, böylece yerel testte doğrulama bağlantıları
// çalışmaya devam eder. Canlı sunucuya o dosya KOPYALANMAMALIDIR.
//
// Şablon: config/app.local.php.example

$APP_BASE_URL = 'https://opsflow.bcccrm.com';

// ---------------------------------------------------------------------------
// MARKA — TEK KAYNAK
// ---------------------------------------------------------------------------
// Ürün adı ve alan adı UYGULAMA GENELİNDE yalnızca burada yazılıdır. Sayfa
// başlıkları, e-posta altbilgisi, telif satırı ve <meta> etiketleri bu üç
// fonksiyondan beslenir — hiçbir sayfa marka metnini kendi içine LİTERAL
// yazmaz.
//
// Gerekçe: marka daha önce "opsflow.bcccrm.com" olarak 20'den fazla dosyaya elle
// kopyalanmıştı. Yeniden markalaşmada o kopyaların her biri ayrı ayrı
// bulunmak zorunda kaldı; biri atlansaydı canlıda ESKİ ad görünmeye devam
// ederdi. Bir dahaki ad değişikliği bu üç satır.

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
 */
function bcc_app_base_url()
{
    global $APP_BASE_URL;

    if (is_string($APP_BASE_URL) && $APP_BASE_URL !== '') {
        return rtrim($APP_BASE_URL, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    return $scheme . '://' . $host;
}
