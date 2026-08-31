<?php
// Her public/ sayfasının başında dahil edilir: oturum + ortak yardımcılar.

require_once __DIR__ . '/error_handler.php';

// İstek HTTPS üzerinden mi geldi? Ters vekil (nginx/Cloudflare) arkasında
// $_SERVER['HTTPS'] boş gelir, protokol X-Forwarded-Proto başlığında taşınır.
$bccIsHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        // Eskiden sabit false idi ve yanındaki not "canlıda https arkasına
        // alınırsa true yapılmalı" diyordu — yani doğru davranış bir insanın
        // dosyayı açıp elle değiştirmesine bağlıydı. Unutulursa oturum çerezi
        // HTTP üzerinden de gönderilir ve ağı dinleyen biri oturumu çalabilir.
        // Artık istekten ölçülüyor: localhost'ta false, canlıda otomatik true.
        'secure' => $bccIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
}

// ---- Güvenlik başlıkları ----
// Her sayfada gönderilir (bu dosya TÜM public/ girişlerinin ilk require'ı).
// headers_sent() koruması: CLI betikleri ve çıktı başlamış bir istek için sessiz geç.
if (!headers_sent()) {
    // Clickjacking: uygulama hiçbir yerde kendini iframe'e gömmüyor.
    header('X-Frame-Options: DENY');
    // Tarayıcı Content-Type'ı tahmin etmesin — yüklenen dosyalar
    // (attachment_download.php) canonical MIME ile servis ediliyor.
    header('X-Content-Type-Options: nosniff');
    // Dış sitelere tam URL (base/table id'leri) sızmasın.
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Tarayıcı özelliklerinden hiçbiri kullanılmıyor.
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if ($bccIsHttps) {
        // HSTS yalnızca HTTPS üzerinden anlamlı; HTTP'de gönderilmesi
        // spesifikasyona aykırı. preload BİLEREK yok — geri dönüşü zor.
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/slack.php';
require_once __DIR__ . '/validation.php';
// Sayfa tarafi olumcul hatalarinin tek cikis noktasi (bcc_error_page).
// schema.php'den SONRA: bcc_tab_title() ve bcc_asset_url() gerekiyor.
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/../config/app.php';
// config/app.php'den SONRA: bcc_demo_login_enabled() oradaki $BCC_DEMO_LOGIN
// bayrağını okur (yerel override config/app.local.php'den gelir).
require_once __DIR__ . '/demo_accounts.php';
require_once __DIR__ . '/mailer.php';

// public/assets/*.css|js dosyalarını mtime tabanlı sürüm sorgu string'iyle
// döndürür — bulunan gerçek bug: bu dosyalar hiç cache-bust edilmiyordu (yalnızca
// home.css/interface.css/interface.js istisnaen elle versiyonlanmıştı, her
// dosya yolu için kendi göreli __DIR__ hesabıyla AYRI AYRI, tutarsız bir
// şekilde). Bir kullanıcı tarayıcısı eski bir JS/CSS'i önbellekten sunmaya
// devam ederse, buraya yapılan bir düzeltme o kullanıcıda hiç görünmez —
// "hâlâ eski davranış var" şikayetlerinin asıl nedeni çoğunlukla budur.
// Bu fonksiyon her zaman KENDİ __DIR__'ine göre çözer (src/), çağıran dosyanın
// public/ altında mı yoksa src/partials/ altında mı olduğuna bakılmaksızın
// AYNI, doğru yolu üretir.
function bcc_asset_url($relativePath)
{
    $fsPath = __DIR__ . '/../public/assets/' . $relativePath;
    $version = @filemtime($fsPath);

    return '/assets/' . $relativePath . ($version !== false ? '?v=' . $version : '');
}

header('Content-Type: text/html; charset=utf-8');

bcc_touch_user_activity();