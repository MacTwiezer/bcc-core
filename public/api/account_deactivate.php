<?php
// AJAX uçnoktası: Hesap sayfasında (account.php) "Hesabımı Pasife Al".
//
// ---------------------------------------------------------------------------
// HESAP SİLME KALDIRILDI — ürün kararı
// ---------------------------------------------------------------------------
// Eskiden burada api/account_delete.php vardı ve kullanıcının KENDİ hesabını
// KALICI OLARAK silmesine izin veriyordu (gerçek DELETE FROM users). Artık
// hiçbir kullanıcı, rolü ne olursa olsun, kendi hesabını silemez — bu kural
// ROL BAĞIMSIZ: platform admin'i için de geçerli.
//
// Kural arayüzde butonu gizleyerek DEĞİL, silme yolunu ORTADAN KALDIRARAK
// uygulanıyor: account_delete.php dosyası SİLİNDİ. İstek elle hazırlanıp
// gönderilse bile çalışacak bir uçnokta yok (404). Projede kullanıcı satırını
// silen BAŞKA bir yol da yoktu — admin paneli yalnızca aktif/pasif, admin
// yetkisi ve ekipten çıkarma işlemleri yapıyor (bkz. public/admin/index.php).
//
// Silmenin yerine geçen işlem PASİFE ALMA: geri alınabilir (bir yönetici
// yeniden aktifleştirebilir), içerik ve denetim izleri bozulmaz.
//
// Güvenlik: require_role() KULLANILMAZ (account_update_name.php ile AYNI
// gerekçe) — kullanıcının kendi hesabı üzerindeki işlemi ROLDEN BAĞIMSIZDIR.
// Hedef kullanıcı İSTEKTEN ALINMAZ, her zaman oturumdaki $user['id']'dir;
// yani bu uçnokta başkasının hesabını pasife almak için kullanılamaz.
// Mevcut şifre account_update_password.php'deki AYNI password_verify()
// deseniyle doğrulanır (oturumu ele geçiren biri tek istekle hesabı
// kilitleyemesin diye).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$currentPassword = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';

// current_user()'ın SELECT listesinde password_hash YOK — ayrı sorgu şart.
$row = bcc_fetch_one('SELECT password_hash, is_active FROM users WHERE id = :id LIMIT 1', array(':id' => $user['id']));

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    json_fail(422, 'Mevcut şifre yanlış.');
}

// Zaten pasifse (nadir: iki sekmeden art arda istek) sessizce başarı — işlem
// idempotenttir, ikinci istek hata saymaz.
if ((int) $row['is_active'] !== 1) {
    logout_user();
    echo json_encode(array('ok' => true, 'redirect' => '/login.php'), JSON_UNESCAPED_UNICODE);
    exit;
}

// SON ADMIN KORUMASI — eski account_delete.php'den DEVRALINDI ve hâlâ gerekli:
// platformun tek aktif admin'i kendini pasife alırsa admin paneline (ve
// dolayısıyla kendini yeniden aktifleştirecek tek yola) kimse ulaşamaz.
// public/admin/index.php'deki "kendini deaktive etme" korumasıyla AYNI amaç,
// farklı yüzey.
if ((int) $user['is_admin'] === 1) {
    $activeAdminCount = bcc_fetch_one('SELECT COUNT(*) AS c FROM users WHERE is_admin = 1 AND is_active = 1');
    if ($activeAdminCount && (int) $activeAdminCount['c'] <= 1) {
        json_fail(422, 'Platformun tek admin\'i olduğunuz için hesabınızı pasife alamazsınız. Önce başka birini admin yapın.');
    }
}

try {
    bcc_begin_transaction();

    bcc_execute('UPDATE users SET is_active = 0 WHERE id = :id', array(':id' => $user['id']));

    // UPDATE + log_audit AYNI transaction'da — projedeki diğer yazma
    // noktalarıyla AYNI gerekçe: ikisi ayrı olsaydı log_audit() istisna
    // attığında hesap zaten pasife alınmış olurdu ama kaydı düşmezdi.
    // Sıra burada serbest (DELETE değil UPDATE — audit_log.user_id FK'ı
    // kırılmıyor), yine de tek transaction'da tutuluyor.
    log_audit('user.self_deactivate', 'user', $user['id'], array('email' => $user['email']));

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

// Pasif kullanıcı zaten giriş yapamaz (attempt_login 'inactive' döner, bkz.
// src/auth.php) — ama AÇIK OTURUM kendiliğinden düşmez, o yüzden burada
// kapatılıyor.
logout_user();

echo json_encode(array('ok' => true, 'redirect' => '/login.php'), JSON_UNESCAPED_UNICODE);
