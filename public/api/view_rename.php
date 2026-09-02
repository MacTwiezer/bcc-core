<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$rawName = isset($_POST['name']) ? $_POST['name'] : '';

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    $name = trim((string) $rawName);

    if ($name === '') {
        json_fail(422, 'Görünüm adı boş olamaz.');
    }
    if (mb_strlen($name, 'UTF-8') > 150) {
        json_fail(422, 'Görünüm adı en fazla 150 karakter olabilir.');
    }

    if (bcc_name_taken('views', $view['table_id'], $name, $view['id'])) {
        json_fail(422, bcc_name_taken_error('views', 'görünüm'));
    }

    bcc_begin_transaction();
    bcc_execute('UPDATE views SET name = :name WHERE id = :id', array(':name' => $name, ':id' => $view['id']));
    log_audit('view.rename', 'view', $view['id'], array('name' => $name), $view['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'name' => $name), JSON_UNESCAPED_UNICODE);
