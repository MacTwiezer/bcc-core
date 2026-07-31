<?php
// AJAX uçnoktası: Hesap sayfasında (account.php) "Şifreyi güncelle". require_role()
// KULLANILMAZ (bkz. account_update_name.php) — yalnızca require_login() +
// oturumdaki $user['id']. Mevcut şifre password_verify() ile doğrulanmadan yeni
// şifre KAYDEDİLMEZ (oturumu ele geçiren biri, kullanıcı adına şifreyi
// değiştirip hesabı tamamen ele geçiremesin diye). Ne mevcut ne yeni şifre
// hiçbir log'a/audit_log'a yazılmaz — yalnızca "değişti" olayı kaydedilir.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
$newPassword = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
$confirmPassword = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

// Oturumdaki $user (current_user()) password_hash döndürmeyebilir (SELECT
// listesine bağlı) — doğrulama için satırı ayrıca, yalnızca bu id için okuyoruz.
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
    bcc_execute('UPDATE users SET password_hash = :hash WHERE id = :id', array(':hash' => $hash, ':id' => $user['id']));

    log_audit('user.account_updated', 'user', $user['id'], array('field' => 'password'));
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
