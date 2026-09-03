<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

$teams = bcc_teams_for_current_user();

$roleByTeamId = array();
foreach ($teams as $t) {
    $roleByTeamId[(int) $t['id']] = $t['role'];
}

$creatableTeams = array();
foreach ($teams as $t) {
    if (bcc_can_manage_bases($t['role'])) {
        $creatableTeams[] = $t;
    }
}
$canCreateBase = !empty($creatableTeams);

$timeframeConditions = array(
    'today' => 'al.last_opened >= CURDATE()',
    '7days' => 'al.last_opened >= (NOW() - INTERVAL 7 DAY)',
    '30days' => 'al.last_opened >= (NOW() - INTERVAL 30 DAY)',
    'anytime' => null,
);
$timeframeButtonLabels = array(
    'today' => 'Bugün açıldı',
    '7days' => 'Son 7 günde açıldı',
    '30days' => 'Son 30 günde açıldı',
    'anytime' => 'Herhangi bir zamanda açıldı',
);
$timeframeOptionLabels = array(
    'today' => 'Bugün',
    '7days' => 'Son 7 gün',
    '30days' => 'Son 30 gün',
    'anytime' => 'Herhangi bir zaman',
);
$timeframe = (isset($_GET['timeframe']) && array_key_exists($_GET['timeframe'], $timeframeConditions)) ? $_GET['timeframe'] : 'anytime';

$bases = array();
$teamIds = array();
if (!empty($teams)) {
    foreach ($teams as $t) {
        $teamIds[] = (int) $t['id'];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $sql = "SELECT b.id, b.team_id, b.name, b.description, b.icon, b.icon_color, b.created_at, al.last_opened
            FROM bases b
            LEFT JOIN (
                SELECT entity_id, MAX(created_at) AS last_opened
                FROM audit_log
                WHERE action = 'base.open' AND entity_type = 'base'
                GROUP BY entity_id
            ) al ON al.entity_id = b.id
            WHERE b.team_id IN ($placeholders) AND b.deleted_at IS NULL";

    if ($timeframeConditions[$timeframe] !== null) {
        $sql .= ' AND ' . $timeframeConditions[$timeframe];
    }

    $sql .= ' ORDER BY b.name';

    $bases = bcc_fetch_all($sql, $teamIds);
}

$starredBases = bcc_starred_bases_for_current_user();
$starredBaseIds = bcc_starred_base_ids_for_current_user();

$baseTableCounts = bcc_base_table_counts(array_column($bases, 'id'));

$teamNamesById = array();
foreach ($teams as $t) {
    $teamNamesById[(int) $t['id']] = $t['name'];
}

$homeActiveNav = 'home';
$homePageTitle = bcc_tab_title('Ana Sayfa');
$homeExtraCss = array('home-bento.css');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1>Ana Sayfa</h1>
        </div>

        <div class="home-toolbar">
            <details class="home-filter" id="home-filter">
                <summary class="home-filter-btn">
                    <span><?php echo htmlspecialchars($timeframeButtonLabels[$timeframe], ENT_QUOTES, 'UTF-8'); ?></span>
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 4.5l3.5 3 3.5-3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </summary>
                <ul class="home-filter-menu">
                    <?php foreach ($timeframeOptionLabels as $tfKey => $tfLabel): ?>
                        <li>
                            <a
                                href="/dashboard.php?timeframe=<?php echo urlencode($tfKey); ?>"
                                class="<?php echo $tfKey === $timeframe ? 'is-selected' : ''; ?>"
                            >
                                <span class="home-filter-check">
                                    <?php if ($tfKey === $timeframe): ?>
                                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6.5l2.5 2.5L10 3" stroke="#1a56db" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?php endif; ?>
                                </span>
                                <?php echo htmlspecialchars($tfLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <div class="home-view-toggle">
                <button type="button" class="home-icon-btn" data-view-mode-btn="list" aria-label="Liste görünümü" aria-pressed="false">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 5.5h12M4 10h12M4 14.5h12" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                </button>
                <button type="button" class="home-icon-btn" data-view-mode-btn="card" aria-label="Kart görünümü" aria-pressed="true">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="11" y="3" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="3" y="11" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="11" y="11" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/></svg>
                </button>
            </div>
        </div>

        <?php
        $emptyMessage = empty($teams)
            ? 'Hesabınız etkin ama henüz bir ekibe eklenmediniz. Bir yöneticinin sizi bir ekibe eklemesini bekleyin.'
            : 'Henüz erişebileceğiniz bir base yok.';
        ?>

        <?php
        $groupByWorkspace = (count($teams) > 1);
        ?>

        <?php if (!empty($bases) && !$groupByWorkspace): ?>
        <div class="home-section-head">
            <h2 class="home-section-title">Base'leriniz</h2>
            <span class="home-section-meta"><?php echo count($bases); ?> base</span>
        </div>
        <?php endif; ?>

        <?php
        bcc_render_home_base_grid($bases, $starredBaseIds, $teamNamesById, $emptyMessage, $roleByTeamId, $canCreateBase, false, $baseTableCounts, $groupByWorkspace);
        ?>

        <?php if ($canCreateBase): ?>
        <?php require __DIR__ . '/../src/partials/create_base_modal.php'; ?>
        <?php endif; ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
