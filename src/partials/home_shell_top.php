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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($homePageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo bcc_asset_url('favicon.svg'); ?>">
<?php if (!empty($homeIdentityMeta)): ?>
<?php // Yalnızca TEK bir base bağlamı olan sayfalarda (ör. base_tables.php).
      // Dashboard/Starred/Workspaces birçok base listeler — orada sekme
      // ikonu tek bir base'e ait olamaz, bu yüzden bu blok hiç basılmaz ve
      // yukarıdaki genel opsflow.bcccrm.com favicon'u kalır. ?>
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
        // Bir dönem burada Lucide "house" simgesi vardı; logo geri alındı.
        //
        // bcc_asset_url(): dosya değişince ?v=filemtime ile önbellek kırılır —
        // diğer varlıklarla AYNI yol.
        //
        // alt="": <a> zaten aria-label="Ana sayfa" taşıyor. Görsele ayrıca
        // alt metni verilseydi ekran okuyucu bağlantıyı İKİ KEZ okurdu.
        // width/height öznitelikleri (logonun doğal 94x44'ü) yükleme sırasında
        // yer kaymasını (CLS) önler; görünen ölçü CSS'ten gelir.
        ?>
        <a href="/dashboard.php" class="home-logo" title="Ana sayfa" aria-label="Ana sayfa">
            <?php
            // ⚠️ ARTIK ORTAK PARTIAL: markup buraya KOPYALANMIŞTI ve beş auth
            // sayfasının kullandığı src/partials/brand_logo.php ile ikinci bir
            // uygulama oluşturuyordu — logo dosyası ya da width/height oranı
            // değişse biri güncellenip diğeri unutulurdu.
            //
            // Görünen ölçü CSS'ten (.home-logo img { height: 24px }); buradaki
            // 44 yalnızca partial'ın DOĞRU EN/BOY oranını (94x44) üretmesi
            // içindir — öznitelikler CLS'i önlemek üzere basılıyor.
            //
            // alt: partial marka adını yazıyor. Bu bağlamda ekran okuyucuya
            // çift okuma OLMAZ, çünkü bağlantının aria-label'ı ("Ana sayfa")
            // içeriğin yerine geçer.
            $brandLogoClass = 'home-logo-mark';
            $brandLogoHeight = 44;
            require __DIR__ . '/brand_logo.php';
            ?>
        </a>
    </div>

    <div class="home-topbar-center">
        <?php // Arama markup'ı ORTAK partial'da — grid.php de AYNI dosyayı
              // include eder, ikinci bir kopya YOK (bkz. global_search.php). ?>
        <?php require __DIR__ . '/global_search.php'; ?>
    </div>

    <div class="home-topbar-right">
        <?php
        // Çevrimiçi rozeti. Bu kabuk dashboard/starred/workspaces/admin/
        // base_tables/form_edit tarafından paylaşıldığı için tek ekleme tüm ana
        // sayfalara düşüyor. Bildirim zilinin SOLUNDA: okuma sırası "durum
        // bilgisi -> eylemler" olsun (rozet tıklanabilir bir öge değil).
        $bccOnlineCount = bcc_online_user_count();
        ?>
        <span class="home-online-badge"
              title="Son <?php echo (int) BCC_PRESENCE_WINDOW_MINUTES; ?> dakika içinde etkin olan kullanıcı sayısı">
            <?php // Nokta salt dekoratif — ekran okuyucu okumasın, sayı ve
                  // "çevrimiçi" kelimesi zaten metin olarak orada. ?>
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
            <?php
            // Yıldızlar ÇALIŞMA ALANINA göre gruplanır: ekip adı üstte, o ekibin
            // yıldızlı base'leri altında. Birden çok takımda üye olan (ve tüm
            // takımları gören platform yöneticisi) için düz liste hangi base'in
            // nereye ait olduğunu söylemiyordu — kart ızgarası bunu zaten
            // grup başlıklarıyla yapıyor, sol panel artık onunla tutarlı.
            // Grup kabı ve #home-starred-list id'si KORUNDU: home.js yıldız
            // toggle'ında bu id'yi arıyor (ve grubu kendisi kuruyor).
            ?>
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
            // ---- Katılımcılar -------------------------------------------------
            // NEDEN EKLENDİ (kullanıcı bildirdi): katılımcı yönetimine giden TÜM
            // yollar bir bağlamın içinde gömülüydü — tabloya gir + "Paylaş", ya da
            // Çalışma Alanları > alan seç > "Katılımcıları yönet". Giriş yapılan
            // ilk ekranda (Ana Sayfa) hiçbir giriş yoktu; en zengin ekran olan
            // team_members.php ise yalnızca modalin içindeki bir bağlantıdan
            // erişilebiliyordu.
            //
            // YALNIZCA YETKİLİYE BASILIR: üye yönetebildiği (bcc_can_manage_members)
            // en az bir çalışma alanı yoksa bu blok hiç render edilmez — CSS ile
            // gizlenmiş bir menü DEĞİL (bu dosyadaki diğer "sunucu tarafı gate"
            // kararlarıyla aynı). Asıl kapı yine team_members.php'nin kendi
            // require_role() çağrısıdır.
            //
            // Liste $teams'ten SÜZÜLÜR, yeni sorgu AÇILMAZ:
            // bcc_teams_for_current_user() statik önbellekli ve bu istekte
            // sayfanın kendisi tarafından zaten çağrılmış oluyor.
            $manageableTeams = array();
            foreach (bcc_teams_for_current_user() as $mt) {
                if (bcc_can_manage_members($mt['role'])) {
                    $manageableTeams[] = $mt;
                }
            }
            ?>
            <?php if (count($manageableTeams) === 1): ?>
                <?php // Tek alan: araya seçim adımı koymak anlamsız, doğrudan gider. ?>
                <a href="/team_members.php?team_id=<?php echo (int) $manageableTeams[0]['id']; ?>" class="home-sidenav-item<?php echo $homeActiveNav === 'members' ? ' is-active' : ''; ?>">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="8" cy="7" r="2.8" stroke="currentColor" stroke-width="1.4"/><path d="M3 16c0-2.5 2.2-4 5-4s5 1.5 5 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M14.5 7.5h3M16 6v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span>Katılımcılar</span>
                </a>
            <?php elseif (count($manageableTeams) > 1): ?>
                <?php // Birden çok alan: hangisinin katılımcıları sorusu KAÇINILMAZ.
                      // Yıldızlı base'lerin alt listesiyle AYNI desen (grup başlığı
                      // + altında satırlar) — ikinci bir menü mekanizması YOK. ?>
                <div class="home-sidenav-item home-sidenav-label">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><circle cx="8" cy="7" r="2.8" stroke="currentColor" stroke-width="1.4"/><path d="M3 16c0-2.5 2.2-4 5-4s5 1.5 5 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M14.5 7.5h3M16 6v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span>Katılımcılar</span>
                </div>
                <div class="home-starred-list" data-members-list>
                    <?php
                    // ARAMA KUTUSU EŞİĞİ: 6 alan. Platform yöneticisi TÜM ekipleri
                    // gördüğü için bu liste onlarca satır olabiliyor (kullanıcı
                    // bildirdi: ekranda 10 ekip). Az sayıda alanı olan kullanıcıda
                    // ise kutu yalnızca gürültü olurdu — göz zaten 3-4 satırı
                    // taramaktan hızlı. Aynı "sayıya göre arayüz" kararı Home'un
                    // çalışma alanına göre gruplama eşiğinde de var (> 1 alan).
                    //
                    // Filtreleme TAMAMEN istemcide: liste zaten DOM'da, ikinci bir
                    // sorgu/istek yok (bildirim panelindeki arama ile AYNI desen).
                    ?>
                    <?php if (count($manageableTeams) >= 6): ?>
                        <div class="home-notif-search home-members-search">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            <input type="text" data-members-search placeholder="Çalışma alanı ara" autocomplete="off" aria-label="Çalışma alanı ara">
                        </div>
                    <?php endif; ?>
                    <?php foreach ($manageableTeams as $mt): ?>
                        <?php // data-members-name: küçük harfe indirgenmiş ad —
                              // eşleştirme her tuşta yeniden lower() çağırmasın
                              // (bildirim aramasındaki data-notif-text ile AYNI). ?>
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
