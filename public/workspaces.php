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
$teams = bcc_fetch_all(
    'SELECT t.id, t.name
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

$collaborators = array();
if ($selectedTeamId) {
    // KVKK: $teams zaten kullanıcının üyeliğiyle filtrelenmişti ama savunma
    // amaçlı ikinci bir doğrulama — projedeki her veri erişiminin ÖNÜNDE olan
    // aynı fonksiyon.
    require_team_access($selectedTeamId);

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

// Sol panelin Starred alt-listesi için — dashboard.php/starred.php ile AYNI desen.
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

                <?php // ---- SOL: çalışma alanı seçici -------------------------------- ?>
                <aside class="wsx-side">
                    <p class="wsx-side-label">Çalışma alanları (<?php echo count($teams); ?>)</p>
                    <?php foreach ($teams as $t):
                        $tid = (int) $t['id'];
                        $isActive = $tid === $selectedTeamId;
                        $baseCount = isset($baseCounts[$tid]) ? $baseCounts[$tid] : 0;
                    ?>
                        <a href="/workspaces.php?team_id=<?php echo $tid; ?>" class="wsx-card<?php echo $isActive ? ' is-active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                            <span class="wsx-card-icon">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                            </span>
                            <span class="wsx-card-body">
                                <span class="wsx-card-name"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="wsx-card-meta"><?php echo (int) $baseCount; ?> base</span>
                            </span>
                        </a>
                    <?php endforeach; ?>

                    <?php // "Yeni çalışma alanı" YALNIZCA platform adminine gösteriliyor:
                          // çalışma alanı = takım ve takım oluşturmak admin/create_team.php'nin
                          // işi (bkz. o dosyanın require_admin()'i). Herkese gösterilen bir
                          // kart, sıradan kullanıcıyı 403'e götüren ölü bir vaat olurdu. ?>
                    <?php if ((int) $user['is_admin'] === 1): ?>
                        <a href="/admin/create_team.php" class="wsx-card wsx-card-new">
                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            Yeni çalışma alanı
                        </a>
                    <?php endif; ?>
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
                                <?php // "Paylaş" birincil eylem — GERÇEKTEN çalışan tek aksiyon
                                      // buydu; etiketi ne yaptığını söyleyecek şekilde netleşti.
                                      // "Oluştur" artık ÖLÜ DEĞİL: base oluşturmanın gerçek
                                      // sayfası olan bases.php'ye gidiyor. "Ayarlar" ise HÂLÂ
                                      // devre dışı — çalışma alanı ayarları diye bir özellik
                                      // yok (bu dosyanın kendi başlık yorumundaki onaylanmış
                                      // karar). Çalışıyormuş gibi göstermek yanıltıcı olurdu. ?>
                                <a href="/team_members.php?team_id=<?php echo $selectedTeamId; ?>" class="wsx-btn wsx-btn--primary">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8" cy="7" r="2.8" stroke="currentColor" stroke-width="1.4"/><path d="M3 16c0-2.5 2.2-4 5-4s5 1.5 5 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M14.5 7.5h3M16 6v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                    Katılımcıları yönet
                                </a>
                                <a href="/bases.php" class="wsx-btn">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    Base oluştur
                                </a>
                                <button type="button" class="wsx-btn" disabled title="Çalışma alanı ayarları henüz kullanılamıyor">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 3v2m0 10v2m7-7h-2M5 10H3m11.9-4.9l-1.4 1.4M6.5 13.5l-1.4 1.4m9.8 0l-1.4-1.4M6.5 6.5L5.1 5.1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                    Ayarlar
                                </button>
                            </div>
                        </div>

                        <div class="wsx-collab-head" id="wsx-collab-head">
                            <h3 class="wsx-collab-title">Katılımcılar <span class="sp-count"><?php echo count($collaborators); ?></span></h3>
                        </div>

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
                                              // kısayolu sahte bir dropdown değil, GERÇEK sayfaya giden link. ?>
                                        <a class="wsx-member-manage" href="/team_members.php?team_id=<?php echo $selectedTeamId; ?>" title="Rolü değiştir veya çıkar" aria-label="<?php echo htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8'); ?> — rolü değiştir veya çıkar">
                                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="4" cy="10" r="1.5" fill="currentColor"/><circle cx="10" cy="10" r="1.5" fill="currentColor"/><circle cx="16" cy="10" r="1.5" fill="currentColor"/></svg>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
            <script src="<?php echo bcc_asset_url('workspaces.js'); ?>" defer></script>
        <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
