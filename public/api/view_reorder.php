<?php
// AJAX uçnoktası: sol Views panelindeki "Move up"/"Move down" — mevcut
// bcc_reorder_sibling() (src/schema.php, table_fields.php/base_tables.php'de
// zaten kullanılıyordu) 'views' => 'table_id' eklenerek genişletildi, paralel
// bir sıralama mantığı YAZILMADI. Güvenlik deseni view_rename.php ile AYNI.

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
$direction = isset($_POST['direction']) ? $_POST['direction'] : '';

if ($direction !== 'up' && $direction !== 'down') {
    json_fail(422, 'Geçersiz yön.');
}

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

    $moved = bcc_reorder_sibling('views', 'table_id', $view['table_id'], $view['id'], $direction);

    if ($moved) {
        log_audit('view.reorder', 'view', $view['id'], array('direction' => $direction), $view['team_id']);
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'moved' => $moved), JSON_UNESCAPED_UNICODE);
