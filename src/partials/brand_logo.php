<?php

$brandLogoClass = isset($brandLogoClass) ? $brandLogoClass : '';
$brandLogoHeight = isset($brandLogoHeight) ? (int) $brandLogoHeight : 32;
?>
<img
    src="<?php echo bcc_asset_url('logo.png'); ?>"
    alt="<?php echo htmlspecialchars(bcc_brand_name(), ENT_QUOTES, 'UTF-8'); ?>"
    height="<?php echo $brandLogoHeight; ?>"
    width="<?php echo (int) round($brandLogoHeight * 94 / 44); ?>"
    class="brand-logo <?php echo htmlspecialchars($brandLogoClass, ENT_QUOTES, 'UTF-8'); ?>"
>
