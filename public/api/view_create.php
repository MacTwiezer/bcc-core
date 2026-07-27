<?php
// AJAX uçnoktası: "+ Yeni oluştur..." — sol Görünüm panelinde boş (config=NULL)
// yeni bir view oluşturur. view_duplicate.php ile AYNI ekleme deseni (tablonun
// EN SONUNA, position = mevcut MAX + 1) — yalnızca config'i kopyalamak yerine
// boş bırakır. Güvenlik deseni diğer view_*.php uçnoktalarıyla AYNI.

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

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$user = current_user();

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    $viewCount = (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id = :table_id', array(':table_id' => $table['id']));
    $newName = 'Görünüm ' . ($viewCount + 1);
    $nextPosition = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 FROM views WHERE table_id = :table_id',
        array(':table_id' => $table['id'])
    );

    bcc_execute(
        'INSERT INTO views (table_id, name, view_type, position, created_by)
         VALUES (:table_id, :name, :view_type, :position, :created_by)',
        array(
            ':table_id' => $table['id'],
            ':name' => $newName,
            ':view_type' => 'grid',
            ':position' => $nextPosition,
            ':created_by' => $user ? $user['id'] : null,
        )
    );

    $newViewId = bcc_last_insert_id();

    log_audit('view.create', 'view', $newViewId, array('table_id' => $table['id'], 'name' => $newName), $table['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'view_id' => $newViewId, 'name' => $newName), JSON_UNESCAPED_UNICODE);
