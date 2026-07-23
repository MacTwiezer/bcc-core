<?php
// AJAX uçnoktası: aktif görünümün adını kaydeder (grid.php / assets/grid-table-tabs.js
// tarafından çağrılır, satır içi yeniden adlandırma). Güvenlik: CSRF + require_role('editor')
// + view_id'nin gerçekten var olduğu ve team_id'nin ondan geldiği kontrolü (istekten değil).
// Viewer rolü: istemci dblclick'i zaten açmaz, ama bu uçnokta sunucu tarafında da
// require_role ile ayrıca reddeder — istemci kontrolü tek başına yeterli sayılmaz.

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
$rawName = isset($_POST['name']) ? $_POST['name'] : '';

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

    // KVKK ekip izolasyonu + editor+ rolü — team_id bu satırdan geliyor, istekten değil.
    require_role($view['team_id'], 'editor');

    $name = trim((string) $rawName);

    if ($name === '') {
        json_fail(422, 'Görünüm adı boş olamaz.');
    }
    if (mb_strlen($name, 'UTF-8') > 150) {
        json_fail(422, 'Görünüm adı en fazla 150 karakter olabilir.');
    }

    bcc_execute('UPDATE views SET name = :name WHERE id = :id', array(':name' => $name, ':id' => $view['id']));

    log_audit('view.rename', 'view', $view['id'], array('name' => $name), $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'name' => $name), JSON_UNESCAPED_UNICODE);
