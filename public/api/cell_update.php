<?php
// AJAX uçnoktası: tek bir hücreyi kaydeder (grid.php / assets/grid.js tarafından çağrılır).
// Güvenlik: CSRF + require_role('editor') + kaydın gerçekten bu alanın tablosuna ait
// olduğu kontrolü. team_id her zaman DB satırından gelir (istekten değil).

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

$fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
$rawValue = isset($_POST['value']) ? $_POST['value'] : '';

try {
    $field = bcc_find_field($fieldId);

    if (!$field) {
        json_fail(404, 'Alan bulunamadı.');
    }

    // KVKK ekip izolasyonu + editor+ rolü — team_id bu satırdan geliyor, istekten değil.
    require_role($field['team_id'], 'editor');

    $record = bcc_fetch_one('SELECT id, table_id FROM records WHERE id = :id LIMIT 1', array(':id' => $recordId));

    if (!$record || (int) $record['table_id'] !== (int) $field['table_id']) {
        json_fail(400, 'Bu kayıt bu alana ait değil.');
    }

    $result = normalize_cell_value($field['field_type'], $field['options'], $rawValue);

    if (!$result['ok']) {
        json_fail(422, $result['error']);
    }

    $column = $result['column'];
    $value = $result['value'];

    $sql = "INSERT INTO cell_values (record_id, field_id, {$column}) VALUES (:record_id, :field_id, :value)
            ON DUPLICATE KEY UPDATE {$column} = VALUES({$column})";
    bcc_execute($sql, array(':record_id' => $recordId, ':field_id' => $fieldId, ':value' => $value));

    log_audit('cell.update', 'record', $recordId, array('field_id' => $fieldId, 'field_name' => $field['name']), $field['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$cellRow = array('value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null);
$cellRow[$column] = $value;

echo json_encode(array(
    'ok' => true,
    'display' => cell_display_text($field['field_type'], $cellRow),
    'raw' => cell_raw_value($field['field_type'], $cellRow),
), JSON_UNESCAPED_UNICODE);
