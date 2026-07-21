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

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif ($fullName === '') {
        $error = 'Ad Soyad boş olamaz.';
    } elseif (strlen($password) < 8) {
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
        <?php if ($error !== null): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($success !== null): ?>
            <p class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <form class="stacked" method="post" action="/admin/create_user.php">
            <?php echo csrf_field(); ?>
            <label>E-posta
                <input type="email" name="email" required>
            </label>
            <label>Ad Soyad
                <input type="text" name="full_name" required>
            </label>
            <label>Şifre (en az 8 karakter)
                <input type="password" name="password" required minlength="8">
            </label>
            <button type="submit">Oluştur</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../src/partials/footer.php'; ?>
