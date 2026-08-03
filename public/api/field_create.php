<?php
// AJAX uçnoktası: grid.php'nin sağ üstteki "+" popup'ından (tip-önce-isim-sonra
// akışı) yeni alan/sütun oluşturur. Doğrulama/insert mantığı bcc_create_field()
// (src/schema.php) — table_fields.php'nin tam sayfa formuyla PAYLAŞILIR,
// ikinci bir kopya YOK.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

try {
    $table = bcc_fetch_one(
        'SELECT tm.id, b.team_id FROM tables_meta tm INNER JOIN bases b ON b.id = tm.base_id WHERE tm.id = :id LIMIT 1',
        array(':id' => $tableId)
    );

    if (!$table) {
        json_fail(404, 'Tablo bulunamadı.');
    }

    require_role($table['team_id'], 'owner');

    $result = bcc_create_field($table['id'], $table['team_id'], $_POST);

    if (!$result['ok']) {
        json_fail(422, $result['error']);
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$typeBadges = $GLOBALS['BCC_FIELD_TYPE_BADGE'];
$typeLabels = $GLOBALS['BCC_FIELD_TYPES'];

echo json_encode(array(
    'ok' => true,
    'field_id' => $result['field_id'],
    'name' => $result['name'],
    'field_type' => $result['field_type'],
    'type_label' => $typeLabels[$result['field_type']],
    'type_badge' => $typeBadges[$result['field_type']],
), JSON_UNESCAPED_UNICODE);
