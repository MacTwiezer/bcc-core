<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
$rawValue = isset($_POST['value']) ? $_POST['value'] : '';

try {
    $field = bcc_find_field($fieldId);

    if (!$field) {
        json_fail(404, 'Alan bulunamadı.');
    }

    require_role($field['team_id'], 'editor');

    $record = bcc_fetch_one('SELECT id, table_id, deleted_at FROM records WHERE id = :id LIMIT 1', array(':id' => $recordId));

    if (!$record || (int) $record['table_id'] !== (int) $field['table_id']) {
        json_fail(400, 'Bu kayıt bu alana ait değil.');
    }

    if ($record['deleted_at'] !== null) {
        json_fail(400, 'Bu kayıt silinmiş, düzenlenemez.');
    }

    $usersById = bcc_team_users_by_id($field['team_id']);

    $result = normalize_cell_value($field['field_type'], $field['options'], $rawValue, $usersById);

    if (!$result['ok']) {
        json_fail(422, $result['error']);
    }

    if ((int) $field['is_required'] === 1 && $result['value'] === null) {
        json_fail(422, 'Bu alan zorunludur, boş bırakılamaz.');
    }

    $column = $result['column'];
    $value = $result['value'];

    $bccSlackWatched = in_array((int) $fieldId, bcc_slack_watched_field_ids($field['table_id']), true);
    $bccSlackOldDisplay = null;

    if ($bccSlackWatched) {
        $oldCellRow = bcc_fetch_one(
            'SELECT value_text, value_number, value_date, value_json FROM cell_values WHERE record_id = :record_id AND field_id = :field_id LIMIT 1',
            array(':record_id' => $recordId, ':field_id' => $fieldId)
        );
        $bccSlackOldDisplay = cell_display_text(
            $field['field_type'],
            $oldCellRow !== false ? $oldCellRow : null,
            $usersById,
            $field['options']
        );
    }

    bcc_begin_transaction();
    $sql = "INSERT INTO cell_values (record_id, field_id, {$column}) VALUES (:record_id, :field_id, :value)
            ON DUPLICATE KEY UPDATE {$column} = VALUES({$column})";
    bcc_execute($sql, array(':record_id' => $recordId, ':field_id' => $fieldId, ':value' => $value));

    bcc_touch_record_modified($recordId);

    log_audit('cell.update', 'record', $recordId, array('field_id' => $fieldId, 'field_name' => $field['name']), $field['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

$cellRow = array('value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null);
$cellRow[$column] = $value;

$response = array(
    'ok' => true,
    'display' => cell_display_text($field['field_type'], $cellRow, $usersById, $field['options']),
    'raw' => cell_raw_value($field['field_type'], $cellRow),
);

if ($bccSlackWatched && $bccSlackOldDisplay !== $response['display']) {
    $bccSlackUser = current_user();

    bcc_notify_slack_cell_change(
        (int) $field['table_id'],
        $recordId,

        $field['field_type'],
        $field['name'],
        $bccSlackOldDisplay,
        $response['display'],
        isset($bccSlackUser['full_name']) ? $bccSlackUser['full_name'] : null
    );
}

if ($field['field_type'] === 'long_text') {
    $response['display'] = bcc_rich_text_grid_html($response['display']);
}

if (is_select_field_type($field['field_type'])) {
    $choices = select_choices_from_options($field['options']);
    $choiceColorMap = bcc_build_choice_color_map($choices, select_choice_colors_from_options($field['options']));

    if ($field['field_type'] === 'single_select') {
        $selectedValues = ($cellRow['value_text'] !== null && $cellRow['value_text'] !== '') ? array($cellRow['value_text']) : array();
    } else {
        $decodedSelected = ($cellRow['value_json'] !== null) ? json_decode($cellRow['value_json'], true) : array();
        $selectedValues = is_array($decodedSelected) ? $decodedSelected : array();
    }

    $response['display_chips'] = bcc_choice_chip_data($selectedValues, $choiceColorMap);
}

if (in_array($field['field_type'], $GLOBALS['BCC_LINKIFIED_FIELD_TYPES'], true)) {
    $linkHref = bcc_cell_link_href($field['field_type'], $response['display']);

    $response['display_link'] = ($linkHref !== null)
        ? array('href' => $linkHref, 'text' => $response['display'])
        : null;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
