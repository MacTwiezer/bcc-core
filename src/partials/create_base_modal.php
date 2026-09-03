<?php

$createBaseSelected = isset($createBaseSelectedTeamId) ? (int) $createBaseSelectedTeamId : 0;
?>
<div class="home-modal-backdrop" id="home-create-base-modal" hidden>
    <div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="home-create-base-title">
        <div class="home-modal-head">
            <h2 id="home-create-base-title">Yeni Base Oluştur</h2>
            <button type="button" class="home-modal-close" id="home-create-base-close" aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <form class="home-modal-form" id="home-create-base-form" method="post" action="/bases.php">
            <?php echo csrf_field(); ?>

            <label class="home-modal-field">
                <span class="home-modal-label">Çalışma alanı</span>
                                <?php if (count($creatableTeams) === 1): ?>
                    <input type="hidden" name="team_id" value="<?php echo (int) $creatableTeams[0]['id']; ?>">
                    <span class="home-modal-static"><?php echo htmlspecialchars($creatableTeams[0]['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php else: ?>
                    <select name="team_id" class="home-modal-input" required>
                        <?php foreach ($creatableTeams as $ct): ?>
                            <option
                                value="<?php echo (int) $ct['id']; ?>"
                                <?php echo ((int) $ct['id'] === $createBaseSelected) ? ' selected' : ''; ?>
                            ><?php echo htmlspecialchars($ct['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </label>

            <label class="home-modal-field">
                <span class="home-modal-label">Base adı</span>
                <input type="text" name="name" class="home-modal-input" maxlength="150" required autocomplete="off" placeholder="Örn. Satış CRM">
            </label>

            <label class="home-modal-field">
                <span class="home-modal-label">Açıklama <span class="home-modal-optional">(opsiyonel)</span></span>
                <input type="text" name="description" class="home-modal-input" maxlength="500" autocomplete="off">
            </label>

                        <div class="home-modal-field">
                <span class="home-modal-label">İkon <span class="home-modal-optional">(opsiyonel)</span></span>
                <div class="home-icon-pick" role="group" aria-label="Base ikonu">
                    <?php foreach ($GLOBALS['BCC_BASE_ICON_LABELS'] as $iconKey => $iconLabel): ?>
                        <label class="home-icon-pick-item" title="<?php echo htmlspecialchars($iconLabel, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="radio" name="icon" value="<?php echo htmlspecialchars($iconKey, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($iconLabel, ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="home-icon-pick-box"><?php echo bcc_base_icon_svg(18, null, $iconKey); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="home-modal-field">
                <span class="home-modal-label">Renk <span class="home-modal-optional">(opsiyonel)</span></span>
                <div class="home-color-pick" role="group" aria-label="Base rengi">
                    <?php foreach ($GLOBALS['BCC_BASE_ICON_THEMES'] as $themeIndex => $theme): ?>
                                                <label class="home-color-pick-item">
                            <input type="radio" name="icon_color" value="<?php echo (int) $themeIndex; ?>" aria-label="Renk <?php echo (int) $themeIndex + 1; ?>">
                            <span class="home-color-pick-dot" style="--pick-solid: <?php echo htmlspecialchars($theme['solid'], ENT_QUOTES, 'UTF-8'); ?>;"></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <p class="home-modal-error" id="home-create-base-error" hidden></p>

            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" id="home-create-base-cancel">Vazgeç</button>
                <button type="submit" class="home-modal-btn home-modal-btn-primary">Oluştur</button>
            </div>
        </form>
    </div>
</div>
