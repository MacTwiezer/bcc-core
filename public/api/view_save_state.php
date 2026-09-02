<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    $fields = bcc_fetch_all('SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id', array(':table_id' => $view['table_id']));
    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;

    $stateParams = array();
    parse_str($stateQueryString, $stateParams);

    $sortRules = parse_grid_sort_rules($stateParams, $fieldsById);
    $groupRules = parse_grid_group_rules($stateParams, $fieldsById);
    $filterRules = parse_grid_filter_rules($stateParams, $fieldsById);
    $filterLogic = (isset($stateParams['filter_logic']) && $stateParams['filter_logic'] === 'or') ? 'or' : 'and';
    $hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);
    $rowHeight = parse_grid_row_height($stateParams);
    $wrapHeaders = parse_grid_wrap_headers($stateParams);

    $gridState = bcc_grid_state_to_array($sortRules, $groupRules, $filterRules, $filterLogic, $hiddenFieldIds, $rowHeight, $wrapHeaders);

    $config = array();
    if ($view['config'] !== null && $view['config'] !== '') {
        $decoded = json_decode($view['config'], true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }
    $config['grid_state'] = $gridState;

    bcc_begin_transaction();
    bcc_execute(
        'UPDATE views SET config = :config WHERE id = :id',
        array(':config' => json_encode($config, JSON_UNESCAPED_UNICODE), ':id' => $view['id'])
    );
    log_audit('view.save_state', 'view', $view['id'], array('grid_state' => $gridState), $view['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
