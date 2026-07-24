<?php
// AJAX uçnoktası: "Delete view" — tablonun SON view'ı silinemez (her tablo
// için en az bir görünüm kalmalı; grid.php bcc_get_or_create_default_view()
// ile hep en az bir tane garanti ediyor, silme burada bu garantiyi bozmamalı).
// Güvenlik deseni view_rename.php ile AYNI.

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail($status, $message)
{
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Yalnızca POST.');
}

if (!is_logged_in()) {
    json_fail(401, 'Giriş gerekli.');
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!csrf_verify($token)) {
    json_fail(403, 'Geçersiz istek (CSRF). Sayfayı yenileyip tekrar deneyin.');
}

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

    bcc_execute('DELETE FROM views WHERE id = :id', array(':id' => $view['id']));

    log_audit('view.delete', 'view', $view['id'], array('table_id' => $view['table_id']), $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'fallback_view_id' => $fallbackViewId), JSON_UNESCAPED_UNICODE);
