<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$page = isset($argv[2]) ? basename($argv[2]) : '';
$query = isset($argv[3]) ? $argv[3] : '';

$path = __DIR__ . '/../public/' . $page;
if ($page === '' || !is_file($path)) {
    fwrite(STDERR, "Sayfa bulunamadi: " . $page . "\n");
    exit(1);
}

session_start();
$_SESSION = array('user_id' => $userId);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/' . $page;
$_POST = array();
parse_str($query, $_GET);

require $path;
