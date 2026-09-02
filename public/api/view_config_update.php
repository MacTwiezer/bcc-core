<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$hasFrozenCount = isset($_POST['frozen_column_count']);
$frozenCount = $hasFrozenCount ? (int) $_POST['frozen_column_count'] : null;
$hasColumnWidths = isset($_POST['column_widths']);
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    $fields = bcc_fetch_all('SELECT id FROM fields WHERE table_id = :table_id ORDER BY position, id', array(':table_id' => $view['table_id']));
    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;

    $stateParams = array();
    parse_str($stateQueryString, $stateParams);
    $hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);
    $visibleFieldCount = count($fields) - count($hiddenFieldIds);

    $maxAllowed = bcc_max_frozen_columns($visibleFieldCount);

    $changes = array();

    if ($hasFrozenCount) {
        if ($frozenCount < 1) {
            $frozenCount = 1;
        }
        if ($frozenCount > $maxAllowed) {
            $frozenCount = $maxAllowed;
        }
        $changes['frozen_column_count'] = $frozenCount;
    }

    $columnWidths = null;
    if ($hasColumnWidths) {
        $rawWidths = json_decode((string) $_POST['column_widths'], true);
        $columnWidths = bcc_sanitize_column_widths($rawWidths, $fieldsById);

        $changes['column_widths'] = empty($columnWidths) ? null : $columnWidths;
    }

    if (empty($changes)) {
        json_fail(400, 'Güncellenecek bir ayar gönderilmedi.');
    }

    bcc_update_view_config($view['id'], $changes);

    log_audit('view.config_update', 'view', $view['id'], $changes, $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$response = array('ok' => true);
if ($hasFrozenCount) {
    $response['frozen_column_count'] = $frozenCount;
}
if ($hasColumnWidths) {
    $response['column_widths'] = (object) $columnWidths;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
