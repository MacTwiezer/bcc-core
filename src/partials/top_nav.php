<?php
// Ortak üst nav bar: giriş yapılmış sayfalarda görünen siyah üst bar.
$navUser = current_user();
?>
<nav class="topnav">
    <a href="/dashboard.php">BCC-Core</a>
    <?php if ($navUser): ?>
        <a href="/bases.php">Base'ler</a>
        <?php if (is_platform_admin()): ?>
            <a href="/admin/index.php">Admin</a>
        <?php endif; ?>
        <span class="navuser">
            <span class="navuser-avatar"><?php echo htmlspecialchars(bcc_user_initial($navUser), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php echo htmlspecialchars($navUser['full_name'], ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <form method="post" action="/logout.php" class="navlogout">
            <?php echo csrf_field(); ?>
            <button type="submit">Çıkış</button>
        </form>
    <?php endif; ?>
</nav>
