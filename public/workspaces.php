<?php
// "Workspaces" — Airtable'ın "All Workspaces" ekranının karşılığı. YENİ bir
// varlık/DDL YOK: mevcut teams/team_members üzerine kurulu (bkz. PROJE-DURUM.md
// analiz notu — "team" zaten workspace'in KVKK-izole edilmiş, collaborators'lı
// karşılığı). Create/Settings hâlâ PASİF (onaylanmış karar, kapsam dışı) — Paylaş
// artık takımın HER üyesine açık (bkz. team_members.php'nin hiyerarşik rol
// yönetimi, require_team_access zaten en dış koruma).

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

// dashboard.php ile AYNI desen.
// m.role EKLENDİ: sağdaki aksiyon butonlarının (Katılımcıları yönet / Base
// oluştur / Ayarlar) görünürlüğü seçili alandaki role bağlı ve bu bilgi zaten
// bu JOIN'de mevcut — current_user_role_in_team() ile AYRI bir sorgu açmak
// gereksiz olurdu (dashboard.php'de de aynı desen).
$teams = bcc_fetch_all(
    'SELECT t.id, t.name, m.role
     FROM team_members m
     INNER JOIN teams t ON t.id = m.team_id
     WHERE m.user_id = :uid
     ORDER BY t.name',
    array('uid' => $user['id'])
);

$teamIds = array();
foreach ($teams as $t) {
    $teamIds[] = (int) $t['id'];
}

$baseCounts = array();
if (!empty($teamIds)) {
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $countRows = bcc_fetch_all("SELECT team_id, COUNT(*) AS cnt FROM bases WHERE team_id IN ($placeholders) AND deleted_at IS NULL GROUP BY team_id", $teamIds);
    foreach ($countRows as $row) {
        $baseCounts[(int) $row['team_id']] = (int) $row['cnt'];
    }
}

// Seçili takım: ?team_id= yalnızca kullanıcının ZATEN üyesi olduğu bir takımsa
// kabul edilir (yukarıdaki $teams listesiyle doğrulanır) — yoksa ilk takıma düşer.
$requestedTeamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
$selectedTeamId = 0;
$selectedTeamName = '';
foreach ($teams as $t) {
    if ((int) $t['id'] === $requestedTeamId) {
        $selectedTeamId = $requestedTeamId;
        $selectedTeamName = $t['name'];
        break;
    }
}
if ($selectedTeamId === 0 && !empty($teams)) {
    $selectedTeamId = (int) $teams[0]['id'];
    $selectedTeamName = $teams[0]['name'];
}

// Seçili çalışma alanındaki YETENEKLER — tek kaynak src/auth.php. Sol panelden
// başka bir çalışma alanına geçildiğinde sayfa yeniden yüklendiği için bu
// bayraklar her zaman GÖRÜNTÜLENEN alana aittir (kullanıcı bir alanda owner,
// diğerinde viewer olabilir; tek bir "ben owner'ım" bayrağı yanlış olurdu).
$selectedRole = null;
$canManageMembers = false;
$canCreateBase = false;

$collaborators = array();
if ($selectedTeamId) {
    // KVKK: $teams zaten kullanıcının üyeliğiyle filtrelenmişti ama savunma
    // amaçlı ikinci bir doğrulama — projedeki her veri erişiminin ÖNÜNDE olan
    // aynı fonksiyon.
    require_team_access($selectedTeamId);

    // $teams zaten m.role'ü içeriyor (yukarıdaki sorgu) — rolü ORADAN okuruz,
    // current_user_role_in_team() ile ikinci bir sorgu AÇILMAZ.
    foreach ($teams as $t) {
        if ((int) $t['id'] === $selectedTeamId) {
            $selectedRole = $t['role'];
            break;
        }
    }
    $canManageMembers = bcc_can_manage_members($selectedRole);
    $canCreateBase = bcc_can_manage_bases($selectedRole);

    // u.is_active AYRICA çekilir (bulunan gerçek bug: bu sorgu hiç seçmiyordu) —
    // admin bir kullanıcıyı pasif yaptığında team_members satırı SİLİNMEZ (bkz.
    // admin/index.php "Pasif yap"), bu yüzden pasif bir kullanıcı burada aktif bir
    // katılımcıdan (isim/e-posta/rol) AYIRT EDİLEMEZ görünüyordu — oysa
    // bcc_team_users_by_id() ('user' alan tipi seçenekleri) ve admin/assign_team.php
    // ('kullanılabilir kullanıcılar' listesi) zaten yalnızca aktif kullanıcıları
    // gösteriyor, aynı prensip burada da uygulanmalı.
    $collaborators = bcc_fetch_all(
        'SELECT u.id, u.full_name, u.email, u.is_active, tm.role
         FROM team_members tm
         INNER JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = :team_id
         ORDER BY u.full_name',
        array('team_id' => $selectedTeamId)
    );
}

// ---- Seçili çalışma alanının PANO verileri --------------------------------
// Sorgular src/schema.php'de (bcc_workspace_*) — bu dosya şablon olarak kalıyor
// ve sayaçlar ileride başka bir ekrandan da okunabilir. Hepsi $selectedTeamId
// ile sınırlı; yukarıdaki require_team_access() KVKK kapısının ardında.
$wsBases = array();
$wsStarredBases = array();
$wsUsage = array('base_count' => 0, 'table_count' => 0, 'record_count' => 0, 'storage_bytes' => 0, 'slack_webhook_count' => 0);
$wsActivity = array();
$wsInviteRoles = array();

if ($selectedTeamId) {
    $wsBases = bcc_workspace_bases($selectedTeamId, (int) $user['id']);
    foreach ($wsBases as $b) {
        if ((int) $b['is_starred'] > 0) {
            $wsStarredBases[] = $b;
        }
    }
    $wsUsage = bcc_workspace_usage($selectedTeamId);
    $wsActivity = bcc_workspace_activity($selectedTeamId, 12);

    // Hızlı davet kutusunun rol listesi — team_members.php ve "Paylaş"
    // modalıyla AYNI kaynak (bcc_assignable_roles): kendi rütben ve altı.
    // Yetkisi olmayan için BOŞ dizi; kutu zaten basılmaz, bu ikinci katman.
    if ($canManageMembers) {
        $wsInviteRoles = bcc_assignable_roles($GLOBALS['BCC_ROLE_RANK'][$selectedRole]);
    }
}

// Sol panelin Starred alt-listesi ARTIK BURADA ÇEKİLMİYOR: kabuk
// (src/partials/home_shell_top.php) bcc_starred_bases_for_current_user()'ı
// kendisi çağırıyor — bkz. src/schema.php'deki tek kaynak notu.
// ($teamIds yukarıdaki base sayaçları için hâlâ gerekli, o yüzden kalıyor.)

$homeActiveNav = 'workspaces';
$homePageTitle = 'BCC-Core — Çalışma Alanları';
// Ortak tasarım sistemi + yalnızca bu sayfaya ait iki sütunlu yerleşim.
// Rol hapı (.sp-role), avatar (.sp-avatar) ve bilgi kutusu (.sp-note) ORTAK
// dosyada — burada kopyası yok.
$homeExtraCss = array('settings-page.css', 'workspaces.css');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
<div class="sp-page wsx-page">
        <div class="home-main-header">
            <h1>Çalışma Alanları</h1>
            <p class="settings-hint">Üyesi olduğunuz çalışma alanlarını, katılımcılarını ve rollerini görün.</p>
        </div>

        <?php if (empty($teams)): ?>
            <p class="settings-empty">
                <strong>Henüz üyesi olduğunuz bir ekip yok.</strong>
                <span class="sp-muted">Bir çalışma alanına eklenmek için yöneticinizle iletişime geçin.</span>
            </p>
        <?php else: ?>
            <div class="wsx-layout">

                <?php // ---- SOL: çalışma alanı seçici --------------------------------
                      // Tek/iki çalışma alanı olan kullanıcıda sütun "boş" görünüyordu:
                      // kartlar serbestçe havada duruyordu, panelin bir sınırı yoktu.
                      // Artık hepsi ÇERÇEVELİ bir panelin içinde (başlık + arama + liste
                      // + alt bilgi) — panel kendi yüksekliğini taşıdığı için 1 öğeyle
                      // bile bitmiş bir bileşen gibi duruyor. ?>
                <aside class="wsx-side">
                    <div class="wsx-panel">

                        <div class="wsx-panel-head">
                            <span class="wsx-side-label">Çalışma alanları</span>
                            <span class="wsx-panel-count"><?php echo count($teams); ?></span>
                        </div>

                        <?php // Arama: liste uzadıkça asıl işini yapar, kısa listede de
                              // panelin üst kenarını tanımlar. Katılımcı aramasından
                              // (.wsx-search, workspaces.js) AYRI bir bileşen: farklı
                              // liste, farklı DOM, farklı data-* kancası. ?>
                        <div class="wsx-panel-search">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            <input
                                type="search"
                                placeholder="Çalışma alanı ara..."
                                aria-label="Çalışma alanı ara"
                                data-wsx-team-search
                                autocomplete="off"
                            >
                        </div>

                        <div class="wsx-panel-list" data-wsx-team-list>
                            <?php foreach ($teams as $t):
                                $tid = (int) $t['id'];
                                $isActive = $tid === $selectedTeamId;
                                $baseCount = isset($baseCounts[$tid]) ? $baseCounts[$tid] : 0;
                            ?>
                                <a
                                    href="/workspaces.php?team_id=<?php echo $tid; ?>"
                                    class="wsx-card<?php echo $isActive ? ' is-active' : ''; ?>"
                                    data-wsx-team-name="<?php echo htmlspecialchars(mb_strtolower($t['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $isActive ? ' aria-current="page"' : ''; ?>
                                >
                                    <span class="wsx-card-icon">
                                        <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                                    </span>
                                    <span class="wsx-card-body">
                                        <span class="wsx-card-name"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="wsx-card-meta">
                                            <?php echo (int) $baseCount; ?> base
                                            · <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$t['role']], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                            <p class="wsx-panel-empty" data-wsx-team-empty hidden>Eşleşen çalışma alanı yok.</p>
                        </div>

                        <div class="wsx-panel-foot">
                            <?php // "Yeni çalışma alanı" YALNIZCA platform adminine:
                                  // çalışma alanı = takım ve takım oluşturmak
                                  // admin/create_team.php'nin işi (o dosyanın require_admin()'i).
                                  // Herkese gösterilen bir buton, sıradan kullanıcıyı 403'e
                                  // götüren ölü bir vaat olurdu — bu yüzden yetkisi olmayana
                                  // BUTON DEĞİL, nedenini söyleyen tek satır basılıyor
                                  // (alt bilgi yine de dolu kalır). ?>
                            <?php if ((int) $user['is_admin'] === 1): ?>
                                <a href="/admin/create_team.php" class="wsx-newbtn">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    Yeni Çalışma Alanı
                                </a>
                            <?php else: ?>
                                <p class="wsx-newnote">
                                    Yeni çalışma alanını <strong>platform yöneticisi</strong> oluşturur.
                                </p>
                            <?php endif; ?>

                            <?php
                            // Kullanım rozeti. GERÇEK sayı: kullanıcının üyesi olduğu
                            // çalışma alanı adedi. Eşik ise BCC_USER_WORKSPACE_SOFT_LIMIT'ten
                            // gelen GÖRSEL bir referans, zorlanan bir kota DEĞİL (bkz.
                            // src/schema.php'deki not) — bu yüzden "plan/tier" adı
                            // UYDURULMADI, yalnızca sayaç gösteriliyor.
                            //
                            // Üyelik eşiği aşarsa kesir GÖSTERİLMEZ ("7 / 5" kırık görünür),
                            // yalnızca sayı yazılır.
                            $wsCount = count($teams);
                            $wsLimit = (int) $GLOBALS['BCC_USER_WORKSPACE_SOFT_LIMIT'];
                            $wsWithinLimit = $wsLimit > 0 && $wsCount <= $wsLimit;
                            $wsPct = $wsWithinLimit ? (int) round($wsCount / $wsLimit * 100) : 100;
                            ?>
                            <div class="wsx-plan">
                                <div class="wsx-plan-head">
                                    <span class="wsx-plan-label">
                                        <svg width="12" height="12" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.5"/></svg>
                                        Çalışma alanı kullanımı
                                    </span>
                                    <span class="wsx-plan-value">
                                        <?php if ($wsWithinLimit): ?>
                                            <strong><?php echo $wsCount; ?></strong> / <?php echo $wsLimit; ?>
                                        <?php else: ?>
                                            <strong><?php echo $wsCount; ?></strong>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($wsWithinLimit): ?>
                                    <div class="wsx-plan-track" role="img" aria-label="<?php echo htmlspecialchars($wsCount . ' / ' . $wsLimit . ' çalışma alanı', ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="wsx-plan-fill" style="width: <?php echo $wsPct; ?>%;"></span>
                                    </div>
                                <?php endif; ?>
                                <p class="wsx-plan-note">Eşik kapasite göstergesidir; zorlanmaz.</p>
                            </div>
                        </div>

                    </div>
                </aside>

                <?php // ---- SAĞ: seçili çalışma alanı --------------------------------- ?>
                <div class="wsx-main">
                    <div class="settings-card">
                        <div class="wsx-head">
                            <div class="wsx-head-id">
                                <span class="wsx-head-icon">
                                    <svg width="22" height="22" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                                </span>
                                <div>
                                    <h2 class="wsx-head-title"><?php echo htmlspecialchars($selectedTeamName, ENT_QUOTES, 'UTF-8'); ?></h2>
                                    <div class="wsx-head-sub">
                                        <?php echo isset($baseCounts[$selectedTeamId]) ? (int) $baseCounts[$selectedTeamId] : 0; ?> base
                                        · <?php echo count($collaborators); ?> katılımcı
                                    </div>
                                </div>
                            </div>
                            <div class="wsx-actions">
                                <?php
                                // Aksiyonlar SEÇİLİ çalışma alanındaki role göre basılır
                                // (bkz. $canManageMembers / $canCreateBase yukarıda) —
                                // CSS ile gizlenmiyor, yetkisi olmayanın kaynağında hiç yok.
                                //
                                // "Katılımcıları yönet": hedef sayfa (team_members.php)
                                //   artık Owner olmayana zaten salt-okunur açılıyor; butonu
                                //   da göstermemek "yönet" vaadini boşa çıkarmamak için.
                                // "Base oluştur": Airtable'da Owner+Creator satırı
                                //   (bcc_can_manage_bases), bases.php'nin formu ve
                                //   api/base_create.php ile AYNI eşik.
                                // "Ayarlar": çalışma alanı ayarları diye bir ÖZELLİK YOK
                                //   (bu dosyanın onaylanmış kararı) — buton hâlâ devre
                                //   dışı, ama artık yalnızca onu bir gün kullanacak olan
                                //   role gösteriliyor.
                                ?>
                                <?php if ($canManageMembers): ?>
                                <a href="/team_members.php?team_id=<?php echo $selectedTeamId; ?>" class="wsx-btn wsx-btn--primary">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8" cy="7" r="2.8" stroke="currentColor" stroke-width="1.4"/><path d="M3 16c0-2.5 2.2-4 5-4s5 1.5 5 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M14.5 7.5h3M16 6v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                    Katılımcıları yönet
                                </a>
                                <?php endif; ?>
                                <?php if ($canCreateBase): ?>
                                <a href="/bases.php" class="wsx-btn">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    Base oluştur
                                </a>
                                <?php endif; ?>
                                <?php if ($canManageMembers): ?>
                                <button type="button" class="wsx-btn" disabled title="Çalışma alanı ayarları henüz kullanılamıyor">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 3v2m0 10v2m7-7h-2M5 10H3m11.9-4.9l-1.4 1.4M6.5 13.5l-1.4 1.4m9.8 0l-1.4-1.4M6.5 6.5L5.1 5.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                    Ayarlar
                                </button>
                                <?php endif; ?>
                                <?php if (!$canManageMembers && !$canCreateBase): ?>
                                    <span class="wsx-role-note">
                                        Rolünüz: <strong><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$selectedRole], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php // ---- Kullanım şeridi: başlığın hemen altında dört sayaç ----
                              // Kartın üstündeki ölü boşluğu dolduran ilk katman. Sayılar
                              // bcc_workspace_usage()'dan; hiçbiri tahmin değil. ?>
                        <div class="wsx-statbar">
                            <div class="wsx-stat">
                                <span class="wsx-stat-value"><?php echo number_format($wsUsage['base_count'], 0, ',', '.'); ?></span>
                                <span class="wsx-stat-label">Base</span>
                            </div>
                            <div class="wsx-stat">
                                <span class="wsx-stat-value"><?php echo number_format($wsUsage['table_count'], 0, ',', '.'); ?></span>
                                <span class="wsx-stat-label">Tablo</span>
                            </div>
                            <div class="wsx-stat">
                                <span class="wsx-stat-value"><?php echo number_format($wsUsage['record_count'], 0, ',', '.'); ?></span>
                                <span class="wsx-stat-label">Kayıt</span>
                            </div>
                            <div class="wsx-stat">
                                <span class="wsx-stat-value"><?php echo htmlspecialchars(bcc_format_bytes($wsUsage['storage_bytes']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="wsx-stat-label">Depolama</span>
                            </div>
                        </div>
                    </div>

                    <?php // Başlık kartının ALTINDA iki sütun: solda içerik (base'ler +
                          // katılımcılar), sağda dar bilgi rayı (kullanım + hareketler).
                          // Sayfanın "sağ tarafta ölü alan" sorununun asıl çözümü bu —
                          // tek geniş sütun yerine içerik kendi içinde ikiye ayrılıyor. ?>
                    <div class="wsx-body">
                    <div class="wsx-body-main">

                    <?php // ================= BASE IZGARASI ========================= ?>
                    <div class="settings-card">
                        <div class="wsx-collab-head">
                            <h3 class="wsx-collab-title">Base'ler <span class="sp-count"><?php echo count($wsBases); ?></span></h3>
                            <?php if ($canCreateBase): ?>
                                <a href="/bases.php" class="wsx-linkbtn">+ Yeni base</a>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($wsStarredBases)): ?>
                            <?php // ---- Yıldızlılar kısayolu ----
                                  // user_starred_bases'ten geliyor (sol paneldeki listeyle
                                  // AYNI kaynak), yalnızca BU çalışma alanına ait olanlar.
                                  // Hiç yıldızlı yoksa bölüm HİÇ basılmaz — boş bir "Yıldızlılar"
                                  // başlığı sayfayı zenginleştirmez, seyreltir. ?>
                            <div class="wsx-fav">
                                <span class="wsx-fav-label">
                                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" fill="currentColor"/></svg>
                                    Yıldızlılar
                                </span>
                                <?php foreach ($wsStarredBases as $fb): ?>
                                    <a class="wsx-fav-chip" href="/base.php?base_id=<?php echo (int) $fb['id']; ?>">
                                        <span class="wsx-fav-chip-icon" style="background: <?php echo htmlspecialchars(bcc_base_icon_color((int) $fb['id']), ENT_QUOTES, 'UTF-8'); ?>;"><?php echo bcc_base_icon_svg(11, $fb['name']); ?></span>
                                        <?php echo htmlspecialchars($fb['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($wsBases)): ?>
                            <p class="settings-empty">
                                <strong>Bu çalışma alanında henüz base yok.</strong>
                                <?php if ($canCreateBase): ?>
                                    <span class="sp-muted">Yukarıdaki “Yeni base” ile ilkini oluşturabilirsiniz.</span>
                                <?php else: ?>
                                    <span class="sp-muted">Base oluşturmak için Owner yetkisi gerekir.</span>
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <div class="wsx-base-grid">
                                <?php foreach ($wsBases as $b):
                                    $bid = (int) $b['id'];
                                    // "Son değişiklik": cell_values.updated_at'ten TÜRETİLİYOR
                                    // (bases'te updated_at yok, DDL eklenmedi). Hiç hücre
                                    // düzenlenmemişse base'in oluşturulma tarihine düşülür —
                                    // hangisi olduğu etikette AÇIKÇA yazıyor, uydurma yok.
                                    $lastTs = $b['last_edit_at'] !== null ? $b['last_edit_at'] : $b['created_at'];
                                    $lastWord = $b['last_edit_at'] !== null ? 'düzenlendi' : 'oluşturuldu';
                                ?>
                                    <a class="wsx-base-card" href="/base.php?base_id=<?php echo $bid; ?>">
                                        <span class="wsx-base-top">
                                            <span class="wsx-base-icon" style="background: <?php echo htmlspecialchars(bcc_base_icon_color($bid), ENT_QUOTES, 'UTF-8'); ?>;"><?php echo bcc_base_icon_svg(16, $b['name']); ?></span>
                                            <?php if ((int) $b['is_starred'] > 0): ?>
                                                <span class="wsx-base-star" title="Yıldızlı">
                                                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" fill="currentColor"/></svg>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="wsx-base-name"><?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ((string) $b['description'] !== ''): ?>
                                            <span class="wsx-base-desc"><?php echo htmlspecialchars($b['description'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <span class="wsx-base-meta">
                                            <?php echo (int) $b['table_count']; ?> tablo · <?php echo number_format((int) $b['record_count'], 0, ',', '.'); ?> kayıt
                                        </span>
                                        <span class="wsx-base-foot">
                                            <span class="wsx-base-time"><?php echo htmlspecialchars(bcc_time_ago($lastTs), ENT_QUOTES, 'UTF-8'); ?> <?php echo $lastWord; ?></span>
                                            <span class="wsx-base-open">Aç →</span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php // ================= KATILIMCILAR ========================== ?>
                    <div class="settings-card">
                        <div class="wsx-collab-head" id="wsx-collab-head">
                            <h3 class="wsx-collab-title">Katılımcılar <span class="sp-count"><?php echo count($collaborators); ?></span></h3>
                        </div>

                        <?php if ($canManageMembers && !empty($wsInviteRoles)): ?>
                            <?php // ---- Hızlı davet ----
                                  // Kendi uç noktasını AÇMIYOR: api/team_member_assign.php'ye
                                  // POST ediyor — "Paylaş" modalının kullandığı AYNI uç nokta,
                                  // dolayısıyla AYNI hiyerarşi kapısı, aynı whitelist, aynı
                                  // audit kaydı. Yetkisiz kullanıcıya kutu HİÇ basılmaz. ?>
                            <div class="wsx-invite" data-ws-invite data-team-id="<?php echo $selectedTeamId; ?>">
                                <input
                                    type="email"
                                    class="wsx-invite-mail"
                                    data-ws-invite-email
                                    placeholder="E-posta ile katılımcı davet edin"
                                    aria-label="Davet edilecek e-posta"
                                    autocomplete="off"
                                >
                                <select class="wsx-invite-role" data-ws-invite-role aria-label="Rol">
                                    <?php foreach ($wsInviteRoles as $r): ?>
                                        <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$r], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="wsx-btn wsx-btn--primary" data-ws-invite-btn>Davet Et</button>
                            </div>
                            <p class="wsx-invite-note" data-ws-invite-note>Yalnızca sistemde hesabı olan kullanıcılar eklenebilir.</p>
                        <?php endif; ?>

                        <?php if (empty($collaborators)): ?>
                            <p class="settings-empty"><strong>Bu çalışma alanında katılımcı yok.</strong></p>
                        <?php else: ?>
                            <?php // Sonsuz dikey liste yerine çok sütunlu ızgara. Rol hapları
                                  // .sp-role--<rol> ile renkleniyor (owner/editor/commenter/viewer).
                                  // .ws-collab-avatar / .ws-collab-role BİLEREK kullanılmadı:
                                  // ikisi de grid/interface/team_members ile PAYLAŞILIYOR. ?>
                            <div class="wsx-collab-grid" id="wsx-collab-grid">
                                <?php foreach ($collaborators as $c): ?>
                                    <div class="wsx-member">
                                        <span class="sp-avatar"><?php echo htmlspecialchars(bcc_user_initial($c), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <div class="wsx-member-info">
                                            <div class="wsx-member-name"><?php echo htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="wsx-member-mail"><?php echo htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                        <div class="wsx-member-badges">
                                            <?php if ((int) $c['is_active'] !== 1): ?>
                                                <span class="wsx-inactive">Pasif</span>
                                            <?php endif; ?>
                                            <span class="sp-role sp-role--<?php echo htmlspecialchars($c['role'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$c['role']], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <?php // Rol değiştirme / çıkarma bu sayfada YOK — team_members.php'nin
                                              // işi (assign / remove aksiyonları orada). Bu yüzden hover
                                              // kısayolu sahte bir dropdown değil, GERÇEK sayfaya giden link.
                                              // Yalnızca Owner'a: "rolü değiştir veya çıkar" diyen bir
                                              // kısayolu, o sayfada hiçbirini yapamayacak bir role
                                              // göstermek yanıltıcı olurdu. ?>
                                        <?php if ($canManageMembers): ?>
                                        <a class="wsx-member-manage" href="/team_members.php?team_id=<?php echo $selectedTeamId; ?>" title="Rolü değiştir veya çıkar" aria-label="<?php echo htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8'); ?> — rolü değiştir veya çıkar">
                                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="4" cy="10" r="1.5" fill="currentColor"/><circle cx="10" cy="10" r="1.5" fill="currentColor"/><circle cx="16" cy="10" r="1.5" fill="currentColor"/></svg>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    </div><?php // /.wsx-body-main ?>

                    <?php // ================= SAĞ RAY ================================ ?>
                    <aside class="wsx-body-side">

                        <?php // ---- Kullanım & Limitler ----
                              // Ölçüler GERÇEK (bcc_workspace_usage). Eşikler ise
                              // BCC_WORKSPACE_SOFT_LIMITS'ten gelen GÖRSEL referanslardır ve
                              // hiçbir yerde ZORLANMAZ — kart bunu açıkça yazıyor, aksi hâlde
                              // kullanıcı var olmayan bir kotaya inanırdı. ?>
                        <div class="settings-card wsx-side-card">
                            <h3 class="wsx-side-title">Kullanım &amp; Limitler</h3>

                            <?php
                            $limits = $GLOBALS['BCC_WORKSPACE_SOFT_LIMITS'];
                            $meters = array(
                                array(
                                    'label' => 'Kayıt',
                                    'used' => $wsUsage['record_count'],
                                    'max' => $limits['records'],
                                    'text' => number_format($wsUsage['record_count'], 0, ',', '.') . ' / ' . number_format($limits['records'], 0, ',', '.'),
                                ),
                                array(
                                    'label' => 'Depolama',
                                    'used' => $wsUsage['storage_bytes'],
                                    'max' => $limits['storage_bytes'],
                                    'text' => bcc_format_bytes($wsUsage['storage_bytes']) . ' / ' . bcc_format_bytes($limits['storage_bytes']),
                                ),
                                array(
                                    'label' => 'Base',
                                    'used' => $wsUsage['base_count'],
                                    'max' => $limits['bases'],
                                    'text' => $wsUsage['base_count'] . ' / ' . $limits['bases'],
                                ),
                            );
                            foreach ($meters as $m):
                                $pct = $m['max'] > 0 ? min(100, round($m['used'] / $m['max'] * 100)) : 0;
                                // Renk eşikleri: %75 sarı, %90 kırmızı.
                                $tone = $pct >= 90 ? ' is-danger' : ($pct >= 75 ? ' is-warn' : '');
                            ?>
                                <div class="wsx-meter">
                                    <div class="wsx-meter-head">
                                        <span class="wsx-meter-label"><?php echo htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="wsx-meter-value"><?php echo htmlspecialchars($m['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="wsx-meter-track" role="img" aria-label="<?php echo htmlspecialchars($m['label'] . ': ' . $pct . '%', ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="wsx-meter-fill<?php echo $tone; ?>" style="width: <?php echo (int) $pct; ?>%;"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php // Entegrasyon durumu — slack_webhooks'taki GERÇEK aktif satır
                                  // sayısı (bkz. bcc_workspace_usage notu). ?>
                            <div class="wsx-integration">
                                <span class="wsx-integration-dot<?php echo $wsUsage['slack_webhook_count'] > 0 ? ' is-on' : ''; ?>"></span>
                                <span class="wsx-integration-text">
                                    Slack entegrasyonu:
                                    <strong><?php echo $wsUsage['slack_webhook_count'] > 0 ? 'aktif' : 'kapalı'; ?></strong>
                                    <?php if ($wsUsage['slack_webhook_count'] > 0): ?>
                                        <span class="sp-muted">(<?php echo (int) $wsUsage['slack_webhook_count']; ?> webhook)</span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <p class="wsx-limit-note">
                                Eşikler kapasite göstergesidir; sistem tarafından <strong>zorlanmaz</strong>.
                            </p>
                        </div>

                        <?php // ---- Son Hareketler ----
                              // audit_log'dan geliyor (bcc_workspace_activity). Örnek/uydurma
                              // satır YOK: kayıt yoksa boş durum basılır. ?>
                        <div class="settings-card wsx-side-card">
                            <h3 class="wsx-side-title">Son Hareketler</h3>

                            <?php if (empty($wsActivity)): ?>
                                <p class="settings-empty wsx-act-empty">
                                    <strong>Henüz hareket yok.</strong>
                                    <span class="sp-muted">Bu çalışma alanında bir değişiklik yapıldığında burada görünür.</span>
                                </p>
                            <?php else: ?>
                                <ul class="wsx-act-list">
                                    <?php foreach ($wsActivity as $a): ?>
                                        <li class="wsx-act">
                                            <span class="wsx-act-dot wsx-act-dot--<?php echo htmlspecialchars($a['kind'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></span>
                                            <span class="wsx-act-body">
                                                <span class="wsx-act-text">
                                                    <strong><?php echo htmlspecialchars($a['actor'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <?php echo htmlspecialchars($a['label'], ENT_QUOTES, 'UTF-8'); ?><?php if ($a['target'] !== null): ?>
                                                        <span class="wsx-act-target"><?php echo htmlspecialchars($a['target'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                                </span>
                                                <span class="wsx-act-time"><?php echo htmlspecialchars($a['ago'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                    </aside>
                    </div><?php // /.wsx-body ?>
                </div>

            </div>
            <script src="<?php echo bcc_asset_url('workspaces.js'); ?>" defer></script>
        <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
