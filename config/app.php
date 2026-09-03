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

function bcc_brand_full()
{
    return bcc_brand_name() . ' — ' . bcc_brand_domain();
}

$BCC_DEMO_LOGIN = false;

$bcc_localAppConfigPath = __DIR__ . '/app.local.php';
if (is_file($bcc_localAppConfigPath)) {
    require $bcc_localAppConfigPath;
}
unset($bcc_localAppConfigPath);

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
