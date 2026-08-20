<?php
// register.php'nin e-postayla gönderdiği tek kullanımlık bağlantı buraya çıkar.
// Kullanıcı burada kendi şifresini oluşturur; başarılı olursa hesap is_active=1
// olur ve token tekrar kullanılamaz hale gelir (NULL'lanır). Ekip ataması bu
// sayfanın işi DEĞİL — o admin/assign_team.php veya team_members.php'nin işi;
// takımsız kalan kullanıcı giriş yaptığında dashboard.php ayrı bir bekleme
// mesajı gösterir (bkz. dashboard.php).

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

function bcc_find_pending_user_by_token($token)
{
    if ($token === '') {
        return false;
    }

    return bcc_fetch_one(
        'SELECT id, full_name, email_verify_expires_at FROM users
         WHERE email_verify_token = :token AND is_active = 0 LIMIT 1',
        array('token' => $token)
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $token = isset($_POST['token']) ? $_POST['token'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $passwordConfirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

    $pending = bcc_find_pending_user_by_token($token);

    if (!$pending) {
        $error = 'Bağlantı geçersiz veya daha önce kullanılmış. Tekrar kayıt olmayı deneyin.';
    } elseif (strtotime($pending['email_verify_expires_at']) < time()) {
        $error = 'Bu bağlantının süresi dolmuş. Tekrar kayıt olmayı deneyin, yeni bir bağlantı gönderilecek.';
    } elseif (!bcc_is_valid_password($password)) {
        $error = 'Şifre 8-72 karakter arasında olmalı.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        bcc_execute(
            'UPDATE users
             SET password_hash = :hash, is_active = 1, email_verify_token = NULL, email_verify_expires_at = NULL
             WHERE id = :id',
            array('hash' => $hash, 'id' => $pending['id'])
        );
        log_audit('user.email_verified', 'user', $pending['id']);

        header('Location: /login.php?verified=1');
        exit;
    }
}

// GET (veya doğrulama başarısız POST sonrası) — token hâlâ URL'de/formda taşınır.
$token = isset($_GET['token']) ? $_GET['token'] : (isset($_POST['token']) ? $_POST['token'] : '');
$pending = $error === null ? bcc_find_pending_user_by_token($token) : null;

if ($error === null && !$pending) {
    $error = 'Bağlantı geçersiz veya daha önce kullanılmış. Tekrar kayıt olmayı deneyin.';
} elseif ($error === null && strtotime($pending['email_verify_expires_at']) < time()) {
    $error = 'Bu bağlantının süresi dolmuş. Tekrar kayıt olmayı deneyin, yeni bir bağlantı gönderilecek.';
    $pending = null;
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars(bcc_brand_name() . ' — E-posta doğrulama', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo bcc_asset_url('favicon.svg'); ?>">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('login.css'); ?>">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-logo">
        <?php $brandLogoClass = 'login-logo-mark'; $brandLogoHeight = 44; require __DIR__ . '/../src/partials/brand_logo.php'; ?>
    </div>
    <div class="login-card-body">
        <h1 class="login-title">Şifreni oluştur</h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($pending): ?>
            <p class="login-tagline">Merhaba <?php echo htmlspecialchars($pending['full_name'], ENT_QUOTES, 'UTF-8'); ?>, e-postan doğrulandı. Devam etmek için bir şifre oluştur.</p>

            <form method="post" action="/verify_email.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="login-field">
                    <label for="verify-password">Şifre</label>
                    <div class="input-with-toggle">
                        <input type="password" id="verify-password" name="password" minlength="8" maxlength="72" required autofocus>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </div>
                <div class="login-field">
                    <label for="verify-password-confirm">Şifre (tekrar)</label>
                    <div class="input-with-toggle">
                        <input type="password" id="verify-password-confirm" name="password_confirm" minlength="8" maxlength="72" required>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="login-submit">Şifreyi oluştur ve hesabı etkinleştir</button>
            </form>
        <?php else: ?>
            <p class="login-register">
                <a href="/register.php">Tekrar kayıt ol</a>
            </p>
        <?php endif; ?>
    </div>
</div>
<script src="<?php echo bcc_asset_url('password-toggle.js'); ?>" defer></script>
</body>
</html>
