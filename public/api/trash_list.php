<?php
// AJAX uçnoktası: Hesap menüsündeki "Çöp kutusu" — Airtable'ın workspace
// trash'i referans alınarak (bkz. support.airtable.com/docs/managing-trash-in-airtable):
// kullanıcının üye olduğu takımlardaki silinmiş base'ler listelenir (KVKK —
// current_user_team_ids() ile AYNI kaynak, ikinci bir "kullanıcının takımları"
// sorgusu YAZILMADI). "Geri Yükle" yalnızca o takımda 'owner' rolündeki
// kullanıcıya gösterilir (istemci) — sunucu tarafında base_restore.php zaten
// require_role('owner') ile ayrıca zorunlu kılıyor.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_login();

$user = current_user();
$teamIds = current_user_team_ids();

$items = array();

if (!empty($teamIds)) {
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT b.id, b.name, b.team_id, b.deleted_at, b.deleted_by, u.full_name AS deleted_by_name
         FROM bases b
         LEFT JOIN users u ON u.id = b.deleted_by
         WHERE b.team_id IN ($placeholders) AND b.deleted_at IS NOT NULL
         ORDER BY b.deleted_at DESC",
        $teamIds
    );

    foreach ($rows as $row) {
        $isSelf = $row['deleted_by'] !== null && (int) $row['deleted_by'] === (int) $user['id'];
        $actorName = $row['deleted_by_name'] !== null ? $row['deleted_by_name'] : 'Bir kullanıcı';
        $message = $isSelf
            ? 'Sen bir base sildin: ' . $row['name']
            : $actorName . ' bir base sildi: ' . $row['name'];

        $items[] = array(
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'message' => $message,
            'relative_date' => bcc_home_relative_date($row['deleted_at']),
            'actor_initial' => $row['deleted_by_name'] !== null ? mb_strtoupper(mb_substr($row['deleted_by_name'], 0, 1, 'UTF-8'), 'UTF-8') : '?',
            'can_restore' => current_user_role_in_team($row['team_id']) === 'owner',
        );
    }
}

echo json_encode(array('ok' => true, 'items' => $items), JSON_UNESCAPED_UNICODE);
