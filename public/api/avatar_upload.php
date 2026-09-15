<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();

/* Dogrulama (boyut, icerikten tur, meta veri ayiklama, olculer) ortak:
   src/image_upload.php. Arayuz resmi 256px JPEG'e cevirip gonderiyor (~30KB). */
$image = bcc_clean_uploaded_image('file', 'Fotoğraf');
if (!$image['ok']) {
    json_fail($image['status'], $image['error']);
}

if (!bcc_write_file_atomically(bcc_avatar_path($user['id']), $image['bytes'])) {
    json_fail(500, 'Fotoğraf kaydedilemedi.');
}

try {
    log_audit('user.avatar_updated', 'user', $user['id'], array('bytes' => strlen($image['bytes']), 'width' => $image['width'], 'height' => $image['height']));
} catch (Throwable $e) {
}

/* Ayni istek icinde dosya yeni yazildi: onbellekteki eski sonuc kullanilmasin.
   'inline' arayuzun resmi YENI bir istek beklemeden hemen gostermesi icin. */
$url = bcc_avatar_url($user['id'], true);

echo json_encode(array('ok' => true, 'url' => $url, 'inline' => bcc_avatar_data_uri($user['id'])), JSON_UNESCAPED_UNICODE);
