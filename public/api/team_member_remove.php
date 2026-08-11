<?php
// AJAX uçnoktası: "Paylaş" modalından (assets/share-modal.js) bir katılımcıyı
// ekipten çıkarma. team_members.php'nin action=remove POST'unun AJAX karşılığı —
// mantık kopyalanmadı, ikisi de bcc_team_member_remove_many() (src/schema.php)
// çağırıyor; "kendini çıkaramama", "son owner", hiyerarşi kuralları ve audit
// action adı oradan gelir.
//
// Güvenlik zinciri team_member_assign.php ile BİREBİR AYNI (aynı sıra, aynı
// eşikler) — oradaki yorumun kopyası yazılmadı.

require __DIR__ . '/../../src/api_bootstrap.php';
require __DIR__ . '/../../src/share_modal_payload.php';

api_require_post();
api_require_login();
api_require_csrf();

$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

require_role($teamId, 'viewer');

$myRole = current_user_role_in_team($teamId);
if (!bcc_can_manage_members($myRole)) {
    json_fail(403, 'Üye yönetimi için Owner yetkisi gerekir.');
}

$myRank = $GLOBALS['BCC_ROLE_RANK'][$myRole];

if ($targetUserId <= 0) {
    json_fail(422, 'Geçersiz seçim.');
}

try {
    $user = current_user();
    // Modal her zaman TEK kişi çıkarır; toplu çıkarma team_members.php'nin
    // onay kutulu ekranında kalıyor. Yine de tek elemanlı liste olarak AYNI
    // fonksiyondan geçiyor — ikinci bir kod yolu yok.
    $result = bcc_team_member_remove_many($teamId, array($targetUserId), (int) $user['id'], $myRank);
    $messages = bcc_team_member_remove_message($result);

    if ($messages['error'] !== null) {
        json_fail(422, $messages['error']);
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$payload = bcc_share_modal_payload($teamId, $myRole);
$payload['ok'] = true;
$payload['message'] = $messages['success'];

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
