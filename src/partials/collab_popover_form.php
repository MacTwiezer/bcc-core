<div class="collab-popover-form">
    <div class="collab-popover-title">"<?php echo htmlspecialchars($collabPopoverTitle, ENT_QUOTES, 'UTF-8'); ?>" paylaş</div>

    <?php if ($canManageMembers): ?>
                <button type="button" class="collab-popover-add-btn" data-share-modal-open>Katılımcı ekle</button>
    <?php else: ?>
                <p class="collab-popover-note">Katılımcı eklemek için Owner yetkisi gerekir.</p>
    <?php endif; ?>

        <button type="button" class="collab-popover-people" data-share-modal-open>
        <div class="collab-popover-avatars">
                        <?php foreach ($shareCollaboratorPreview as $c): ?>
                <div class="ws-collab-avatar collab-popover-avatar" title="<?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c['initial'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
                <span class="collab-popover-people-label" data-share-people-label>
            <?php echo count($shareCollaborators); ?> kişinin erişimi var<?php echo $shareCollaboratorExtraCount > 0 ? ' (+' . (int) $shareCollaboratorExtraCount . ')' : ''; ?>
        </span>
    </button>
</div>
