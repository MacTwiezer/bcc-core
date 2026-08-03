<?php
// AJAX uçnoktası: bir kaydın yorumlarını listeler (grid-row-detail.js, satır
// genişletme paneli). Salt-okunur — Airtable'da "Access and view" dört rolde de
// (viewer dahil) açık, bu yüzden require_role('editor') YOK, view_export_csv.php
// ile AYNI desen (yalnızca require_team_access). team_id record_id üzerinden
// bcc_find_record() ile DB'den türetilir (istekten değil, KVKK).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_login();

$recordId = isset($_GET['record_id']) ? (int) $_GET['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_team_access($record['team_id']);

$currentUserId = (int) current_user()['id'];

$rows = bcc_fetch_all(
    'SELECT c.id, c.body, c.created_at, c.updated_at, c.user_id, u.full_name
     FROM comments c
     LEFT JOIN users u ON u.id = c.user_id
     WHERE c.record_id = :record_id AND c.deleted_at IS NULL
     ORDER BY c.created_at ASC',
    array('record_id' => $recordId)
);

$comments = array();
foreach ($rows as $row) {
    $comments[] = array(
        'id' => (int) $row['id'],
        'body' => $row['body'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'author_name' => $row['full_name'],
        'is_own' => $row['user_id'] !== null && (int) $row['user_id'] === $currentUserId,
    );
}

echo json_encode(array('ok' => true, 'comments' => $comments), JSON_UNESCAPED_UNICODE);
