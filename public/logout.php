<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    log_audit('user.logout');
    logout_user();
    header('Location: /login.php');
    exit;
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars(bcc_brand_domain() . ' — Çıkış', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="<?php echo bcc_asset_url('style.css'); ?>">
</head>
<body>
<div class="page">
    <form class="stacked" method="post" action="/logout.php">
        <?php echo csrf_field(); ?>
        <p>Çıkış yapmak istediğinize emin misiniz?</p>
        <button type="submit">Çıkış yap</button>
    </form>
</div>
</body>
</html>
