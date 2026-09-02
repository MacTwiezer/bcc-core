<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_login();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $table = find_table_or_404($tableId);
    require_team_access($table['team_id']);

    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array('table_id' => $tableId)
    );

    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }

    $filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';

    $filterRules = parse_grid_filter_rules($_GET, $fieldsById);
    $sortRules = parse_grid_sort_rules($_GET, $fieldsById);
    $groupRules = parse_grid_group_rules($_GET, $fieldsById);

    list($sql, $params) = bcc_build_grid_records_query($tableId, $groupRules, $sortRules, $filterRules, $filterLogic);
    $records = bcc_fetch_all($sql, $params);

    if ($query !== '') {
        $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
        $summaryField = bcc_interface_summary_field($fields);
        $summaryFieldId = $summaryField ? (int) $summaryField['id'] : null;

        $matched = bcc_interface_fetch_records($tableId, $primaryFieldId, $summaryFieldId, $query);
        $allowed = array_flip(array_map('intval', array_column($matched, 'id')));

        $records = array_values(array_filter($records, function ($r) use ($allowed) {
            return isset($allowed[(int) $r['id']]);
        }));
    }

    $usersById = bcc_team_users_by_id($table['team_id']);

    $items = array();
    if (!empty($groupRules)) {
        $tree = bcc_build_grouped_tree($records, $groupRules, $usersById);
        $flatten = function ($nodes) use (&$flatten, &$items) {
            foreach ($nodes as $node) {
                $items[] = array(
                    't' => 'g',
                    'level' => (int) $node['level'],
                    'label' => (string) $node['display'],
                    'count' => (int) $node['count'],
                );
                if ($node['is_leaf']) {
                    foreach ($node['records'] as $rec) {
                        $items[] = array('t' => 'r', 'id' => (int) $rec['id']);
                    }
                } else {
                    $flatten($node['children']);
                }
            }
        };
        $flatten($tree);
    } else {
        foreach ($records as $rec) {
            $items[] = array('t' => 'r', 'id' => (int) $rec['id']);
        }
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array(
    'ok' => true,
    'items' => $items,
    'record_ids' => array_map('intval', array_column($records, 'id')),
    'counts' => array(
        'filters' => count($filterRules),
        'sorts' => count($sortRules),
        'groups' => count($groupRules),
    ),
), JSON_UNESCAPED_UNICODE);
