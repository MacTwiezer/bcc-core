<?php
// AJAX uçnoktası: "Edit view description" popover'ının kaydı — view_rename.php
// ile BİREBİR AYNI güvenlik deseni (CSRF + require_role('editor') +
// view_id'nin gerçekten var olduğu ve team_id'nin ondan geldiği kontrolü).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$rawDescription = isset($_POST['description']) ? $_POST['description'] : '';

try {
    $view = bcc_fetch_one(
        'SELECT v.id, v.table_id, b.team_id
         FROM views v
         INNER JOIN tables_meta tm ON tm.id = v.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE v.id = :id LIMIT 1',
        array(':id' => $viewId)
    );

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    $description = trim((string) $rawDescription);

    if (mb_strlen($description, 'UTF-8') > 500) {
        json_fail(422, 'Açıklama en fazla 500 karakter olabilir.');
    }

    // UPDATE + log_audit AYNI transaction'da — record_add.php/table_clear_data.php/
    // view_create.php/view_delete.php'de bulunan AYNI sınıf bug: ikisi ayrı
    // olsaydı, log_audit() istisna atarsa (nadir ama mümkün) UPDATE zaten
    // commit edilmiş olurdu, istemci yine de "Veritabanı hatası" görürdü.
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
