<?php
// AJAX uçnoktası: Hesap sayfasında (account.php) "E-posta" satırının inline
// düzenlemesi. require_role() KULLANILMAZ (bkz. account_update_name.php) —
// yalnızca require_login() + oturumdaki $user['id']. Yeni e-posta başka bir
// kullanıcıda kayıtlıysa reddedilir: register.php ile AYNI uygulama-katmanı
// kontrolü (kullanıcı dostu mesaj için), ayrıca DB'de zaten uq_users_email
// UNIQUE kısıtı var (son savunma hattı).

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail($status, $message)
{
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Yalnızca POST.');
}

if (!is_logged_in()) {
    json_fail(401, 'Giriş gerekli.');
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!csrf_verify($token)) {
    json_fail(403, 'Geçersiz istek (CSRF). Sayfayı yenileyip tekrar deneyin.');
}

$user = current_user();
$rawEmail = isset($_POST['email']) ? $_POST['email'] : '';
$email = trim((string) $rawEmail);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'email' => $email), JSON_UNESCAPED_UNICODE);
