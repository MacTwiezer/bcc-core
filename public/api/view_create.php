<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$user = current_user();

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    $viewType = isset($_POST['view_type']) ? (string) $_POST['view_type'] : 'grid';
    if (!isset($GLOBALS['BCC_VIEW_TYPES'][$viewType])) {
        json_fail(422, 'Geçersiz görünüm türü.');
    }

    $sameTypeCount = (int) bcc_fetch_column(
        'SELECT COUNT(*) FROM views WHERE table_id = :table_id AND view_type = :vt',
        array(':table_id' => $table['id'], ':vt' => $viewType)
    );
    $newName = $GLOBALS['BCC_VIEW_TYPES'][$viewType] . ' ' . ($sameTypeCount + 1);

    $nameSuffix = $sameTypeCount + 1;
    while (bcc_name_taken('views', $table['id'], $newName)) {
        $nameSuffix++;
        $newName = $GLOBALS['BCC_VIEW_TYPES'][$viewType] . ' ' . $nameSuffix;
    }

    $nextPosition = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 FROM views WHERE table_id = :table_id',
        array(':table_id' => $table['id'])
    );

    bcc_begin_transaction();

    bcc_execute(
        'INSERT INTO views (table_id, name, view_type, position, created_by)
         VALUES (:table_id, :name, :view_type, :position, :created_by)',
        array(
            ':table_id' => $table['id'],
            ':name' => $newName,
            ':view_type' => $viewType,
            ':position' => $nextPosition,
            ':created_by' => $user ? $user['id'] : null,
        )
    );

    $newViewId = bcc_last_insert_id();

    if ($viewType === 'kanban') {
        $firstSelect = bcc_fetch_one(
            "SELECT id FROM fields WHERE table_id = :tid AND field_type = 'single_select'
             ORDER BY position, id LIMIT 1",
            array(':tid' => $table['id'])
        );

        if ($firstSelect) {
            bcc_execute(
                'UPDATE views SET config = :config WHERE id = :id',
                array(
                    ':config' => json_encode(array('kanban_field_id' => (int) $firstSelect['id']), JSON_UNESCAPED_UNICODE),
                    ':id' => $newViewId,
                )
            );
        }
    }

    log_audit('view.create', 'view', $newViewId, array('table_id' => $table['id'], 'name' => $newName, 'view_type' => $viewType), $table['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array(
    'ok' => true,
    'view_id' => $newViewId,
    'name' => $newName,
    'view_type' => $viewType,
    'redirect_url' => bcc_view_route_for($viewType, $table['id'], $newViewId),
), JSON_UNESCAPED_UNICODE);
