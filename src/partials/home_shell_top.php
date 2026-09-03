<?php

if (!isset($homeActiveNav)) {
    $homeActiveNav = 'home';
}
if (!isset($homeExtraCss) || !is_array($homeExtraCss)) {
    $homeExtraCss = array();
}

if (!isset($starredBases) || !is_array($starredBases)) {
    $starredBases = bcc_starred_bases_for_current_user();
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($homePageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo bcc_asset_url('favicon.svg'); ?>">
<?php if (!empty($homeIdentityMeta)): ?>
<?php echo $homeIdentityMeta, "\n"; ?>
<script src="<?php echo bcc_asset_url('page-identity.js'); ?>" defer></script>
<?php endif; ?>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('home.css'); ?>">
<?php foreach ($homeExtraCss as $bccExtraCssFile): ?>
<link rel="stylesheet" href="<?php echo bcc_asset_url($bccExtraCssFile); ?>">
<?php endforeach; ?>
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
                <a href="/dashboard.php" class="home-logo" title="Ana sayfa" aria-label="Ana sayfa">
            <?php

            $brandLogoClass = 'home-logo-mark';
            $brandLogoHeight = 44;
            require __DIR__ . '/brand_logo.php';
            ?>
        </a>
    </div>

    <div class="home-topbar-center">
                <?php require __DIR__ . '/global_search.php'; ?>
    </div>

    <div class="home-topbar-right">
        <?php

        $bccOnlineCount = bcc_online_user_count();
        ?>
        <span class="home-online-badge"
              title="Son <?php echo (int) BCC_PRESENCE_WINDOW_MINUTES; ?> dakika içinde etkin olan kullanıcı sayısı">
                        <span class="home-online-dot" aria-hidden="true"></span>
            <span class="home-online-count"><?php echo (int) $bccOnlineCount; ?></span>
            <span class="home-online-label">çevrimiçi</span>
        </span>

        <?php
        $notifUser = $user;
        $notifTriggerClass = 'home-icon-btn';
        $notifIconSize = 19;
        $notifIconStroke = '#5f6368';
        require __DIR__ . '/notifications_panel.php';
        ?>

        <?php
        $accountMenuPrefix = 'home';
        $accountMenuUser = $user;
        require __DIR__ . '/account_menu.php';
        ?>
    </div>
</header>

<div class="home-body">
    <aside class="home-sidebar" id="home-sidebar">
        <nav class="home-sidenav">
            <a href="/dashboard.php" class="home-sidenav-item<?php echo $homeActiveNav === 'home' ? ' is-active' : ''; ?>">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M3 9.5L10 3l7 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 8.5V17h10V8.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                <span>Ana Sayfa</span>
            </a>
            <a href="/starred.php" class="home-sidenav-item<?php echo $homeActiveNav === 'starred' ? ' is-active' : ''; ?>">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                <span>Yıldızlılar</span>
            </a>
                        <div class="home-starred-list" id="home-starred-list">
                <?php foreach (bcc_group_starred_bases_by_team($starredBases) as $sg): ?>
                    <div class="home-starred-group" data-starred-team-id="<?php echo (int) $sg['team_id']; ?>">
                        <div class="home-starred-team" title="<?php echo htmlspecialchars($sg['team_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sg['team_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php foreach ($sg['bases'] as $sb): ?>
                            <a href="/base.php?base_id=<?php echo (int) $sb['id']; ?>" class="home-sidenav-item home-starred-item" data-starred-base-id="<?php echo (int) $sb['id']; ?>">
                                <span class="home-starred-item-dot"></span>
                                <span class="home-starred-item-name"><?php echo htmlspecialchars($sb['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <a href="/workspaces.php" class="home-sidenav-item<?php echo $homeActiveNav === 'workspaces' ? ' is-active' : ''; ?>">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                <span>Çalışma Alanları</span>
            </a>

            <?php

            $manageableTeams = array();
            foreach (bcc_teams_for_current_user() as $mt) {
                if (bcc_can_manage_members($mt['role'])) {
                    $manageableTeams[] = $mt;
                }
            }
            ?>
            <?php if (count($manageableTeams) === 1): ?>
                                <a href="/team_members.php?team_id=<?php echo (int) $manageableTeams[0]['id']; ?>" class="home-sidenav-item<?php echo $homeActiveNav === 'members' ? ' is-active' : ''; ?>">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="8" cy="7" r="2.8" stroke="currentColor" stroke-width="1.4"/><path d="M3 16c0-2.5 2.2-4 5-4s5 1.5 5 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M14.5 7.5h3M16 6v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span>Katılımcılar</span>
                </a>
            <?php elseif (count($manageableTeams) > 1): ?>
                                <div class="home-sidenav-item home-sidenav-label">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="8" cy="7" r="2.8" stroke="currentColor" stroke-width="1.4"/><path d="M3 16c0-2.5 2.2-4 5-4s5 1.5 5 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M14.5 7.5h3M16 6v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span>Katılımcılar</span>
                </div>
                <div class="home-starred-list" data-members-list>
                                        <?php if (count($manageableTeams) >= 6): ?>
                        <div class="home-notif-search home-members-search">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            <input type="text" data-members-search placeholder="Çalışma alanı ara" autocomplete="off" aria-label="Çalışma alanı ara">
                        </div>
                    <?php endif; ?>
                    <?php foreach ($manageableTeams as $mt): ?>
                                                <a
                            href="/team_members.php?team_id=<?php echo (int) $mt['id']; ?>"
                            class="home-sidenav-item home-starred-item"
                            title="<?php echo htmlspecialchars($mt['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-members-name="<?php echo htmlspecialchars(mb_strtolower($mt['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                        >
                            <span class="home-starred-item-dot"></span>
                            <span class="home-starred-item-name"><?php echo htmlspecialchars($mt['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <div class="home-members-empty" data-members-empty hidden>Sonuç yok</div>
                </div>
            <?php endif; ?>
        </nav>
    </aside>

    <main class="home-main">
