<?php
// Home (dashboard.php), Starred (starred.php) ve Workspaces (workspaces.php)
// sayfalarının ORTAK kabuğu: <!doctype>, üst bar (global arama popover +
// hesap menüsü), sol panel (nav + yıldızlı base'ler listesi), <main
// class="home-main"> açılışı. Sayfaya özgü içerik (başlık, araç çubuğu, base
// grid) bu include'dan SONRA yazılır; kapanış src/partials/home_shell_bottom.php'de.
//
// Beklenen değişkenler (include eden sayfa ayarlar):
//   $user           - current_user() dizisi
//   $starredBases   - (OPSİYONEL, artık kabuk kendi doldurur) array,
//                     [['id'=>.., 'name'=>..], ...]. Ayarlanmazsa aşağıda
//                     bcc_starred_bases_for_current_user()'dan gelir; yalnızca
//                     listeyi BİLEREK değiştiren sayfalar (starred.php kendi
//                     grid sorgusunu yeniden kullanır) elle ayarlar.
//   $homeActiveNav  - 'home' | 'starred' | 'workspaces'
//   $homePageTitle  - <title> metni
//   $homeIdentityMeta - (opsiyonel) bcc_page_identity_meta() çıktısı. YALNIZCA
//                     tek bir base bağlamı olan sayfalar ayarlar (base_tables.php);
//                     ayarlanırsa sekme ikonu o base'in rozetine döner
//                     (assets/page-identity.js). Çok base listeleyen sayfalar
//                     (dashboard/starred/workspaces) ayarlamaz.
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
// Yıldızlı base listesi ARTIK KABUĞUN KENDİ SORUMLULUĞU.
//
// Bulunan gerçek bug: bu liste onbir sayfada tek tek kopyalanmış bir sorgu
// bloğuyla dolduruluyordu ve form_edit.php bloğu eklemeyi UNUTMUŞTU — kabuk
// tanımsız bir değişken üzerinde foreach çalıştırdı, sol panel SESSİZCE boş
// kaldı (display_errors kapalı, ekranda uyarı yok; Apache error.log'unda
// "Undefined variable: starredBases" satırları birikti).
//
// Önceki düzeltme burada yalnızca array() varsayılanı bırakıyordu: warning
// susuyordu ama liste HÂLÂ boş kalıyordu, yani kullanıcının gördüğü hata
// aynen duruyordu. Artık kabuk veriyi KENDİSİ çekiyor (tek kaynak:
// bcc_starred_bases_for_current_user(), bkz. src/schema.php) — bir sayfanın
// "unutması" artık mümkün değil, çünkü sayfadan hiçbir şey beklenmiyor.
//
// isset() kontrolü KALIYOR: starred.php listeyi BİLEREK kendi grid sorgusundan
// besliyor (aynı veri, tek sorgu) — o sayfada ikinci bir sorgu açılmamalı.
if (!isset($starredBases) || !is_array($starredBases)) {
    $starredBases = bcc_starred_bases_for_current_user();
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars($homePageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<?php if (!empty($homeIdentityMeta)): ?>
<?php // Yalnızca TEK bir base bağlamı olan sayfalarda (ör. base_tables.php).
      // Dashboard/Starred/Workspaces birçok base listeler — orada sekme
      // ikonu tek bir base'e ait olamaz, bu yüzden bu blok hiç basılmaz ve
      // yukarıdaki genel BCC-Core favicon'u kalır. ?>
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
        <?php
        // Üst barın sol köşesi: marka logosu, "Ana sayfa" düğmesi olarak.
        // Bir dönem burada Lucide "house" simgesi vardı; logo geri alındı —
        // login/register/verify_email'de kimlik olarak zaten duran AYNI dosya
        // (assets/logo.png), ikinci bir varlık eklenmedi.
        //
        // bcc_asset_url(): dosya değişince ?v=filemtime ile önbellek kırılır —
        // diğer varlıklarla AYNI yol. (login.php/register.php/verify_email.php
        // bu logoyu hâlâ düz "/assets/logo.png" ile basıyor; oraya
        // dokunulmadı, bu işin kapsamı üst gezinme çubuğu.)
        //
        // alt="": <a> zaten aria-label="Ana sayfa" taşıyor. Görsele ayrıca
        // alt metni verilseydi ekran okuyucu bağlantıyı İKİ KEZ okurdu.
        // width/height öznitelikleri (logonun doğal 94x44'ü) yükleme sırasında
        // yer kaymasını (CLS) önler; görünen ölçü CSS'ten gelir.
        ?>
        <a href="/dashboard.php" class="home-logo" title="Ana sayfa" aria-label="Ana sayfa">
            <img src="<?php echo bcc_asset_url('logo.png'); ?>" width="94" height="44" alt="">
        </a>
    </div>

    <div class="home-topbar-center">
        <?php // Arama markup'ı ORTAK partial'da — grid.php de AYNI dosyayı
              // include eder, ikinci bir kopya YOK (bkz. global_search.php). ?>
        <?php require __DIR__ . '/global_search.php'; ?>
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
