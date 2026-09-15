<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();

if ((int) $user['is_admin'] !== 1) {
    json_fail(403, 'Kullanıcıyı ekibe atamak için platform yöneticisi olmanız gerekir.');
}

$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$role = isset($_POST['role']) ? (string) $_POST['role'] : '';
$roles = array_keys($GLOBALS['BCC_ROLE_RANK']);

if ($userId <= 0 || $teamId <= 0) {
    json_fail(422, 'Kullanıcı ve ekip seçin.');
}

try {
    if (!bcc_fetch_one('SELECT id FROM teams WHERE id = :id', array('id' => $teamId))) {
        json_fail(404, 'Ekip bulunamadı.');
    }

    $result = bcc_team_member_assign($teamId, $userId, $role, $GLOBALS['BCC_ROLE_RANK']['owner'], $roles);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

if (!$result['ok']) {
    json_fail(422, $result['error']);
}

echo json_encode(array(
    'ok' => true,
    'created' => (bool) $result['created'],
    'team_id' => $teamId,
), JSON_UNESCAPED_UNICODE);
