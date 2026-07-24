<?php
// AJAX uçnoktası: "Mark all as read" — users.last_seen_notifications_at'i
// NOW()'a çeker. require_team_access() burada anlamsız (başka hiçbir
// kullanıcı/takım verisine dokunmuyor, yalnızca oturumdaki kullanıcının KENDİ
// satırı) — CSRF + is_logged_in() yeterli.

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

try {
    bcc_execute('UPDATE users SET last_seen_notifications_at = NOW() WHERE id = :id', array('id' => $user['id']));
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
