<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

const BCC_NOTE_VIEW_MAX_SECONDS = 14400;

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;

$user = current_user();

$row = bcc_fetch_one(
    'SELECT id, team_id, closed_at FROM record_view_log
      WHERE id = :id AND user_id = :user_id LIMIT 1',
    array('id' => $viewId, 'user_id' => $user['id'])
);

if (!$row || $row['closed_at'] !== null) {
    echo json_encode(array('ok' => true, 'duration_seconds' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

require_team_access($row['team_id']);

bcc_execute(
    'UPDATE record_view_log
        SET duration_seconds = LEAST(TIMESTAMPDIFF(SECOND, opened_at, NOW()), :max_seconds)
      WHERE id = :id AND user_id = :user_id AND closed_at IS NULL',
    array(
        'id' => $viewId,
        'user_id' => $user['id'],
        'max_seconds' => BCC_NOTE_VIEW_MAX_SECONDS,
    )
);

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
