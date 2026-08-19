<?php
// AJAX uçnoktası: "Paylaş" modalından (assets/share-modal.js) üye EKLEME ve
// mevcut bir üyenin ROLÜNÜ DEĞİŞTİRME. team_members.php'nin action=assign
// POST'unun AJAX karşılığı — mantık kopyalanmadı, ikisi de
// bcc_team_member_assign() (src/schema.php) çağırıyor.
//
// Güvenlik zinciri (team_members.php ile AYNI sıra ve AYNI eşikler):
//   1) POST + login + CSRF
//   2) require_role($teamId, 'viewer')  -> KVKK ekip izolasyonu; ekipte
//      olmayan biri bu ekibin varlığını bile öğrenemez (403)
//   3) bcc_can_manage_members()         -> ASIL kapı, yalnızca owner yazabilir.
//      Modal bu butonları yetkisiz kullanıcıya HİÇ basmıyor; burası "gizleme !=
//      yetkilendirme" ikinci katmanı.
//   4) bcc_team_member_assign()         -> hiyerarşi kapısı (rank(hedef) <=
//      rank(ben)) + atanabilir rol whitelist'i
//
// Yanıt, mutasyondan SONRAKİ tam listeyi içerir (bkz. share_modal_payload.php):
// istemci kendi DOM'unu tahmin ederek güncellemiyor, sunucunun döndürdüğü
// gerçek durumu yeniden basıyor.

require __DIR__ . '/../../src/api_bootstrap.php';
require __DIR__ . '/../../src/share_modal_payload.php';

api_require_post();
api_require_login();
api_require_csrf();

$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$rawEmail = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$role = isset($_POST['role']) ? (string) $_POST['role'] : '';

require_role($teamId, 'viewer');

$myRole = current_user_role_in_team($teamId);
if (!bcc_can_manage_members($myRole)) {
    json_fail(403, 'Üye yönetimi için Owner yetkisi gerekir.');
}

$myRank = $GLOBALS['BCC_ROLE_RANK'][$myRole];
$assignableRoles = bcc_assignable_roles($myRank);

try {
    // Modalın davet kutusu E-POSTA alır (OpsFlow davranışı), rol satırındaki
    // <select> ise doğrudan user_id gönderir. E-posta yolunda kullanıcıyı
    // BURADA çözüyoruz ki istemci bir kullanıcı listesi taşımak zorunda
    // kalmasın ve hata mesajı "hesap yok" ile "yetki yok" arasında ayrışsın.
    //
    // HESAP OLUŞTURULMAZ: bu uygulamada hesap açma platform admin'inde
    // (admin/create_user.php) ya da kullanıcının kendi kaydında
    // (register.php + verify_email.php). Owner'ın bir e-postaya hesap
    // açabilmesi ayrı bir yetki genişlemesi olurdu — bilinçli olarak YOK.
    if ($targetUserId <= 0 && $rawEmail !== '') {
        $found = bcc_fetch_one(
            'SELECT id, is_active FROM users WHERE email = :email LIMIT 1',
            array('email' => $rawEmail)
        );

        if (!$found) {
            json_fail(404, 'Bu e-postayla kayıtlı bir hesap yok. Hesap oluşturma platform yöneticisindedir.');
        }
        if ((int) $found['is_active'] !== 1) {
            json_fail(422, 'Bu hesap henüz e-posta doğrulamasını tamamlamadı; doğrulandıktan sonra ekleyebilirsiniz.');
        }

        $targetUserId = (int) $found['id'];
    }

    $result = bcc_team_member_assign($teamId, $targetUserId, $role, $myRank, $assignableRoles);

    if (!$result['ok']) {
        // 422: istek biçimsel olarak geçerli ama iş kuralına takıldı
        // (geçersiz rol / bulunamayan kullanıcı / hiyerarşi).
        json_fail(422, $result['error']);
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$payload = bcc_share_modal_payload($teamId, $myRole);
$payload['ok'] = true;
$payload['message'] = $result['created'] ? 'Katılımcı eklendi.' : 'Rol güncellendi.';

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
