<?php
// AJAX uçnoktası: "Edit view description" popover'ının kaydı — view_rename.php
// ile BİREBİR AYNI güvenlik deseni (CSRF + require_role('editor') +
// view_id'nin gerçekten var olduğu ve team_id'nin ondan geldiği kontrolü).

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

    bcc_execute(
        'UPDATE views SET description = :description WHERE id = :id',
        array(':description' => $description === '' ? null : $description, ':id' => $view['id'])
    );

    log_audit('view.description_update', 'view', $view['id'], array('description' => $description), $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'description' => $description), JSON_UNESCAPED_UNICODE);
