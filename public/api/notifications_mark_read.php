<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();

try {
    bcc_execute('UPDATE users SET last_seen_notifications_at = NOW() WHERE id = :id', array('id' => $user['id']));

    bcc_execute('DELETE FROM user_read_notifications WHERE user_id = :id', array('id' => $user['id']));
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
