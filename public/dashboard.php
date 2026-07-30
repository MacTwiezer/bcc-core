<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

// KVKK izolasyonu: kullanıcının üye olduğu ekipler -> yalnızca o ekiplerin base'leri.
// Sorgu deseni bases.php / eski dashboard.php ile aynıdır, sadece görünüm için
// tek düz bir listeye indirgenir.
$teams = bcc_fetch_all(
    'SELECT t.id, t.name, m.role
     FROM team_members m
     INNER JOIN teams t ON t.id = m.team_id
     WHERE m.user_id = :uid
     ORDER BY t.name',
    array('uid' => $user['id'])
);

// Trash: yalnızca 'owner' rolündeki kullanıcı bir base'i silebilir/geri
// yükleyebilir (Airtable referansı) — kart "⋯" menüsündeki "Sil" öğesi bu
// haritaya bakarak gösterilir/gizlenir.
$roleByTeamId = array();
foreach ($teams as $t) {
    $roleByTeamId[(int) $t['id']] = $t['role'];
}

// Tarih filtresi: timeframe GET parametresi ASLA doğrudan SQL'e girmez —
// yalnızca aşağıdaki sabit dizinin anahtarı olarak kullanılır (whitelist);
// dizide olmayan/eksik bir değer sessizce 'anytime'a düşer. Eklenen SQL parçası
// her zaman bu dizideki 4 sabit string'den biridir, kullanıcı girdisinden
// üretilmez.
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

    // Ekip izolasyonu (team_id IN ...) her zaman ÖNCE gelir; tarih koşulu ancak
    // whitelist'ten geçerliyse (anytime hariç) buna EK olarak eklenir, onun
    // yerine geçmez. "Son açılma" kaydı olmayan (NULL) base'ler
    // today/7days/30days koşullarında otomatik elenir (NULL >= ... => NULL/false),
    // yalnızca 'anytime'da görünür.
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $sql = "SELECT b.id, b.team_id, b.name, b.description, b.created_at, al.last_opened
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

// Yıldızlı base'ler: takım üyeliği bırakılmış ama satır silinmemiş olabilir
// (user_starred_bases.base_id CASCADE yalnızca BASE silinince temizler,
// takımdan ayrılmayı değil) — bu yüzden b.team_id IN (...) ile ana base
// sorgusuyla AYNI şekilde her seferinde yeniden süzülür, DB'de saklı bir
// erişim bayrağına güvenilmez.
$starredBases = array();
$starredBaseIds = array();
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
    foreach ($starredBases as $sb) {
        $starredBaseIds[(int) $sb['id']] = true;
    }
}

// Liste görünümünün "Çalışma alanı" kolonu için — $teams zaten yukarıda çekildi,
// yeni sorgu yazılmıyor.
$teamNamesById = array();
foreach ($teams as $t) {
    $teamNamesById[(int) $t['id']] = $t['name'];
}

$homeActiveNav = 'home';
$homePageTitle = 'BCC-Core — Ana Sayfa';
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

        <?php bcc_render_home_base_grid($bases, $starredBaseIds, $teamNamesById, 'Henüz erişebileceğiniz bir base yok.', $roleByTeamId); ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
