<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

session_start();

$_SESSION = array(
    'user_id' => isset($argv[1]) ? (int) $argv[1] : 0,
    'csrf_token' => 'TEST_CSRF_TOKEN',
);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    'csrf_token' => (isset($argv[4]) && $argv[4] === 'bozuk_csrf') ? 'YANLIS_TOKEN' : 'TEST_CSRF_TOKEN',
    'team_id' => isset($argv[2]) ? $argv[2] : '0',
    'name' => isset($argv[3]) ? $argv[3] : '',
    'description' => 'yetki testi',
);

register_shutdown_function(function () {
    $code = http_response_code();
    echo "\nHTTP_STATUS=" . ($code === false ? 200 : $code) . "\n";
});

require __DIR__ . '/../public/api/base_create.php';
