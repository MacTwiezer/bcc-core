<div class="home-modal-backdrop" id="assign-team-modal" hidden>
    <div class="home-modal assign-team-modal" role="dialog" aria-modal="true" aria-labelledby="assign-team-title">
        <div class="home-modal-head">
            <h2 id="assign-team-title">Kullanıcıyı Ekibe Ata</h2>
            <button type="button" class="home-modal-close" data-assign-team-close aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <form class="home-modal-form" id="assign-team-form" method="post" action="/api/admin_team_member_assign.php">
            <?php echo csrf_field(); ?>

            <div class="home-modal-field">
                <label class="home-modal-label" for="assign-team-user">Kullanıcı</label>
                <div class="assign-team-picker">
                    <input type="search" class="assign-team-search" data-assign-filter="assign-team-user" placeholder="Kullanıcı ara…" aria-label="Kullanıcı ara" autocomplete="off">
                    <select name="user_id" id="assign-team-user" class="assign-team-list" size="5" required>
                        <?php foreach ($users as $au): ?>
                            <?php if ((int) $au['is_active'] !== 1) { continue; } ?>
                            <option value="<?php echo (int) $au['id']; ?>"><?php echo htmlspecialchars($au['full_name'] . ' (' . $au['email'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="assign-team-empty" hidden>Eşleşen kullanıcı yok.</p>
                </div>
            </div>

            <div class="home-modal-field">
                <label class="home-modal-label" for="assign-team-team">Ekip</label>
                <div class="assign-team-picker">
                    <input type="search" class="assign-team-search" data-assign-filter="assign-team-team" placeholder="Ekip ara…" aria-label="Ekip ara" autocomplete="off">
                    <select name="team_id" id="assign-team-team" class="assign-team-list" size="4" required>
                        <?php foreach ($teams as $at): ?>
                            <option value="<?php echo (int) $at['id']; ?>"><?php echo htmlspecialchars($at['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="assign-team-empty" hidden>Eşleşen ekip yok.</p>
                </div>
            </div>

            <label class="home-modal-field">
                <span class="home-modal-label">Rol</span>
                <select name="role" class="home-modal-input" required>
                    <?php foreach (array_keys($GLOBALS['BCC_ROLE_RANK']) as $ar): ?>
                        <option value="<?php echo htmlspecialchars($ar, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $ar === 'viewer' ? ' selected' : ''; ?>><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$ar], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <p class="home-modal-error" id="assign-team-error" hidden></p>

            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" data-assign-team-close>Vazgeç</button>
                <button type="submit" class="home-modal-btn home-modal-btn-primary">Ata</button>
            </div>
        </form>
    </div>
</div>
