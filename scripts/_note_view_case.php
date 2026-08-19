<?php
// Ic yardimci — _verify_note_view_log.php tarafindan alt surec olarak
// calistirilir. _post_as_case.php ile AYNI fikir, iki farkla: (1) HTTP metodu
// secilebilir (GET uc noktalari ve "GET ile POST uc noktasi" testi icin),
// (2) CSRF token'i BILEREK atlanabilir ("CSRF'siz reddediliyor mu" testi).
//
// Kullanim: php _note_view_case.php <endpoint> <user_id> <params_json_b64> <method> <csrf 1|0>
// Cikti: uc noktanin govdesi + sonda "|HTTP=<kod>".
//
// POST/GET govdesi BASE64 ile gecirilir, ham JSON ile DEGIL: Windows'ta
// escapeshellarg() cift tirnaklari kaldirdigi icin JSON komut satirinda
// bozuluyor (bkz. _post_as_case.php'deki ayni not).

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

$decoded = json_decode((string) base64_decode($paramsB64, true), true);
$params = is_array($decoded) ? $decoded : array();

session_start();

// CSRF gecerli kurulur (istenmedikce) — amac CSRF'i degil ROL/SAHIPLIK
// kapisini test etmek; istek "her seyi dogru yapmis ama yetkisi olmayan"
// kullaniciyi temsil eder. _post_as_case.php ile AYNI gerekce.
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
