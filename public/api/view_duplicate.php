<?php
// AJAX uçnoktası: "Duplicate view" — mevcut view'ın config'ini (grid_state)
// kopyalayan yeni bir views satırı oluşturur. Güvenlik deseni view_rename.php
// ile AYNI. Yeni satır tablonun EN SONUNA eklenir (position = mevcut MAX + 1).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$user = current_user();

try {
    $view = bcc_fetch_one(
        'SELECT v.id, v.table_id, v.name, v.description, v.view_type, v.config, b.team_id
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

    // Aynı view art arda birden çok kez çoğaltılırsa (bulunan gerçek bug: gayet
    // olağan bir kullanım — "Duplicate view"e iki kez tıklamak) hepsi AYNI
    // "X copy" adını alıyordu. Çakışma varsa "X copy 2", "X copy 3" ... şeklinde
    // ilk boş numaraya düşülür.
    $baseName = mb_substr($view['name'] . ' copy', 0, 150, 'UTF-8');
    $newName = $baseName;
    $suffix = 2;
    while (bcc_fetch_one('SELECT id FROM views WHERE table_id = :table_id AND name = :name', array(':table_id' => $view['table_id'], ':name' => $newName))) {
        $newName = mb_substr($baseName . ' ' . $suffix, 0, 150, 'UTF-8');
        $suffix++;
    }

    $nextPosition = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 FROM views WHERE table_id = :table_id',
        array(':table_id' => $view['table_id'])
    );

    // INSERT + log_audit AYNI transaction'da — record_add.php/table_clear_data.php/
    // view_create.php/view_delete.php/view_description_update.php'de bulunan
    // AYNI sınıf bug: ikisi ayrı olsaydı, log_audit() istisna atarsa (nadir ama
    // mümkün) INSERT zaten commit edilmiş olurdu, istemci yine de "Veritabanı
    // hatası" görürdü.
    bcc_begin_transaction();

    bcc_execute(
        'INSERT INTO views (table_id, name, description, view_type, position, config, created_by)
         VALUES (:table_id, :name, :description, :view_type, :position, :config, :created_by)',
        array(
            ':table_id' => $view['table_id'],
            ':name' => $newName,
            ':description' => $view['description'],
            ':view_type' => $view['view_type'],
            ':position' => $nextPosition,
            ':config' => $view['config'],
            ':created_by' => $user ? $user['id'] : null,
        )
    );

    $newViewId = bcc_last_insert_id();

    log_audit('view.duplicate', 'view', $newViewId, array('source_view_id' => $view['id'], 'name' => $newName), $view['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'view_id' => $newViewId, 'name' => $newName), JSON_UNESCAPED_UNICODE);
