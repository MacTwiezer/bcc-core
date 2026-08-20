<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

// Doğrulanmamış bir hesap için etkinleştirme mailinin yeniden gönderilebilmesi
// arasındaki en kısa süre (saniye). Çift tıklama / "geri"ye basıp tekrar
// gönderme / iki sekme gibi durumlarda AYNI adrese ikinci bir mail çıkmasını
// engeller; gerçekten maili kaçıran kullanıcı 2 dakika sonra tekrar deneyebilir.
define('BCC_REGISTER_RESEND_COOLDOWN', 120);

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

        // Aynı adrese arka arkaya etkinleştirme maili atılmasını engelleyen
        // bekleme süresi (saniye). Bir "yeniden gönder" isteğini tamamen
        // yasaklamayacak kadar kısa, çift gönderimi/çift tıklamayı yakalayacak
        // kadar uzun.
        $skipVerificationMail = false;

        // email_verify_expires_at: aşağıdaki mükerrer gönderim kapısı bunu
        // okuyor (veriliş anını buradan türetiyor) — SELECT'e eklendi.
        $existing = bcc_fetch_one(
            'SELECT id, is_active, email_verify_expires_at FROM users WHERE email = :email LIMIT 1',
            array('email' => $email)
        );

        if ($existing && (int) $existing['is_active'] === 1) {
            // Zaten doğrulanmış/aktif bir hesap — normal "zaten kayıtlı" reddi.
            $error = 'Bu e-posta zaten kayıtlı.';
        } elseif ($existing) {
            // Daha önce kayıt olmuş ama hiç doğrulamamış (mailini kaçırmış/linki
            // kaybetmiş olabilir) — yeni hesap açmak yerine token'ı yenileyip
            // e-postayı tekrar gönderiyoruz. Sessizce aynı "kaydınız alındı"
            // akışına düşer, bir saldırgana "bu e-posta zaten var" bilgisini
            // aktif/pasif ayrımı dışında sızdırmaz.
            //
            // MÜKERRER GÖNDERİM KAPISI (asıl koruma BURASI, istemci tarafı
            // yalnızca UX): bu dal her POST'ta token'ı yenileyip YENİ bir mail
            // atıyordu. Formu iki kez göndermek (çift tıklama, "geri" tuşuyla
            // dönüp tekrar gönderme, iki sekme) alıcıya İKİ ayrı etkinleştirme
            // maili demekti. Artık son token'ın üzerinden BCC_REGISTER_RESEND_COOLDOWN
            // saniye geçmediyse yeni mail GÖNDERİLMEZ.
            //
            // Neden ayrı bir "gönderildi" kolonu YOK: token'ın veriliş anı
            // email_verify_expires_at'ten türetilebiliyor (veriliş = son
            // kullanma - 86400). Bu iş için DDL/migration eklemek gerekmedi —
            // projedeki "kolon icat etmeden türet" kararıyla aynı çizgi.
            // Karar saf bir fonksiyonda (src/mailer.php) — burada yalnızca
            // sonucuna göre dallanıyoruz.
            if (!bcc_should_send_verification_mail($existing['email_verify_expires_at'], BCC_REGISTER_RESEND_COOLDOWN)) {
                // Yakın zamanda zaten gönderilmiş: token'a ve son kullanma
                // tarihine DOKUNULMAZ (aksi hâlde kullanıcının elindeki
                // linki geçersiz kılardık) ve mail tekrar atılmaz.
                // Kullanıcı yine aynı başarı ekranını görür — "gönderildi mi,
                // gönderilmedi mi" belirsizliği yaratmamak için.
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
            // Marka adı bcc_brand_name()'den (config/app.php) — eskiden burada
            // ALAN ADI literal yazılıydı ("opsflow.bcccrm.com"). Kullanıcıya
            // gösterilecek olan ÜRÜN ADIDIR; alan adı hem teknik hem de posta
            // istemcilerinde otomatik bağlantıya dönüşüp yanlış yere
            // götürüyordu (bkz. telif satırındaki aynı sorun).
            $bodyText = "Merhaba {$fullName},\n\n"
                . bcc_brand_name() . " hesabınızı etkinleştirmek ve şifrenizi oluşturmak için aşağıdaki bağlantıyı açın:\n\n"
                . $verifyLink . "\n\n"
                . "Bu bağlantı 24 saat geçerlidir."
                . bcc_mail_text_footer();

            // $verifyLink'in kaçırılmış hâli ARTIK burada gerekmiyor: şablon
            // hem butonda hem kopyalama kutusunda kendisi kaçırıyor.
            $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');

            $introHtml = '<p style="margin: 0 0 14px;">Merhaba <strong>' . $safeName . '</strong>,</p>'
                . '<p style="margin: 0 0 14px;">' . htmlspecialchars(bcc_brand_name(), ENT_QUOTES, 'UTF-8')
                . ' hesabınız oluşturuldu. Hesabınızı etkinleştirmek ve şifrenizi belirlemek için aşağıdaki butona tıklayın.</p>'
                . '<p style="margin: 0;">Bu bağlantı <strong>24 saat</strong> geçerlidir.</p>';

            // Not satırı KALDIRILDI (ürün kararı): "Bu kaydı siz yapmadıysanız
            // bu e-postayı yok sayabilirsiniz." cümlesi çıkarıldı. null
            // geçilince bcc_mail_html_shell() o satırı hiç basmaz.
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

            // Konu sade ve net: eski "opsflow.bcccrm.com — e-postanızı doğrulayın"daki
            // uzun tire ve ürün öneki spam filtrelerinde gereksiz gürültüydü.
            //
            // TEK ÇAĞRI, TEK GÖNDERİM: $skipVerificationMail yalnızca yukarıdaki
            // "az önce zaten gönderildi" dalında true olur. Ardından gelen
            // header()+exit (Post/Redirect/Get) tarayıcı yenilemesinin formu
            // yeniden göndermesini de engelliyor — iki koruma birbirini
            // tamamlıyor, birbirinin yerine geçmiyor.
            if (!$skipVerificationMail) {
                bcc_send_mail($email, bcc_brand_name() . ' hesabınızı etkinleştirin', $bodyText, $bodyHtml);
            }

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
<title><?php echo htmlspecialchars(bcc_brand_name() . ' — Kayıt ol', ENT_QUOTES, 'UTF-8'); ?></title>
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
        <h1 class="login-title">Kayıt ol</h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php // data-once-submit: gönder düğmesini ilk tıklamada kilitler,
              // yani ikinci bir POST hiç yola çıkmaz. Bu YALNIZCA UX katmanı —
              // JS kapalıysa ya da istek elle tekrarlanırsa asıl korumayı
              // sunucudaki BCC_REGISTER_RESEND_COOLDOWN kapısı sağlıyor. ?>
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

        <div class="login-legal">
            <?php // login.php'deki AYNI satır bcc_brand_full() kullanıyordu, burası
                  // elle yazılmış literal marka taşıyordu — ad değişince ikisi
                  // ayrışırdı. Tek kaynağa bağlandı (config/app.php). ?>
            <p class="login-tagline"><?php echo htmlspecialchars(bcc_brand_full(), ENT_QUOTES, 'UTF-8'); ?> — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
    </div>
</div>
<?php // Çift gönderim kilidi: form bir kez gönderildikten sonra düğme devre
      // dışı kalır ve ikinci submit iptal edilir. Sunucudaki bekleme kapısının
      // YERİNE değil, ÖNÜNE konan bir katman — ikisi de gerekli (bkz.
      // BCC_REGISTER_RESEND_COOLDOWN yorumu). ?>
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
            // disabled ATTRIBUTE'u submit'ten SONRA konmalı; hemen konursa
            // tarayıcı düğmeyi göndermez (burada gövdeye dahil bir alan değil,
            // yine de davranışı bir sonraki tura bırakmak en güvenlisi).
            window.setTimeout(function () {
                btn.disabled = true;
                btn.textContent = 'Gönderiliyor...';
            }, 0);
        }
    });
})();
</script>
</body>
</html>
