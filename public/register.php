<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

// Kayıt akışı artık şifreyi burada ALMAZ: kullanıcı Ad Soyad + E-posta girer,
// e-postasına gelen tek kullanımlık bağlantıdan (/verify_email.php) kendi
// şifresini oluşturur. Hesap o ana kadar is_active=0 kalır (giriş yapılamaz)
// — password_hash kolonu NOT NULL olduğu için, hiç bilinmeyen/tahmin edilemeyen
// rastgele bir değerle dolduruluyor (bkz. aşağı), gerçek şifre yalnızca
// doğrulama linkinden ayarlanabiliyor.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    if ($fullName === '' || $email === '') {
        $error = 'Tüm alanları doldurun.';
    } elseif (!bcc_is_valid_email($email)) {
        $error = 'Geçersiz e-posta adresi.';
    } elseif (mb_strlen($email, 'UTF-8') > 190) {
        // users.email VARCHAR(190) — bu kontrol olmadan uzun bir e-posta hatasız
        // sessizce kırpılıyordu (sql_mode'da STRICT_TRANS_TABLES yok). admin/create_user.php/
        // account_update_email.php ile AYNI sınır/mesaj — bu dosya (kendi kendine kayıt
        // formu) atlanmıştı.
        $error = 'E-posta en fazla 190 karakter olabilir.';
    } elseif (mb_strlen($fullName, 'UTF-8') > 150) {
        // users.full_name VARCHAR(150) — admin/create_user.php/account_update_name.php
        // ile AYNI sınır/mesaj.
        $error = 'Ad Soyad en fazla 150 karakter olabilir.';
    } else {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 saat

        $existing = bcc_fetch_one('SELECT id, is_active FROM users WHERE email = :email LIMIT 1', array('email' => $email));

        if ($existing && (int) $existing['is_active'] === 1) {
            // Zaten doğrulanmış/aktif bir hesap — normal "zaten kayıtlı" reddi.
            $error = 'Bu e-posta zaten kayıtlı.';
        } elseif ($existing) {
            // Daha önce kayıt olmuş ama hiç doğrulamamış (mailini kaçırmış/linki
            // kaybetmiş olabilir) — yeni hesap açmak yerine token'ı yenileyip
            // e-postayı tekrar gönderiyoruz. Sessizce aynı "kaydınız alındı"
            // akışına düşer, bir saldırgana "bu e-posta zaten var" bilgisini
            // aktif/pasif ayrımı dışında sızdırmaz.
            bcc_execute(
                'UPDATE users SET full_name = :full_name, email_verify_token = :token, email_verify_expires_at = :expires WHERE id = :id',
                array('full_name' => $fullName, 'token' => $token, 'expires' => $expiresAt, 'id' => $existing['id'])
            );
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
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $verifyLink = $scheme . '://' . $host . '/verify_email.php?token=' . $token;

            $body = "Merhaba {$fullName},\n\n"
                . "BCC-Core hesabınızı doğrulamak ve şifrenizi oluşturmak için aşağıdaki bağlantıya tıklayın:\n\n"
                . $verifyLink . "\n\n"
                . "Bu bağlantı 24 saat geçerlidir.\n\n"
                . "Bu kaydı siz yapmadıysanız bu e-postayı yok sayabilirsiniz.";

            bcc_send_mail($email, 'BCC-Core — e-postanızı doğrulayın', $body);

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
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('login.css'); ?>">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-logo">
        <img src="/assets/logo.png" alt="BCC-Core">
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
            <p class="login-tagline">Kayıt olduktan sonra e-postanıza gönderilecek bağlantıdan şifrenizi oluşturacaksınız.</p>
            <button type="submit" class="login-submit">Kayıt ol</button>
        </form>

        <p class="login-register">
            Zaten hesabın var mı? <a href="/login.php">Giriş yap</a>
        </p>

        <div class="login-legal">
            <p class="login-tagline">BCC-Core — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
    </div>
</div>
</body>
</html>
