<?php

if (!isset($authShowLegal)) {
    $authShowLegal = true;
}
if (!isset($authScripts) || !is_array($authScripts)) {
    $authScripts = array();
}
?>
<?php if ($authShowLegal): ?>
        <div class="login-legal">
            <p class="login-tagline"><?php echo htmlspecialchars(bcc_brand_full(), ENT_QUOTES, 'UTF-8'); ?> — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
<?php endif; ?>
    </div>
</div>
<?php foreach ($authScripts as $authScript): ?>
<script src="<?php echo bcc_asset_url($authScript); ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
