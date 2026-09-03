<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

$baseId = isset($_GET['base_id']) ? (int) $_GET['base_id'] : 0;
$base = find_base_or_404($baseId);

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

require_once __DIR__ . '/../src/share_modal_payload.php';

$shareRole = current_user_role_in_team($base['team_id']);
$canManageMembers = bcc_can_manage_members($shareRole);

$trackNoteViews = bcc_is_representative($shareRole);
$canViewNoteAudits = bcc_can_view_record_audits($shareRole);

$shareModalPayload = bcc_share_modal_payload($base['team_id'], $shareRole);
$shareModalTeamId = (int) $base['team_id'];
$shareModalTeamName = $base['name'];

$shareCollaborators = $shareModalPayload['collaborators'];
$shareCollaboratorPreview = array_slice($shareCollaborators, 0, 4);
$shareCollaboratorExtraCount = count($shareCollaborators) - count($shareCollaboratorPreview);

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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars(bcc_page_title($base['name'], $activeTableName), ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo bcc_asset_url('favicon.svg'); ?>">
<?php echo bcc_page_identity_meta($base['id'], $base['name'], $activeTableName, isset($base['icon']) ? $base['icon'] : null, isset($base['icon_color']) ? $base['icon_color'] : null), "\n"; ?>
<script src="<?php echo bcc_asset_url('page-identity.js'); ?>" defer></script>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('style.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('grid-shell.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('interface.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('home.css'); ?>">
</head>
<body class="if-page">

<div class="if-shell">
    <nav class="if-nav" id="if-nav">
        <div class="if-nav-top">
            <details class="if-nav-menu gs-table-tab-menu" name="if-nav-menu">
                <summary class="if-nav-back" title="Menü">
                    <span class="home-base-icon"><?php echo bcc_base_icon_svg(14, $base['name'], isset($base['icon']) ? $base['icon'] : null); ?></span>
                    <span><?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M3 4.5l3 3 3-3" stroke="#5a4a00" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </summary>
                <div class="gs-table-tab-menu-panel">
                    <a class="gs-table-tab-menu-item" href="/base.php?base_id=<?php echo (int) $baseId; ?>">Tabloları görüntüle</a>
                    <a class="gs-table-tab-menu-item" href="/dashboard.php">Ana sayfaya dön</a>
                </div>
            </details>
        </div>

        <div class="if-nav-scroll">
            <div class="if-nav-list-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M2.5 5.5A1.5 1.5 0 014 4h3.4l1.4 1.7H16A1.5 1.5 0 0117.5 7v7.5A1.5 1.5 0 0116 16H4a1.5 1.5 0 01-1.5-1.5v-9z" stroke="#5a4a00" stroke-width="1.4" stroke-linejoin="round"/></svg>
            </div>

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

        <div class="if-nav-bottom">

            <div class="if-nav-util-row">
            <details class="if-nav-collab-share gs-tool-details collab-popover-trigger" name="if-nav-share">
                <summary class="if-nav-icon-btn if-nav-collab-share-btn" aria-label="Paylaş" title="Paylaş">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="15" cy="5" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="5" cy="10" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><circle cx="15" cy="15" r="2.2" stroke="#5a4a00" stroke-width="1.4"/><path d="M6.9 8.8l6.2-2.6M6.9 11.2l6.2 2.6" stroke="#5a4a00" stroke-width="1.4"/></svg>
                    <span class="if-nav-bottom-label">Paylaş</span>
                </summary>
                <?php
                $collabPopoverTitle = $base['name'];
                require __DIR__ . '/../src/partials/collab_popover_form.php';
                ?>
            </details>

            <?php
            $accountMenuPrefix = 'if';
            $accountMenuUser = $user;
            require __DIR__ . '/../src/partials/account_menu.php';
            ?>
            <div class="if-nav-spacer" aria-hidden="true"></div>

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
        </div>
    </nav>

    <main class="if-list-panel">
        <div class="if-search">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" id="if-search-input" placeholder="Ara..." aria-label="Ara" autocomplete="off">
        </div>

        <?php
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
            var BCC_IF_TRACK_VIEWS = <?php echo $trackNoteViews ? 'true' : 'false'; ?>;
            var BCC_IF_CAN_VIEW_AUDITS = <?php echo $canViewNoteAudits ? 'true' : 'false'; ?>;
        </script>

        <div class="if-record-list" id="if-record-list" data-table-id="<?php echo (int) $tableId; ?>">
            <?php if (empty($tables)): ?>
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
                        $summaryPreview = strip_tags(cell_display_text('long_text', $summaryCell, $usersById));
                    }

                    $detailFields = array();
                    foreach ($fields as $f) {
                        if ((int) $f['id'] === $primaryFieldId) {
                            continue;
                        }
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

            <?php if ($canViewNoteAudits): ?>
                <details class="if-audit" id="if-audit">
                    <summary class="if-audit-summary">
                        <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.2" stroke="currentColor" stroke-width="1.4"/><path d="M10 6v4.2l2.6 1.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>Temsilci İnceleme Geçmişi</span>
                        <span class="if-audit-count" data-audit-count hidden></span>
                        <svg class="if-audit-chevron" width="10" height="10" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 4.5l3 3 3-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="if-audit-panel">
                        <div class="if-audit-head">
                            <p class="if-audit-note">Son 15 gün gösterilir, en yeni inceleme en üstte.</p>
                            <a class="if-audit-export" data-audit-export href="#" aria-disabled="true"
                               title="Bu notun son 15 günlük inceleme kayıtlarını Excel olarak indir">
                                <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3v9m0 0l-3.2-3.2M10 12l3.2-3.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.5 13.5v2a1.5 1.5 0 001.5 1.5h10a1.5 1.5 0 001.5-1.5v-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                <span>Excel indir</span>
                            </a>
                        </div>
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

<?php require __DIR__ . '/../src/partials/share_modal.php'; ?>
<script src="<?php echo bcc_asset_url('confirm-modal.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('dismissable-panel.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('account-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('home.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('share-modal.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('interface.js'); ?>" defer></script>
</body>
</html>
