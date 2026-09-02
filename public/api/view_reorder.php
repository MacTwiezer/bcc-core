<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$direction = isset($_POST['direction']) ? $_POST['direction'] : '';

if ($direction !== 'up' && $direction !== 'down') {
    json_fail(422, 'Geçersiz yön.');
}

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    bcc_begin_transaction();

    $moved = bcc_reorder_sibling('views', 'table_id', $view['table_id'], $view['id'], $direction);

    if ($moved) {
        log_audit('view.reorder', 'view', $view['id'], array('direction' => $direction), $view['team_id']);
    }

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'moved' => $moved), JSON_UNESCAPED_UNICODE);
