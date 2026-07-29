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

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>BCC-Core — <?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<!-- Dosya eki (attachment) rozet/küçük resim stilleri (.attachment-*) style.css'te
     tanımlı (grid.php'de de kullanılıyor) — burada da AYNI kurallar, ikinci bir
     kopya YAZILMADI. -->
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/interface.css?v=<?php echo (int) @filemtime(__DIR__ . '/assets/interface.css'); ?>">
<!-- Bildirim paneli + hesap menüsü partial'ları home.css'teki .home-notif-*/
     paylaşılan sınıfları kullanıyor (bkz. src/partials/notifications_panel.php
     yorumu) — grid.php'nin de aynı gerekçeyle style.css/grid-shell.css'in
     YANINDA home.css yüklemesiyle AYNI desen, ikinci bir kopya YOK. -->
<link rel="stylesheet" href="/assets/home.css">
</head>
<body class="if-page">

<div class="if-shell">
    <nav class="if-nav" id="if-nav">
        <div class="if-nav-top">
            <a href="/base.php?base_id=<?php echo (int) $baseId; ?>" class="if-nav-back" title="Base'e dön">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M12.5 4.5L6 10l6.5 5.5" stroke="#5a4a00" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <button type="button" class="if-nav-collapse-btn" id="if-nav-collapse" aria-label="Daralt" title="Daralt">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M12.5 4.5L6 10l6.5 5.5" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 4.5L1 10l6.5 5.5" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
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

        <!-- Profil/bildirim: mevcut ortak partial'lar require edilir, ikinci bir
             kopya YAZILMAZ — yalnızca bu sayfaya özgü "if" öneki + koyu zemin
             tetikleyici stiliyle (bkz. interface.css .if-account/.if-avatar). -->
        <div class="if-nav-bottom">
            <button type="button" class="if-nav-icon-btn if-nav-share-btn" aria-label="Paylaş" title="Paylaş">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="15" cy="5" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="5" cy="10" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="15" cy="15" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><path d="M6.9 8.8l6.2-2.6M6.9 11.2l6.2 2.6" stroke="#5a4a00" stroke-width="1.4"/></svg>
                <span class="if-nav-bottom-label">Paylaş</span>
            </button>
            <?php
            $notifUser = $user;
            $notifTriggerClass = 'if-nav-icon-btn';
            $notifIconSize = 16;
            $notifIconStroke = '#5a4a00';
            require __DIR__ . '/../src/partials/notifications_panel.php';
            ?>
            <button type="button" class="if-nav-icon-btn if-nav-expand-btn" id="if-nav-expand" aria-label="Genişlet" title="Genişlet" hidden>
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M7.5 4.5L14 10l-6.5 5.5" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <?php
            $accountMenuPrefix = 'if';
            $accountMenuUser = $user;
            require __DIR__ . '/../src/partials/account_menu.php';
            ?>
        </div>
    </nav>

    <main class="if-list-panel">
        <div class="if-search">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" id="if-search-input" placeholder="Ara..." aria-label="Ara" autocomplete="off">
        </div>

        <div class="if-record-list" id="if-record-list" data-table-id="<?php echo (int) $tableId; ?>">
            <?php if (empty($records)): ?>
                <div class="if-empty">Bu tabloda henüz kayıt yok.</div>
            <?php else: ?>
                <?php foreach ($records as $rec):
                    $recordId = (int) $rec['id'];
                    $cellsForRecord = isset($cellsByRecord[$recordId]) ? $cellsByRecord[$recordId] : array();

                    $primaryText = '';
                    if ($primaryFieldId !== null) {
                        $primaryCell = isset($cellsForRecord[$primaryFieldId]) ? $cellsForRecord[$primaryFieldId] : null;
                        $primaryText = cell_display_text($fields[0]['field_type'], $primaryCell, $usersById);
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
                            'value' => cell_display_text($f['field_type'], $fCell, $usersById),
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
            <h1 class="if-detail-title" id="if-detail-title"></h1>
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
<script src="/assets/dismissable-panel.js" defer></script>
<script src="/assets/account-menu.js" defer></script>
<script src="/assets/home.js" defer></script>
<script src="/assets/interface.js?v=<?php echo (int) @filemtime(__DIR__ . '/assets/interface.js'); ?>" defer></script>
</body>
</html>
