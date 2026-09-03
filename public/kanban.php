<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$table = find_table_or_404($tableId);

require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = bcc_can_edit_records($role);
$canComment = bcc_can_comment($role);

$viewId = isset($_GET['view_id']) ? (int) $_GET['view_id'] : 0;
$view = $viewId ? bcc_find_view($viewId, $table['id']) : null;

if (!$view) {
    bcc_error_page('Görünüm bulunamadı', 'Aradığınız görünüm silinmiş ya da adresi değişmiş olabilir.', 404);
}

if ($view['view_type'] !== 'kanban') {
    header('Location: ' . bcc_view_route_for($view['view_type'], $table['id'], $view['id']));
    exit;
}

$fields = bcc_fetch_all(
    'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :tid ORDER BY position, id',
    array(':tid' => $table['id'])
);
$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}
$primaryField = !empty($fields) ? $fields[0] : null;

$kanbanEligibleFields = array();
foreach ($fields as $f) {
    if (bcc_field_allowed_for_kanban($f['field_type'])) {
        $kanbanEligibleFields[] = $f;
    }
}

$kanbanConfig = bcc_kanban_config_from_view($view);
$columnFieldId = $kanbanConfig['kanban_field_id'];

if ($columnFieldId > 0 && (!isset($fieldsById[$columnFieldId])
    || !bcc_field_allowed_for_kanban($fieldsById[$columnFieldId]['field_type']))) {
    $columnFieldId = 0;
}

$columnField = $columnFieldId > 0 ? $fieldsById[$columnFieldId] : null;

$cardFields = array();
foreach ($kanbanConfig['kanban_card_fields'] as $fid) {
    if (isset($fieldsById[$fid]) && $fid !== $columnFieldId
        && ($primaryField === null || $fid !== (int) $primaryField['id'])) {
        $cardFields[] = $fieldsById[$fid];
    }
}

$columns = array();
$recordCount = 0;

if ($columnField !== null) {
    $choices = select_choices_from_options($columnField['options']);
    $choiceColorMap = bcc_build_choice_color_map($choices, select_choice_colors_from_options($columnField['options']));

    $filterRules = parse_grid_filter_rules($_GET, $fieldsById);
    $filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';
    list($recordsSql, $recordsParams) = bcc_build_grid_records_query($table['id'], array(), array(), $filterRules, $filterLogic);
    $records = bcc_fetch_all($recordsSql, $recordsParams);
    $recordCount = count($records);

    $recordIds = array_column($records, 'id');
    $cellsByRecord = bcc_fetch_cells_by_record($recordIds);
    $usersById = bcc_team_users_by_id($table['team_id']);

    $UNASSIGNED = '';
    $columns[$UNASSIGNED] = array('key' => $UNASSIGNED, 'label' => 'Atanmamış', 'color' => null, 'cards' => array());
    foreach ($choices as $choice) {
        $columns[$choice] = array(
            'key' => $choice,
            'label' => $choice,
            'color' => isset($choiceColorMap[$choice]) ? $choiceColorMap[$choice] : null,
            'cards' => array(),
        );
    }

    foreach ($records as $rec) {
        $recId = (int) $rec['id'];
        $cellsForRecord = isset($cellsByRecord[$recId]) ? $cellsByRecord[$recId] : array();

        $cellRow = isset($cellsForRecord[$columnFieldId]) ? $cellsForRecord[$columnFieldId] : null;
        $rawValue = ($cellRow !== null && $cellRow['value_text'] !== null) ? (string) $cellRow['value_text'] : '';

        $staleValue = null;
        $columnKey = $UNASSIGNED;
        if ($rawValue !== '') {
            if (isset($columns[$rawValue])) {
                $columnKey = $rawValue;
            } else {
                $staleValue = $rawValue;
            }
        }

        $primaryText = '';
        if ($primaryField !== null) {
            $pCell = bcc_cell_row_for_field($primaryField['field_type'], $rec, $cellsByRecord, $primaryField['id']);
            $primaryText = cell_display_text($primaryField['field_type'], $pCell, $usersById, $primaryField['options']);
        }

        $extra = array();
        foreach ($cardFields as $cf) {
            $cfCell = bcc_cell_row_for_field($cf['field_type'], $rec, $cellsByRecord, $cf['id']);
            $extra[] = array(
                'name' => $cf['name'],
                'text' => cell_display_text($cf['field_type'], $cfCell, $usersById, $cf['options']),
            );
        }

        $columns[$columnKey]['cards'][] = array(
            'id' => $recId,
            'primary' => $primaryText,
            'extra' => $extra,
            'stale' => $staleValue,
        );
    }
}

$homeActiveNav = 'fields';
$homePageTitle = bcc_tab_title('Kanban: ' . $table['name']);
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="settings-breadcrumb">
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">&larr; <?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?> tablosuna dön</a>
            <span>·</span> <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">Alanları yönet</a>
        </div>

        <div class="home-main-header kanban-header">
            <h1><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></h1>

            <?php if ($canEdit && !empty($kanbanEligibleFields)): ?>
            <details class="gs-tool-details kanban-settings-menu" name="gs-table-tab-menu">
                <summary class="settings-btn">Sütunlama</summary>
                <div class="kanban-settings-panel" data-kanban-settings data-view-id="<?php echo (int) $view['id']; ?>">
                    <p class="settings-hint">Hangi alana göre sütunlansın?</p>
                    <?php foreach ($kanbanEligibleFields as $ef): ?>
                        <label class="kanban-settings-row">
                            <input type="radio" name="kanban_field_id" value="<?php echo (int) $ef['id']; ?>"
                                   <?php echo ((int) $ef['id'] === $columnFieldId) ? 'checked' : ''; ?>>
                            <span class="field-badge field-badge--single_select"></span>
                            <?php echo htmlspecialchars($ef['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endforeach; ?>

                    <p class="settings-hint">Kartta gösterilecek ek alanlar</p>
                    <?php foreach ($fields as $f):
                        if ($primaryField !== null && (int) $f['id'] === (int) $primaryField['id']) { continue; }
                        if ((int) $f['id'] === $columnFieldId) { continue; }
                    ?>
                        <label class="kanban-settings-row">
                            <input type="checkbox" name="kanban_card_fields" value="<?php echo (int) $f['id']; ?>"
                                   <?php echo in_array((int) $f['id'], $kanbanConfig['kanban_card_fields'], true) ? 'checked' : ''; ?>>
                            <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                            <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endforeach; ?>

                    <button type="button" class="settings-btn settings-btn-primary" data-kanban-save>Kaydet</button>
                </div>
            </details>
            <?php endif; ?>
        </div>

<?php if ($columnField === null): ?>
        <div class="settings-card kanban-empty">
            <h2>Bu Kanban henüz sütunlanamıyor</h2>
            <?php if (empty($kanbanEligibleFields)): ?>
                <p class="settings-hint">
                    Kanban, kartları bir <strong>Tekli seçim</strong> alanının seçeneklerine göre
                    sütunlara ayırır. Bu tabloda henüz Tekli seçim alanı yok.
                </p>
                <a class="settings-btn settings-btn-primary" href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">Tekli seçim alanı oluştur</a>
            <?php else: ?>
                <p class="settings-hint">
                    Sütunlama alanı seçilmemiş. Yukarıdaki <strong>Sütunlama</strong> panelinden
                    bir Tekli seçim alanı seçin.
                </p>
            <?php endif; ?>
        </div>
<?php else: ?>
        <div class="kanban-board"
             data-kanban-board
             data-view-id="<?php echo (int) $view['id']; ?>"
             data-table-id="<?php echo (int) $table['id']; ?>"
             data-column-field-id="<?php echo (int) $columnFieldId; ?>"
             data-can-edit="<?php echo $canEdit ? '1' : '0'; ?>">
            <?php foreach ($columns as $col): ?>
                <section class="kanban-column" data-kanban-column data-column-value="<?php echo htmlspecialchars($col['key'], ENT_QUOTES, 'UTF-8'); ?>">
                    <header class="kanban-column-head">
                        <?php if ($col['color'] !== null && isset($GLOBALS['BCC_CHOICE_COLORS'][$col['color']])): ?>
                            <span class="kanban-column-dot" style="background: <?php echo htmlspecialchars($GLOBALS['BCC_CHOICE_COLORS'][$col['color']], ENT_QUOTES, 'UTF-8'); ?>"></span>
                        <?php endif; ?>
                        <span class="kanban-column-title"><?php echo htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="kanban-column-count" data-kanban-count><?php echo count($col['cards']); ?></span>
                    </header>
                    <?php if (count($col['cards']) > 8): ?>
                        <div class="kanban-search">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.4"/>
                                <path d="M12.7 12.7L17 17" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                            <input type="search" data-kanban-search autocomplete="off"
                                   placeholder="Bu sütunda ara…"
                                   aria-label="<?php echo htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8'); ?> sütununda ara">
                        </div>
                    <?php endif; ?>
                    <div class="kanban-column-body" data-kanban-dropzone>
                        <p class="kanban-column-nomatch" data-kanban-nomatch hidden>Eşleşme yok</p>
                        <?php foreach ($col['cards'] as $card): ?>
                            <article class="kanban-card<?php echo $canEdit ? ' is-draggable' : ''; ?>"
                                     data-kanban-card
                                     data-record-id="<?php echo (int) $card['id']; ?>">
                                <div class="kanban-card-primary"><?php echo htmlspecialchars($card['primary'] !== '' ? $card['primary'] : '(Adsız)', ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($card['stale'] !== null): ?>
                                    <div class="kanban-card-stale" title="Bu değer alanın seçenek listesinde yok">
                                        <?php echo htmlspecialchars($card['stale'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ($card['extra'] as $ex): ?>
                                    <?php if ($ex['text'] === '') { continue; } ?>
                                    <div class="kanban-card-field">
                                        <span class="kanban-card-field-name"><?php echo htmlspecialchars($ex['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="kanban-card-field-value"><?php echo htmlspecialchars($ex['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <p class="settings-hint kanban-footer"><?php echo (int) $recordCount; ?> kayıt</p>
<?php endif; ?>

<script>
    var BCC_KANBAN_CSRF = <?php echo bcc_json_for_script(csrf_token()); ?>;
    var BCC_KANBAN_CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
</script>
<script src="<?php echo bcc_asset_url('grid-column-drag.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('kanban.js'); ?>" defer></script>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
