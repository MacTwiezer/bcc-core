<?php

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/xlsx_writer.php';


$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$table = find_table_or_404($tableId);
require_team_access($table['team_id']);

$fields = bcc_fetch_all('SELECT id, name, field_type, options, position FROM fields WHERE table_id = :table_id ORDER BY position, id', array('table_id' => $table['id']));
$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}

$primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
$hiddenFieldIds = parse_grid_hidden_fields($_GET, $fieldsById, $primaryFieldId);

$visibleFields = array();
foreach ($fields as $f) {
    if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
        $visibleFields[] = $f;
    }
}

$sortRules = parse_grid_sort_rules($_GET, $fieldsById);
$filterRules = parse_grid_filter_rules($_GET, $fieldsById);
$filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';

list($recordsSql, $recordsParams) = bcc_build_grid_records_query($table['id'], array(), $sortRules, $filterRules, $filterLogic);
$records = bcc_fetch_all($recordsSql, $recordsParams);

$cellsByRecord = bcc_fetch_cells_by_record(array_column($records, 'id'));
$attachmentsByRecord = bcc_fetch_attachments_by_record(array_column($records, 'id'));
$usersById = bcc_team_users_by_id($table['team_id']);

$fileName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $table['name']);
$fileName = $fileName !== '' ? $fileName : 'grid';

$headerRow = array();
foreach ($visibleFields as $f) {
    $headerRow[] = bcc_csv_injection_guard($f['name']);
}

$rows = array();
foreach ($records as $rec) {
    $cellsForRecord = isset($cellsByRecord[$rec['id']]) ? $cellsByRecord[$rec['id']] : array();
    $row = array();
    foreach ($visibleFields as $f) {
        if ($f['field_type'] === 'attachment') {
            $files = isset($attachmentsByRecord[$rec['id']][$f['id']]) ? $attachmentsByRecord[$rec['id']][$f['id']] : array();
            $row[] = bcc_csv_injection_guard(implode(', ', array_column($files, 'name')));
            continue;
        }

        $cellRow = isset($cellsForRecord[$f['id']]) ? $cellsForRecord[$f['id']] : null;
        $displayText = cell_display_text($f['field_type'], $cellRow, $usersById, $f['options']);
        if ($f['field_type'] === 'long_text') {
            $displayText = strip_tags($displayText);
        }
        $row[] = bcc_csv_injection_guard($displayText);
    }
    $rows[] = $row;
}

log_audit('view.export_xlsx', 'table', $table['id'], array('record_count' => count($records)), $table['team_id']);

bcc_send_xlsx($fileName . '.xlsx', $table['name'], $headerRow, $rows);
