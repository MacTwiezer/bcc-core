<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$roles = current_user_team_roles();

if ($teamId <= 0 || !isset($roles[$teamId])) {
    json_fail(404, 'Çalışma alanı bulunamadı.');
}
if (!bcc_can_manage_members($roles[$teamId])) {
    json_fail(403, 'Çalışma alanı resmini yalnızca owner kaldırabilir.');
}

$path = bcc_team_image_path($teamId);
if (is_file($path) && !@unlink($path)) {
    json_fail(500, 'Resim kaldırılamadı.');
}

try {
    log_audit('team.image_removed', 'team', $teamId, null, $teamId);
} catch (Throwable $e) {
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
