<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$path = bcc_avatar_path($user['id']);

if (is_file($path) && !@unlink($path)) {
    json_fail(500, 'Fotoğraf kaldırılamadı.');
}

try {
    log_audit('user.avatar_removed', 'user', $user['id']);
} catch (Throwable $e) {
}

echo json_encode(array('ok' => true, 'initial' => bcc_user_initial($user)), JSON_UNESCAPED_UNICODE);
