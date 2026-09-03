<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$page = isset($argv[2]) ? $argv[2] : '';
$query = isset($argv[3]) ? $argv[3] : '';
$postJson = isset($argv[4]) ? base64_decode($argv[4], true) : '{}';
if ($postJson === false) {
    fwrite(STDERR, "POST govdesi cozulemedi (gecersiz base64).\n");
    exit(1);
}

if ($page === '' || strpos($page, '..') !== false || !preg_match('#^(api/)?[a-z0-9_]+\.php$#i', $page)) {
    fwrite(STDERR, "Gecersiz sayfa: " . $page . "\n");
    exit(1);
}

$path = __DIR__ . '/../public/' . $page;
if (!is_file($path)) {
    fwrite(STDERR, "Sayfa bulunamadi: " . $page . "\n");
    exit(1);
}

session_start();

$_SESSION = array('user_id' => $userId, 'csrf_token' => 'RBAC_TEST_TOKEN');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/' . $page;

parse_str($query, $_GET);

$decoded = json_decode($postJson, true);
$_POST = is_array($decoded) ? $decoded : array();
$_POST['csrf_token'] = 'RBAC_TEST_TOKEN';

register_shutdown_function(function () {
    $code = http_response_code();
    echo "\nHTTP_STATUS=" . ($code === false ? 200 : $code) . "\n";
});

require $path;
