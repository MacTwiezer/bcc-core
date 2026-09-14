<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
$rawValue = isset($_POST['value']) ? $_POST['value'] : '';

try {
    $field = bcc_find_field($fieldId);

    if (!$field) {
        json_fail(404, 'Alan bulunamadı.');
    }

    require_role($field['team_id'], 'editor');

    $record = bcc_fetch_one('SELECT id, table_id, deleted_at FROM records WHERE id = :id LIMIT 1', array(':id' => $recordId));

    if (!$record || (int) $record['table_id'] !== (int) $field['table_id']) {
        json_fail(400, 'Bu kayıt bu alana ait değil.');
    }

    if ($record['deleted_at'] !== null) {
        json_fail(400, 'Bu kayıt silinmiş, düzenlenemez.');
    }

    $usersById = bcc_team_users_by_id($field['team_id']);

    $result = normalize_cell_value($field['field_type'], $field['options'], $rawValue, $usersById);

    if (!$result['ok']) {
        json_fail(422, $result['error']);
    }

    if ((int) $field['is_required'] === 1 && $result['value'] === null) {
        json_fail(422, 'Bu alan zorunludur, boş bırakılamaz.');
    }

    $column = $result['column'];
    $value = $result['value'];

    /* 2026-09-08: Eski degeri okuyan sorgu KALDIRILDI. Mesaj artik "eski -> yeni"
       degil, kaydin GUNCEL degerlerini listeliyor (bkz. src/slack.php toplu
       bildirim blogu), yani eski gosterimi hesaplamanin bir alicisi kalmadi. */

    bcc_begin_transaction();
    $sql = "INSERT INTO cell_values (record_id, field_id, {$column}) VALUES (:record_id, :field_id, :value)
            ON DUPLICATE KEY UPDATE {$column} = VALUES({$column})";
    bcc_execute($sql, array(':record_id' => $recordId, ':field_id' => $fieldId, ':value' => $value));

    bcc_touch_record_modified($recordId);

    log_audit('cell.update', 'record', $recordId, array('field_id' => $fieldId, 'field_name' => $field['name']), $field['team_id']);
    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

$cellRow = array('value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null);
$cellRow[$column] = $value;

$response = array(
    'ok' => true,
    'display' => cell_display_text($field['field_type'], $cellRow, $usersById, $field['options']),
    'raw' => cell_raw_value($field['field_type'], $cellRow),
);

/* 2026-09-08: Bu hucre icin ANINDA mesaj ATILMAZ. Degisiklik zaten
   cell_values.updated_at + records.updated_at damgalarina yazildi; kaydin
   ozeti, uzerinde belirli bir sure islem yapilmayinca TEK mesaj olarak gider
   (src/slack.php, bcc_slack_flush_table). Burada yalnizca "vakti gelmis"
   kayitlar bosaltilir — ayni tabloda calisan onceki bir duzenleme oturumu
   boylece bir sonraki yazmada kanala dusmus olur. */
bcc_slack_flush_table((int) $field['table_id']);

if ($field['field_type'] === 'long_text') {
    $response['display'] = bcc_rich_text_grid_html($response['display']);
}

/* Kullanici alani istemcide yeniden ciziliyor (grid.js renderUserCell): fotograf
   adresi yalnizca bakan kisi o kullaniciyi gorebiliyorsa gonderilir. */
if ($field['field_type'] === 'user') {
    $response['display_avatar'] = ($cellRow['value_number'] !== null && $cellRow['value_number'] !== '')
        ? bcc_avatar_url_for_viewer($cellRow['value_number'])
        : null;
}

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

if (in_array($field['field_type'], $GLOBALS['BCC_LINKIFIED_FIELD_TYPES'], true)) {
    $linkHref = bcc_cell_link_href($field['field_type'], $response['display']);

    $response['display_link'] = ($linkHref !== null)
        ? array('href' => $linkHref, 'text' => $response['display'])
        : null;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
