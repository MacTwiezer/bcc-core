<?php
// Kanban görünümü (Grup View-Kanban) — oturumlu, grid ile AYNI rol modeli.
//
// NEDEN grid.php'nin İÇİNDE BİR DAL DEĞİL, AYRI SAYFA:
// Form'u ayırmanın İKİ gerekçesi vardı; Kanban'da yalnızca biri geçerli ve
// körü körüne kopyalamamak için ikisi ayrı ayrı değerlendirildi:
//   (a) grid.php 1400+ satır ve ikinci yarısının TAMAMI tablo varsayımı
//       (<table>, dondurulmuş sütunlar, satır yüksekliği, sütun sürükleme).
//       Kanban bunların HİÇBİRİNİ kullanmaz.            -> GEÇERLİ
//   (b) Anonim erişim require_role çağıran dosyada duramaz. -> GEÇERSİZ
//       (Kanban oturumlu, Form'un aksine)
// (a) tek başına yeterli: içine erken dal koymak dosyayı ~2000 satıra ve
// birbirini dışlayan iki yarıya çıkarırdı, dördüncü tür (Calendar) durumu daha
// da kötüleştirirdi.
//
// SUNUCU TARAFI PAYLAŞILIR, KOPYALANMAZ: find_table_or_404, require_team_access,
// bcc_find_view, alan çekme sorgusu, bcc_build_grid_records_query (FİLTRE ve
// soft-delete süzgeci BEDAVA gelir — ikinci bir kayıt sorgusu yolu açılmadı),
// select_choices_from_options, bcc_build_choice_color_map, cell_display_text.

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$table = find_table_or_404($tableId);

// KVKK ekip izolasyonu — grid.php ile AYNI kapı.
require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
// grid.php:14 ile BİREBİR AYNI kural — Kanban'da sürüklemek "o hücreyi
// düzenlemek" demek, yeni bir izin kavramı İCAT EDİLMEDİ.
$canEdit = in_array($role, array('editor', 'owner'), true);
$canComment = in_array($role, array('commenter', 'editor', 'owner'), true);

$viewId = isset($_GET['view_id']) ? (int) $_GET['view_id'] : 0;
$view = $viewId ? bcc_find_view($viewId, $table['id']) : null;

if (!$view) {
    http_response_code(404);
    die('Görünüm bulunamadı.');
}

// Bu sayfa YALNIZCA kanban görünümleri içindir — grid.php'nin erken
// yönlendirmesinin aynadaki karşılığı, TEK yönlendirme noktası kullanılır.
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

// Sütunlamaya UYGUN alanlar (yalnızca single_select — bkz. bcc_field_allowed_for_kanban)
$kanbanEligibleFields = array();
foreach ($fields as $f) {
    if (bcc_field_allowed_for_kanban($f['field_type'])) {
        $kanbanEligibleFields[] = $f;
    }
}

$kanbanConfig = bcc_kanban_config_from_view($view);
$columnFieldId = $kanbanConfig['kanban_field_id'];

// Yapılandırılmış alan silinmiş ya da tipi değişmişse "seçilmemiş"e düşülür —
// beyaz ekran yerine yönlendirici boş durum (fail-safe).
if ($columnFieldId > 0 && (!isset($fieldsById[$columnFieldId])
    || !bcc_field_allowed_for_kanban($fieldsById[$columnFieldId]['field_type']))) {
    $columnFieldId = 0;
}

$columnField = $columnFieldId > 0 ? $fieldsById[$columnFieldId] : null;

// Kartta gösterilecek EK alanlar (birincil alan her zaman ayrıca basılır).
// Sütunlama alanı listeden çıkarılır — zaten kartın bulunduğu sütun onu söylüyor.
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

    // Kayıtlar: grid'in KENDİ sorgusu. Filtre ve soft-delete süzgeci bedava
    // gelir (bcc_build_grid_records_query WHERE'ine 'r.deleted_at IS NULL'
    // gömülü), gruplama/sıralama boş geçilir — Kanban zaten gruplamanın kendisi,
    // sütun içi sıralama bu turda kapsam dışı.
    $filterRules = parse_grid_filter_rules($_GET, $fieldsById);
    $filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';
    list($recordsSql, $recordsParams) = bcc_build_grid_records_query($table['id'], array(), array(), $filterRules, $filterLogic);
    $records = bcc_fetch_all($recordsSql, $recordsParams);
    $recordCount = count($records);

    $recordIds = array_column($records, 'id');
    $cellsByRecord = bcc_fetch_cells_by_record($recordIds);
    $usersById = bcc_team_users_by_id($table['team_id']);

    // "Atanmamış" EN BAŞTA ve ZORUNLU: hücresi hiç olmayan / NULL / boş kayıtlar
    // aksi hâlde tahtada HİÇ GÖRÜNMEZDİ — kullanıcı verisinin sessizce
    // kaybolması en kötü sonuç.
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

        // choices'ta OLMAYAN bir değer (seçenek sonradan yeniden adlandırılmış):
        // kart "Atanmamış"a düşer AMA ham değeri kartta gösterilir — yoksa
        // kullanıcı "burada bir değer vardı" bilgisini kaybederdi.
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
$homePageTitle = 'BCC-Core — Kanban: ' . $table['name'];
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="settings-breadcrumb">
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">&larr; <?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?> tablosuna dön</a>
            <span>·</span> <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">Alanları yönet</a>
        </div>

        <div class="home-main-header kanban-header">
            <h1><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></h1>

            <?php if ($canEdit && !empty($kanbanEligibleFields)): ?>
            <?php /* "Sütunlama" paneli — mevcut <details name="gs-table-tab-menu">
                     grubuna KATILIR, böylece dışarı-tık / Escape / karşılıklı
                     dışlama grid-table-tabs.js'ten BEDAVA gelir (o dosya artık
                     sınıf adına değil name özniteliğine bakıyor). */ ?>
            <details class="gs-tool-details kanban-settings-menu" name="gs-table-tab-menu">
                <summary class="settings-btn">Sütunlama</summary>
                <div class="kanban-settings-panel" data-kanban-settings>
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
        <?php /* BOŞ DURUM: tabloda hiç single_select yok (ya da seçilen alan
                 silindi/tipi değişti). Görünümün OLUŞTURULMASI engellenmedi —
                 kullanıcı "Kanban istiyorum ama neden yok?" çıkmazına düşmesin
                 diye burada net bir yönlendirme veriliyor. */ ?>
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
                <?php /* data-column-value: sürükle-bırakta cell_update.php'ye
                         gönderilecek DEĞER. "Atanmamış" boş string gönderir —
                         normalize_cell_value single_select'te boşu null'a
                         çevirir, yani karta "seçimi temizle" anlamına gelir. */ ?>
                <section class="kanban-column" data-kanban-column data-column-value="<?php echo htmlspecialchars($col['key'], ENT_QUOTES, 'UTF-8'); ?>">
                    <header class="kanban-column-head">
                        <?php /* Renk ANAHTARI (bcc_build_choice_color_map) hex'e burada
                                 çözülür — palette'te olmayan bir anahtar (elle kurcalanmış
                                 options) noktayı hiç basmaz, style'a ham değer YAZILMAZ. */ ?>
                        <?php if ($col['color'] !== null && isset($GLOBALS['BCC_CHOICE_COLORS'][$col['color']])): ?>
                            <span class="kanban-column-dot" style="background: <?php echo htmlspecialchars($GLOBALS['BCC_CHOICE_COLORS'][$col['color']], ENT_QUOTES, 'UTF-8'); ?>"></span>
                        <?php endif; ?>
                        <span class="kanban-column-title"><?php echo htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="kanban-column-count" data-kanban-count><?php echo count($col['cards']); ?></span>
                    </header>
                    <div class="kanban-column-body" data-kanban-dropzone>
                        <?php foreach ($col['cards'] as $card): ?>
                            <article class="kanban-card<?php echo $canEdit ? ' is-draggable' : ''; ?>"
                                     data-kanban-card
                                     data-record-id="<?php echo (int) $card['id']; ?>">
                                <div class="kanban-card-primary"><?php echo htmlspecialchars($card['primary'] !== '' ? $card['primary'] : '(Adsız)', ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if ($card['stale'] !== null): ?>
                                    <?php /* Seçeneklerde artık bulunmayan değer — veri kaybı
                                             görünümü olmasın diye ham hâliyle gösterilir. */ ?>
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
    var BCC_KANBAN_CSRF = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_UNICODE); ?>;
    var BCC_KANBAN_CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
</script>
<script src="<?php echo bcc_asset_url('grid-column-drag.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('kanban.js'); ?>" defer></script>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
