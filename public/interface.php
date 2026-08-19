<?php
// F3 — Duyuru (Interface / yayınlanmış görünüm), GEREKSINIMLER.md. Salt-okunur:
// F4 "Temsilci görünümü" bu SAME sayfayı düzenleme hakkı olmadan görür — burada
// editor/viewer ayrımı YOK, hiçbir mutasyon (cell_update.php vb.) çağrılmaz.

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

$baseId = isset($_GET['base_id']) ? (int) $_GET['base_id'] : 0;
$base = find_base_or_404($baseId);

// KVKK ekip izolasyonu: base.php ile AYNI zincir (base -> team_id -> require_team_access).
require_team_access($base['team_id']);

$tables = bcc_list_base_tables($baseId);

$requestedTableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$tableId = 0;
foreach ($tables as $t) {
    if ((int) $t['id'] === $requestedTableId) {
        $tableId = $requestedTableId;
        break;
    }
}
if ($tableId === 0 && !empty($tables)) {
    $tableId = (int) $tables[0]['id'];
}

// Sekme başlığı/favicon için aktif tablonun adı (bkz. bcc_page_title).
// Yukarıdaki çözümleme $tableId'yi kesinleştirdikten SONRA aranır — istenen
// tablo bu base'e ait değilse ilk tabloya düşülüyor, başlık da o tabloyu
// göstermeli. Base'de hiç tablo yoksa boş kalır ve başlık yalnız base adını
// taşır.
$activeTableName = '';
foreach ($tables as $t) {
    if ((int) $t['id'] === $tableId) {
        $activeTableName = $t['name'];
        break;
    }
}

$fields = array();
$primaryFieldId = null;
$summaryField = null;
$records = array();
$cellsByRecord = array();
$attachmentsByRecord = array();
$usersById = array();

if ($tableId) {
    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options, position FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array('table_id' => $tableId)
    );
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
    $summaryField = bcc_interface_summary_field($fields);
    $summaryFieldId = $summaryField ? (int) $summaryField['id'] : null;

    $usersById = bcc_team_users_by_id($base['team_id']);

    $records = bcc_interface_fetch_records($tableId, $primaryFieldId, $summaryFieldId, null);
    $cellsByRecord = bcc_fetch_cells_by_record(array_column($records, 'id'));
    $attachmentsByRecord = bcc_fetch_attachments_by_record(array_column($records, 'id'));
}

// E1 — grid.php'deki D2/D3 "Share" popover'ıyla AYNI mekanizma/URL deseni
// (src/slack.php'nin scheme+HTTP_HOST kullanımıyla da tutarlı) — bu sayfanın
// kendi linkini gösterir, DDL/oturumsuz erişim YOK.
$bccShareScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$bccShareHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$interfaceSelfShareUrl = $bccShareScheme . '://' . $bccShareHost . '/interface.php?base_id=' . (int) $baseId . '&table_id=' . (int) $tableId;

// D1 "Paylaş" — grid.php ile BİREBİR AYNI bileşen.
//
// ⚠️ ÖNCEKİ HÂLİ SAYFADAN ÇIKIYORDU: bu blok kendi katılımcı/aday
// sorgularını yazıyor, popover da bir <form action="/team_members.php">
// (tam sayfa POST) ve bir <a href="/team_members.php"> taşıyordu — yani
// "Paylaş"a tıklayan kullanıcı Duyuru ekranını TERK EDİYORDU. grid.php aynı
// yolu daha önce bırakmıştı; burası geride kalmıştı.
//
// Artık TEK kaynak: bcc_share_modal_payload() + src/partials/share_modal.php
// + assets/share-modal.js. Kendi sorguları SİLİNDİ (ikinci bir hesap yok);
// davet/rol değiştirme/çıkarma işleri modalın AJAX uçnoktalarından geçiyor
// (api/team_member_assign.php, api/team_member_remove.php).
require_once __DIR__ . '/../src/share_modal_payload.php';

$shareRole = current_user_role_in_team($base['team_id']);
$canManageMembers = bcc_can_manage_members($shareRole);

// Temsilci not inceleme takibi (record_view_log — api/note_view_start.php,
// note_view_end.php, note_view_list.php). $shareRole YUKARIDA zaten
// hesaplandı; rol için İKİNCİ bir sorgu YAPILMAZ.
//
// İki AYRI soru, iki AYRI fonksiyon (bkz. src/auth.php'deki notlar):
//   $trackNoteViews    -> "bu kişi temsilci mi" (izlenen taraf, commenter)
//   $canViewNoteAudits -> "bu kişi geçmişi görebilir mi" (izleyen taraf, owner)
// Bir kullanıcı bunların ikisine birden EVET olamaz.
$trackNoteViews = bcc_is_representative($shareRole);
$canViewNoteAudits = bcc_can_view_record_audits($shareRole);

$shareModalPayload = bcc_share_modal_payload($base['team_id'], $shareRole);
$shareModalTeamId = (int) $base['team_id'];
$shareModalTeamName = $base['name'];

$shareCollaborators = $shareModalPayload['collaborators'];
$shareCollaboratorPreview = array_slice($shareCollaborators, 0, 4);
$shareCollaboratorExtraCount = count($shareCollaborators) - count($shareCollaboratorPreview);

// Davet kutusunun <datalist> önerileri — grid.php'deki AYNI süzgeç: takımın
// (bekleyenler dahil) henüz üyesi OLMAYAN aktif kullanıcılar.
$shareExistingIds = array_map('intval', array_column(
    array_merge($shareModalPayload['collaborators'], $shareModalPayload['pending']),
    'id'
));
$shareModalCandidates = bcc_fetch_all('SELECT id, email, full_name FROM users WHERE is_active = 1 ORDER BY full_name');
if (!empty($shareExistingIds)) {
    $shareModalCandidates = array_values(array_filter($shareModalCandidates, function ($candidate) use ($shareExistingIds) {
        return !in_array((int) $candidate['id'], $shareExistingIds, true);
    }));
}

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars(bcc_page_title($base['name'], $activeTableName), ENT_QUOTES, 'UTF-8'); ?></title>
<?php // Yedek ikon: page-identity.js base rozetiyle DEĞİŞTİRİR (JS kapalıysa bu kalır). ?>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<?php echo bcc_page_identity_meta($base['id'], $base['name'], $activeTableName), "\n"; ?>
<script src="<?php echo bcc_asset_url('page-identity.js'); ?>" defer></script>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<!-- Dosya eki (attachment) rozet/küçük resim stilleri (.attachment-*) style.css'te
     tanımlı (grid.php'de de kullanılıyor) — burada da AYNI kurallar, ikinci bir
     kopya YAZILMADI. -->
<link rel="stylesheet" href="<?php echo bcc_asset_url('style.css'); ?>">
<!-- E3/E1: "opsflow.bcccrm.com ▾" menüsü + Share popover, grid.php'nin .gs-table-tab-menu-*/
     .share-popover-* sınıflarını kullanıyor — ikinci bir kopya YAZILMADI,
     grid-shell.css bu yüzden burada da yüklü (yalnızca .gs-*/.share-popover-*
     kapsamlı kurallar geçerli olur, grid.php'ye özgü .gs-body/.gs-rail vb.
     bu sayfada hiç eşleşmez). -->
<link rel="stylesheet" href="<?php echo bcc_asset_url('grid-shell.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('interface.css'); ?>">
<!-- Bildirim paneli + hesap menüsü partial'ları home.css'teki .home-notif-*/
     paylaşılan sınıfları kullanıyor (bkz. src/partials/notifications_panel.php
     yorumu) — grid.php'nin de aynı gerekçeyle style.css/grid-shell.css'in
     YANINDA home.css yüklemesiyle AYNI desen, ikinci bir kopya YOK. -->
<link rel="stylesheet" href="<?php echo bcc_asset_url('home.css'); ?>">
</head>
<body class="if-page">

<div class="if-shell">
    <nav class="if-nav" id="if-nav">
        <div class="if-nav-top">
            <!-- E3 — OpsFlow davranışı (docs/GEREKSINIMLER.md — arayüz tasarımcısı,
                 "Interface dropdown menu"): base adının yanındaki ok, "View data"
                 (temel base/grid'e döner) ve "Back to home" (hesap ana ekranı)
                 sunar. Bizde "Edit" YOK (interface builder'ımız yok). Diğer
                 gs-table-tab-menu panelleriyle AYNI <details> deseni. -->
            <details class="if-nav-menu gs-table-tab-menu" name="if-nav-menu">
                <summary class="if-nav-back" title="Menü">
                    <!-- Dashboard/Starred kartındaki AYNI ikon (bcc_base_icon_svg,
                         base ADINDAN türetilen kategori glifi) — src/schema.php'deki
                         paylaşılan fonksiyon, ikinci bir kopya YAZILMADI.
                         ⚠️ Satır içi `background: bcc_base_icon_color($baseId)`
                         KALDIRILDI: base id'sinden türeyen dolu/canlı renk (bu
                         base'de #06b6d4 turkuaz) amber kenar çubuğunda yabancı
                         bir leke gibi duruyordu ve satır içi olduğu için hiçbir
                         CSS kuralıyla yumuşatılamıyordu. Zemin+kenarlık artık
                         interface.css'te (.if-nav-back .home-base-icon).
                         Kategori GLİFİ değişmedi — base'ler hâlâ birbirinden
                         ayırt edilebiliyor. -->
                    <span class="home-base-icon"><?php echo bcc_base_icon_svg(14, $base['name']); ?></span>
                    <span><?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M3 4.5l3 3 3-3" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </summary>
                <div class="gs-table-tab-menu-panel">
                    <a class="gs-table-tab-menu-item" href="/base.php?base_id=<?php echo (int) $baseId; ?>">Tabloları görüntüle</a>
                    <a class="gs-table-tab-menu-item" href="/dashboard.php">Ana sayfaya dön</a>
                </div>
            </details>
        </div>

        <!-- Kaydırma yalnızca BU sarmalayıcıda — .if-nav'ın kendisinde overflow
             OLMAMALI, yoksa position:absolute açılan bildirim/hesap panelleri
             (.if-nav-bottom altında) kırpılır (aynı ders: bkz. style.css
             ".grid-wrap { overflow: auto }" yorumu, .grid-add-field-panel). -->
        <div class="if-nav-scroll">
            <!-- Daraltılmış durumda tablo isimleri (metin) yerine tek bir dekoratif
                 klasör ikonu gösterilir — CSS ile aç/kapa (bkz. interface.css). -->
            <div class="if-nav-list-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M2.5 5.5A1.5 1.5 0 014 4h3.4l1.4 1.7H16A1.5 1.5 0 0117.5 7v7.5A1.5 1.5 0 0116 16H4a1.5 1.5 0 01-1.5-1.5v-9z" stroke="#5a4a00" stroke-width="1.4" stroke-linejoin="round"/></svg>
            </div>

            <?php // Kategori başlığı: listeye derinlik verir ve daraltılmış
                  // hâlde (metin gizlenirken) klasör ikonuyla değişir.
                  // "TABLOLAR" — bu liste GERÇEKTEN tabloları gösteriyor
                  // (arayüz/görünüm değil), uydurma bir başlık kullanılmadı. ?>
            <p class="if-nav-group-label">Tablolar</p>

            <div class="if-nav-list">
                <?php foreach ($tables as $t): ?>
                    <a
                        href="/interface.php?base_id=<?php echo (int) $baseId; ?>&table_id=<?php echo (int) $t['id']; ?>"
                        class="if-nav-item <?php echo (int) $t['id'] === $tableId ? 'is-active' : ''; ?>"
                    >
                        <?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- E2 — sıralama tamamen CSS `order` ile sürülüyor (interface.css):
             açıkken soldan sağa Avatar—boşluk—Share—Bildirim—≪, kapalıyken
             yukarıdan aşağı Avatar—≫—Bildirim—Share (Share/Bildirim sırası
             İKİ HÂLDE FARKLI olduğu için DOM sırası değil `order` kullanılır).
             Daralt (≪) artık BURADA — önceki hâlde .if-nav-top'taydı, genişlet
             (≫) ile aynı yuvada olmadığı için "karışık" hissi veriyordu.
             Profil/bildirim: mevcut ortak partial'lar require edilir, ikinci
             bir kopya YAZILMAZ. -->
        <?php // ALT BÖLGE: TEK SATIR — avatar · boşluk · Paylaş · Bağlantı ·
              // bildirim · daralt(≪), tamamı kendi zemin rengine (#ffc11e)
              // sahip bir "footer" kabında.
              //
              // ⚠️ TARİHÇE: bu bölge bir dönem tek satırdı, "Paylaş"/"Bağlantı"
              // METİN etiketleri yüzünden 220px'e sığmıyordu ve taşan kısım
              // komşu panelin altında kayboluyordu; o yüzden "Paylaş" kendi tam
              // genişlikteki satırına alınmıştı. Şimdi tekrar tek satıra
              // dönüldü ama ESKİ HATAYA DÜŞMEDEN: satır içindeki "Paylaş" da
              // "Bağlantı" gibi İKON-YALNIZ (etiketi CSS gizliyor,
              // title/aria-label duruyor). Ölçüldü: 5 ikon + avatar = 156px,
              // kullanılabilir genişlik 190px — taşma yok (bkz.
              // scripts/_verify_interface_nav_ui.php, satır taşma kontrolü). ?>
        <div class="if-nav-bottom">

            <div class="if-nav-util-row">
            <!-- D1 — grid.php'deki AYNI OpsFlow Share davranışı (collab-popover-*),
                 ikinci bir kopya YOK. .if-nav-bottom içindeki konumlandırma
                 düzeltmesi (sağa açılma) interface.css'te. -->
            <details class="if-nav-collab-share gs-tool-details collab-popover-trigger" name="if-nav-share">
                <summary class="if-nav-icon-btn if-nav-collab-share-btn" aria-label="Paylaş" title="Paylaş">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="15" cy="5" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="5" cy="10" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="15" cy="15" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><path d="M6.9 8.8l6.2-2.6M6.9 11.2l6.2 2.6" stroke="#5a4a00" stroke-width="1.4"/></svg>
                    <span class="if-nav-bottom-label">Paylaş</span>
                </summary>
                <div class="collab-popover-form">
                    <div class="collab-popover-title">"<?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?>" paylaş</div>

                    <?php if ($canManageMembers): ?>
                        <?php // grid.php ile AYNI: tam sayfa POST eden kullanıcı
                              // seçici + rol <select>'i KALDIRILDI. Aynı iş
                              // (e-posta + rol + Davet Et) modalın davet
                              // kutusunda, sayfadan çıkmadan yapılıyor. ?>
                        <button type="button" class="collab-popover-add-btn" data-share-modal-open>Katılımcı ekle</button>
                    <?php else: ?>
                        <?php // Owner değil: ekleme yolu HİÇ basılmaz (sunucu
                              // tarafı gate, CSS ile gizlenmiş bir form değil).
                              // Katılımcı listesi görünür kalır — kimin erişimi
                              // olduğunu görmek yetki gerektirmez. ?>
                        <p class="collab-popover-note">Katılımcı eklemek için Owner yetkisi gerekir.</p>
                    <?php endif; ?>

                    <?php // ARTIK YÖNLENDİRME YOK: eskiden bu satır
                          // team_members.php'ye giden bir <a> idi ve Duyuru
                          // ekranından çıkarıyordu. Şimdi grid.php ile AYNI
                          // <button data-share-modal-open> — aynı sayfada modalı
                          // açıyor. Tam yönetim ekranı kaybolmadı: modalın
                          // altındaki "Tüm üye ayarları →" hâlâ oraya gidiyor. ?>
                    <button type="button" class="collab-popover-people" data-share-modal-open>
                        <div class="collab-popover-avatars">
                            <?php // 'name' / 'initial' anahtarları payload'da
                                  // hazırlanmış (modaldakiyle AYNI kaynak). ?>
                            <?php foreach ($shareCollaboratorPreview as $c): ?>
                                <div class="ws-collab-avatar collab-popover-avatar" title="<?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c['initial'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php // data-share-people-label: modalda biri eklenip
                              // çıkarıldığında share-modal.js bu özeti tazeliyor. ?>
                        <span class="collab-popover-people-label" data-share-people-label>
                            <?php echo count($shareCollaborators); ?> kişinin erişimi var<?php echo $shareCollaboratorExtraCount > 0 ? ' (+' . (int) $shareCollaboratorExtraCount . ')' : ''; ?>
                        </span>
                    </button>
                </div>
            </details>

            <?php // Sıralama `order` ile sürülüyor (daraltılmış hâlde satır
                  // sütuna dönüşüp sıra değiştiği için DOM sırası tek başına
                  // yetmiyor — bkz. interface.css .if-nav-util-row order'ları). ?>
            <?php
            $accountMenuPrefix = 'if';
            $accountMenuUser = $user;
            require __DIR__ . '/../src/partials/account_menu.php';
            ?>
            <div class="if-nav-spacer" aria-hidden="true"></div>

            <!-- E1 — eski "Paylaş" (view-link kopyalama), grid.php'nin "Bağlantı"sıyla
                 AYNI ad değişikliği — yukarıdaki YENİ collaborators "Paylaş"'ıyla
                 karışmasın diye (share-popover.js + .share-popover-form, ikinci
                 bir kopya YOK). -->
            <details class="if-nav-share gs-tool-details share-popover-trigger" name="if-nav-share">
                <summary class="if-nav-icon-btn if-nav-share-btn" aria-label="Bağlantı" title="Bağlantı">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="#5a4a00" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="#5a4a00" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="if-nav-bottom-label">Bağlantı</span>
                </summary>
                <?php
                // grid.php'nin iki paylaşım kutusuyla PAYLAŞILAN partial.
                $shareLinkUrl = $interfaceSelfShareUrl;
                $shareLinkLabel = 'Bağlantıyı paylaş';
                require __DIR__ . '/../src/partials/share_link_popover.php';
                ?>
            </details>
            <?php
            $notifUser = $user;
            $notifTriggerClass = 'if-nav-icon-btn';
            $notifIconSize = 16;
            $notifIconStroke = '#5a4a00';
            require __DIR__ . '/../src/partials/notifications_panel.php';
            ?>
            <button type="button" class="if-nav-icon-btn if-nav-collapse-btn" id="if-nav-collapse" aria-label="Daralt" title="Daralt">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M12.5 4.5L6 10l6.5 5.5" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 4.5L1 10l6.5 5.5" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="if-nav-icon-btn if-nav-expand-btn" id="if-nav-expand" aria-label="Genişlet" title="Genişlet" hidden>
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M7.5 4.5L14 10l-6.5 5.5" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            </div><!-- /.if-nav-util-row -->
        </div>
    </nav>

    <main class="if-list-panel">
        <div class="if-search">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" id="if-search-input" placeholder="Ara..." aria-label="Ara" autocomplete="off">
        </div>

        <?php
        // Grupla / Filtrele / Sırala — grid.php'nin panelleriyle AYNI PARAMETRE
        // ADLARINI üretirler (group_field_N, filter_field_N/filter_cond_N/
        // filter_value_N/filter_logic, sort_field_N/sort_dir_N) ve sunucuda
        // grid.php'nin kullandığı AYNI ayrıştırma + SQL fonksiyonlarına gider
        // (bkz. public/api/interface_records.php). Buradaki tek fark UYGULAMA
        // BİÇİMİ: grid.php formu GET ile gönderip sayfayı yeniliyor, burada
        // istemci aynı parametreleri fetch ile yolluyor ve mevcut satırları
        // yeniden sıralayıp gösterip gizliyor — sayfa yenilenmiyor.
        //
        // Alanlar/operatörler JS'e SUNUCUDAN veriliyor: seçenek listesi ikinci
        // kez (istemcide) tanımlanmıyor, tek kaynak BCC_FILTER_OPERATORS.
        $ifToolFields = array();
        foreach ($fields as $f) {
            $ifToolFields[] = array(
                'id' => (int) $f['id'],
                'name' => $f['name'],
                'type' => $f['field_type'],
            );
        }
        ?>
        <div class="if-tools" id="if-tools">
            <details class="if-tool" name="if-tool">
                <summary class="if-tool-btn" title="Grupla">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M3 5h14M6 10h11M9 15h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <span>Grupla</span><span class="if-tool-badge" data-tool-badge="group" hidden></span>
                </summary>
                <div class="if-tool-panel" data-tool-panel="group">
                    <div class="if-tool-rows" data-tool-rows></div>
                    <button type="button" class="if-tool-add" data-tool-add>+ Gruplama ekle</button>
                </div>
            </details>

            <details class="if-tool" name="if-tool">
                <summary class="if-tool-btn" title="Filtrele">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M3 4h14l-5.5 6.5V16l-3-1.5v-4L3 4z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    <span>Filtrele</span><span class="if-tool-badge" data-tool-badge="filter" hidden></span>
                </summary>
                <div class="if-tool-panel" data-tool-panel="filter">
                    <p class="if-tool-hint">Görmek istediklerinizi tarif edin.</p>
                    <div class="if-tool-rows" data-tool-rows></div>
                    <button type="button" class="if-tool-add" data-tool-add>+ Koşul ekle</button>
                </div>
            </details>

            <details class="if-tool" name="if-tool">
                <summary class="if-tool-btn" title="Sırala">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M6 4v12M6 16l-2.5-2.5M6 16l2.5-2.5M14 16V4M14 4l-2.5 2.5M14 4l2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Sırala</span><span class="if-tool-badge" data-tool-badge="sort" hidden></span>
                </summary>
                <div class="if-tool-panel" data-tool-panel="sort">
                    <div class="if-tool-rows" data-tool-rows></div>
                    <button type="button" class="if-tool-add" data-tool-add>+ Sıralama ekle</button>
                </div>
            </details>
        </div>

        <script>
            var BCC_IF_FIELDS = <?php echo json_encode($ifToolFields, JSON_UNESCAPED_UNICODE); ?>;
            var BCC_IF_OPERATORS = <?php echo json_encode($GLOBALS['BCC_FILTER_OPERATORS'], JSON_UNESCAPED_UNICODE); ?>;
            var BCC_IF_MAX = {
                filter: <?php echo (int) $GLOBALS['BCC_FILTER_MAX_SLOTS']; ?>,
                sort: <?php echo (int) $GLOBALS['BCC_SORT_MAX_SLOTS']; ?>,
                group: 3
            };
            // Temsilci not inceleme takibi — SUNUCUDAN gelen rol kararı.
            // interface.js rolü kendi başına ÇÖZMEZ (istemcide rol mantığı
            // kopyası olmaz); yalnızca bu bayrağa bakar. Bayrak false ise
            // hiç istek atılmaz — owner/editor/viewer gezinirken boşuna
            // trafik oluşmasın diye. Bu bir OPTİMİZASYON, güvenlik sınırı
            // DEĞİL: api/note_view_start.php aynı kontrolü sunucuda TEKRAR
            // yapar (önbellekten gelen eski bir sayfa bayrağı ezemesin).
            var BCC_IF_TRACK_VIEWS = <?php echo $trackNoteViews ? 'true' : 'false'; ?>;
            var BCC_IF_CAN_VIEW_AUDITS = <?php echo $canViewNoteAudits ? 'true' : 'false'; ?>;
        </script>

        <div class="if-record-list" id="if-record-list" data-table-id="<?php echo (int) $tableId; ?>">
            <?php if (empty($tables)): ?>
                <!-- Bulunan gerçek bug: base'de HİÇ tablo yokken (henüz yeni oluşturulmuş,
                     bkz. bases.php) bu sayfa "Bu tabloda henüz kayıt yok." diyordu —
                     yanıltıcı, çünkü ortada bakılacak bir tablo bile yok. base_tables.php'nin
                     AYNI durum için kullandığı mesajla tutarlı hale getirildi. -->
                <div class="if-empty">Bu base'de henüz tablo yok.</div>
            <?php elseif (empty($records)): ?>
                <div class="if-empty">Bu tabloda henüz kayıt yok.</div>
            <?php else: ?>
                <?php foreach ($records as $rec):
                    $recordId = (int) $rec['id'];
                    $cellsForRecord = isset($cellsByRecord[$recordId]) ? $cellsByRecord[$recordId] : array();

                    $primaryText = '';
                    if ($primaryFieldId !== null) {
                        $primaryCell = isset($cellsForRecord[$primaryFieldId]) ? $cellsForRecord[$primaryFieldId] : null;
                        $primaryText = cell_display_text($fields[0]['field_type'], $primaryCell, $usersById, $fields[0]['options']);
                    }
                    $primaryText = $primaryText !== '' ? $primaryText : '(başlıksız kayıt)';

                    $summaryPreview = '';
                    if ($summaryField) {
                        $summaryCell = isset($cellsForRecord[$summaryField['id']]) ? $cellsForRecord[$summaryField['id']] : null;
                        // long_text'in ham çıktısı sanitize edilmiş HTML — burada yalnızca
                        // TEK SATIR düz metin önizlemesi için strip_tags ile metne indirgenir,
                        // sonra (artık düz metin olduğu için) her yerdeki gibi htmlspecialchars uygulanır.
                        $summaryPreview = strip_tags(cell_display_text('long_text', $summaryCell, $usersById));
                    }

                    $detailFields = array();
                    foreach ($fields as $f) {
                        if ((int) $f['id'] === $primaryFieldId) {
                            continue;
                        }
                        // 'attachment': değer cell_values'ta değil — dosya listesi
                        // 'files' olarak ayrıca taşınır, interface.js küçük
                        // resim/rozet + indirme linki olarak render eder (grid.php'nin
                        // salt-okunur karşılığı, yükleme/silme YOK).
                        if ($f['field_type'] === 'attachment') {
                            $files = isset($attachmentsByRecord[$recordId][$f['id']]) ? $attachmentsByRecord[$recordId][$f['id']] : array();
                            $detailFields[] = array(
                                'label' => $f['name'],
                                'value' => '',
                                'is_rich' => false,
                                'field_type' => 'attachment',
                                'files' => $files,
                            );
                            continue;
                        }
                        $fCell = isset($cellsForRecord[$f['id']]) ? $cellsForRecord[$f['id']] : null;
                        $detailFields[] = array(
                            'label' => $f['name'],
                            'value' => cell_display_text($f['field_type'], $fCell, $usersById, $f['options']),
                            'is_rich' => $f['field_type'] === 'long_text',
                            'field_type' => $f['field_type'],
                            'files' => null,
                        );
                    }
                ?>
                    <button
                        type="button"
                        class="if-record-row"
                        data-record-id="<?php echo $recordId; ?>"
                        data-title="<?php echo htmlspecialchars($primaryText, ENT_QUOTES, 'UTF-8'); ?>"
                        data-last-update="<?php echo htmlspecialchars(bcc_home_relative_date($rec['last_update']), ENT_QUOTES, 'UTF-8'); ?>"
                        data-detail-fields="<?php echo htmlspecialchars(json_encode($detailFields, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                    >
                        <div class="if-record-title"><?php echo htmlspecialchars($primaryText, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($summaryPreview !== ''): ?>
                            <div class="if-record-summary"><?php echo htmlspecialchars($summaryPreview, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <div class="if-record-date"><?php echo htmlspecialchars(bcc_home_relative_date($rec['last_update']), ENT_QUOTES, 'UTF-8'); ?></div>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="if-no-results" id="if-no-results" hidden>Kayıt bulunamadı</div>
        </div>
    </main>

    <aside class="if-detail-panel" id="if-detail-panel">
        <div class="if-detail-placeholder" id="if-detail-placeholder">Bir kayıt seçin</div>
        <div class="if-detail-content" id="if-detail-content" hidden>
            <!-- E4 — grid.php'nin satır detay panelindeki ▲▼ (grid-row-detail.js)
                 ile AYNI mantık/SVG'ler (style.css'teki .grid-detail-nav*
                 sınıfları burada da yüklü, ikinci bir kopya YAZILMADI) — yalnızca
                 arama gerçekten satır gizlediği için (grid.php'de gizlemiyor)
                 interface.js kendi "görünür satırlar" listesini kullanır. -->
            <div class="if-detail-header">
                <div class="grid-detail-nav">
                    <button type="button" class="grid-detail-nav-btn" id="if-detail-prev" aria-label="Önceki kayıt">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 7.2L6 3.8l3.5 3.4" stroke="#5f6368" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button type="button" class="grid-detail-nav-btn" id="if-detail-next" aria-label="Sonraki kayıt">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 4.8L6 8.2l3.5-3.4" stroke="#5f6368" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>
                <h1 class="if-detail-title" id="if-detail-title"></h1>
            </div>
            <div class="if-detail-meta">
                <span class="if-detail-meta-label">Son Güncelleme</span>
                <span id="if-detail-last-update"></span>
            </div>

            <?php // Temsilci İnceleme Geçmişi — YALNIZCA yetkili role BASILIR.
                  // CSS ile gizlenmiş bir blok DEĞİL: yetkisi olmayan kullanıcı
                  // bu HTML'i kaynağında da göremez (interface.php:249-253'teki
                  // "sunucu tarafı gate" deseniyle AYNI). Sunucu tarafı ayrıca
                  // api/note_view_list.php'de de kontrol ediyor.
                  //
                  // .if-tool ile AYNI <details> deseni (bu sayfada zaten var),
                  // ama position:absolute YOK — .if-detail-panel kaydırılabilir
                  // bir sütun, mutlak konumlu panel kırpılırdı (bkz.
                  // interface.css'teki .if-nav-scroll overflow notu). ?>
            <?php if ($canViewNoteAudits): ?>
                <details class="if-audit" id="if-audit">
                    <summary class="if-audit-summary">
                        <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.2" stroke="currentColor" stroke-width="1.4"/><path d="M10 6v4.2l2.6 1.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>Temsilci İnceleme Geçmişi</span>
                        <span class="if-audit-count" data-audit-count hidden></span>
                        <svg class="if-audit-chevron" width="10" height="10" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 4.5l3 3 3-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="if-audit-panel">
                        <p class="if-audit-note">Son 15 gün gösterilir, en yeni inceleme en üstte.</p>
                        <div class="if-audit-list" data-audit-list></div>
                        <p class="if-audit-empty" data-audit-empty hidden>Bu notu henüz inceleyen temsilci yok.</p>
                        <p class="if-audit-error" data-audit-error hidden>Geçmiş yüklenemedi.</p>
                    </div>
                </details>
            <?php endif; ?>

            <div class="if-detail-fields" id="if-detail-fields"></div>
        </div>
    </aside>
</div>

<!-- Bildirim panelinin (home-notif) tab/arama/mark-all-read davranışı home.js'de
     yaşıyor, ikinci bir kopya YAZILMAZ — home.js'deki TÜM diğer bloklar
     (arama popover'ı, starred listesi, kart/liste görünüm) bu sayfada
     bulunmayan elemanlara bakar ve null-check'li olduğu için no-op kalır. -->
<?php // "Paylaş" modalı — grid.php ile BİREBİR AYNI partial ve AYNI JS.
      // Overlay .gs-* sınıflarını kullanıyor; bu sayfa grid-shell.css'i zaten
      // yüklüyor (bkz. <head>'deki not), ek bir stil dosyası GEREKMEDİ. ?>
<?php require __DIR__ . '/../src/partials/share_modal.php'; ?>
<script src="<?php echo bcc_asset_url('dismissable-panel.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('account-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('home.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('share-popover.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('share-modal.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('interface.js'); ?>" defer></script>
</body>
</html>
