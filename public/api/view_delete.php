<?php
// AJAX uçnoktası: "Delete view" — tablonun SON view'ı silinemez (her tablo
// için en az bir görünüm kalmalı; grid.php bcc_get_or_create_default_view()
// ile hep en az bir tane garanti ediyor, silme burada bu garantiyi bozmamalı).
// Güvenlik deseni view_rename.php ile AYNI.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;

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

    $viewCount = (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id = :table_id', array(':table_id' => $view['table_id']));

    if ($viewCount <= 1) {
        json_fail(422, 'Bir tabloda en az bir görünüm kalmalı, son görünüm silinemez.');
    }

    // Silinen aktif view'sa nereye dönüleceğini istemciye söylemek için —
    // tablonun (silmeden sonra) kalan en eski view'ı.
    $fallbackViewId = (int) bcc_fetch_column(
        'SELECT id FROM views WHERE table_id = :table_id AND id != :id ORDER BY id ASC LIMIT 1',
        array(':table_id' => $view['table_id'], ':id' => $view['id'])
    );

    // DELETE + log_audit AYNI transaction'da — record_add.php/table_clear_data.php/
    // view_create.php'de bulunan AYNI sınıf bug: ikisi ayrı olsaydı, log_audit()
    // istisna atarsa (nadir ama mümkün) DELETE zaten commit edilmiş olurdu,
    // istemci yine de "Veritabanı hatası" görürdü.
    bcc_begin_transaction();
    bcc_execute('DELETE FROM views WHERE id = :id', array(':id' => $view['id']));
    log_audit('view.delete', 'view', $view['id'], array('table_id' => $view['table_id']), $view['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'fallback_view_id' => $fallbackViewId), JSON_UNESCAPED_UNICODE);
