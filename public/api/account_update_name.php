<?php
// AJAX uçnoktası: Hesap sayfasında (account.php) "Ad Soyad" satırının inline
// düzenlemesi. ÖNEMLİ: require_role() KULLANILMAZ — bu kullanıcının KENDİ
// hesap bilgisi, takım rolüyle ilgisi yok (viewer dahil herkes kendi adını
// değiştirebilir). Yalnızca require_login() + oturumdaki $user['id'] üzerinden
// güncelleme — istekten bir kullanıcı id'si ASLA kabul edilmez.

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
$rawName = isset($_POST['full_name']) ? $_POST['full_name'] : '';
$name = trim((string) $rawName);

if ($name === '') {
    json_fail(422, 'Ad Soyad boş olamaz.');
}
if (mb_strlen($name, 'UTF-8') > 150) {
    json_fail(422, 'Ad Soyad en fazla 150 karakter olabilir.');
}

try {
    bcc_execute('UPDATE users SET full_name = :name WHERE id = :id', array(':name' => $name, ':id' => $user['id']));

    log_audit('user.account_updated', 'user', $user['id'], array('field' => 'full_name'));
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'full_name' => $name), JSON_UNESCAPED_UNICODE);
