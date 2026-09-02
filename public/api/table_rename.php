<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
$description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';

$table = find_table_or_404($tableId);
require_role($table['team_id'], 'owner');

if ($name === '') {
    json_fail(422, 'Tablo adı boş olamaz.');
}
if (mb_strlen($name, 'UTF-8') > 150) {
    json_fail(422, 'Tablo adı en fazla 150 karakter olabilir.');
}
if (mb_strlen($description, 'UTF-8') > 500) {
    json_fail(422, 'Açıklama en fazla 500 karakter olabilir.');
}

if (bcc_name_taken('tables_meta', $table['base_id'], $name, $table['id'])) {
    json_fail(422, bcc_name_taken_error('tables_meta', 'tablo'));
}

try {
    bcc_begin_transaction();

    bcc_execute(
        'UPDATE tables_meta SET name = :name, description = :description WHERE id = :id',
        array(
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':id' => $table['id'],
        )
    );
    log_audit('table.rename', 'table', $table['id'], array('name' => $name), $table['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Tablo güncellenemedi (veritabanı hatası).');
}

echo json_encode(array('ok' => true, 'name' => $name), JSON_UNESCAPED_UNICODE);
