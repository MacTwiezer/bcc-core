<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_role($record['team_id'], 'editor');

try {
    $tableId = (int) $record['table_id'];

    $original = bcc_fetch_one(
        'SELECT id, position FROM records WHERE id = :id AND table_id = :tid AND deleted_at IS NULL LIMIT 1',
        array(':id' => $recordId, ':tid' => $tableId)
    );
    if (!$original) {
        json_fail(404, 'Kayıt bulunamadı.');
    }

    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array(':table_id' => $tableId)
    );

    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
    $primaryFieldType = !empty($fields) ? $fields[0]['field_type'] : null;

    $attachmentFieldIds = array();
    $autonumberFieldIds = array();
    foreach ($fields as $f) {
        if ($f['field_type'] === 'attachment') {
            $attachmentFieldIds[] = (int) $f['id'];
        }
        if ($f['field_type'] === 'autonumber') {
            $autonumberFieldIds[] = (int) $f['id'];
        }
    }

    bcc_begin_transaction();

    bcc_execute(
        'UPDATE records SET position = position + 1, updated_at = updated_at WHERE table_id = :tid AND position > :pos',
        array(':tid' => $tableId, ':pos' => $original['position'])
    );
    $newPos = $original['position'] + 1;

    $user = current_user();
    bcc_execute(
        'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)',
        array(':tid' => $tableId, ':pos' => $newPos, ':uid' => $user['id'])
    );
    $newRecordId = (int) bcc_last_insert_id();

    $excludeIds = array_merge($attachmentFieldIds, $autonumberFieldIds);
    if ($primaryFieldId !== null) {
        $excludeIds[] = $primaryFieldId;
    }

    if (!empty($excludeIds)) {
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
        bcc_execute(
            "INSERT INTO cell_values (record_id, field_id, value_text, value_number, value_date, value_json)
             SELECT ?, field_id, value_text, value_number, value_date, value_json
             FROM cell_values WHERE record_id = ? AND field_id NOT IN ($placeholders)",
            array_merge(array($newRecordId, $recordId), $excludeIds)
        );
    } else {
        bcc_execute(
            'INSERT INTO cell_values (record_id, field_id, value_text, value_number, value_date, value_json)
             SELECT ?, field_id, value_text, value_number, value_date, value_json
             FROM cell_values WHERE record_id = ?',
            array($newRecordId, $recordId)
        );
    }

    if ($primaryFieldId !== null && $primaryFieldType !== 'autonumber') {
        $origPrimaryCell = bcc_fetch_one(
            'SELECT value_text, value_number, value_date, value_json FROM cell_values WHERE record_id = :rid AND field_id = :fid LIMIT 1',
            array(':rid' => $recordId, ':fid' => $primaryFieldId)
        );

        if ($origPrimaryCell) {
            $newValueText = $origPrimaryCell['value_text'];
            if (in_array($primaryFieldType, $GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES'], true)) {
                $newValueText = ($newValueText === null || $newValueText === '')
                    ? 'copy'
                    : $newValueText . ' copy';
            }

            bcc_execute(
                'INSERT INTO cell_values (record_id, field_id, value_text, value_number, value_date, value_json)
                 VALUES (:rid, :fid, :vt, :vn, :vd, :vj)',
                array(
                    ':rid' => $newRecordId,
                    ':fid' => $primaryFieldId,
                    ':vt' => $newValueText,
                    ':vn' => $origPrimaryCell['value_number'],
                    ':vd' => $origPrimaryCell['value_date'],
                    ':vj' => $origPrimaryCell['value_json'],
                )
            );
        }
    }

    bcc_assign_autonumbers($tableId, $newRecordId);

    log_audit('record.duplicate', 'record', $newRecordId, array('table_id' => $tableId, 'source_record_id' => $recordId), $record['team_id']);

    bcc_commit();

    bcc_notify_slack_new_record($tableId, $newRecordId, $user['full_name']);
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

$table = find_table_or_404($tableId);
$usersById = bcc_team_users_by_id($table['team_id']);
$cellsByRecord = bcc_fetch_cells_by_record(array($newRecordId));
$attachmentsByRecord = bcc_fetch_attachments_by_record(array($newRecordId));

$stateParams = array();
parse_str($stateQueryString, $stateParams);
$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}
$hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);
$visibleFields = array();
foreach ($fields as $f) {
    if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
        $visibleFields[] = $f;
    }
}

$newRecord = bcc_fetch_one(
    'SELECT id, created_at, created_by, updated_at, updated_by FROM records WHERE id = :id',
    array(':id' => $newRecordId)
);
ob_start();
bcc_render_grid_data_row($newRecord, 0, $visibleFields, $cellsByRecord, true, $tableId, $stateQueryString, null, $usersById, $fields, $attachmentsByRecord);
$rowHtml = ob_get_clean();

echo json_encode(array(
    'ok' => true,
    'record_id' => $newRecordId,
    'row_html' => $rowHtml,
), JSON_UNESCAPED_UNICODE);
