<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_team_access($record['team_id']);

$recordStatus = bcc_fetch_one(
    'SELECT deleted_at FROM records WHERE id = :id LIMIT 1',
    array('id' => $recordId)
);
if (!$recordStatus || $recordStatus['deleted_at'] !== null) {
    json_fail(404, 'Kayıt bulunamadı (silinmiş).');
}

$role = current_user_role_in_team($record['team_id']);

if (!bcc_is_representative($role)) {
    echo json_encode(array('ok' => true, 'view_id' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();

bcc_execute(
    'INSERT INTO record_view_log (record_id, user_id, team_id, role_at_view, opened_at)
     VALUES (:record_id, :user_id, :team_id, :role_at_view, NOW())',
    array(
        'record_id' => $recordId,
        'user_id' => $user['id'],
        'team_id' => $record['team_id'],
        'role_at_view' => $role,
    )
);

$viewId = (int) bcc_last_insert_id();

echo json_encode(array('ok' => true, 'view_id' => $viewId), JSON_UNESCAPED_UNICODE);
