<?php

$shareLinkLabel = isset($shareLinkLabel) && $shareLinkLabel !== '' ? $shareLinkLabel : 'Bağlantıyı paylaş';
?>
<div class="share-popover-form">
    <div class="share-popover-label"><?php echo htmlspecialchars($shareLinkLabel, ENT_QUOTES, 'UTF-8'); ?></div>
    <div class="share-popover-row">
        <input type="text" class="share-popover-input" data-share-url-input readonly value="<?php echo htmlspecialchars($shareLinkUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($shareLinkLabel, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="btn-sm" data-share-copy-btn>Kopyala</button>
    </div>
    <p class="share-popover-note">Bu bağlantı yalnızca oturum açmış takım üyeleri için çalışır.</p>
</div>
