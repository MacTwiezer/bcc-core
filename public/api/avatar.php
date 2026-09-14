<?php

require __DIR__ . '/../../src/bootstrap.php';

require_login();

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

/* Yetkisiz istek ile "fotografi yok" ayni cevabi aliyor (404): baska ekipten
   birinin fotografi olup olmadigi bile sizmasin. */
if ($userId <= 0 || !bcc_can_view_user_avatar($userId)) {
    http_response_code(404);
    exit;
}

$path = bcc_avatar_path($userId);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

/* Tur diskteki icerikten yeniden olculuyor ve yalnizca iki resim turune izin
   veriliyor: dosya bir sekilde degistirilse bile HTML/SVG olarak sunulamaz. */
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $path) : false;
if ($finfo) {
    finfo_close($finfo);
}

if ($mime !== 'image/jpeg' && $mime !== 'image/png') {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Disposition: inline');
/* Adres surum tasiyor (bcc_avatar_url), degisince adres de degisir. "private":
   kimlik dogrulamali icerik ortak onbelleklerde tutulmasin. */
header('Cache-Control: private, max-age=31536000, immutable');
header('Content-Length: ' . filesize($path));

readfile($path);
