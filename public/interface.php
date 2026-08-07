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

// D1 "Paylaş" popup — grid.php'deki AYNI Airtable Share paritesi (People with
// access özeti + mevcut kullanıcılar arasından hızlı atama), burada da $base
// üzerinden AYNI mantık. Kod tekrarı yok: bcc_assignable_roles() (src/auth.php).
$myRank = $GLOBALS['BCC_ROLE_RANK'][current_user_role_in_team($base['team_id'])];
$shareAssignableRoles = bcc_assignable_roles($myRank);
$shareCollaborators = bcc_fetch_all(
    'SELECT u.id, u.full_name, u.email
     FROM team_members tm
     INNER JOIN users u ON u.id = tm.user_id
     WHERE tm.team_id = :team_id AND u.is_active = 1
     ORDER BY u.full_name',
    array('team_id' => $base['team_id'])
);
$shareCollaboratorPreview = array_slice($shareCollaborators, 0, 4);
$shareCollaboratorExtraCount = count($shareCollaborators) - count($shareCollaboratorPreview);

$shareExistingIds = array_map('intval', array_column($shareCollaborators, 'id'));
$shareCandidateUsers = bcc_fetch_all('SELECT id, email, full_name FROM users WHERE is_active = 1 ORDER BY full_name');
if (!empty($shareExistingIds)) {
    $shareCandidateUsers = array_values(array_filter($shareCandidateUsers, function ($candidate) use ($shareExistingIds) {
        return !in_array((int) $candidate['id'], $shareExistingIds, true);
    }));
}

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>BCC-Core — <?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<!-- Dosya eki (attachment) rozet/küçük resim stilleri (.attachment-*) style.css'te
     tanımlı (grid.php'de de kullanılıyor) — burada da AYNI kurallar, ikinci bir
     kopya YAZILMADI. -->
<link rel="stylesheet" href="<?php echo bcc_asset_url('style.css'); ?>">
<!-- E3/E1: "Bcc-Core ▾" menüsü + Share popover, grid.php'nin .gs-table-tab-menu-*/
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
            <!-- E3 — Airtable referansı (support.airtable.com/docs/getting-started-with-airtable-interface-designer,
                 "Interface dropdown menu"): base adının yanındaki ok, "View data"
                 (temel base/grid'e döner) ve "Back to home" (hesap ana ekranı)
                 sunar. Bizde "Edit" YOK (interface builder'ımız yok). Diğer
                 gs-table-tab-menu panelleriyle AYNI <details> deseni. -->
            <details class="if-nav-menu gs-table-tab-menu" name="if-nav-menu">
                <summary class="if-nav-back" title="Menü">
                    <!-- Dashboard/Starred kartındaki AYNI ikon (bcc_base_icon_svg) +
                         AYNI base_id-tabanlı renk (bcc_base_icon_color) — ikinci bir
                         kopya YAZILMADI, src/schema.php'deki paylaşılan fonksiyonlar. -->
                    <span class="home-base-icon" style="background: <?php echo htmlspecialchars(bcc_base_icon_color($baseId), ENT_QUOTES, 'UTF-8'); ?>;"><?php echo bcc_base_icon_svg(14); ?></span>
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
        <div class="if-nav-bottom">
            <?php
            $accountMenuPrefix = 'if';
            $accountMenuUser = $user;
            require __DIR__ . '/../src/partials/account_menu.php';
            ?>
            <div class="if-nav-spacer" aria-hidden="true"></div>

            <!-- D1 — grid.php'deki AYNI Airtable Share paritesi (collab-popover-*),
                 ikinci bir kopya YOK. .if-nav-bottom içindeki konumlandırma
                 düzeltmesi (sağa açılma) interface.css'te. -->
            <details class="if-nav-collab-share gs-tool-details collab-popover-trigger" name="if-nav-share">
                <summary class="if-nav-icon-btn if-nav-collab-share-btn" aria-label="Paylaş" title="Paylaş">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="15" cy="5" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="5" cy="10" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="15" cy="15" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><path d="M6.9 8.8l6.2-2.6M6.9 11.2l6.2 2.6" stroke="#5a4a00" stroke-width="1.4"/></svg>
                    <span class="if-nav-bottom-label">Paylaş</span>
                </summary>
                <div class="collab-popover-form">
                    <div class="collab-popover-title">"<?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?>" paylaş</div>

                    <form class="collab-popover-assign" method="post" action="/team_members.php?team_id=<?php echo (int) $base['team_id']; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="assign">
                        <?php if (empty($shareCandidateUsers)): ?>
                            <p class="collab-popover-note">Eklenebilecek başka aktif kullanıcı yok.</p>
                        <?php else: ?>
                            <label class="collab-popover-field">
                                <select name="user_id" required>
                                    <option value="">Kullanıcı ara ve seç...</option>
                                    <?php foreach ($shareCandidateUsers as $cu): ?>
                                        <option value="<?php echo (int) $cu['id']; ?>">
                                            <?php echo htmlspecialchars($cu['full_name'] . ' (' . $cu['email'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="collab-popover-field collab-popover-field-role">
                                <select name="role" required>
                                    <?php foreach ($shareAssignableRoles as $r): ?>
                                        <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$r], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="btn-sm">Ekle</button>
                        <?php endif; ?>
                    </form>

                    <a class="collab-popover-people" href="/team_members.php?team_id=<?php echo (int) $base['team_id']; ?>">
                        <div class="collab-popover-avatars">
                            <?php foreach ($shareCollaboratorPreview as $c): ?>
                                <div class="ws-collab-avatar collab-popover-avatar" title="<?php echo htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bcc_user_initial($c), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endforeach; ?>
                        </div>
                        <span class="collab-popover-people-label">
                            <?php echo count($shareCollaborators); ?> kişinin erişimi var<?php echo $shareCollaboratorExtraCount > 0 ? ' (+' . (int) $shareCollaboratorExtraCount . ')' : ''; ?>
                        </span>
                    </a>
                </div>
            </details>

            <!-- E1 — eski "Paylaş" (view-link kopyalama), grid.php'nin "Bağlantı"sıyla
                 AYNI ad değişikliği — yukarıdaki YENİ collaborators "Paylaş"'ıyla
                 karışmasın diye (share-popover.js + .share-popover-form, ikinci
                 bir kopya YOK). -->
            <details class="if-nav-share gs-tool-details share-popover-trigger" name="if-nav-share">
                <summary class="if-nav-icon-btn if-nav-share-btn" aria-label="Bağlantı" title="Bağlantı">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="#5a4a00" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="#5a4a00" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="if-nav-bottom-label">Bağlantı</span>
                </summary>
                <div class="share-popover-form">
                    <div class="share-popover-label">Bağlantıyı paylaş</div>
                    <div class="share-popover-row">
                        <input type="text" class="share-popover-input" data-share-url-input readonly value="<?php echo htmlspecialchars($interfaceSelfShareUrl, ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select()">
                        <button type="button" class="btn-sm" data-share-copy-btn>Kopyala</button>
                    </div>
                    <p class="share-popover-note">Bu bağlantı yalnızca oturum açmış takım üyeleri için çalışır.</p>
                </div>
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
        </div>
    </nav>

    <main class="if-list-panel">
        <div class="if-search">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" id="if-search-input" placeholder="Ara..." aria-label="Ara" autocomplete="off">
        </div>

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
            <div class="if-detail-fields" id="if-detail-fields"></div>
        </div>
    </aside>
</div>

<!-- Bildirim panelinin (home-notif) tab/arama/mark-all-read davranışı home.js'de
     yaşıyor, ikinci bir kopya YAZILMAZ — home.js'deki TÜM diğer bloklar
     (arama popover'ı, starred listesi, kart/liste görünüm) bu sayfada
     bulunmayan elemanlara bakar ve null-check'li olduğu için no-op kalır. -->
<script src="<?php echo bcc_asset_url('dismissable-panel.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('account-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('home.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('share-popover.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('interface.js'); ?>" defer></script>
</body>
</html>
