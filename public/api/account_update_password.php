<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
$newPassword = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
$confirmPassword = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

$row = bcc_fetch_one('SELECT password_hash FROM users WHERE id = :id LIMIT 1', array(':id' => $user['id']));

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    json_fail(422, 'Mevcut şifre yanlış.');
}
if (!bcc_is_valid_password($newPassword)) {
    json_fail(422, 'Yeni şifre 8-72 karakter arasında olmalı.');
}
if ($newPassword !== $confirmPassword) {
    json_fail(422, 'Yeni şifre ve tekrarı eşleşmiyor.');
}

try {
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    bcc_execute(
        'UPDATE users
         SET password_hash = :hash, password_reset_token = NULL, password_reset_expires_at = NULL
         WHERE id = :id',
        array(':hash' => $hash, ':id' => $user['id'])
    );

    log_audit('user.account_updated', 'user', $user['id'], array('field' => 'password'));
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

session_regenerate_id(true);

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
