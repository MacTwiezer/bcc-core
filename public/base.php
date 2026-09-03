<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$baseId = isset($_GET['base_id']) ? (int) $_GET['base_id'] : 0;
$base = find_base_or_404($baseId);

require_team_access($base['team_id']);

try {
    log_base_open($base['id'], $base['team_id']);
} catch (Throwable $e) {
}

$tables = bcc_list_base_tables($base['id']);

if (empty($tables)) {
    header('Location: /base_tables.php?base_id=' . (int) $base['id']);
    exit;
}

header('Location: /grid.php?table_id=' . (int) $tables[0]['id']);
exit;
