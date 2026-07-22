<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($fullName === '' || $email === '' || $password === '') {
        $error = 'Tüm alanları doldurun.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif (strlen($password) < 8) {
        $error = 'Şifre en az 8 karakter olmalı.';
    } else {
        $existing = bcc_fetch_one('SELECT id FROM users WHERE email = :email LIMIT 1', array('email' => $email));

        if ($existing) {
            $error = 'Bu e-posta zaten kayıtlı.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            bcc_execute(
                'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :full_name, 0, 0)',
                array('email' => $email, 'hash' => $hash, 'full_name' => $fullName)
            );
            $newId = bcc_last_insert_id();
            log_audit('user.register', 'user', $newId, array('email' => $email));

            header('Location: /login.php?registered=1');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>BCC-Core — Kayıt ol</title>
<link rel="stylesheet" href="/assets/login.css">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-card-header">
        <img src="/assets/bcc-logo.svg" alt="BCC-Core">
    </div>
    <div class="login-card-body">
        <h1 class="login-title">Kayıt ol</h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <form method="post" action="/register.php">
            <?php echo csrf_field(); ?>
            <div class="login-field">
                <label for="register-fullname">Ad Soyad</label>
                <input type="text" id="register-fullname" name="full_name" value="<?php echo htmlspecialchars(isset($fullName) ? $fullName : '', ENT_QUOTES, 'UTF-8'); ?>" required autofocus>
            </div>
            <div class="login-field">
                <label for="register-email">E-posta</label>
                <input type="email" id="register-email" name="email" value="<?php echo htmlspecialchars(isset($email) ? $email : '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="login-field">
                <label for="register-password">Şifre</label>
                <input type="password" id="register-password" name="password" minlength="8" required>
            </div>
            <button type="submit" class="login-submit">Kayıt ol</button>
        </form>

        <p class="login-register">
            Zaten hesabın var mı? <a href="/login.php">Giriş yap</a>
        </p>

        <div class="login-legal">
            <p>
                Kayıt olarak
                <a href="/terms.php">Kullanım Koşulları</a>
                ve
                <a href="/privacy.php">Gizlilik Politikası</a>'nı kabul etmiş olursunuz.
            </p>
            <p class="login-tagline">BCC-Core — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
    </div>
</div>
</body>
</html>
