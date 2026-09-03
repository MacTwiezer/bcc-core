<?php

require_once __DIR__ . '/error_handler.php';

$bccIsHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',

        'secure' => $bccIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
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
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/csv.php';
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
