<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

$endpoint = isset($argv[1]) ? $argv[1] : '';
$userId   = isset($argv[2]) ? (int) $argv[2] : 0;
$paramsB64 = isset($argv[3]) ? $argv[3] : '';
$method   = isset($argv[4]) ? strtoupper($argv[4]) : 'POST';
$withCsrf = isset($argv[5]) ? ((int) $argv[5] === 1) : true;

if (!preg_match('#^note_view_[a-z]+\.php$#', $endpoint)) {
    fwrite(STDERR, "Gecersiz uc nokta: " . $endpoint . "\n");
    exit(1);
}

$path = __DIR__ . '/../public/api/' . $endpoint;
if (!is_file($path)) {
    fwrite(STDERR, "Uc nokta bulunamadi: " . $endpoint . "\n");
    exit(1);
}

$raw = base64_decode($paramsB64, true);
if ($raw === false) {
    fwrite(STDERR, "Parametreler cozulemedi (gecersiz base64).\n");
    exit(1);
}
$decoded = json_decode($raw, true);
$params = is_array($decoded) ? $decoded : array();

session_start();

$_SESSION = array('user_id' => $userId, 'csrf_token' => 'NOTE_VIEW_TEST_TOKEN');

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['SCRIPT_NAME'] = '/api/' . $endpoint;

$_GET = array();
$_POST = array();

if ($method === 'GET') {
    $_GET = $params;
} else {
    $_POST = $params;
    if ($withCsrf) {
        $_POST['csrf_token'] = 'NOTE_VIEW_TEST_TOKEN';
    }
}

register_shutdown_function(function () {
    $code = http_response_code();
    echo "|HTTP=" . ($code === false ? 200 : $code);
});

require $path;
