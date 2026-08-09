<?php
// BCC-Core — uygulama geneli ayarlar.
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
// CANLIYA ÇIKARKEN: config/app.local.php oluşturup (git'e girmez, bkz.
// .gitignore) uygulamanın GERÇEK adresini yazın, sonunda / OLMADAN:
//
//   <?php
//   $APP_BASE_URL = 'https://uygulama.bcciletisim.com.tr';
//
// Şablon: config/app.local.php.example

$APP_BASE_URL = '';

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
