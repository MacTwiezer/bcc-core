<?php
// AJAX uçnoktası: bir ek dosyayı siler (grid.php / grid-row-detail.js —
// window.BCC_GRID.deleteAttachment üzerinden). Güvenlik: CSRF + require_role
// ('editor') — team_id bcc_find_attachment() ile field_id -> table_id -> base_id
// -> team_id zincirinden gelir, istekten değil.

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
    // "Last modified time/by" (Grup B2): attachments DELETE + records'un "son
    // değişiklik" damgası + audit log AYNI transaction'da (cell_update.php ile
    // AYNI gerekçe).
    bcc_begin_transaction();
    bcc_execute('DELETE FROM attachments WHERE id = :id', array('id' => $attachment['id']));
    bcc_touch_record_modified($attachment['record_id']);
    log_audit('attachment.delete', 'record', $attachment['record_id'], array('field_id' => $attachment['field_id'], 'file_name' => $attachment['original_name']), $attachment['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

// DB satırı silindikten sonra fiziksel dosya temizlenir — bu adım başarısız olsa
// bile (nadiren) kullanıcı için işlem zaten tamamlanmış sayılır, en fazla diskte
// yetim bir dosya kalır.
$path = bcc_attachment_storage_path($attachment['stored_name']);
if (is_file($path)) {
    unlink($path);
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
