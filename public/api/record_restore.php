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

require_role($record['team_id'], 'editor');

try {
    $affected = bcc_execute(
        'UPDATE records SET deleted_at = NULL, deleted_by = NULL, updated_at = updated_at WHERE id = :id AND deleted_at IS NOT NULL',
        array(':id' => $recordId)
    );
    if ((int) $affected === 0) {
        json_fail(422, 'Bu kayıt zaten aktif, geri yüklemeye gerek yok.');
    }

    log_audit('record.restore', 'record', $recordId, array('table_id' => $record['table_id']), $record['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
