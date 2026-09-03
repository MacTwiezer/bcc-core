<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

define('BCC_REGISTER_RESEND_COOLDOWN', 120);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    if ($fullName === '' || $email === '') {
        $error = 'Tüm alanları doldurun.';
    } elseif (!bcc_is_valid_email($email)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif (mb_strlen($email, 'UTF-8') > 190) {
        $error = 'E-posta en fazla 190 karakter olabilir.';
    } elseif (mb_strlen($fullName, 'UTF-8') > 150) {
        $error = 'Ad Soyad en fazla 150 karakter olabilir.';
    } else {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);

        $skipVerificationMail = false;

        $existing = bcc_fetch_one(
            'SELECT id, is_active, email_verify_expires_at FROM users WHERE email = :email LIMIT 1',
            array('email' => $email)
        );

        if ($existing && (int) $existing['is_active'] === 1) {
            $error = 'Bu e-posta zaten kayıtlı.';
        } elseif ($existing) {
            if (!bcc_should_send_verification_mail($existing['email_verify_expires_at'], BCC_REGISTER_RESEND_COOLDOWN)) {
                $skipVerificationMail = true;
                bcc_execute(
                    'UPDATE users SET full_name = :full_name WHERE id = :id',
                    array('full_name' => $fullName, 'id' => $existing['id'])
                );
            } else {
                bcc_execute(
                    'UPDATE users SET full_name = :full_name, email_verify_token = :token, email_verify_expires_at = :expires WHERE id = :id',
                    array('full_name' => $fullName, 'token' => $token, 'expires' => $expiresAt, 'id' => $existing['id'])
                );
            }
        } else {
            $unusableHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            bcc_execute(
                'INSERT INTO users (email, password_hash, full_name, is_admin, is_active, email_verify_token, email_verify_expires_at)
                 VALUES (:email, :hash, :full_name, 0, 0, :token, :expires)',
                array('email' => $email, 'hash' => $unusableHash, 'full_name' => $fullName, 'token' => $token, 'expires' => $expiresAt)
            );
            $newId = bcc_last_insert_id();
            log_audit('user.register', 'user', $newId, array('email' => $email));
        }

        if ($error === null) {
            $verifyLink = bcc_app_base_url() . '/verify_email.php?token=' . $token;

            $bodyText = "Merhaba {$fullName},\n\n"
                . bcc_brand_name() . " hesabınızı etkinleştirmek ve şifrenizi oluşturmak için aşağıdaki bağlantıyı açın:\n\n"
                . $verifyLink . "\n\n"
                . "Bu bağlantı 24 saat geçerlidir."
                . bcc_mail_text_footer();

            $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');

            $introHtml = '<p style="margin: 0 0 14px;">Merhaba <strong>' . $safeName . '</strong>,</p>'
                . '<p style="margin: 0 0 14px;">' . htmlspecialchars(bcc_brand_name(), ENT_QUOTES, 'UTF-8')
                . ' hesabınız oluşturuldu. Hesabınızı etkinleştirmek ve şifrenizi belirlemek için aşağıdaki butona tıklayın.</p>'
                . '<p style="margin: 0;">Bu bağlantı <strong>24 saat</strong> geçerlidir.</p>';

            $noteHtml = null;

            $bodyHtml = bcc_mail_html_shell(
                'Hesabınızı etkinleştirin',
                $introHtml,
                'Hesabımı Etkinleştir',
                $verifyLink,
                $noteHtml,
                'Hesap Doğrulama',
                $verifyLink
            );

            if (!$skipVerificationMail) {
                bcc_send_mail($email, bcc_brand_name() . ' hesabınızı etkinleştirin', $bodyText, $bodyHtml);
            }

            header('Location: /login.php?registered=1');
            exit;
        }
    }
}
?>
<?php
$authPageTitle = 'Kayıt ol';
require __DIR__ . '/../src/partials/auth_shell_top.php';
?>
        <h1 class="login-title">Kayıt ol</h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php
              ?>
        <form method="post" action="/register.php" data-once-submit>
            <?php echo csrf_field(); ?>
            <div class="login-field">
                <label for="register-fullname">Ad Soyad</label>
                <input type="text" id="register-fullname" name="full_name" value="<?php echo htmlspecialchars(isset($fullName) ? $fullName : '', ENT_QUOTES, 'UTF-8'); ?>" required autofocus>
            </div>
            <div class="login-field">
                <label for="register-email">E-posta</label>
                <input type="email" id="register-email" name="email" value="<?php echo htmlspecialchars(isset($email) ? $email : '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <p class="login-tagline">Kayıt olduktan sonra e-postanıza gönderilecek bağlantıdan şifrenizi oluşturacaksınız.</p>
            <button type="submit" class="login-submit">Kayıt ol</button>
        </form>

        <p class="login-register">
            Zaten hesabın var mı? <a href="/login.php">Giriş yap</a>
        </p>

<?php
      ?>
<?php
      ?>
<script>
(function () {
    var form = document.querySelector('form[data-once-submit]');
    if (!form) {
        return;
    }
    var submitted = false;
    form.addEventListener('submit', function (e) {
        if (submitted) {
            e.preventDefault();
            return;
        }
        submitted = true;
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            window.setTimeout(function () {
                btn.disabled = true;
                btn.textContent = 'Gönderiliyor...';
            }, 0);
        }
    });
})();
</script>
<?php
require __DIR__ . '/../src/partials/auth_shell_bottom.php';

