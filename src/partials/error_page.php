<?php
// Yetki/bulunamadı hatalarının ortak sayfası.
//
// NEDEN VAR: bu hatalar 16 ayrı yerde `die('Bu tablo bu base'e ait değil.')`
// gibi ÇIPLAK METİN basıyordu — kullanıcı beyaz bir ekranda tek satır yazı
// görüyor, geri dönmek için tarayıcının geri düğmesini bulmak zorunda kalıyordu.
// Marka, tema (koyu/açık) ve dönüş bağlantısı yoktu.
//
// auth_shell_top.php ile AYNI gerekçe: aynı 14 satırı her sayfaya kopyalamak
// yerine tek partial. Buraya doğrudan require edilmez — bcc_error_page()
// (src/errors.php) üzerinden çağrılır, o fonksiyon HTTP durum kodunu da yazar.
//
// Beklenen değişkenler:
//   $errorTitle   - başlık ("Erişim yetkiniz yok")
//   $errorMessage - açıklama; kullanıcıya gösterilecek metin
//   $errorStatus  - HTTP durum kodu (yalnızca gösterim için)

if (!isset($errorTitle)) { $errorTitle = 'Bir şeyler ters gitti'; }
if (!isset($errorMessage)) { $errorMessage = ''; }
if (!isset($errorStatus)) { $errorStatus = 500; }
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars(bcc_tab_title($errorTitle), ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo bcc_asset_url('favicon.svg'); ?>">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('login.css'); ?>">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-logo">
        <?php $brandLogoClass = 'login-logo-mark'; $brandLogoHeight = 44; require __DIR__ . '/brand_logo.php'; ?>
    </div>
    <div class="login-card-body">
        <h1 class="login-title"><?php echo htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($errorMessage !== ''): ?>
            <p class="hint" style="text-align:center;"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <p style="text-align:center; margin-top:1.2rem;">
            <?php // Oturumu olan kullanıcı ana sayfaya, olmayan girişe döner —
                  // "yetkiniz yok" görüp giriş ekranına atılmak kafa karıştırıcı olurdu. ?>
            <?php // .login-submit: bu sayfa login.css yükler, giriş düğmesiyle AYNI
                  // görünüm. Genel .btn burada TANIMSIZ olurdu (style.css yüklü değil). ?>
            <a class="login-submit" style="display:inline-block; text-decoration:none;"
               href="<?php echo current_user() !== null ? '/dashboard.php' : '/login.php'; ?>">
                <?php echo current_user() !== null ? 'Ana sayfaya dön' : 'Giriş yap'; ?>
            </a>
        </p>
        <p class="hint" style="text-align:center; margin-top:0.8rem; opacity:0.6;">
            Hata kodu: <?php echo (int) $errorStatus; ?>
        </p>
    </div>
</div>
</body>
</html>
