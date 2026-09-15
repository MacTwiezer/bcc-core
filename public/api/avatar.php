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

/* Icerikten tur kontrolu + guvenli basliklar: src/image_upload.php. */
bcc_serve_stored_image(bcc_avatar_path($userId));
