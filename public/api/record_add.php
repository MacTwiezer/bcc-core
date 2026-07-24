<?php
// AJAX uçnoktası: yeni (boş) kayıt ekler. (a) yuvarlak + butonu, (b) tablo tabanı
// + satırı VE (c) Shift+Enter kısayolu — ÜÇÜ DE bu TEK uç noktayı çağırır
// (grid.php / assets/grid.js) — ikinci bir "kayıt ekle" mekanizması yazılmaz.
// Güvenlik: CSRF + require_role('editor') + table_id doğrulaması; after_record_id
// verilmişse gerçekten bu tabloya ait olduğu kontrol edilir (yoksa sessizce sona eklenir).

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
$afterRecordId = isset($_POST['after_record_id']) ? (int) $_POST['after_record_id'] : 0;
// Silme formunun action URL'i için — grid.php'nin kendi $stateQueryString'i ile aynı
// mantıkla, istemci mevcut adres çubuğunun query string'ini olduğu gibi geri gönderir.
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array(':table_id' => $table['id'])
    );

    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;

    // Görünür alan listesi grid.php ile AYNI whitelist fonksiyonundan gelir —
    // hangi alanların "Hide fields" ile kapatıldığını yeniden yazmıyoruz.
    $stateParams = array();
    parse_str($stateQueryString, $stateParams);
    $hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);

    $visibleFields = array();
    foreach ($fields as $f) {
        if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
            $visibleFields[] = $f;
        }
    }

    bcc_begin_transaction();

    $newPos = null;

    if ($afterRecordId > 0) {
        $afterRecord = bcc_fetch_one(
            'SELECT id, position FROM records WHERE id = :id AND table_id = :tid LIMIT 1',
            array(':id' => $afterRecordId, ':tid' => $table['id'])
        );

        if ($afterRecord) {
            // Araya ekleme: after_record_id'den sonraki kayıtların position'ı bir
            // kaydırılır, yeni kayıt açılan boşluğa yerleşir.
            bcc_execute(
                'UPDATE records SET position = position + 1 WHERE table_id = :tid AND position > :pos',
                array(':tid' => $table['id'], ':pos' => $afterRecord['position'])
            );
            $newPos = $afterRecord['position'] + 1;
        }
    }

    if ($newPos === null) {
        // (a)/(b) her zaman, (c) ise after_record_id gönderilmediğinde (sıralama/
        // gruplama aktifken istemci bilerek göndermez) sona ekler.
        $newPos = (int) bcc_fetch_column(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :tid',
            array(':tid' => $table['id'])
        );
    }

    $user = current_user();
    bcc_execute(
        'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)',
        array(':tid' => $table['id'], ':pos' => $newPos, ':uid' => $user['id'])
    );
    $newRecordId = (int) bcc_last_insert_id();

    bcc_commit();

    log_audit('record.create', 'record', $newRecordId, array('table_id' => $table['id'], 'after_record_id' => $afterRecordId ?: null), $table['team_id']);
    bcc_notify_slack_new_record($table['id'], $newRecordId);
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

// Yeni satırın HTML'i grid.php'nin ilk sayfa render'ıyla AYNI fonksiyondan üretilir
// (bcc_render_grid_data_row, src/schema.php) — ikinci bir satır şablonu yazılmaz.
// $usersById: yeni satırda henüz hücre verisi yok (görüntülenecek isim yok) ama
// 'user' tipi hücrelerin editör seçeneği (data-options) için yine de gerekir.
$usersById = bcc_team_users_by_id($table['team_id']);
$record = array('id' => $newRecordId);
ob_start();
bcc_render_grid_data_row($record, 0, $visibleFields, array(), true, $table['id'], $stateQueryString, null, $usersById);
$rowHtml = ob_get_clean();

echo json_encode(array(
    'ok' => true,
    'record_id' => $newRecordId,
    'row_html' => $rowHtml,
), JSON_UNESCAPED_UNICODE);
