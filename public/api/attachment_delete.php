<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$attachmentId = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;

$attachment = bcc_find_attachment($attachmentId);
if (!$attachment) {
    json_fail(404, 'Dosya bulunamadı.');
}

require_role($attachment['team_id'], 'editor');

try {
    bcc_begin_transaction();
    bcc_execute('DELETE FROM attachments WHERE id = :id', array('id' => $attachment['id']));
    bcc_touch_record_modified($attachment['record_id']);
    log_audit('attachment.delete', 'record', $attachment['record_id'], array('field_id' => $attachment['field_id'], 'file_name' => $attachment['original_name']), $attachment['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

$path = bcc_attachment_storage_path($attachment['stored_name']);
if (is_file($path)) {
    unlink($path);
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
