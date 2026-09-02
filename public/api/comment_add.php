<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

const BCC_COMMENT_MAX_CHARS = 4000;

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
$body = isset($_POST['body']) ? trim($_POST['body']) : '';

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_role($record['team_id'], 'commenter');

$recordStatus = bcc_fetch_one('SELECT deleted_at FROM records WHERE id = :id LIMIT 1', array(':id' => $recordId));
if (!$recordStatus || $recordStatus['deleted_at'] !== null) {
    json_fail(404, 'Kayıt bulunamadı (silinmiş).');
}

if ($body === '') {
    json_fail(422, 'Yorum boş olamaz.');
}
if (mb_strlen($body, 'UTF-8') > BCC_COMMENT_MAX_CHARS) {
    json_fail(422, 'Yorum en fazla ' . BCC_COMMENT_MAX_CHARS . ' karakter olabilir.');
}

$user = current_user();

bcc_execute(
    'INSERT INTO comments (record_id, user_id, body) VALUES (:record_id, :user_id, :body)',
    array('record_id' => $recordId, 'user_id' => $user['id'], 'body' => $body)
);
$commentId = (int) bcc_last_insert_id();

log_audit('comment.add', 'record', $recordId, array('comment_id' => $commentId), $record['team_id']);

$row = bcc_fetch_one('SELECT created_at, updated_at FROM comments WHERE id = :id LIMIT 1', array('id' => $commentId));

echo json_encode(array(
    'ok' => true,
    'comment' => array(
        'id' => $commentId,
        'body' => $body,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'author_name' => $user['full_name'],
        'is_own' => true,
    ),
), JSON_UNESCAPED_UNICODE);
