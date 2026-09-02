<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

$table = find_table_or_404($tableId);
require_role($table['team_id'], 'owner');

$tableName = $table['name'];
$baseId = (int) $table['base_id'];

$nextTableId = (int) bcc_fetch_column(
    'SELECT id FROM tables_meta WHERE base_id = :b AND id <> :t ORDER BY position, id LIMIT 1',
    array(':b' => $baseId, ':t' => $table['id'])
);

try {
    bcc_delete_attachment_files_by_table($table['id']);

    bcc_begin_transaction();

    bcc_execute('DELETE FROM tables_meta WHERE id = :id', array(':id' => $table['id']));
    log_audit('table.delete', 'table', $table['id'], array('name' => $tableName), $table['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Tablo silinemedi (veritabanı hatası).');
}

echo json_encode(array(
    'ok' => true,
    'redirect_url' => $nextTableId > 0
        ? '/grid.php?table_id=' . $nextTableId
        : '/base_tables.php?base_id=' . $baseId,
), JSON_UNESCAPED_UNICODE);
