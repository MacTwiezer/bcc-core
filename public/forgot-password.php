<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}


define('BCC_PASSWORD_RESET_TTL', 3600);

define('BCC_PASSWORD_RESET_COOLDOWN', 120);

define('BCC_PASSWORD_RESET_IP_WINDOW', 3600);
define('BCC_PASSWORD_RESET_IP_MAX', 5);


function bcc_reset_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

function bcc_reset_ip_quota_exceeded($ip)
{
    if ($ip === '') {
        return false;
    }

    $since = date('Y-m-d H:i:s', time() - BCC_PASSWORD_RESET_IP_WINDOW);

    bcc_execute(
        'DELETE FROM password_reset_attempts WHERE attempted_at < :since',
        array('since' => $since)
    );

    $count = bcc_fetch_column(
        'SELECT COUNT(*) FROM password_reset_attempts WHERE ip_address = :ip AND attempted_at >= :since',
        array('ip' => $ip, 'since' => $since)
    );

    return (int) $count >= BCC_PASSWORD_RESET_IP_MAX;
}

function bcc_reset_record_attempt($ip)
{
    if ($ip === '') {
        return;
    }

    bcc_execute(
        'INSERT INTO password_reset_attempts (ip_address, attempted_at) VALUES (:ip, :now)',
        array('ip' => $ip, 'now' => date('Y-m-d H:i:s'))
    );
}


$error = null;
$info = null;

if (isset($_GET['sent']) && $_GET['sent'] === '1') {
    $info = 'Eğer bu adres kayıtlı ve etkin bir hesaba aitse, şifre sıfırlama bağlantısı gönderildi. E-postanızı kontrol edin — bağlantı 1 saat geçerlidir.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $ip = bcc_reset_client_ip();

    if ($email === '') {
        $error = 'E-posta adresinizi girin.';
    } elseif (!bcc_is_valid_email($email)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif (bcc_reset_ip_quota_exceeded($ip)) {
        $error = 'Çok fazla şifre sıfırlama talebi gönderildi. Lütfen bir saat sonra tekrar deneyin.';
    } else {
        bcc_reset_record_attempt($ip);

        $row = bcc_fetch_one(
            'SELECT id, full_name, is_active, password_reset_expires_at FROM users WHERE email = :email LIMIT 1',
            array('email' => $email)
        );

        if ($row
            && (int) $row['is_active'] === 1
            && bcc_should_send_verification_mail($row['password_reset_expires_at'], BCC_PASSWORD_RESET_COOLDOWN, null, BCC_PASSWORD_RESET_TTL)
        ) {
            $rawToken = bin2hex(random_bytes(32));

            $tokenHash = hash('sha256', $rawToken);

            $expiresAt = date('Y-m-d H:i:s', time() + BCC_PASSWORD_RESET_TTL);

            bcc_execute(
                'UPDATE users SET password_reset_token = :hash, password_reset_expires_at = :expires WHERE id = :id',
                array('hash' => $tokenHash, 'expires' => $expiresAt, 'id' => $row['id'])
            );

            $resetLink = bcc_app_base_url() . '/reset-password.php?token=' . $rawToken;

            $bodyText = "Merhaba {$row['full_name']},\n\n"
                . bcc_brand_name() . " hesabınız için şifre sıfırlama talebi aldık. Yeni şifrenizi belirlemek için aşağıdaki bağlantıyı açın:\n\n"
                . $resetLink . "\n\n"
                . "Bu bağlantı 1 saat geçerlidir ve yalnızca bir kez kullanılabilir.\n\n"
                . "Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz; şifreniz değişmeden kalır."
                . bcc_mail_text_footer();

            $safeName = htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8');

            $introHtml = '<p style="margin: 0 0 14px;">Merhaba <strong>' . $safeName . '</strong>,</p>'
                . '<p style="margin: 0 0 14px;">' . htmlspecialchars(bcc_brand_name(), ENT_QUOTES, 'UTF-8')
                . ' hesabınız için bir şifre sıfırlama talebi aldık. Yeni şifrenizi belirlemek için aşağıdaki butona tıklayın.</p>'
                . '<p style="margin: 0;">Bu bağlantı <strong>1 saat</strong> geçerlidir ve yalnızca <strong>bir kez</strong> kullanılabilir.</p>';

            $noteHtml = 'Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz — şifreniz değişmeden kalır.';

            $bodyHtml = bcc_mail_html_shell(
                'Şifrenizi sıfırlayın',
                $introHtml,
                'Yeni Şifremi Belirle',
                $resetLink,
                $noteHtml,
                'Şifre Sıfırlama',
                $resetLink
            );

            bcc_send_mail($email, bcc_brand_name() . ' şifre sıfırlama talebi', $bodyText, $bodyHtml);

            log_audit('user.password_reset_requested', 'user', $row['id']);
        }

        header('Location: /forgot-password.php?sent=1');
        exit;
    }
}
?>
<?php
$authPageTitle = 'Şifremi unuttum';
require __DIR__ . '/../src/partials/auth_shell_top.php';
?>
        <h1 class="login-title">Şifremi unuttum</h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($info !== null): ?>
            <p class="login-info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <form method="post" action="/forgot-password.php">
            <?php echo csrf_field(); ?>
            <div class="login-field">
                <label for="forgot-email">E-posta</label>
                <input type="email" id="forgot-email" name="email" value="<?php echo htmlspecialchars(isset($email) ? $email : '', ENT_QUOTES, 'UTF-8'); ?>" required autofocus>
            </div>
            <p class="login-tagline">Hesabınıza kayıtlı e-posta adresini girin; şifrenizi yeniden belirleyebileceğiniz, 1 saat geçerli bir bağlantı gönderelim.</p>
            <button type="submit" class="login-submit">Sıfırlama Bağlantısı Gönder</button>
        </form>

        <p class="login-register">
            <a href="/login.php">Giriş Sayfasına Dön</a>
        </p>
<?php require __DIR__ . '/../src/partials/auth_shell_bottom.php'; ?>
