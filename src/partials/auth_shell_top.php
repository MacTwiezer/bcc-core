<?php
// Oturum AÇMADAN erişilen sayfaların ortak kabuğu — ÜST yarısı.
//
// NEDEN VAR (denetimde bulundu): login, register, forgot-password,
// reset-password ve verify_email sayfalarının HEPSİ bu 14 satırı birebir
// kopyalıyordu; aralarındaki tek fark <title> metniydi. Bir stylesheet
// eklemek ya da sekme başlığı biçimini değiştirmek BEŞ dosyaya ayrı ayrı
// dokunmak demekti (sekme başlığı işi tam olarak böyle geçti).
//
// home_shell_top.php / home_shell_bottom.php ikilisiyle AYNI desen — oturumlu
// sayfalar bu sorunu zaten böyle çözmüştü, oturumsuz sayfalar geride kalmıştı.
//
// Beklenen değişkenler (çağıran sayfa ayarlar):
//   $authPageTitle - string, sekmede görünecek SAYFA ADI ("Giriş", "Kayıt ol").
//                    Marka ve ayırıcı BURADA eklenmez; tek kural
//                    bcc_tab_title() (bkz. src/schema.php).
//
// ⚠️ Kart gövdesi (.login-card-body) BURADA AÇILIR, auth_shell_bottom.php'de
// kapanır. Çağıran sayfa yalnızca gövdeyi yazar.

if (!isset($authPageTitle)) {
    $authPageTitle = '';
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars(bcc_tab_title($authPageTitle), ENT_QUOTES, 'UTF-8'); ?></title>
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
