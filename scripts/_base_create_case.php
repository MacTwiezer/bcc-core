<?php
// Ic yardimci — _verify_base_permissions.php tarafindan alt surec olarak
// calistirilir. GERCEK public/api/base_create.php dosyasini (kopyasini degil)
// izole bir PHP surecinde calistirir: oturum/POST taklit edilir, uctan uca
// gercek yetki zinciri (api_require_* -> uyelik -> bcc_can_manage_bases ->
// bcc_create_base) isler. curl/HTTP yok.
//
// Kullanim: php _base_create_case.php <user_id> <team_id> <name> [bozuk_csrf]
// Cikti: uc noktanin JSON govdesi + son satirda "HTTP_STATUS=<kod>".

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

// Oturum BURADA baslatilir; bootstrap.php'nin session_status() kontrolu boylece
// atlanir ve $_SESSION'i (kullanici + CSRF) uc nokta okumadan once kurabiliriz.
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

// json_fail() exit ile ciktigi icin durum kodu ancak kapanista okunabilir.
register_shutdown_function(function () {
    $code = http_response_code();
    echo "\nHTTP_STATUS=" . ($code === false ? 200 : $code) . "\n";
});

require __DIR__ . '/../public/api/base_create.php';
