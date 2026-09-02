<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$rawDescription = isset($_POST['description']) ? $_POST['description'] : '';

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    $description = trim((string) $rawDescription);

    if (mb_strlen($description, 'UTF-8') > 500) {
        json_fail(422, 'Açıklama en fazla 500 karakter olabilir.');
    }

    bcc_begin_transaction();
    bcc_execute(
        'UPDATE views SET description = :description WHERE id = :id',
        array(':description' => $description === '' ? null : $description, ':id' => $view['id'])
    );
    log_audit('view.description_update', 'view', $view['id'], array('description' => $description), $view['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'description' => $description), JSON_UNESCAPED_UNICODE);
