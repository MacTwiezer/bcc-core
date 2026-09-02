<?php

require __DIR__ . '/../../src/api_bootstrap.php';

const BCC_ADD_MAX_ROWS = 500;

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$afterRecordId = isset($_POST['after_record_id']) ? (int) $_POST['after_record_id'] : 0;
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

$count = isset($_POST['count']) ? (int) $_POST['count'] : 1;
if ($count < 1) {
    $count = 1;
}
if ($count > BCC_ADD_MAX_ROWS) {
    json_fail(422, 'Tek seferde en fazla ' . BCC_ADD_MAX_ROWS . ' satir eklenebilir.');
}

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array(':table_id' => $table['id'])
    );

    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;

    $stateParams = array();
    parse_str($stateQueryString, $stateParams);
    $hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);

    $visibleFields = array();
    foreach ($fields as $f) {
        if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
            $visibleFields[] = $f;
        }
    }

    bcc_begin_transaction();

    $newPos = null;

    if ($afterRecordId > 0) {
        $afterRecord = bcc_fetch_one(
            'SELECT id, position FROM records WHERE id = :id AND table_id = :tid AND deleted_at IS NULL LIMIT 1',
            array(':id' => $afterRecordId, ':tid' => $table['id'])
        );

        if ($afterRecord) {
            bcc_execute(
                'UPDATE records SET position = position + :cnt, updated_at = updated_at WHERE table_id = :tid AND position > :pos',
                array(':tid' => $table['id'], ':pos' => $afterRecord['position'], ':cnt' => $count)
            );
            $newPos = $afterRecord['position'] + 1;
        }
    }

    if ($newPos === null) {
        $newPos = (int) bcc_fetch_column(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :tid',
            array(':tid' => $table['id'])
        );
    }

    $user = current_user();
    $newRecordIds = array();
    for ($n = 0; $n < $count; $n++) {
        bcc_execute(
            'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)',
            array(':tid' => $table['id'], ':pos' => $newPos + $n, ':uid' => $user['id'])
        );
        $rid = (int) bcc_last_insert_id();
        $newRecordIds[] = $rid;
        bcc_assign_autonumbers($table['id'], $rid);
    }

    $newRecordId = $newRecordIds[0];

    if ($count === 1) {
        log_audit('record.create', 'record', $newRecordId, array('table_id' => $table['id'], 'after_record_id' => $afterRecordId ?: null), $table['team_id']);
    } else {
        log_audit('record.create_bulk', 'table', $table['id'], array('table_id' => $table['id'], 'count' => $count, 'record_ids' => $newRecordIds, 'after_record_id' => $afterRecordId ?: null), $table['team_id']);
    }

    bcc_commit();

    if ($count === 1) {
        bcc_notify_slack_new_record($table['id'], $newRecordId, $user['full_name']);
    }
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

$usersById = bcc_team_users_by_id($table['team_id']);

$records = bcc_fetch_all(
    'SELECT id, created_at, created_by, updated_at, updated_by FROM records WHERE id IN (' . implode(',', array_map('intval', $newRecordIds)) . ') ORDER BY position, id'
);
$cellsByRecord = bcc_fetch_cells_by_record($newRecordIds);

$rowsHtml = array();
foreach ($records as $rec) {
    ob_start();
    bcc_render_grid_data_row($rec, 0, $visibleFields, $cellsByRecord, true, $table['id'], $stateQueryString, null, $usersById, $fields, array());
    $rowsHtml[] = ob_get_clean();
}

echo json_encode(array(
    'ok' => true,
    'record_id' => $newRecordId,
    'row_html' => isset($rowsHtml[0]) ? $rowsHtml[0] : '',
    'count' => count($rowsHtml),
    'record_ids' => $newRecordIds,
    'rows_html' => $rowsHtml,
), JSON_UNESCAPED_UNICODE);
