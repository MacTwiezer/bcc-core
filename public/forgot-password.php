<?php
// Şifremi unuttum — 1. ekran: kullanıcı e-postasını girer, sistem tek
// kullanımlık bir sıfırlama bağlantısı gönderir. Şifre BURADA değişmez;
// asıl değişiklik /reset-password.php'de olur (register.php -> verify_email.php
// ikilisiyle AYNI desen).

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

// --- Ayarlar ---------------------------------------------------------------

// Sıfırlama bağlantısının ömrü (saniye). Kayıt doğrulamasındaki 24 saatten
// KASITLI OLARAK kısa: o link "hesabını kur" davetidir. Bu link ise ÇALIŞAN bir
// hesabın şifresini değiştirme yetkisi taşır — e-posta kutusu bir süreliğine
// başkasının eline geçerse zarar penceresi 1 saatle sınırlı kalsın.
define('BCC_PASSWORD_RESET_TTL', 3600);

// Aynı ADRESE arka arkaya mail atılmasını engelleyen bekleme (saniye).
// register.php'deki BCC_REGISTER_RESEND_COOLDOWN ile aynı fikir.
define('BCC_PASSWORD_RESET_COOLDOWN', 120);

// Aynı IP'den bir pencerede kabul edilecek en fazla talep ve pencerenin
// uzunluğu (saniye). Adres cooldown'ı "tek kurbana çok mail"i keser; bu sınır
// "tek kaynaktan çok adrese"yi keser — ikisi farklı saldırıya bakar.
define('BCC_PASSWORD_RESET_IP_WINDOW', 3600);
define('BCC_PASSWORD_RESET_IP_MAX', 5);

// --- IP hız sınırı yardımcıları --------------------------------------------
// Tek çağıran bu dosya olduğu için src/ altına taşınmadı (projedeki ortak
// yardımcılar ikinci bir çağıran çıkınca oluşturulmuş — bkz. src/validation.php).
// reset-password.php bunları KULLANMAZ: orada kaba kuvvetle kırılacak şey 256
// bitlik bir token, hız sınırı gerçek bir riski azaltmaz.

function bcc_reset_client_ip()
{
    // $_SERVER['REMOTE_ADDR'] TCP bağlantısının karşı ucudur — istemci bunu
    // taklit EDEMEZ. X-Forwarded-For ise istemcinin yazdığı düz bir metindir;
    // ona güvenmek saldırgana her istekte kendi IP'sini değiştirme (yani bu
    // sınırı tamamen atlama) imkânı verirdi. Bu yüzden BİLEREK sadece REMOTE_ADDR.
    //
    // DİKKAT: uygulama bir ters proxy (Cloudflare/nginx) arkasına alınırsa bu
    // değer HERKES için proxy'nin IP'si olur ve kota tüm kullanıcıları birlikte
    // kilitler. O kuruluma geçilirse burası güvenilen-proxy listesiyle
    // genişletilmeli.
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

function bcc_reset_ip_quota_exceeded($ip)
{
    // IP okunamıyorsa (CLI, tuhaf sunucu yapılandırması) kotayı UYGULAMA.
    // Kimseyi kilitlememek, tanımlanamayan bir isteği engellemekten iyidir;
    // adres bazlı cooldown ikinci katman olarak zaten çalışıyor.
    if ($ip === '') {
        return false;
    }

    $since = date('Y-m-d H:i:s', time() - BCC_PASSWORD_RESET_IP_WINDOW);

    // Pencere dışındaki satırlar SİLİNİYOR: tablo süresiz büyümüyor ve eski IP
    // kayıtları kendiliğinden yok oluyor (KVKK — IP kişisel veridir, burada
    // kalıcı bir ziyaretçi günlüğü değil birkaç saatlik bir sayaç tutuluyor).
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

    // attempted_at NOT NULL ve DEFAULT'u YOK (bkz. migrations/016) — değeri
    // açıkça vermek zorundayız. Zaman PHP'den geliyor, pencere hesabı da
    // (yukarıdaki $since) PHP'den: iki taraf aynı saati kullansın.
    bcc_execute(
        'INSERT INTO password_reset_attempts (ip_address, attempted_at) VALUES (:ip, :now)',
        array('ip' => $ip, 'now' => date('Y-m-d H:i:s'))
    );
}

// --- Sayfa akışı -----------------------------------------------------------

$error = null;
$info = null;

// Post/Redirect/Get: başarı mesajı POST'un kendisinden DEĞİL, yönlendirme
// sonrası ?sent=1'den geliyor. Kullanıcı sayfayı yenilediğinde tarayıcı
// "formu yeniden gönder?" demez ve ikinci bir mail çıkmaz.
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
        // Bu mesaj ADRES hakkında hiçbir şey söylemiyor, İSTEK KAYNAĞI hakkında
        // konuşuyor — dolayısıyla aşağıdaki kullanıcı-sızdırma korumasını delmiyor.
        $error = 'Çok fazla şifre sıfırlama talebi gönderildi. Lütfen bir saat sonra tekrar deneyin.';
    } else {
        // Kota kontrolünden GEÇEN her istek sayılır. Kotası dolmuş istekler
        // kaydedilmez -> tablo IP başına en fazla BCC_PASSWORD_RESET_IP_MAX
        // satırda kalır, saldırı altında bile şişmez.
        bcc_reset_record_attempt($ip);

        $row = bcc_fetch_one(
            'SELECT id, full_name, is_active, password_reset_expires_at FROM users WHERE email = :email LIMIT 1',
            array('email' => $email)
        );

        // ÜÇ koşulun HEPSİ sağlanmazsa hiçbir şey yapılmaz — ama kullanıcı yine
        // aşağıdaki AYNI yönlendirmeye düşer:
        //   1. Böyle bir kullanıcı var mı?
        //   2. Hesap AKTİF mi? (is_active=0 olan, doğrulanmamış bir hesaba
        //      sıfırlama linki göndermek, kayıt doğrulama akışını atlatan ikinci
        //      bir kapı açardı — o kullanıcı /register.php'den yeniden istemeli.)
        //   3. Aynı adrese yakın zamanda zaten gönderilmiş mi?
        if ($row
            && (int) $row['is_active'] === 1
            && bcc_should_send_verification_mail($row['password_reset_expires_at'], BCC_PASSWORD_RESET_COOLDOWN, null, BCC_PASSWORD_RESET_TTL)
        ) {
            // 32 bayt = 256 bit entropi, hex'e çevrilince 64 karakter.
            // random_bytes() KRİPTOGRAFİK kaynaktır; rand()/mt_rand()/uniqid()
            // tahmin edilebilir ve bu iş için ASLA kullanılmaz.
            $rawToken = bin2hex(random_bytes(32));

            // ==== TOKEN HASH'LEME ====
            // $rawToken: kullanıcıya (maile) giden HAM sır — asla DB'ye yazılmaz.
            // $tokenHash: DB'ye yazılan tek şey. Veritabanı sızarsa saldırganın
            // eline yalnızca özetler geçer, onlardan çalışan link üretilemez.
            //
            // KOLON ADI NOTU: kolon `password_reset_token` (bkz. migrations/016)
            // ama İÇİNDE HAM TOKEN DEĞİL, onun SHA-256 ÖZETİ durur. Adın içeriği
            // tam yansıtmadığının farkındayız; kolonu `password_reset_token_hash`
            // olarak yeniden adlandırmak istersen ALTER için README'deki nota bak.
            //
            // Neden password_hash() değil de sha256: password_hash() her çağrıda
            // rastgele tuz üretir -> aynı token her seferinde farklı çıktı verir
            // -> WHERE ile ARANAMAZ. Ayrıca onun yavaşlığı DÜŞÜK entropili insan
            // şifrelerini korumak içindir; 256 bit CSPRNG çıktısında kaba kuvvet
            // zaten imkânsız.
            $tokenHash = hash('sha256', $rawToken);

            $expiresAt = date('Y-m-d H:i:s', time() + BCC_PASSWORD_RESET_TTL);

            // Yeni token ESKİSİNİ EZER: iki kez talep edilirse yalnızca son gelen
            // mail çalışır, önceki link ölür. İstenen davranış bu.
            bcc_execute(
                'UPDATE users SET password_reset_token = :hash, password_reset_expires_at = :expires WHERE id = :id',
                array('hash' => $tokenHash, 'expires' => $expiresAt, 'id' => $row['id'])
            );

            // Linke HAM token gider (özet DEĞİL) — doğrulama, gelen ham token'ın
            // özeti alınıp DB'dekiyle karşılaştırılarak yapılır.
            //
            // Taban adres bcc_app_base_url()'den (config/app.php:92) geliyor;
            // bcc_brand_domain() DEĞİL: o yalnızca "opsflow.bcccrm.com" döndürür,
            // şema (https://) içermez ve ortaya tıklanamayan bir bağlantı çıkar.
            // HTTP_HOST da kullanılmıyor: istemciden gelen bir başlık olduğu için
            // e-postaya gömmek host-header enjeksiyonuna kapı açar.
            $resetLink = bcc_app_base_url() . '/reset-password.php?token=' . $rawToken;

            // Düz metin parçası ELLE yazılıyor (register.php:125 ile aynı
            // gerekçe): sadece-HTML mail spam puanını yükseltir.
            $bodyText = "Merhaba {$row['full_name']},\n\n"
                . "opsflow.bcccrm.com hesabınız için şifre sıfırlama talebi aldık. Yeni şifrenizi belirlemek için aşağıdaki bağlantıyı açın:\n\n"
                . $resetLink . "\n\n"
                . "Bu bağlantı 1 saat geçerlidir ve yalnızca bir kez kullanılabilir.\n\n"
                . "Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz; şifreniz değişmeden kalır."
                . bcc_mail_text_footer();

            // bcc_mail_html_shell'in $introHtml parametresi GÜVENLİ HTML bekler
            // (src/mail_template.php:163) — değişken içeriği çağıran kaçırmalı.
            $safeName = htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8');

            $introHtml = '<p style="margin: 0 0 14px;">Merhaba <strong>' . $safeName . '</strong>,</p>'
                . '<p style="margin: 0 0 14px;">opsflow.bcccrm.com hesabınız için bir şifre sıfırlama talebi aldık. Yeni şifrenizi belirlemek için aşağıdaki butona tıklayın.</p>'
                . '<p style="margin: 0;">Bu bağlantı <strong>1 saat</strong> geçerlidir ve yalnızca <strong>bir kez</strong> kullanılabilir.</p>';

            $noteHtml = 'Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz — şifreniz değişmeden kalır.';

            // Parametreler sırasıyla: başlık, gövde, buton metni, buton hedefi,
            // alt not, rozet, ham bağlantı kutusu. Sonuncusu, butonu düz metne
            // çeviren istemciler için adresi kopyalanabilir bir kutuda gösterir.
            $bodyHtml = bcc_mail_html_shell(
                'Şifrenizi sıfırlayın',
                $introHtml,
                'Yeni Şifremi Belirle',
                $resetLink,
                $noteHtml,
                'Şifre Sıfırlama',
                $resetLink
            );

            // Projenin TEK mail fonksiyonu (src/mailer.php:164). $MAIL_MODE='log'
            // iken gerçekten göndermez, storage/mail/ altına .txt + .html yazar.
            bcc_send_mail($email, 'opsflow.bcccrm.com şifre sıfırlama talebi', $bodyText, $bodyHtml);

            // Oturum AÇIK OLMADIĞI için user_id NULL kalır (audit_log.user_id
            // nullable, schema.sql:354); hedef kullanıcı entity_id'de taşınıyor.
            // Token, token özeti ve e-posta ASLA loglanmaz.
            log_audit('user.password_reset_requested', 'user', $row['id']);
        }

        header('Location: /forgot-password.php?sent=1');
        exit;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars(bcc_brand_domain() . ' — Şifremi unuttum', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('login.css'); ?>">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-logo">
        <?php $brandLogoClass = 'login-logo-mark'; $brandLogoHeight = 34; require __DIR__ . '/../src/partials/brand_logo.php'; ?>
    </div>
    <div class="login-card-body">
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

        <div class="login-legal">
            <p class="login-tagline"><?php echo htmlspecialchars(bcc_brand_full(), ENT_QUOTES, 'UTF-8'); ?> — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
    </div>
</div>
</body>
</html>
