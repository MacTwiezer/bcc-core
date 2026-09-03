<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

session_start();

require __DIR__ . '/../src/bootstrap.php';

echo attempt_login(isset($argv[1]) ? $argv[1] : '', isset($argv[2]) ? $argv[2] : '');
