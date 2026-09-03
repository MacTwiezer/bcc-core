<?php

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail($status, $message)
{
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_require_post()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_fail(405, 'Yalnızca POST.');
    }
}

function api_require_login()
{
    if (!is_logged_in()) {
        json_fail(401, 'Giriş gerekli.');
    }
}

function api_require_csrf()
{
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!csrf_verify($token)) {
        json_fail(403, 'Geçersiz istek (CSRF). Sayfayı yenileyip tekrar deneyin.');
    }
}
