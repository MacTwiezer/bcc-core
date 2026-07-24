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

    // 'user' tipi için tek kaynak: bu takımın (KVKK) aktif üyeleri — hem gönderilen
    // id'nin gerçekten üye olduğunu doğrulamak hem de yanıttaki 'display' adını
    // çözmek için kullanılır (bkz. bcc_team_users_by_id, src/schema.php).
    $usersById = bcc_team_users_by_id($field['team_id']);

    $result = normalize_cell_value($field['field_type'], $field['options'], $rawValue, $usersById);

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

$response = array(
    'ok' => true,
    'display' => cell_display_text($field['field_type'], $cellRow, $usersById),
    'raw' => cell_raw_value($field['field_type'], $cellRow),
);

// Color: tekli/çoklu seçim hücreleri düz metin değil renkli "chip" olarak
// render edilir (bkz. bcc_render_grid_data_row) — bu anahtar VARSA (boş dizi
// dahil), grid.js kaydettikten sonra .cell-view'ı chip olarak yeniden çizer;
// yoksa (diğer tüm tipler) her zamanki gibi düz metin yazılır.
if (is_select_field_type($field['field_type'])) {
    $choices = select_choices_from_options($field['options']);
    $choiceColorMap = bcc_build_choice_color_map($choices, select_choice_colors_from_options($field['options']));

    if ($field['field_type'] === 'single_select') {
        $selectedValues = ($cellRow['value_text'] !== null && $cellRow['value_text'] !== '') ? array($cellRow['value_text']) : array();
    } else {
        $decodedSelected = ($cellRow['value_json'] !== null) ? json_decode($cellRow['value_json'], true) : array();
        $selectedValues = is_array($decodedSelected) ? $decodedSelected : array();
    }

    $response['display_chips'] = bcc_choice_chip_data($selectedValues, $choiceColorMap);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
