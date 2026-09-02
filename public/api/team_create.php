<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();

if ((int) $user['is_admin'] !== 1) {
    json_fail(403, 'Çalışma alanı oluşturmak için platform yöneticisi olmanız gerekir.');
}

$name = isset($_POST['name']) ? $_POST['name'] : '';

try {
    $result = bcc_create_team($name, $user['id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

if (!$result['ok']) {
    json_fail(422, $result['error']);
}

echo json_encode(array(
    'ok' => true,
    'team_id' => $result['id'],
    'redirect_url' => '/workspaces.php?team_id=' . $result['id'],
), JSON_UNESCAPED_UNICODE);
