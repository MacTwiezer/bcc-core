<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();
$user = current_user();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (!bcc_is_valid_email($email)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif (mb_strlen($email, 'UTF-8') > 190) {
        // users.email VARCHAR(190) — bu kontrol olmadan uzun bir e-posta hatasız
        // sessizce kırpılıyordu (sql_mode'da STRICT_TRANS_TABLES yok, doğrulandı).
        // account_update_email.php ile AYNI sınır/mesaj.
        $error = 'E-posta en fazla 190 karakter olabilir.';
    } elseif ($fullName === '') {
        $error = 'Ad Soyad boş olamaz.';
    } elseif (mb_strlen($fullName, 'UTF-8') > 150) {
        // users.full_name VARCHAR(150) — account_update_name.php ile AYNI sınır/mesaj.
        $error = 'Ad Soyad en fazla 150 karakter olabilir.';
    } elseif (!bcc_is_valid_password($password)) {
        $error = 'Şifre 8-72 karakter arasında olmalı.';
    } else {
        $existing = bcc_fetch_one('SELECT id FROM users WHERE email = :email', array('email' => $email));

        if ($existing) {
            $error = 'Bu e-posta zaten kayıtlı.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            bcc_execute(
                'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :full_name, 0, 1)',
                array('email' => $email, 'hash' => $hash, 'full_name' => $fullName)
            );
            $newId = bcc_last_insert_id();
            log_audit('user.create', 'user', $newId, array('email' => $email));
            $success = 'Kullanıcı oluşturuldu: ' . $email;
            // Bir sonraki kullanıcı için formu temizle.
            $email = '';
            $fullName = '';
        }
    }
}
// Sol panel "Yıldızlılar" listesi — workspaces.php/team_members.php ile AYNI desen.
$starredBases = array();
$teamIdsForStar = current_user_team_ids();
if (!empty($teamIdsForStar)) {
    $starredPlaceholders = implode(',', array_fill(0, count($teamIdsForStar), '?'));
    $starredBases = bcc_fetch_all(
        "SELECT b.id, b.name FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($starredPlaceholders) AND b.deleted_at IS NULL
         WHERE usb.user_id = ? ORDER BY b.name",
        array_merge($teamIdsForStar, array((int) $user['id']))
    );
}

$homeActiveNav = 'admin';
$homePageTitle = 'BCC-Core — Yeni Kullanıcı';
require __DIR__ . '/../../src/partials/home_shell_top.php';
?>
        <div class="settings-breadcrumb">
            <a href="/admin/index.php">&larr; Admin paneline dön</a>
        </div>
        <div class="home-main-header">
            <h1>Yeni Kullanıcı Oluştur</h1>
        </div>

        <div class="settings-card">
            <?php require __DIR__ . '/../../src/partials/flash.php'; ?>
            <form class="settings-form settings-form-stacked" method="post" action="/admin/create_user.php">
                <?php echo csrf_field(); ?>
                <label class="settings-field">E-posta
                    <input type="email" name="email" value="<?php echo htmlspecialchars(isset($email) ? $email : '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label class="settings-field">Ad Soyad
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars(isset($fullName) ? $fullName : '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label class="settings-field">Şifre (en az 8 karakter)
                    <div class="input-with-toggle">
                        <input type="password" name="password" required minlength="8" maxlength="72">
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </label>
                <div class="settings-form-actions">
                    <button type="submit" class="settings-btn settings-btn-primary">Oluştur</button>
                    <a href="/admin/index.php" class="settings-cancel-link">İptal</a>
                </div>
            </form>
        </div>
<script src="<?php echo bcc_asset_url('password-toggle.js'); ?>" defer></script>
<?php require __DIR__ . '/../../src/partials/home_shell_bottom.php'; ?>
