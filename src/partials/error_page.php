<?php

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
<div class="login-card error-card">
    <div class="login-logo">
        <?php $brandLogoClass = 'login-logo-mark'; $brandLogoHeight = 44; require __DIR__ . '/brand_logo.php'; ?>
    </div>
    <?php /* Hizalama ve bosluklar login.css'teki .error-* kurallarinda.
             Onceden satir ici style ile yapiliyordu; ayrica aciklama metni
             style.css'teki bir sinifa guveniyordu ama bu sayfa yalnizca
             theme.css + login.css yukluyor, yani o kural hic uygulanmiyordu. */ ?>
    <div class="login-card-body error-body">
        <h1 class="login-title error-title"><?php echo htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($errorMessage !== ''): ?>
            <p class="error-message"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <a class="login-submit error-action"
           href="<?php echo current_user() !== null ? '/dashboard.php' : '/login.php'; ?>">
            <?php echo current_user() !== null ? 'Ana sayfaya dön' : 'Giriş yap'; ?>
        </a>
        <p class="error-code">Hata kodu: <?php echo (int) $errorStatus; ?></p>
    </div>
</div>
</body>
</html>
