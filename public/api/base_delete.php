<?php
// AJAX uçnoktası: dashboard/starred kartının "⋯" menüsündeki "Sil" — Trash
// özelliği (OpsFlow workspace trash referansı). Gerçek DELETE DEĞİL,
// soft-delete (deleted_at/deleted_by) — geri alınabilir (bkz. base_restore.php,
// trash_list.php). Yalnızca 'owner' rolü silebilir (OpsFlow'da da sadece
// Owner silip geri yükleyebiliyor).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$baseId = isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0;
$user = current_user();

try {
    $base = bcc_fetch_one('SELECT id, team_id, name FROM bases WHERE id = :id AND deleted_at IS NULL LIMIT 1', array(':id' => $baseId));

    if (!$base) {
        json_fail(404, 'Base bulunamadı.');
    }

    require_role($base['team_id'], 'owner');

    bcc_execute(
        'UPDATE bases SET deleted_at = NOW(), deleted_by = :uid WHERE id = :id',
        array(':uid' => $user['id'], ':id' => $base['id'])
    );

    log_audit('base.delete', 'base', $base['id'], array('name' => $base['name']), $base['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
