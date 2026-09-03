<div class="gs-view-desc-overlay gs-share-overlay" id="gs-share-overlay" hidden>
    <div class="gs-share-modal" role="dialog" aria-modal="true" aria-labelledby="gs-share-modal-title">
        <div class="gs-share-head">
            <div>
                <div class="gs-share-title" id="gs-share-modal-title">
                    "<?php echo htmlspecialchars($shareModalTeamName, ENT_QUOTES, 'UTF-8'); ?>" paylaşım ayarları
                </div>
                <div class="gs-share-subtitle" data-share-subtitle></div>
            </div>
            <button type="button" class="gs-share-close" id="gs-share-close" aria-label="Kapat">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>
        </div>

                <div class="gs-share-invite" data-share-invite hidden>
            <div class="gs-share-invite-row">
                                <div class="gs-share-invite-field">
                    <input
                        type="email"
                        class="gs-share-invite-email"
                        data-share-invite-email
                        placeholder="E-posta adresi ekleyin"
                        aria-label="Davet edilecek e-posta"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                    >
                    <div class="gs-share-suggest" data-share-suggest role="listbox" hidden></div>
                </div>
                <select class="gs-share-invite-role" data-share-invite-role aria-label="Rol"></select>
                <button type="button" class="gs-btn-primary gs-share-invite-btn" data-share-invite-btn>Davet Et</button>
            </div>
            <p class="gs-share-invite-note">
                Yalnızca sistemde <strong>hesabı olan</strong> kullanıcılar eklenebilir.
                Yeni hesap oluşturma platform yöneticisindedir.
            </p>
        </div>

        <p class="gs-share-readonly-note" data-share-readonly-note hidden></p>

        <div class="gs-share-status" data-share-status hidden></div>

        <div class="gs-share-tabs" role="tablist">
            <button type="button" class="gs-share-tab is-active" data-share-tab="collaborators" role="tab" aria-selected="true">
                Katılımcılar <span class="gs-share-tab-count" data-share-count-collaborators>0</span>
            </button>
            <button type="button" class="gs-share-tab" data-share-tab="pending" role="tab" aria-selected="false">
                Bekleyen davetler <span class="gs-share-tab-count" data-share-count-pending>0</span>
            </button>
        </div>

        <div class="gs-share-list" data-share-panel="collaborators" role="tabpanel"></div>
        <div class="gs-share-list" data-share-panel="pending" role="tabpanel" hidden></div>

        <div class="gs-share-foot">
                        <a class="gs-share-foot-link" href="/team_members.php?team_id=<?php echo (int) $shareModalTeamId; ?>">Tüm üye ayarları →</a>
        </div>
    </div>
</div>
<script>
    var BCC_SHARE_MODAL = <?php echo bcc_json_for_script($shareModalPayload); ?>;
    var BCC_SHARE_CANDIDATES = <?php echo bcc_json_for_script(
        empty($shareModalPayload['can_manage']) ? array() : $shareModalCandidates); ?>;
</script>
