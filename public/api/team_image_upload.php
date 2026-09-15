<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$roles = current_user_team_roles();

/* Resmi calisma alaninin owner'i degistirir (katilimcilari yoneten rolle ayni).
   Uye olmayana da ayni cevap: ekibin varligi sizmasin. */
if ($teamId <= 0 || !isset($roles[$teamId])) {
    json_fail(404, 'Çalışma alanı bulunamadı.');
}
if (!bcc_can_manage_members($roles[$teamId])) {
    json_fail(403, 'Çalışma alanı resmini yalnızca owner değiştirebilir.');
}

$image = bcc_clean_uploaded_image('file', 'Resim');
if (!$image['ok']) {
    json_fail($image['status'], $image['error']);
}

if (!bcc_write_file_atomically(bcc_team_image_path($teamId), $image['bytes'])) {
    json_fail(500, 'Resim kaydedilemedi.');
}

try {
    log_audit('team.image_updated', 'team', $teamId, array('bytes' => strlen($image['bytes']), 'width' => $image['width'], 'height' => $image['height']), $teamId);
} catch (Throwable $e) {
}

echo json_encode(array('ok' => true, 'url' => bcc_team_image_url($teamId, true)), JSON_UNESCAPED_UNICODE);
