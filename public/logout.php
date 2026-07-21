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
<title>BCC-Core — Çıkış</title>
<link rel="stylesheet" href="/assets/style.css">
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
