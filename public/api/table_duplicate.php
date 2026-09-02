<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$name = isset($_POST['name']) ? (string) $_POST['name'] : '';
$withRecords = !empty($_POST['with_records']);

$table = bcc_fetch_one(
    'SELECT t.id, t.base_id, t.name, b.team_id
     FROM tables_meta t INNER JOIN bases b ON b.id = t.base_id
     WHERE t.id = :id AND b.deleted_at IS NULL',
    array('id' => $tableId)
);

if (!$table) {
    json_fail(404, 'Tablo bulunamadı.');
}

if (!in_array((int) $table['team_id'], current_user_team_ids(), true)) {
    json_fail(403, 'Bu tabloya erişim yetkiniz yok.');
}
if (!bcc_can_manage_schema(current_user_role_in_team((int) $table['team_id']))) {
    json_fail(403, 'Tabloyu çoğaltmak için Owner yetkisi gerekir.');
}

if (trim($name) === '') {
    $base = $table['name'] . ' kopyası';
    $name = $base;
    $suffix = 2;
    while (bcc_name_taken('tables_meta', $table['base_id'], $name)) {
        $name = $base . ' ' . $suffix;
        $suffix++;
        if ($suffix > 200) {
            json_fail(422, 'Uygun bir kopya adı üretilemedi, lütfen elle bir ad girin.');
        }
    }
}

try {
    $result = bcc_duplicate_table($table['id'], $name, $withRecords, $user['id']);
} catch (Throwable $e) {
    json_fail(500, 'Tablo çoğaltılamadı (veritabanı hatası).');
}

if (!$result['ok']) {
    json_fail(422, $result['error']);
}

bcc_notify_slack_new_table((int) $result['id'], $user['full_name']);

echo json_encode(array(
    'ok' => true,
    'table_id' => $result['id'],
    'record_count' => $result['record_count'],
    'attachment_count' => $result['attachment_count'],
    'redirect_url' => '/grid.php?table_id=' . $result['id'],
), JSON_UNESCAPED_UNICODE);
