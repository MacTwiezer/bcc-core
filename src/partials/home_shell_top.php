<?php
// Home (dashboard.php), Starred (starred.php) ve Workspaces (workspaces.php)
// sayfalarının ORTAK kabuğu: <!doctype>, üst bar (global arama popover +
// hesap menüsü), sol panel (nav + yıldızlı base'ler listesi), <main
// class="home-main"> açılışı. Sayfaya özgü içerik (başlık, araç çubuğu, base
// grid) bu include'dan SONRA yazılır; kapanış src/partials/home_shell_bottom.php'de.
//
// Beklenen değişkenler (include eden sayfa ayarlar):
//   $user           - current_user() dizisi
//   $starredBases   - array, [['id'=>.., 'name'=>..], ...] (team-scoped, alfabetik)
//   $homeActiveNav  - 'home' | 'starred' | 'workspaces'
//   $homePageTitle  - <title> metni
//   $homeExtraCss   - (opsiyonel) sayfaya ÖZEL stylesheet adları, ör.
//                     array('table-fields.css'). Tanımsızsa hiçbir şey basılmaz;
//                     bu kabuğu paylaşan diğer sayfalar etkilenmez. Sayfaya özel
//                     CSS'i <body> içine <link> ile koymak yerine buraya
//                     alınıyor: gövdedeki stylesheet, kart/tablo yeniden
//                     boyanırken kısa bir FOUC üretir.

if (!isset($homeActiveNav)) {
    $homeActiveNav = 'home';
}
if (!isset($homeExtraCss) || !is_array($homeExtraCss)) {
    $homeExtraCss = array();
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars($homePageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
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
        <a href="/dashboard.php" class="home-logo"><img src="/assets/logo.png" alt="BCC-Core"></a>
    </div>

    <div class="home-topbar-center">
        <details class="home-search" id="home-search">
            <summary class="home-search-trigger">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="#8a8a8e" stroke-width="1.4"/><path d="M11 11l3.5 3.5" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
                <span class="home-search-trigger-label">Ara...</span>
                <span class="home-search-kbd">Ctrl K</span>
            </summary>
            <div class="home-search-overlay"></div>
            <div class="home-search-popover">
                <div class="home-search-popover-inputwrap">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="#8a8a8e" stroke-width="1.4"/><path d="M11 11l3.5 3.5" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <input type="text" id="home-search-input" placeholder="Ara..." aria-label="Ara" autocomplete="off">
                </div>
                <div class="home-search-results" id="home-search-results"></div>
                <div class="home-search-empty" id="home-search-empty" hidden>Aramanızla eşleşen base bulunamadı.</div>
                <div class="home-search-hint">Aramak için istediğiniz zaman Ctrl K'ya basın</div>
            </div>
        </details>
    </div>

    <div class="home-topbar-right">
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
                <?php foreach ($starredBases as $sb): ?>
                    <a href="/base.php?base_id=<?php echo (int) $sb['id']; ?>" class="home-sidenav-item home-starred-item" data-starred-base-id="<?php echo (int) $sb['id']; ?>">
                        <span class="home-starred-item-dot"></span>
                        <span class="home-starred-item-name"><?php echo htmlspecialchars($sb['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <a href="/workspaces.php" class="home-sidenav-item<?php echo $homeActiveNav === 'workspaces' ? ' is-active' : ''; ?>">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="4" width="15" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2.5 8h15" stroke="currentColor" stroke-width="1.4"/></svg>
                <span>Çalışma Alanları</span>
            </a>
        </nav>
    </aside>

    <main class="home-main">
