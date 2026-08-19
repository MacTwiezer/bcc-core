<?php
// AJAX uçnoktası: bir yorumu düzenler. Güvenlik: CSRF + require_role('commenter')
// + SAHİPLİK kontrolü — yalnızca yorumu yazan kullanıcı düzenleyebilir, owner
// dahil KİMSE başkasının yorumunu düzenleyemez (OpsFlow araştırması: resmi SSS
// "deaktif kullanıcının yorumu silinemez" — owner'a admin-override yetkisi
// verilmediğinin kanıtı, bkz. docs/PROJE-DURUM.md). team_id, comment -> record
// zincirinden bcc_find_record() ile DB'den türetilir.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

const BCC_COMMENT_MAX_CHARS = 4000;

$commentId = isset($_POST['comment_id']) ? (int) $_POST['comment_id'] : 0;
$body = isset($_POST['body']) ? trim($_POST['body']) : '';

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
    json_fail(403, 'Yalnızca kendi yorumunuzu düzenleyebilirsiniz.');
}

if ($body === '') {
    json_fail(422, 'Yorum boş olamaz.');
}
if (mb_strlen($body, 'UTF-8') > BCC_COMMENT_MAX_CHARS) {
    json_fail(422, 'Yorum en fazla ' . BCC_COMMENT_MAX_CHARS . ' karakter olabilir.');
}

bcc_execute('UPDATE comments SET body = :body WHERE id = :id', array('body' => $body, 'id' => $commentId));

log_audit('comment.update', 'record', $comment['record_id'], array('comment_id' => $commentId), $record['team_id']);

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
