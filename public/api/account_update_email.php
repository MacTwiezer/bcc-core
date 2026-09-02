<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
$rawEmail = isset($_POST['email']) ? $_POST['email'] : '';
$email = trim((string) $rawEmail);

$row = bcc_fetch_one('SELECT password_hash FROM users WHERE id = :id LIMIT 1', array(':id' => $user['id']));

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    json_fail(422, 'Mevcut şifre yanlış.');
}
if (!bcc_is_valid_email($email)) {
    json_fail(422, 'Geçersiz e-posta adresi.');
}
if (mb_strlen($email, 'UTF-8') > 190) {
    json_fail(422, 'E-posta en fazla 190 karakter olabilir.');
}

try {
    $existing = bcc_fetch_one(
        'SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1',
        array(':email' => $email, ':id' => $user['id'])
    );
    if ($existing) {
        json_fail(422, 'Bu e-posta zaten başka bir hesapta kayıtlı.');
    }

    bcc_execute('UPDATE users SET email = :email WHERE id = :id', array(':email' => $email, ':id' => $user['id']));

    log_audit('user.account_updated', 'user', $user['id'], array('field' => 'email', 'old_email' => $user['email'], 'new_email' => $email));
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        json_fail(422, 'Bu e-posta zaten başka bir hesapta kayıtlı.');
    }
    json_fail(500, 'Veritabanı hatası.');
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'email' => $email), JSON_UNESCAPED_UNICODE);
