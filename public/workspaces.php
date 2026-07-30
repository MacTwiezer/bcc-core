<?php
// "Workspaces" — Airtable'ın "All Workspaces" ekranının karşılığı. YENİ bir
// varlık/DDL YOK: mevcut teams/team_members üzerine kurulu (bkz. PROJE-DURUM.md
// analiz notu — "team" zaten workspace'in KVKK-izole edilmiş, collaborators'lı
// karşılığı). Create/Share/Settings PASİF (onaylanmış karar, yetkilendirme
// kapsam dışı).

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

    $collaborators = bcc_fetch_all(
        'SELECT u.id, u.full_name, u.email, tm.role
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
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1>Çalışma Alanları</h1>
        </div>

        <?php if (empty($teams)): ?>
            <div class="home-empty">
                <p>Henüz üyesi olduğunuz bir ekip yok.</p>
            </div>
        <?php else: ?>
            <div class="ws-grid">
                <?php foreach ($teams as $t):
                    $tid = (int) $t['id'];
                    $isActive = $tid === $selectedTeamId;
                    $baseCount = isset($baseCounts[$tid]) ? $baseCounts[$tid] : 0;
                ?>
                    <a href="/workspaces.php?team_id=<?php echo $tid; ?>" class="ws-card<?php echo $isActive ? ' is-active' : ''; ?>">
                        <div class="ws-card-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="#fff" stroke-width="1.4"/><path d="M2.5 8h15" stroke="#fff" stroke-width="1.4"/></svg>
                        </div>
                        <div class="ws-card-name"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="ws-card-meta"><?php echo (int) $baseCount; ?> base</div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="ws-detail">
                <div class="ws-detail-header">
                    <h2 class="ws-detail-title"><?php echo htmlspecialchars($selectedTeamName, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="ws-detail-actions">
                        <button type="button" class="ws-detail-btn" disabled>Oluştur</button>
                        <button type="button" class="ws-detail-btn" disabled>Paylaş</button>
                        <button type="button" class="ws-detail-btn" disabled>Ayarlar</button>
                    </div>
                </div>

                <h3 class="ws-collab-heading">Katılımcılar (<?php echo count($collaborators); ?>)</h3>
                <div class="ws-collab-list">
                    <?php foreach ($collaborators as $c): ?>
                        <div class="ws-collab-row">
                            <div class="ws-collab-avatar"><?php echo htmlspecialchars(bcc_user_initial($c), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="ws-collab-info">
                                <div class="ws-collab-name"><?php echo htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="ws-collab-email"><?php echo htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="ws-collab-role"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$c['role']], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
