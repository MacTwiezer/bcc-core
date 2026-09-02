<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$baseId = isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0;

try {
    $base = bcc_fetch_one('SELECT id, team_id, name FROM bases WHERE id = :id AND deleted_at IS NOT NULL LIMIT 1', array(':id' => $baseId));

    if (!$base) {
        json_fail(404, 'Silinmiş base bulunamadı.');
    }

    require_role($base['team_id'], 'owner');

    if (bcc_name_taken('bases', $base['team_id'], $base['name'])) {
        json_fail(422, 'Bu adda aktif bir base zaten var. Geri yüklemeden önce birini yeniden adlandırın.');
    }

    bcc_execute('UPDATE bases SET deleted_at = NULL, deleted_by = NULL WHERE id = :id', array(':id' => $base['id']));

    log_audit('base.restore', 'base', $base['id'], array('name' => $base['name']), $base['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
