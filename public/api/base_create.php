<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$name = isset($_POST['name']) ? $_POST['name'] : '';
$description = isset($_POST['description']) ? $_POST['description'] : '';

$icon = isset($_POST['icon']) ? $_POST['icon'] : null;
$iconColor = isset($_POST['icon_color']) ? $_POST['icon_color'] : null;

if (!in_array($teamId, current_user_team_ids(), true)) {
    json_fail(403, 'Bu çalışma alanına erişim yetkiniz yok.');
}

if (!bcc_can_manage_bases(current_user_role_in_team($teamId))) {
    json_fail(403, 'Bu çalışma alanında base oluşturmak için Owner yetkisi gerekir.');
}

try {
    $result = bcc_create_base($teamId, $name, $description, $user['id'], $icon, $iconColor);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

if (!$result['ok']) {
    json_fail(422, $result['error']);
}

echo json_encode(array('ok' => true, 'id' => $result['id']), JSON_UNESCAPED_UNICODE);
