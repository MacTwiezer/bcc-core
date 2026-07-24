<?php
// AJAX uçnoktası: F3 Duyuru arayüzünün arama kutusu (public/interface.js).
// Salt-okunur (SELECT), mutasyon yok — CSRF gerekmez, ama require_team_access()
// aynen diğer her uçnokta gibi zorunlu. Kayıtları YENİDEN RENDER ETMEZ — yalnızca
// eşleşen record_id listesini döner, interface.php'nin zaten bastığı satırlar
// istemci tarafında gösterilip/gizlenir (ikinci bir HTML üretim yolu yok).
// bcc_interface_fetch_records() interface.php'nin İLK yüklemede kullandığı AYNI
// fonksiyon — paralel sorgu YOK.

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail($status, $message)
{
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    json_fail(401, 'Giriş gerekli.');
}

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $table = find_table_or_404($tableId);
    require_team_access($table['team_id']);

    $fields = bcc_fetch_all(
        'SELECT id, field_type FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array('table_id' => $tableId)
    );
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
    $summaryField = bcc_interface_summary_field($fields);
    $summaryFieldId = $summaryField ? (int) $summaryField['id'] : null;

    $records = bcc_interface_fetch_records($tableId, $primaryFieldId, $summaryFieldId, $query);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'record_ids' => array_map('intval', array_column($records, 'id'))), JSON_UNESCAPED_UNICODE);
