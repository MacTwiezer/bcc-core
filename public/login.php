<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;
$info = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'E-posta ve şifre gerekli.';
    } else {
        $status = attempt_login($email, $password);

        if ($status === 'ok') {
            log_audit('user.login');
            header('Location: /dashboard.php');
            exit;
        } elseif ($status === 'inactive') {
            $error = 'Hesabınız henüz yönetici tarafından onaylanmadı.';
        } else {
            $error = 'E-posta veya şifre hatalı.';
        }
    }
} elseif (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $info = 'Kaydınız alındı. Yönetici hesabınızı onayladıktan sonra giriş yapabilirsiniz.';
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>BCC-Core — Giriş</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<script src="/assets/theme-init.js"></script>
<link rel="stylesheet" href="/assets/theme.css">
<link rel="stylesheet" href="/assets/login.css">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-card-header">
        <img src="/assets/logo.png" alt="BCC-Core">
    </div>
    <div class="login-card-body">
        <h1 class="login-title">Hoş geldiniz</h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($info !== null): ?>
            <p class="login-info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <form method="post" action="/login.php">
            <?php echo csrf_field(); ?>
            <div class="login-field">
                <label for="login-email">E-posta</label>
                <input type="email" id="login-email" name="email" required autofocus>
            </div>
            <div class="login-field">
                <label for="login-password">Şifre</label>
                <div class="input-with-toggle">
                    <input type="password" id="login-password" name="password" required>
                    <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="login-submit">Giriş yap</button>
        </form>

        <p class="login-register">
            <a href="/register.php">Kayıt ol</a>
        </p>

        <div class="login-legal">
            <p>
                Giriş yaparak
                <a href="/terms.php">Kullanım Koşulları</a>
                ve
                <a href="/privacy.php">Gizlilik Politikası</a>'nı kabul etmiş olursunuz.
            </p>
            <p class="login-tagline">BCC-Core — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
    </div>
</div>
<script src="/assets/password-toggle.js" defer></script>
</body>
</html>
