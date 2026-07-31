<?php
// "Hesap Özeti" — Airtable'ın "Account Overview" ekranının karşılığı (hesap
// menüsündeki, önceden işlevsiz "Hesap" öğesi artık buraya bağlanıyor, bkz.
// src/partials/account_menu.php). Ad/e-posta/şifre değişikliği ROLDEN
// BAĞIMSIZ — require_role() BURADA KULLANILMAZ, yalnızca require_login()
// (bkz. public/api/account_update_*.php): kullanıcı yalnızca KENDİ hesabını
// düzenler, takım rolü yalnızca takım VERİSİNE erişimi kısıtlar, kişinin
// kendi hesap bilgilerine değil. Takım/rol listesi bu sayfada SALT-OKUNUR —
// değiştirme admin panelinin işi (bkz. admin/assign_team.php), kapsam dışı.

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

// Salt-okunur ekip/rol listesi — workspaces.php'nin takım sorgusuyla AYNI
// desen, yalnızca role de eklendi.
$teams = bcc_fetch_all(
    'SELECT t.id, t.name, tm.role
     FROM team_members tm
     INNER JOIN teams t ON t.id = tm.team_id
     WHERE tm.user_id = :uid
     ORDER BY t.name',
    array('uid' => $user['id'])
);

// Sol panelin Starred alt-listesi için — dashboard.php/starred.php/workspaces.php
// ile AYNI desen.
$teamIds = array();
foreach ($teams as $t) {
    $teamIds[] = (int) $t['id'];
}
$starredBases = array();
if (!empty($teamIds)) {
    $starredPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
    $starredBases = bcc_fetch_all(
        "SELECT b.id, b.name
         FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($starredPlaceholders) AND b.deleted_at IS NULL
         WHERE usb.user_id = ?
         ORDER BY b.name",
        array_merge($teamIds, array((int) $user['id']))
    );
}

$homeActiveNav = 'account';
$homePageTitle = 'BCC-Core — Hesap Özeti';
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1>Hesap Özeti</h1>
        </div>

        <div class="account-card account-card-profile">
            <div class="account-avatar-lg"><?php echo htmlspecialchars(bcc_user_initial($user), ENT_QUOTES, 'UTF-8'); ?></div>

            <div class="account-rows">
                <div class="account-row" data-account-field="full_name">
                    <div class="account-row-label">Ad Soyad</div>
                    <div class="account-row-display" data-account-display>
                        <span class="account-row-value" data-account-value><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <button type="button" class="account-edit-btn" data-account-edit-trigger>
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M13.5 3.5l3 3-9.5 9.5H4v-3l9.5-9.5z" stroke="#1a56db" stroke-width="1.3" stroke-linejoin="round"/></svg>
                            Düzenle
                        </button>
                    </div>
                    <form class="account-row-form account-row-form-inline" data-account-edit-form data-account-endpoint="/api/account_update_name.php" hidden>
                        <input type="text" name="full_name" class="account-input" data-account-input maxlength="150" required value="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="account-row-actions">
                            <button type="submit" class="account-btn-primary">Kaydet</button>
                            <button type="button" class="account-btn-secondary" data-account-edit-cancel>İptal</button>
                        </div>
                        <div class="account-row-error" data-account-error hidden></div>
                    </form>
                </div>

                <div class="account-row" data-account-field="email">
                    <div class="account-row-label">E-posta</div>
                    <div class="account-row-display" data-account-display>
                        <span class="account-row-value" data-account-value><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <button type="button" class="account-edit-btn" data-account-edit-trigger>
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M13.5 3.5l3 3-9.5 9.5H4v-3l9.5-9.5z" stroke="#1a56db" stroke-width="1.3" stroke-linejoin="round"/></svg>
                            E-postayı düzenle
                        </button>
                    </div>
                    <form class="account-row-form" data-account-edit-form data-account-endpoint="/api/account_update_email.php" hidden>
                        <label class="account-field-label">E-posta
                            <input type="email" name="email" class="account-input" data-account-input maxlength="190" required value="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <label class="account-field-label">Mevcut şifre
                            <div class="input-with-toggle">
                                <input type="password" name="current_password" class="account-input" autocomplete="current-password" required>
                                <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                                </button>
                            </div>
                        </label>
                        <div class="account-row-actions">
                            <button type="submit" class="account-btn-primary">Kaydet</button>
                            <button type="button" class="account-btn-secondary" data-account-edit-cancel>İptal</button>
                        </div>
                        <div class="account-row-error" data-account-error hidden></div>
                    </form>
                </div>

                <div class="account-row">
                    <div class="account-row-label">Şifre</div>
                    <div class="account-row-display" data-account-display>
                        <span class="account-row-value">••••••••</span>
                        <button type="button" class="account-edit-btn" id="account-password-trigger">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M13.5 3.5l3 3-9.5 9.5H4v-3l9.5-9.5z" stroke="#1a56db" stroke-width="1.3" stroke-linejoin="round"/></svg>
                            Şifreyi güncelle
                        </button>
                    </div>
                    <form class="account-row-form" id="account-password-form" hidden>
                        <label class="account-field-label">Mevcut şifre
                            <div class="input-with-toggle">
                                <input type="password" name="current_password" class="account-input" autocomplete="current-password" required>
                                <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                                </button>
                            </div>
                        </label>
                        <label class="account-field-label">Yeni şifre (en az 8 karakter)
                            <div class="input-with-toggle">
                                <input type="password" name="new_password" class="account-input" autocomplete="new-password" minlength="8" maxlength="72" required>
                                <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                                </button>
                            </div>
                        </label>
                        <label class="account-field-label">Yeni şifre (tekrar)
                            <div class="input-with-toggle">
                                <input type="password" name="confirm_password" class="account-input" autocomplete="new-password" maxlength="72" required>
                                <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                                </button>
                            </div>
                        </label>
                        <div class="account-row-actions">
                            <button type="submit" class="account-btn-primary">Kaydet</button>
                            <button type="button" class="account-btn-secondary" id="account-password-cancel">İptal</button>
                        </div>
                        <div class="account-row-error" id="account-password-error" hidden></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="account-card">
            <h3 class="account-section-heading">Ekipler</h3>
            <?php if (empty($teams)): ?>
                <p class="account-teams-empty">Henüz üyesi olduğunuz bir ekip yok.</p>
            <?php else: ?>
                <div class="account-team-list">
                    <?php foreach ($teams as $t): ?>
                        <div class="account-team-row">
                            <div class="account-team-name"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="ws-collab-role"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$t['role']], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="account-card account-card-danger">
            <h3 class="account-section-heading account-section-heading-danger">Tehlikeli Bölge</h3>
            <div class="account-row-display" data-account-display>
                <span class="account-row-value">Hesabınızı kalıcı olarak silin.</span>
                <button type="button" class="account-btn-danger-link" id="account-delete-trigger">Hesabımı Sil</button>
            </div>
            <form class="account-row-form" id="account-delete-form" hidden>
                <p class="account-danger-warning">Bu işlem geri alınamaz. Hesabınız kalıcı olarak silinir; oluşturduğunuz base/kayıt/görünümler silinmez ama artık sizinle ilişkilendirilmez.</p>
                <label class="account-field-label">Mevcut şifre
                    <div class="input-with-toggle">
                        <input type="password" name="current_password" class="account-input" autocomplete="current-password" required>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </label>
                <div class="account-row-actions">
                    <button type="submit" class="account-btn-danger">Hesabımı kalıcı olarak sil</button>
                    <button type="button" class="account-btn-secondary" id="account-delete-cancel">İptal</button>
                </div>
                <div class="account-row-error" id="account-delete-error" hidden></div>
            </form>
        </div>
<script src="/assets/account-page.js" defer></script>
<script src="/assets/password-toggle.js" defer></script>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
