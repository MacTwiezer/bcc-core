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
            // Bağlantının tabanı ARTIK $_SERVER['HTTP_HOST']'tan kurulmuyor:
            // bcc_app_base_url() önce config/app.local.php'deki $APP_BASE_URL'e
            // bakar, yoksa eski davranışa (istek host'u) düşer. Gerekçe
            // config/app.php'de: localhost'ta çalışan uygulamada mail'e giden
            // link ALICI İÇİN ERİŞİLEMEZ oluyordu, ayrıca HTTP_HOST istemciden
            // gelen bir başlık olduğu için e-postaya gömmek host-header
            // enjeksiyonuna açık kapı bırakıyordu.
            $verifyLink = bcc_app_base_url() . '/verify_email.php?token=' . $token;

            // Düz metin parçası ELLE yazılıyor (HTML'den strip_tags ile
            // türetilmiyor) — multipart'ın text/plain tarafı da okunaklı olsun.
            // Sadece-HTML mail spam puanını yükseltiyor, bu yüzden ikisi de var.
            $bodyText = "Merhaba {$fullName},\n\n"
                . "BCC-Core hesabınızı etkinleştirmek ve şifrenizi oluşturmak için aşağıdaki bağlantıyı açın:\n\n"
                . $verifyLink . "\n\n"
                . "Bu bağlantı 24 saat geçerlidir.\n\n"
                . "Bu kaydı siz yapmadıysanız bu e-postayı yok sayabilirsiniz."
                . bcc_mail_text_footer();

            $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');

            $introHtml = '<p style="margin: 0 0 14px;">Merhaba <strong>' . $safeName . '</strong>,</p>'
                . '<p style="margin: 0 0 14px;">BCC-Core hesabınız oluşturuldu. Hesabınızı etkinleştirmek ve şifrenizi belirlemek için aşağıdaki butona tıklayın.</p>'
                . '<p style="margin: 0;">Bu bağlantı <strong>24 saat</strong> geçerlidir.</p>';

            // Buton çalışmazsa (bazı istemciler <a>'yı düz metne çevirir) diye
            // altında ham bağlantı da duruyor — word-break, uzun token'ın
            // kutuyu yatay taşırmaması için.
            $noteHtml = '<p style="margin: 12px 0 0;">Buton çalışmazsa bu adresi tarayıcınıza yapıştırın:<br>'
                . '<a href="' . $safeLink . '" style="color: #2d7ff9; word-break: break-all;">' . $safeLink . '</a></p>'
                . '<p style="margin: 12px 0 0;">Bu kaydı siz yapmadıysanız bu e-postayı yok sayabilirsiniz.</p>';

            $bodyHtml = bcc_mail_html_shell(
                'Hesabınızı etkinleştirin',
                $introHtml,
                'Hesabımı Etkinleştir',
                $verifyLink,
                $noteHtml
            );

            // Konu sade ve net: eski "BCC-Core — e-postanızı doğrulayın"daki
            // uzun tire ve ürün öneki spam filtrelerinde gereksiz gürültüydü.
            bcc_send_mail($email, 'BCC-Core hesabınızı etkinleştirin', $bodyText, $bodyHtml);

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
