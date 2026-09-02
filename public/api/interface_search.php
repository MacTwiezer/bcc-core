<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_login();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $table = find_table_or_404($tableId);
    require_team_access($table['team_id']);

    $fields = bcc_fetch_all(
        'SELECT id, field_type FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array('table_id' => $tableId)
    );
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
    $summaryField = bcc_interface_summary_field($fields);
    $summaryFieldId = $summaryField ? (int) $summaryField['id'] : null;

    $records = bcc_interface_fetch_records($tableId, $primaryFieldId, $summaryFieldId, $query);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'record_ids' => array_map('intval', array_column($records, 'id'))), JSON_UNESCAPED_UNICODE);
