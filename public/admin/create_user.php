<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (!bcc_is_valid_email($email)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif ($fullName === '') {
        $error = 'Ad Soyad boş olamaz.';
    } elseif (!bcc_is_valid_password($password)) {
        $error = 'Şifre en az 8 karakter olmalı.';
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
        }
    }
}
$pageTitle = 'Yeni Kullanıcı';
require __DIR__ . '/../../src/partials/header.php';
require __DIR__ . '/../../src/partials/top_nav.php';
?>
<div class="page">
    <h1>Yeni Kullanıcı Oluştur</h1>
    <p><a href="/admin/index.php">&larr; Admin paneline dön</a></p>

    <div class="card">
        <?php require __DIR__ . '/../../src/partials/flash.php'; ?>
        <form class="stacked" method="post" action="/admin/create_user.php">
            <?php echo csrf_field(); ?>
            <label>E-posta
                <input type="email" name="email" required>
            </label>
            <label>Ad Soyad
                <input type="text" name="full_name" required>
            </label>
            <label>Şifre (en az 8 karakter)
                <div class="input-with-toggle">
                    <input type="password" name="password" id="create-user-password" required minlength="8">
                    <button type="button" class="input-toggle-btn" id="create-user-password-toggle" aria-label="Şifreyi göster">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                    </button>
                </div>
            </label>
            <div class="form-actions">
                <button type="submit">Oluştur</button>
                <a href="/admin/index.php" class="form-cancel-link">İptal</a>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var toggle = document.getElementById('create-user-password-toggle');
    var input = document.getElementById('create-user-password');
    if (!toggle || !input) { return; }
    toggle.addEventListener('click', function () {
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        toggle.setAttribute('aria-label', showing ? 'Şifreyi göster' : 'Şifreyi gizle');
    });
})();
</script>
<?php require __DIR__ . '/../../src/partials/footer.php'; ?>
