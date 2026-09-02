<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';

$row = bcc_fetch_one('SELECT password_hash, is_active FROM users WHERE id = :id LIMIT 1', array(':id' => $user['id']));

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    json_fail(422, 'Mevcut şifre yanlış.');
}

try {
    bcc_begin_transaction();

    if ((int) $user['is_admin'] === 1) {
        $activeAdminCount = bcc_fetch_one('SELECT COUNT(*) AS c FROM users WHERE is_admin = 1 AND is_active = 1 FOR UPDATE');
        if ($activeAdminCount && (int) $activeAdminCount['c'] <= 1) {
            bcc_rollback();
            json_fail(422, 'Platformun tek admin\'i olduğunuz için hesabınızı pasife alamazsınız. Önce başka birini admin yapın.');
        }
    }

    bcc_execute('UPDATE users SET is_active = 0 WHERE id = :id', array(':id' => $user['id']));

    log_audit('user.self_deactivate', 'user', $user['id'], array('email' => $user['email']));

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

logout_user();

echo json_encode(array('ok' => true, 'redirect' => '/login.php'), JSON_UNESCAPED_UNICODE);
