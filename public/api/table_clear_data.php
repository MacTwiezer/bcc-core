<?php
// AJAX uçnoktası: tablodaki TÜM kayıtları siler, alan/kolon yapısını KORUR
// (OpsFlow'un "Clear data" karşılığı — "Delete table"dan farklı, tablo kalır,
// yalnızca içi boşalır). Sekme dropdown'ından çağrılır (public/grid.php,
// assets/grid-table-data.js).
// Güvenlik: CSRF + require_role('editor') + table_id doğrulaması.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    $recordCount = (int) bcc_fetch_column(
        'SELECT COUNT(*) FROM records WHERE table_id = :tid',
        array(':tid' => $table['id'])
    );

    // Dosya eki fiziksel dosyaları records silinmeden ÖNCE toplanıp silinir
    // (bkz. bcc_delete_attachment_files_by_table yorumu, src/schema.php) —
    // attachments DB satırları records'un ON DELETE CASCADE'i ile otomatik gider.
    bcc_delete_attachment_files_by_table($table['id']);

    // DELETE + log_audit AYNI transaction'da — bulunan gerçek bug: log_audit()
    // burada bir istisna atarsa (nadir ama mümkün) ve ikisi ayrı olsaydı, DELETE
    // zaten commit edilmiş olur, istemci yine de "Veritabanı hatası" görürdü
    // (record_add.php'de bulunan AYNI sınıf sorun).
    bcc_begin_transaction();
    bcc_execute('DELETE FROM records WHERE table_id = :tid', array(':tid' => $table['id']));
    log_audit('table.clear_data', 'table', $table['id'], array('record_count' => $recordCount), $table['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'record_count' => $recordCount), JSON_UNESCAPED_UNICODE);
