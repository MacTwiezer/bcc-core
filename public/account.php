<?php
// "Hesap Özeti" — OpsFlow'un "Account Overview" ekranının karşılığı (hesap
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

// Sol panelin Starred alt-listesi ARTIK BURADA ÇEKİLMİYOR: kabuk
// (src/partials/home_shell_top.php) bcc_starred_bases_for_current_user()'ı
// kendisi çağırıyor. $teamIds KALIYOR — aşağıdaki sağ sütun sayaçları
// (KVKK izolasyonu) onu kullanıyor.
$teamIds = array();
foreach ($teams as $t) {
    $teamIds[] = (int) $t['id'];
}

// ---- Sağ sütun widget'ları -------------------------------------------------
//
// ⚠️ BURADAKİ HER DEĞER GERÇEK BİR SORGUDAN GELİR. İstenen widget'lardan
// bazılarının bu uygulamada KARŞILIĞI YOK ve UYDURULMADI:
//   * İki faktörlü doğrulama — users tablosunda kolon, kodda akış YOK.
//   * Aktif oturumlar     — oturumlar PHP'nin native $_SESSION'ında, DB'de
//                           tutulmuyor; sayılamaz.
//   * API anahtarları     — böyle bir özellik YOK.
//   * Bildirim ayarları   — ayar sayfası YOK (yalnızca okundu damgası:
//                           users.last_seen_notifications_at).
// Bunlar için sahte gösterge/ölü link basmak, kullanıcıya olmayan bir güvenlik
// durumu veya sayfa vaat etmek olurdu. Var olmayan şey EKRANA DA KONMADI.
//
// KVKK izolasyonu: tüm sayaçlar $teamIds ile sınırlı — kullanıcı yalnızca üyesi
// olduğu ekiplerin verisini sayar.

$accountStats = array(
    'base_count' => 0,
    'table_count' => 0,
    'record_count' => 0,
    'storage_bytes' => 0,
);

if (!empty($teamIds)) {
    $ph = implode(',', array_fill(0, count($teamIds), '?'));

    $accountStats['base_count'] = (int) bcc_fetch_column(
        "SELECT COUNT(*) FROM bases WHERE team_id IN ($ph) AND deleted_at IS NULL",
        $teamIds
    );
    $accountStats['table_count'] = (int) bcc_fetch_column(
        "SELECT COUNT(*) FROM tables_meta tm
         INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
         WHERE b.team_id IN ($ph)",
        $teamIds
    );
    $accountStats['record_count'] = (int) bcc_fetch_column(
        "SELECT COUNT(*) FROM records r
         INNER JOIN tables_meta tm ON tm.id = r.table_id
         INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
         WHERE b.team_id IN ($ph) AND r.deleted_at IS NULL",
        $teamIds
    );
    // Depolama: attachments.file_size'ın GERÇEK toplamı (tahmin değil).
    $accountStats['storage_bytes'] = (int) bcc_fetch_column(
        "SELECT COALESCE(SUM(a.file_size), 0) FROM attachments a
         INNER JOIN records r ON r.id = a.record_id AND r.deleted_at IS NULL
         INNER JOIN tables_meta tm ON tm.id = r.table_id
         INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
         WHERE b.team_id IN ($ph)",
        $teamIds
    );
}

// Son giriş: audit_log'daki 'user.login' (login.php:25 gerçekten yazıyor).
// EN YENİSİ bu oturumun kendi girişidir — "şu anki oturum" olarak gösteriliyor;
// ondan bir öncekisi "önceki giriş". İkisi de gerçek kayıt, tahmin yok.
$loginRows = bcc_fetch_all(
    "SELECT created_at FROM audit_log
     WHERE user_id = :uid AND action = 'user.login'
     ORDER BY id DESC LIMIT 2",
    array('uid' => $user['id'])
);
$currentLoginAt = isset($loginRows[0]) ? $loginRows[0]['created_at'] : null;
$previousLoginAt = isset($loginRows[1]) ? $loginRows[1]['created_at'] : null;

$loginCount30d = (int) bcc_fetch_column(
    "SELECT COUNT(*) FROM audit_log
     WHERE user_id = :uid AND action = 'user.login' AND created_at >= (NOW() - INTERVAL 30 DAY)",
    array('uid' => $user['id'])
);

// current_user() (src/auth.php) YALNIZCA şu kolonları döndürüyor:
// id, email, full_name, is_admin, is_active, last_seen_notifications_at.
// created_at ve email_verify_token ORADA YOK — bunlara $user üzerinden erişmek
// sessizce NULL verir.
//
// BULUNAN GERÇEK BUG (ilk sürümde yapıldı, /browse'da yakalandı): doğrulama
// rozeti `$user['email_verify_token'] === null` ile hesaplanıyordu; tanımsız
// anahtar NULL sayıldığı için rozet, hesabın gerçek durumundan BAĞIMSIZ olarak
// HER ZAMAN "doğrulandı" diyordu — yani kullanıcıya yanlış bir güvenlik
// bilgisi gösteriyordu. Kolonlar artık açıkça sorgulanıyor.
$accountRow = bcc_fetch_one(
    'SELECT created_at, email_verify_token FROM users WHERE id = :id LIMIT 1',
    array('id' => $user['id'])
);
$accountCreatedAt = $accountRow ? $accountRow['created_at'] : null;
$emailVerified = $accountRow
    && ($accountRow['email_verify_token'] === null || $accountRow['email_verify_token'] === '');

// Rol dağılımı — "en yetkili rol" rozeti için (BCC_ROLE_RANK zaten tanımlı).
$topRole = null;
foreach ($teams as $t) {
    if ($topRole === null || $GLOBALS['BCC_ROLE_RANK'][$t['role']] > $GLOBALS['BCC_ROLE_RANK'][$topRole]) {
        $topRole = $t['role'];
    }
}

// Buradaki yerel bcc_account_format_bytes() KALDIRILDI: bayt biçimlendirme
// artık src/schema.php'teki ortak bcc_format_bytes() fonksiyonunda —
// workspaces.php'nin "Kullanım & Limitler" kartı da AYNI biçimi kullanıyor,
// ikinci bir kopya YOK.

function bcc_account_format_dt($value)
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime((string) $value);

    return $ts ? date('d.m.Y H:i', $ts) : '—';
}

$homeActiveNav = 'account';
$homePageTitle = bcc_brand_domain() . ' — Hesap Özeti';
// Ortak tasarım sistemi (table_fields.php / base_tables.php ile PAYLAŞILAN) +
// yalnızca bu sayfaya ait iki sütunlu yerleşim ve widget'lar.
$homeExtraCss = array('settings-page.css', 'account.css');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
<div class="sp-page ac-page">
        <div class="home-main-header">
            <h1>Hesap Özeti</h1>
            <p class="settings-hint">Hesap bilgilerinizi yönetin, ekip üyeliklerinizi ve kullanım durumunuzu görün.</p>
        </div>

        <div class="ac-grid">
        <div class="ac-main">

        <div class="account-card account-card-profile">
            <?php // Profil başlığı: büyük avatar + ad/e-posta + durum rozetleri.
                  // "Fotoğraf değiştir" tetikleyicisi BİLEREK YOK — uygulamada
                  // avatar yükleme diye bir özellik yok, buton olmayan bir akışa
                  // işaret ederdi. Avatar her yerde olduğu gibi baş harf diski
                  // (bcc_user_initial), yalnızca burada daha büyük. ?>
            <div class="ac-profile-head">
                <div class="account-avatar-lg"><?php echo htmlspecialchars(bcc_user_initial($user), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="ac-profile-id">
                    <div class="ac-profile-name"><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="ac-profile-mail"><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="ac-badges">
                        <?php if ($emailVerified): ?>
                            <span class="ac-badge ac-badge-ok">
                                <svg width="12" height="12" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.5 10.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                E-posta doğrulandı
                            </span>
                        <?php else: ?>
                            <span class="ac-badge ac-badge-warn">E-posta doğrulanmadı</span>
                        <?php endif; ?>
                        <?php if ((int) $user['is_admin'] === 1): ?>
                            <span class="ac-badge ac-badge-accent">Platform admini</span>
                        <?php endif; ?>
                        <?php if ($topRole !== null): ?>
                            <span class="ac-badge"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$topRole], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

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
                <?php // Rol rozeti için .ws-collab-role KULLANILMIYOR: o sınıf
                      // team_members.php ve workspaces.php ile PAYLAŞILIYOR, buradaki
                      // yeni görünüm için değiştirmek o iki sayfayı da etkilerdi.
                      // Role göre renklenen kendi hap sınıfı (.ac-role--<rol>). ?>
                <div class="account-team-list">
                    <?php foreach ($teams as $t): ?>
                        <div class="account-team-row">
                            <span class="ac-team-icon" aria-hidden="true">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                            </span>
                            <div class="account-team-name"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <span class="ac-role ac-role--<?php echo htmlspecialchars($t['role'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$t['role']], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="account-card account-card-danger">
            <h3 class="account-section-heading account-section-heading-danger">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3.5l7 12.5H3l7-12.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                Tehlikeli Bölge
            </h3>
            <?php // HESAP SİLME KALDIRILDI (ürün kararı): hiçbir kullanıcı, rolü ne
                  // olursa olsun, kendi hesabını KALICI OLARAK SİLEMEZ. Yerine
                  // "pasife alma" sunuluyor — geri alınabilir ve içerik/denetim
                  // izleri bozulmadan kalır.
                  //
                  // ⚠️ Butonu gizlemek YETMEZ: api/account_delete.php uçnoktası
                  // tamamen KALDIRILDI, yani istek elle gönderilse bile silecek
                  // bir yol yok (bkz. api/account_deactivate.php baş yorumu). ?>
            <div class="account-row-display ac-danger-row" data-account-display>
                <span class="account-row-value">
                    <strong>Hesabı pasife al</strong>
                    <span class="ac-danger-sub">Giriş yapamazsınız; bir yönetici hesabınızı yeniden aktifleştirebilir.</span>
                </span>
                <button type="button" class="ac-btn-danger" id="account-deactivate-trigger">Hesabımı Pasife Al</button>
            </div>
            <form class="account-row-form" id="account-deactivate-form" hidden>
                <p class="account-danger-warning">Hesabınız pasife alınır ve oturumunuz kapatılır. Bundan sonra giriş yapamazsınız — tekrar erişim için bir platform yöneticisinin hesabınızı aktifleştirmesi gerekir. Base'leriniz, kayıtlarınız ve görünümleriniz silinmez.</p>
                <label class="account-field-label">Mevcut şifre
                    <div class="input-with-toggle">
                        <input type="password" name="current_password" class="account-input" autocomplete="current-password" required>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </label>
                <div class="account-row-actions">
                    <button type="submit" class="account-btn-danger">Hesabımı pasife al</button>
                    <button type="button" class="account-btn-secondary" id="account-deactivate-cancel">İptal</button>
                </div>
                <div class="account-row-error" id="account-deactivate-error" hidden></div>
            </form>
        </div>
        </div><!-- /.ac-main -->

        <aside class="ac-side">
            <?php // ---- Hesap durumu -------------------------------------------------
                  // İki faktörlü doğrulama / aktif oturum sayısı BİLEREK YOK:
                  // uygulamada ne 2FA akışı ne de DB'de oturum kaydı var (oturumlar
                  // PHP native $_SESSION). Sahte bir "2FA: Kapalı" göstergesi
                  // olmayan bir ayarı varmış gibi gösterirdi. Buradaki her satır
                  // gerçek bir sorgudan geliyor. ?>
            <div class="ac-widget">
                <h3 class="ac-widget-title">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5l6 2.5v5c0 3.6-2.5 6.6-6 7.5-3.5-.9-6-3.9-6-7.5V5l6-2.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M7.5 10l1.8 1.8L13 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Hesap durumu
                </h3>
                <dl class="ac-stat-list">
                    <div class="ac-stat">
                        <dt>E-posta</dt>
                        <dd>
                            <?php if ($emailVerified): ?>
                                <span class="ac-dot ac-dot-ok"></span> Doğrulandı
                            <?php else: ?>
                                <span class="ac-dot ac-dot-warn"></span> Doğrulanmadı
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="ac-stat">
                        <dt>Hesap</dt>
                        <dd>
                            <span class="ac-dot <?php echo ((int) $user['is_active'] === 1) ? 'ac-dot-ok' : 'ac-dot-warn'; ?>"></span>
                            <?php echo ((int) $user['is_active'] === 1) ? 'Aktif' : 'Pasif'; ?>
                        </dd>
                    </div>
                    <div class="ac-stat">
                        <dt>Bu oturum</dt>
                        <dd><?php echo htmlspecialchars(bcc_account_format_dt($currentLoginAt), ENT_QUOTES, 'UTF-8'); ?></dd>
                    </div>
                    <div class="ac-stat">
                        <dt>Önceki giriş</dt>
                        <dd><?php echo htmlspecialchars(bcc_account_format_dt($previousLoginAt), ENT_QUOTES, 'UTF-8'); ?></dd>
                    </div>
                    <div class="ac-stat">
                        <dt>Son 30 günde giriş</dt>
                        <dd><?php echo (int) $loginCount30d; ?> kez</dd>
                    </div>
                    <div class="ac-stat">
                        <dt>Üyelik</dt>
                        <dd><?php echo htmlspecialchars(bcc_account_format_dt($accountCreatedAt), ENT_QUOTES, 'UTF-8'); ?></dd>
                    </div>
                </dl>
            </div>

            <div class="ac-widget">
                <h3 class="ac-widget-title">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3.5 16V9m4.5 7V4m4.5 12V7m4.5 9v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    Kullanım özeti
                </h3>
                <div class="ac-metric-grid">
                    <div class="ac-metric">
                        <span class="ac-metric-value"><?php echo count($teams); ?></span>
                        <span class="ac-metric-label">Ekip</span>
                    </div>
                    <div class="ac-metric">
                        <span class="ac-metric-value"><?php echo (int) $accountStats['base_count']; ?></span>
                        <span class="ac-metric-label">Base</span>
                    </div>
                    <div class="ac-metric">
                        <span class="ac-metric-value"><?php echo (int) $accountStats['table_count']; ?></span>
                        <span class="ac-metric-label">Tablo</span>
                    </div>
                    <div class="ac-metric">
                        <span class="ac-metric-value"><?php echo number_format((int) $accountStats['record_count'], 0, ',', '.'); ?></span>
                        <span class="ac-metric-label">Kayıt</span>
                    </div>
                </div>
                <div class="ac-stat ac-stat-standalone">
                    <dt>Dosya eki depolaması</dt>
                    <dd><?php echo htmlspecialchars(bcc_format_bytes($accountStats['storage_bytes']), ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <p class="ac-widget-note">Üyesi olduğunuz ekiplerin toplamı.</p>
            </div>

            <?php // ---- Hızlı erişim --------------------------------------------------
                  // YALNIZCA GERÇEKTEN VAR OLAN sayfalara link veriliyor. İstenen
                  // "API anahtarları / Bildirim ayarları / Yardım & Destek"
                  // kalemleri EKLENMEDİ: bu uygulamada o sayfalar yok, link ölü
                  // olurdu. Admin kalemi yalnızca is_admin'de görünür (hesap
                  // menüsündeki AYNI koşul). ?>
            <div class="ac-widget">
                <h3 class="ac-widget-title">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M11 2.5L4 11h5l-1 6.5L16 9h-5l1-6.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    Hızlı erişim
                </h3>
                <div class="ac-links">
                    <a href="/workspaces.php" class="ac-link">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                        <span>Çalışma alanları</span>
                        <svg class="ac-link-arrow" width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <a href="/starred.php" class="ac-link">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3l2.2 4.5 5 .7-3.6 3.5.9 4.9L10 14.3 5.5 16.6l.9-4.9L2.8 8.2l5-.7L10 3z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        <span>Yıldızlı base'ler</span>
                        <svg class="ac-link-arrow" width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <a href="/dashboard.php" class="ac-link">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3.5 9.5L10 4l6.5 5.5V16a1 1 0 01-1 1h-11a1 1 0 01-1-1V9.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                        <span>Ana sayfa</span>
                        <svg class="ac-link-arrow" width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <?php if ((int) $user['is_admin'] === 1): ?>
                    <a href="/admin/index.php" class="ac-link">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.4"/><path d="M4 16.5c0-2.8 2.7-4.5 6-4.5s6 1.7 6 4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        <span>Admin paneli</span>
                        <svg class="ac-link-arrow" width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
        </div><!-- /.ac-grid -->
</div><!-- /.sp-page -->
<script src="<?php echo bcc_asset_url('account-page.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('password-toggle.js'); ?>" defer></script>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
