<?php
// AJAX uçnoktası: Hesap sayfasında (account.php) "Hesabımı Sil". require_role()
// KULLANILMAZ (bkz. account_update_name.php) — yalnızca require_login() +
// oturumdaki $user['id'], kullanıcı yalnızca KENDİ hesabını siler. Mevcut şifre
// account_update_password.php'deki AYNI password_verify() deseniyle doğrulanır
// (geri alınamaz bir işlem — oturumu ele geçiren biri tek istekle hesabı
// silemesin diye).
//
// Gerçek (hard) DELETE — schema.sql'deki FK'lar zaten güvenli: bases/records/
// attachments/views.created_by/uploaded_by ON DELETE SET NULL (içerik kalır,
// sahiplik anonimleşir), team_members/user_starred_bases/user_favorite_views
// ON DELETE CASCADE (kişisel üyelik/tercih temizlenir), audit_log.user_id
// ON DELETE SET NULL (denetim kaydı kalır — bcc_notification_message() zaten
// NULL actor_name için "Bir kullanıcı" gösteriyor, ekstra kod gerekmedi).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';

$row = bcc_fetch_one('SELECT password_hash FROM users WHERE id = :id LIMIT 1', array(':id' => $user['id']));

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    json_fail(422, 'Mevcut şifre yanlış.');
}

if ((int) $user['is_admin'] === 1) {
    $activeAdminCount = bcc_fetch_one('SELECT COUNT(*) AS c FROM users WHERE is_admin = 1 AND is_active = 1');
    if ($activeAdminCount && (int) $activeAdminCount['c'] <= 1) {
        json_fail(422, 'Platformun tek admin\'i olduğunuz için hesabınızı silemezsiniz. Önce başka birini admin yapın.');
    }
}

try {
    // details'e ad/e-posta anlık görüntüsü — satır silinince user_id NULL'lanacağı
    // için (audit_log FK) denetim kaydının kendisi anonim aktörle kalacak, ama
    // details JSON (FK'sız) kimin silindiğini adli/denetim amaçlı saklar.
    log_audit('user.self_delete', 'user', $user['id'], array('email' => $user['email'], 'full_name' => $user['full_name']));

    bcc_execute('DELETE FROM users WHERE id = :id', array(':id' => $user['id']));
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

logout_user();

echo json_encode(array('ok' => true, 'redirect' => '/login.php'), JSON_UNESCAPED_UNICODE);
