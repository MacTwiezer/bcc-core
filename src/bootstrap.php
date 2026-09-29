<?php

require_once __DIR__ . '/error_handler.php';

$bccIsHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

/* Bosta kalma siniri: temsilci bu sure boyunca hic istek atmazsa oturum duser
   (2026-09-29, musteri istegi: "surekli logout olup tekrar giris yapmasinlar").
   PHP varsayilani session.gc_maxlifetime = 1440 sn (24 dk) idi.
   Oturumlar KENDI klasorumuzde tutulur: ortak sistem klasorunde baska bir
   uygulamanin (ayni Apache'deki Nexora) ya da Linux'taki sessionclean
   cron'unun GC'si KENDI 24 dakikasiyla bizim dosyalarimizi da silerdi. */
define('BCC_SESSION_IDLE_SECONDS', 8 * 3600);

if (session_status() === PHP_SESSION_NONE) {
    $bccSessionDir = __DIR__ . '/../storage/sessions';
    if (!is_dir($bccSessionDir)) {
        @mkdir($bccSessionDir, 0700, true);
    }
    if (is_dir($bccSessionDir) && is_writable($bccSessionDir)) {
        session_save_path($bccSessionDir);
    }
    ini_set('session.gc_maxlifetime', (string) BCC_SESSION_IDLE_SECONDS);

    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',

        'secure' => $bccIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();

    /* GC olasiliksal calisir; sinirin kesin olmasi icin son istek zamani
       oturumda tutulur ve asildiysa oturum burada bosaltilir. */
    if (!empty($_SESSION['user_id'])) {
        $bccNow = time();
        if (isset($_SESSION['bcc_last_request'])
            && $bccNow - (int) $_SESSION['bcc_last_request'] > BCC_SESSION_IDLE_SECONDS) {
            $_SESSION = array();
            session_regenerate_id(true);
        } else {
            $_SESSION['bcc_last_request'] = $bccNow;
        }
    }
}

if (!headers_sent()) {

    header('X-Frame-Options: DENY');

    header('X-Content-Type-Options: nosniff');

    header('Referrer-Policy: strict-origin-when-cross-origin');

    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if ($bccIsHttps) {

        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/image_upload.php';
require_once __DIR__ . '/team_image.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/slack.php';
require_once __DIR__ . '/validation.php';

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/../config/app.php';

require_once __DIR__ . '/demo_accounts.php';
require_once __DIR__ . '/mailer.php';

function bcc_asset_url($relativePath)
{
    $fsPath = __DIR__ . '/../public/assets/' . $relativePath;
    $version = @filemtime($fsPath);

    return '/assets/' . $relativePath . ($version !== false ? '?v=' . $version : '');
}

function bcc_json_for_script($value)
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}

header('Content-Type: text/html; charset=utf-8');

bcc_touch_user_activity();
