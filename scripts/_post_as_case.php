<?php
// Ic yardimci — _verify_rbac.php tarafindan alt surec olarak calistirilir.
// Belirtilen kullanicinin oturumuyla GERCEK bir public/*.php sayfasina veya
// public/api/*.php uc noktasina POST atar. Yetki mantiginin bir kopyasi degil,
// uygulamanin kendi dosyasi calisir — "gizleme != yetkilendirme" ancak boyle
// kanitlanabilir.
//
// Kullanim: php _post_as_case.php <user_id> <sayfa> <query> <post_json_base64>
// Cikti: sayfanin/uc noktanin govdesi + son satirda "HTTP_STATUS=<kod>".
//
// POST govdesi BASE64 ile gecirilir, ham JSON ile DEGIL: Windows'ta
// escapeshellarg() cift tirnaklari kaldirdigi icin JSON komut satirinda
// bozuluyordu (bulunan gercek test hatasi — payload sessizce bosaliyor,
// uc nokta "Tablo bulunamadi" 404'u donuyor ve test yanlislikla "reddedildi"
// sanabiliyordu). Base64 alfabesi kabuk icin tamamen zararsizdir.

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

// "api/foo.php" gibi tek seviyeli alt klasore izin ver, ustune cikmaya HAYIR.
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

// CSRF gecerli olacak sekilde kurulur — BILEREK: amac CSRF'i degil ROL
// kapisini test etmek. Istek "her seyi dogru yapmis ama yetkisi olmayan"
// bir kullaniciyi temsil eder; en zorlu senaryo budur.
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
