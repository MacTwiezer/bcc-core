<?php
// Ic yardimci — _verify_demo_roles.php tarafindan alt surec olarak calistirilir.
// login.php'nin cagirdigi GERCEK attempt_login() fonksiyonunu izole bir surecte
// dener (oturum yan etkisi cagiran betige sizmasin diye ayri surec).
//
// Kullanim: php _login_case.php <email> <sifre>
// Cikti: 'ok' | 'inactive' | 'invalid'

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

session_start();

require __DIR__ . '/../src/bootstrap.php';

echo attempt_login(isset($argv[1]) ? $argv[1] : '', isset($argv[2]) ? $argv[2] : '');
