<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
}

function bcc_find_user_by_reset_token($token)
{
    if ($token === '') {
        return false;
    }

    return bcc_fetch_one(
        'SELECT id, full_name, password_reset_expires_at
         FROM users
         WHERE password_reset_token = :hash AND is_active = 1
         LIMIT 1',
        array('hash' => hash('sha256', $token))
    );
}

$error = null;

$done = isset($_GET['done']) && $_GET['done'] === '1';

$token = isset($_POST['token'])
    ? (string) $_POST['token']
    : (isset($_GET['token']) ? (string) $_GET['token'] : '');

$user = $done ? false : bcc_find_user_by_reset_token($token);

if (!$done) {
    if ($token === '') {
        $error = 'Geçersiz istek: bağlantı parametresi eksik. Lütfen e-postanızdaki bağlantıyı kullanın.';
    } elseif (!$user) {
        $error = 'Bağlantı geçersiz veya daha önce kullanılmış. Lütfen yeni bir sıfırlama bağlantısı isteyin.';
    } elseif (strtotime($user['password_reset_expires_at']) < time()) {
        $error = 'Bu bağlantının süresi dolmuş (bağlantılar 1 saat geçerlidir). Lütfen yeni bir sıfırlama bağlantısı isteyin.';
        $user = false;
    }
}

if (!$done && $user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $passwordConfirm = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';

    if (!bcc_is_valid_password($password)) {
        $error = 'Şifre 8-72 karakter arasında olmalı.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $affected = bcc_execute(
            'UPDATE users
             SET password_hash = :hash, password_reset_token = NULL, password_reset_expires_at = NULL
             WHERE id = :id AND password_reset_token = :hash_check',
            array(
                'hash' => $hash,
                'id' => $user['id'],
                'hash_check' => hash('sha256', $token),
            )
        );

        if ($affected < 1) {
            $error = 'Bağlantı geçersiz veya daha önce kullanılmış. Lütfen yeni bir sıfırlama bağlantısı isteyin.';
            $user = false;
        } else {
            log_audit('user.password_reset_completed', 'user', $user['id']);

            header('Location: /reset-password.php?done=1');
            exit;
        }
    }
}
?>
<?php
$authPageTitle = 'Yeni şifre belirle';
require __DIR__ . '/../src/partials/auth_shell_top.php';
?>
        <h1 class="login-title"><?php echo $done ? 'Şifreniz güncellendi' : 'Yeni şifre belirle'; ?></h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($done): ?>
            <p class="login-info">Şifreniz başarıyla güncellendi. Artık yeni şifrenizle giriş yapabilirsiniz.</p>
            <a class="login-submit login-submit-link" href="/login.php">Giriş sayfasına git</a>

        <?php elseif ($user): ?>
            <p class="login-tagline">Merhaba <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>, hesabınız için yeni bir şifre belirleyin. Şifre en az 8 karakter olmalı.</p>

            <?php
                  ?>
            <form method="post" action="/reset-password.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="login-field">
                    <label for="reset-password">Yeni Şifre</label>
                    <div class="input-with-toggle">
                        <input type="password" id="reset-password" name="password" minlength="8" maxlength="72" required autofocus>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </div>

                <div class="login-field">
                    <label for="reset-password-confirm">Yeni Şifre (Tekrar)</label>
                    <div class="input-with-toggle">
                        <input type="password" id="reset-password-confirm" name="password_confirm" minlength="8" maxlength="72" required>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-submit">Şifremi Güncelle</button>
            </form>

        <?php else: ?>
            <p class="login-register">
                <a href="/forgot-password.php">Yeni sıfırlama bağlantısı iste</a>
            </p>
        <?php endif; ?>
<?php
$authScripts = array('password-toggle.js');
require __DIR__ . '/../src/partials/auth_shell_bottom.php';
