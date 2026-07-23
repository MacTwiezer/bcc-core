<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

// KVKK izolasyonu: kullanıcının üye olduğu ekipler -> yalnızca o ekiplerin base'leri.
// Sorgu deseni bases.php / eski dashboard.php ile aynıdır, sadece görünüm için
// tek düz bir listeye indirgenir.
$teams = bcc_fetch_all(
    'SELECT t.id, t.name
     FROM team_members m
     INNER JOIN teams t ON t.id = m.team_id
     WHERE m.user_id = :uid
     ORDER BY t.name',
    array('uid' => $user['id'])
);

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
    'today' => 'Opened today',
    '7days' => 'Opened in the past 7 days',
    '30days' => 'Opened in the past 30 days',
    'anytime' => 'Opened anytime',
);
$timeframeOptionLabels = array(
    'today' => 'Today',
    '7days' => 'In the past 7 days',
    '30days' => 'In the past 30 days',
    'anytime' => 'Anytime',
);
$timeframe = (isset($_GET['timeframe']) && array_key_exists($_GET['timeframe'], $timeframeConditions)) ? $_GET['timeframe'] : 'anytime';

$bases = array();
if (!empty($teams)) {
    $teamIds = array();
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
            WHERE b.team_id IN ($placeholders)";

    if ($timeframeConditions[$timeframe] !== null) {
        $sql .= ' AND ' . $timeframeConditions[$timeframe];
    }

    $sql .= ' ORDER BY b.name';

    $bases = bcc_fetch_all($sql, $teamIds);
}

$bccHomeIconColors = array('#2D7FF9', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444', '#06b6d4');

// Liste görünümünün "Çalışma alanı" kolonu için — $teams zaten yukarıda çekildi,
// yeni sorgu yazılmıyor.
$teamNamesById = array();
foreach ($teams as $t) {
    $teamNamesById[(int) $t['id']] = $t['name'];
}

function bcc_home_relative_date($datetimeStr)
{
    $ts = strtotime((string) $datetimeStr);
    if ($ts === false) {
        return '';
    }

    $days = intdiv(time() - $ts, 86400);

    if ($days <= 0) {
        return 'Bugün';
    }
    if ($days === 1) {
        return 'Dün';
    }
    if ($days < 30) {
        return $days . ' gün önce';
    }

    $months = intdiv($days, 30);
    if ($months < 12) {
        return $months . ' ay önce';
    }

    return intdiv($months, 12) . ' yıl önce';
}

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>BCC-Core — Home</title>
<link rel="stylesheet" href="/assets/home.css">
<script>
// Sayfa boyanmadan ÖNCE çalışır (senkron, defer değil) — localStorage'daki
// görünüm tercihini burada okuyup doğrulamak, .home-base-grid henüz DOM'da
// yokken bile <html>'e işaretleyerek liste modunda kart->liste sıçramasını
// (FOUC) önler. Bu, localStorage'ı DOĞRULAYAN tek yerdir; home.js bu kararı
// <html> sınıfından devralır, tekrar okumaz/doğrulamaz.
(function () {
    var stored = null;
    try { stored = window.localStorage.getItem('bcc_home_view_mode'); } catch (e) {}
    if (stored === 'list') {
        document.documentElement.classList.add('home-view-list');
    }
})();
</script>
</head>
<body class="home-page">

<header class="home-topbar">
    <div class="home-topbar-left">
        <button type="button" class="home-icon-btn" id="home-sidebar-toggle" aria-label="Menüyü aç/kapat">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M2 5h16M2 10h16M2 15h16" stroke="#5f6368" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
        <a href="/dashboard.php" class="home-logo"><img src="/assets/bcc-logo.svg" alt="BCC-Core"></a>
    </div>

    <div class="home-topbar-center">
        <div class="home-search">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="#8a8a8e" stroke-width="1.4"/><path d="M11 11l3.5 3.5" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" placeholder="Ara..." aria-label="Ara">
            <span class="home-search-kbd">Ctrl K</span>
        </div>
    </div>

    <div class="home-topbar-right">
        <a href="#" class="home-help-link">Yardım</a>
        <button type="button" class="home-icon-btn" aria-label="Bildirimler">
            <svg width="19" height="19" viewBox="0 0 20 20" fill="none"><path d="M10 2.5c-2.4 0-4.2 1.9-4.2 4.3v2.6c0 .5-.2 1.3-.5 1.7L4.4 12.5c-.6.8-.2 1.9.8 2.2 3.3 1 6.9 1 10.2 0 .9-.3 1.3-1.4.7-2.2l-.9-1.4c-.3-.4-.5-1.2-.5-1.7V6.8c0-2.4-1.9-4.3-4.2-4.3z" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/><path d="M8.2 16.5a1.8 1.8 0 003.6 0" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
        </button>

        <?php
        $accountMenuPrefix = 'home';
        $accountMenuUser = $user;
        require __DIR__ . '/../src/partials/account_menu.php';
        ?>
    </div>
</header>

<div class="home-body">
    <aside class="home-sidebar" id="home-sidebar">
        <nav class="home-sidenav">
            <a href="/dashboard.php" class="home-sidenav-item is-active">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M3 9.5L10 3l7 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 8.5V17h10V8.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                <span>Home</span>
            </a>
            <a href="#" class="home-sidenav-item">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                <span>Starred</span>
            </a>
            <a href="#" class="home-sidenav-item">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M6 8.5a2.2 2.2 0 100-4.4 2.2 2.2 0 000 4.4zM14 8.5a2.2 2.2 0 100-4.4 2.2 2.2 0 000 4.4zM6 17.5a2.2 2.2 0 100-4.4 2.2 2.2 0 000 4.4z" stroke="currentColor" stroke-width="1.4"/><path d="M7.8 7.3l4.4-1.6M7.8 15l4.4-4.8" stroke="currentColor" stroke-width="1.3"/></svg>
                <span>Shared</span>
            </a>
            <a href="#" class="home-sidenav-item">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                <span>Workspaces</span>
            </a>
        </nav>

        <div class="home-sidenav-divider"></div>

        <nav class="home-sidenav home-sidenav-secondary">
            <a href="#" class="home-sidenav-item">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="3" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="3" y="11" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="11" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.4"/></svg>
                <span>Templates and apps</span>
            </a>
            <a href="#" class="home-sidenav-item">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M3 6l7-3.5L17 6v8l-7 3.5L3 14V6z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                <span>Marketplace</span>
            </a>
        </nav>
    </aside>

    <main class="home-main">
        <div class="home-main-header">
            <h1>Home</h1>
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

        <?php if (empty($bases)): ?>
            <div class="home-empty">
                <p>Henüz erişebileceğiniz bir base yok.</p>
            </div>
        <?php else: ?>
            <div class="home-base-grid" id="home-base-grid">
                <?php foreach ($bases as $i => $b): ?>
                    <a class="home-base-card" href="/base.php?base_id=<?php echo (int) $b['id']; ?>">
                        <div class="home-base-icon" style="background: <?php echo htmlspecialchars($bccHomeIconColors[$i % count($bccHomeIconColors)], ENT_QUOTES, 'UTF-8'); ?>;">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M2.5 6.2L10 2.5l7.5 3.7L10 9.9 2.5 6.2z" fill="#fff" fill-opacity="0.95"/><path d="M2.5 6.2V13l7.5 3.7V9.9L2.5 6.2z" fill="#fff" fill-opacity="0.7"/><path d="M17.5 6.2V13L10 16.7V9.9l7.5-3.7z" fill="#fff" fill-opacity="0.85"/></svg>
                        </div>
                        <div class="home-base-info">
                            <div class="home-base-name"><?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="home-base-meta">Açıldı: <?php echo htmlspecialchars(bcc_home_relative_date($b['created_at']), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="home-base-workspace"><?php echo htmlspecialchars(isset($teamNamesById[(int) $b['team_id']]) ? $teamNamesById[(int) $b['team_id']] : '', ENT_QUOTES, 'UTF-8'); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="/assets/account-menu.js" defer></script>
<script src="/assets/home.js" defer></script>
<script>
(function () {
    var sidebar = document.getElementById('home-sidebar');
    var sidebarToggle = document.getElementById('home-sidebar-toggle');
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.toggle('is-collapsed');
    });
})();
</script>
</body>
</html>
