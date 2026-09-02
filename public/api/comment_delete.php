<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$commentId = isset($_POST['comment_id']) ? (int) $_POST['comment_id'] : 0;

$comment = bcc_fetch_one(
    'SELECT id, record_id, user_id FROM comments WHERE id = :id AND deleted_at IS NULL LIMIT 1',
    array('id' => $commentId)
);
if (!$comment) {
    json_fail(404, 'Yorum bulunamadı.');
}

$record = bcc_find_record($comment['record_id']);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_role($record['team_id'], 'commenter');

$user = current_user();
if ($comment['user_id'] === null || (int) $comment['user_id'] !== (int) $user['id']) {
    json_fail(403, 'Yalnızca kendi yorumunuzu silebilirsiniz.');
}

bcc_execute('UPDATE comments SET deleted_at = NOW() WHERE id = :id', array('id' => $commentId));

log_audit('comment.delete', 'record', $comment['record_id'], array('comment_id' => $commentId), $record['team_id']);

echo json_encode(array('ok' => true, 'id' => $commentId), JSON_UNESCAPED_UNICODE);
