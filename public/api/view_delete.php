<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    $viewCount = (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id = :table_id', array(':table_id' => $view['table_id']));

    if ($viewCount <= 1) {
        json_fail(422, 'Bir tabloda en az bir görünüm kalmalı, son görünüm silinemez.');
    }

    $fallbackViewId = (int) bcc_fetch_column(
        'SELECT id FROM views WHERE table_id = :table_id AND id != :id ORDER BY id ASC LIMIT 1',
        array(':table_id' => $view['table_id'], ':id' => $view['id'])
    );

    bcc_begin_transaction();
    bcc_execute('DELETE FROM views WHERE id = :id', array(':id' => $view['id']));
    log_audit('view.delete', 'view', $view['id'], array('table_id' => $view['table_id']), $view['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'fallback_view_id' => $fallbackViewId), JSON_UNESCAPED_UNICODE);
