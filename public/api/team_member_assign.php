<?php

require __DIR__ . '/../../src/api_bootstrap.php';
require __DIR__ . '/../../src/share_modal_payload.php';

api_require_post();
api_require_login();
api_require_csrf();

$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$rawEmail = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$role = isset($_POST['role']) ? (string) $_POST['role'] : '';

require_role($teamId, 'viewer');

$myRole = current_user_role_in_team($teamId);
if (!bcc_can_manage_members($myRole)) {
    json_fail(403, 'Üye yönetimi için Owner yetkisi gerekir.');
}

$myRank = $GLOBALS['BCC_ROLE_RANK'][$myRole];
$assignableRoles = bcc_assignable_roles($myRank);

try {
    if ($targetUserId <= 0 && $rawEmail !== '') {
        $found = bcc_fetch_one(
            'SELECT id, is_active FROM users WHERE email = :email LIMIT 1',
            array('email' => $rawEmail)
        );

        if (!$found) {
            json_fail(404, 'Bu e-postayla kayıtlı bir hesap yok. Hesap oluşturma platform yöneticisindedir.');
        }
        if ((int) $found['is_active'] !== 1) {
            json_fail(422, 'Bu hesap henüz e-posta doğrulamasını tamamlamadı; doğrulandıktan sonra ekleyebilirsiniz.');
        }

        $targetUserId = (int) $found['id'];
    }

    $result = bcc_team_member_assign($teamId, $targetUserId, $role, $myRank, $assignableRoles);

    if (!$result['ok']) {
        json_fail(422, $result['error']);
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$payload = bcc_share_modal_payload($teamId, $myRole);
$payload['ok'] = true;
$payload['message'] = $result['created'] ? 'Katılımcı eklendi.' : 'Rol güncellendi.';

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
