<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

// Her erişimde KVKK ekip izolasyonu: bu tablonun ekibine üye olmayan hiçbir şey göremez.
require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = in_array($role, array('editor', 'owner'), true);

// 'user' alan tipi (görüntüleme + hücre/filtre editörü seçenek listesi) için TEK
// kaynak — yalnızca bu takımın (KVKK) aktif üyeleri, bkz. bcc_team_users_by_id().
$usersById = bcc_team_users_by_id($table['team_id']);

// Görünüm bilgi popover'ı / seçenekler menüsü / satır içi yeniden adlandırma bu tabloya
// ait TEK varsayılan görünüm satırını (views) kullanır — bkz. bcc_get_or_create_default_view().
$view = bcc_get_or_create_default_view($table['id']);

// Kaydedilebilir görünümler (docs/PROJE-DURUM.md #8): URL'de HİÇ grid state
// parametresi yoksa (yalnızca table_id ile açılmış "çıplak" istek) ve view'ın
// kayıtlı bir grid_state'i varsa, o state'e yönlendirilir — URL kayıtlı görünümü
// yansıtır, aşağıdaki parse_grid_* çağrıları redirect sonrası isteği normal
// şekilde işler (ayrı bir kod yolu yok). POST istekleri (kayıt ekle/sil) etkilenmez.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && bcc_grid_state_is_empty($_GET)) {
    $savedGridState = bcc_get_view_grid_state($view['config']);
    if (!empty($savedGridState)) {
        $redirectQuery = http_build_query(array('table_id' => $table['id']) + $savedGridState);
        header('Location: /grid.php?' . $redirectQuery);
        exit;
    }
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    // Kayıt ekleme/silme yalnızca editor+ rolünde açık.
    require_role($table['team_id'], 'editor');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_record') {
        $nextPos = (int) bcc_fetch_column('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :table_id', array(':table_id' => $table['id']));

        $user = current_user();
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:table_id, :position, :created_by)', array(':table_id' => $table['id'], ':position' => $nextPos, ':created_by' => $user['id']));
        $newId = bcc_last_insert_id();
        log_audit('record.create', 'record', $newId, array('table_id' => $table['id']), $table['team_id']);
        bcc_notify_slack_new_record($table['id'], $newId);
        $success = 'Kayıt eklendi.';
    } elseif ($action === 'delete_record') {
        $recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;

        $existingRecord = bcc_fetch_one('SELECT id FROM records WHERE id = :id AND table_id = :table_id LIMIT 1', array(':id' => $recordId, ':table_id' => $table['id']));

        if (!$existingRecord) {
            http_response_code(403);
            die('Bu kayıt bu tabloya ait değil.');
        }

        bcc_execute('DELETE FROM records WHERE id = :id', array(':id' => $recordId));
        log_audit('record.delete', 'record', $recordId, array('table_id' => $table['id']), $table['team_id']);
        $success = 'Kayıt silindi.';
    }
}

$fields = bcc_fetch_all('SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id', array(':table_id' => $table['id']));

$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}

// Alan gizleme (Grid araçları Adım 1): hidden_fields=ID,ID,... (ya da panelin kendi
// formundan gelen visible_fields[]) GET parametresi, yalnızca bu tabloya ait alan
// id'leri kabul edilir (whitelist). Birincil alan ($fields zaten position,id sırayla
// çekildiği için ilk eleman) Airtable'daki gibi hiçbir zaman gizlenemez — bu kural
// parse_grid_hidden_fields() içinde uygulanır, URL'e elle yazılsa bile bozulmaz.
// Gizli alan hâlâ filtrelenebilir/sıralanabilir — SQL sorgusu ve $fieldsById her
// zaman $fields'in tamamını kullanır; $visibleFields yalnızca render (thead/tbody)
// için daraltılmış listedir, veri katmanını etkilemez.
$primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
$hiddenFieldIds = parse_grid_hidden_fields($_GET, $fieldsById, $primaryFieldId);

$visibleFields = array();
foreach ($fields as $f) {
    if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
        $visibleFields[] = $f;
    }
}

// Sütun dondurma: dondurulmuş sütun sayısı views.config'ten (kalıcı, görünüm
// başına) okunur — bcc_get_frozen_column_count() savunmacıdır (NULL/bozuk
// JSON/beklenmedik değer -> sessizce 1'e düşer). Üst sınır (bcc_max_frozen_columns)
// hem burada hem view_config_update.php'de AYNI formülle hesaplanır.
$maxFrozenColumns = bcc_max_frozen_columns(count($visibleFields));
$frozenColumnCount = bcc_get_frozen_column_count($view['config'], $maxFrozenColumns);

// Sıralama (Faz 4): sort_field_1..3 / sort_dir_1..3 GET parametreleri, yalnızca bu
// tabloya ait alanlar kabul edilir. Kalıcılık henüz yok — durum URL'de taşınıyor.
$sortRules = parse_grid_sort_rules($_GET, $fieldsById);

// Filtreleme (Faz 4): filter_field_1..5 / filter_cond_1..5 / filter_value_1..5 +
// filter_logic (and/or). Alan id'si VE operatör whitelist'te değilse kural
// sessizce yok sayılır (parse_grid_filter_rules). Değerler prepared statement ile
// bağlanır (filter_condition_sql), sunucu tarafında gerçek SQL sorgusu ile filtrelenir.
$filterRules = parse_grid_filter_rules($_GET, $fieldsById);
$filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';

// Gruplama (çok seviyeli, en fazla 3 seviye): group_field_1..3 / group_dir_1..3
// GET parametreleri, yalnızca bu tabloya ait alanlar kabul edilir (whitelist).
// $fieldsById'in tamamı kullanıldığı için gizli (Hide fields ile kapatılmış) bir
// alana göre de gruplama yapılabilir. SQL ve segmentasyon/render artık
// $groupRules dizisinin tamamını (tüm seviyeleri) kullanıyor.
$groupRules = parse_grid_group_rules($_GET, $fieldsById);

// Satır yüksekliği / başlık sarma (Grid araçları Adım 3): row_height / wrap_headers
// GET parametreleri, whitelist'e karşı doğrulanır (parse_grid_row_height/wrap_headers).
$rowHeight = parse_grid_row_height($_GET);
$wrapHeaders = parse_grid_wrap_headers($_GET);

// Her grup seviyesi kendi cell_values alias'ını alır (gv0, gv1, gv2 — sort'taki
// sv0..2 / filtredeki fv0..4 ile aynı desen, çakışma yok). SELECT'e her seviye
// için ayrı group_raw_value_N kolonu eklenir; bcc_build_grouped_tree() tüm
// seviyeleri (0..N-1) tek geçişte segmentler.
$groupSelectExtra = '';
foreach ($groupRules as $gIdx => $gRule) {
    $groupSelectExtra .= ", gv{$gIdx}.{$gRule['column']} AS group_raw_value_{$gIdx}";
}
$recordsSql = "SELECT r.id, r.position, r.created_at{$groupSelectExtra} FROM records r";
$recordsParams = array(':table_id' => $table['id']);
$orderParts = array();

// Grup değeri null/boş olan kayıtlar Airtable'daki gibi "(Empty)" grubunda ve en
// üstte toplanır — bu yüzden her seviye için IS NULL DESC, seçilen yönden ÖNCE
// ve ondan bağımsız olarak uygulanır. Grup sırası (0 → 2), kullanıcının Sort
// kurallarından ÖNCE gelir.
foreach ($groupRules as $gIdx => $gRule) {
    $alias = 'gv' . $gIdx;
    $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :gfid{$gIdx}";
    $recordsParams[':gfid' . $gIdx] = $gRule['field_id'];
    $orderParts[] = "({$alias}.{$gRule['column']} IS NULL) DESC";
    $orderParts[] = "{$alias}.{$gRule['column']} {$gRule['dir']}";
}

foreach ($sortRules as $idx => $rule) {
    $alias = 'sv' . $idx;
    $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :sfid{$idx}";
    $recordsParams[':sfid' . $idx] = $rule['field_id'];
    $orderParts[] = "{$alias}.{$rule['column']} {$rule['dir']}";
}

$filterConds = array();
foreach ($filterRules as $idx => $rule) {
    $alias = 'fv' . $idx;
    $paramName = ':fval' . $idx;
    $frag = filter_condition_sql($rule['field_type'], $rule['operator'], $rule['raw_value'], $alias, $paramName);

    if ($frag === null) {
        continue; // deger gecersiz/eksik (ör. sayi alaninda sayi olmayan girdi) -> kural atlanir
    }

    $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :ffid{$idx}";
    $recordsParams[':ffid' . $idx] = $rule['field_id'];
    foreach ($frag['params'] as $pName => $pValue) {
        $recordsParams[$pName] = $pValue;
    }
    $filterConds[] = $frag['sql'];
}

$orderParts[] = 'r.position ASC';
$orderParts[] = 'r.id ASC';

$recordsSql .= ' WHERE r.table_id = :table_id';
if (!empty($filterConds)) {
    $joinWord = ($filterLogic === 'OR') ? ' OR ' : ' AND ';
    $recordsSql .= ' AND (' . implode($joinWord, $filterConds) . ')';
}
$recordsSql .= ' ORDER BY ' . implode(', ', $orderParts);

$records = bcc_fetch_all($recordsSql, $recordsParams);

// Gruplama render hazırlığı: SQL'de grup seviyeleri zaten birincil ORDER BY
// anahtarları olduğu için aynı seviye değerlerine sahip kayıtlar $records
// içinde her zaman ardışıktır — bcc_build_grouped_tree() TEK geçişte iç içe
// bir ağaca böler (bkz. fonksiyon tanımı, aşağıda bcc_render_grid_data_row'un
// yanında).
$groupTree = bcc_build_grouped_tree($records, $groupRules, $usersById);

// Grup başlığı render'ının kullanacağı, her seviyenin alan adı — $fieldsById
// üzerinden, seviye sırasına göre.
$groupFieldNames = array();
foreach ($groupRules as $gRule) {
    $groupFieldNames[] = $fieldsById[$gRule['field_id']]['name'];
}

// Kayıt ekleme/silme formlarının ve "temizle" linklerinin geçerli sort/filter
// durumunu koruması için ortak query string parçaları.
$sortState = array();
foreach ($sortRules as $rule) {
    $sortState['sort_field_' . $rule['slot']] = $rule['field_id'];
    $sortState['sort_dir_' . $rule['slot']] = strtolower($rule['dir']);
}

$filterState = array();
foreach ($filterRules as $rule) {
    $filterState['filter_field_' . $rule['slot']] = $rule['field_id'];
    $filterState['filter_cond_' . $rule['slot']] = $rule['operator'];
    $filterState['filter_value_' . $rule['slot']] = $rule['raw_value'];
}
if (!empty($filterRules)) {
    $filterState['filter_logic'] = strtolower($filterLogic);
}

$hiddenFieldsState = array();
if (!empty($hiddenFieldIds)) {
    $hiddenFieldsState['hidden_fields'] = implode(',', $hiddenFieldIds);
}

$groupState = array();
foreach ($groupRules as $rule) {
    $groupState['group_field_' . $rule['slot']] = $rule['field_id'];
    $groupState['group_dir_' . $rule['slot']] = strtolower($rule['dir']);
}

$rowHeightState = array();
if ($rowHeight !== 'short') {
    $rowHeightState['row_height'] = $rowHeight;
}

$wrapHeadersState = array();
if ($wrapHeaders) {
    $wrapHeadersState['wrap_headers'] = '1';
}

$baseState = array('table_id' => $table['id']);
$stateQueryString = http_build_query($baseState + $sortState + $filterState + $hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState);
$clearSortQueryString = http_build_query($baseState + $filterState + $hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState);
$clearFilterQueryString = http_build_query($baseState + $sortState + $hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState);
$clearGroupQueryString = http_build_query($baseState + $sortState + $filterState + $hiddenFieldsState + $rowHeightState + $wrapHeadersState);

// Hide fields panelinin "Tümünü göster/gizle" kısayolları için hazır sorgu dizeleri
// (mevcut sort/filter durumu korunur — grid.php'nin diğer state linkleriyle aynı desen).
// Birincil alan "Tümünü gizle"den her zaman muaf tutulur.
$showAllFieldsQueryString = http_build_query($baseState + $sortState + $filterState + $groupState + $rowHeightState + $wrapHeadersState);
$nonPrimaryFieldIds = array();
foreach ($fields as $f) {
    if ((int) $f['id'] !== $primaryFieldId) {
        $nonPrimaryFieldIds[] = (int) $f['id'];
    }
}
$hideAllFieldsQueryString = http_build_query($baseState + $sortState + $filterState + $groupState + $rowHeightState + $wrapHeadersState + array('hidden_fields' => implode(',', $nonPrimaryFieldIds)));

// Group panelinin boş alan listesi (henüz gruplama yokken) her alan için hazır bir
// bağlantı üretir — mevcut sort/filter/hidden_fields durumu korunur.
$groupFieldLinkBase = $baseState + $sortState + $filterState + $hiddenFieldsState + $rowHeightState + $wrapHeadersState;

// Her seviye için "bu seviyeyi kaldır" linki: kalan seviyeler 1'den yeniden
// numaralanarak (parse_grid_group_rules'un kendi sıkıştırma davranışıyla aynı
// sonucu üretir) diğer tüm state (sort/filter/hidden/row height) korunarak
// yeniden kurulur — JS gerekmez, "Gruplamayı kaldır" linkiyle aynı desen.
$groupRemoveLinks = array();
foreach ($groupRules as $removeIdx => $ruleToRemove) {
    $remaining = array();
    $newSlot = 1;
    foreach ($groupRules as $idx => $rule) {
        if ($idx === $removeIdx) {
            continue;
        }
        $remaining['group_field_' . $newSlot] = $rule['field_id'];
        $remaining['group_dir_' . $newSlot] = strtolower($rule['dir']);
        $newSlot++;
    }
    $groupRemoveLinks[$removeIdx] = http_build_query($groupFieldLinkBase + $remaining);
}

// Row height panelinin kendi linkleri (yükseklik seçenekleri + Wrap headers) için
// mevcut tüm state (row_height/wrap_headers hariç, onlar linkler tarafından ayrı
// ayrı eklenir/değiştirilir).
$rowHeightPanelBase = $baseState + $sortState + $filterState + $hiddenFieldsState + $groupState;

$cellsByRecord = array();
if (!empty($records) && !empty($fields)) {
    $recordIds = array_column($records, 'id');
    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $cellRows = bcc_fetch_all("SELECT record_id, field_id, value_text, value_number, value_date, value_json FROM cell_values WHERE record_id IN ($placeholders)", $recordIds);

    foreach ($cellRows as $cell) {
        $cellsByRecord[$cell['record_id']][$cell['field_id']] = $cell;
    }
}

$typeBadges = $GLOBALS['BCC_FIELD_TYPE_BADGE'];
$typeLabels = $GLOBALS['BCC_FIELD_TYPES'];

// Çok seviyeli gruplama: $records üzerinde TEK geçişte, sıralı gelen kayıtları
// iç içe bir ağaca böler. Segmentleme HAM DEĞER (group_raw_value_N, SQL'in
// GROUP BY değil ORDER BY ile getirdiği ham kolon) üzerinden karşılaştırılır;
// cell_display_text() yalnızca başlıkta GÖSTERİM için çağrılır, karşılaştırmaya
// hiç girmez. Bir seviyenin ham değeri bir önceki kayıttan farklıysa, o seviye
// VE ondan sonraki (daha iç) tüm seviyeler için yeni segment açılır — iç
// sayaçlar bu noktada sıfırlanır (bkz. $counters), böylece dıştaki bir grup
// değişince içteki "0-1" gibi bir path yanlışlıkla eski sayaçtan devam etmez.
// checkbox için ayrı İngilizce etiket kullanılır (cell_display_text() checkbox'ı
// desteklemez, hücre normalde bir input olarak render edilir); diğer tipler
// cell_display_text() ile (tarih formatı, seçim etiketleri vb. doğru çıksın
// diye) biçimlendirilir. (Empty) davranışı: tek seviyeli gruplamadaki gibi.
//
// Dönüş: düğüm dizisi. Her düğüm:
//   'level'    => 0 tabanlı seviye
//   'path'     => hiyerarşik segment yolu, ör. "0-2-1" (data-group-path'e gider)
//   'display'  => başlıkta gösterilecek metin
//   'count'    => bu düğümün altındaki TOPLAM kayıt sayısı (iç içe seviyelerde
//                 tüm alt dallardaki kayıtların toplamı)
//   'is_leaf'  => bu, gruplamanın en iç (son) seviyesi mi
//   'children' => is_leaf değilse, alt düğüm dizisi (aksi halde null)
//   'records'  => is_leaf ise, bu segmentteki kayıt dizisi (aksi halde null)
function bcc_build_grouped_tree($records, $groupRules, $usersById = array())
{
    $levelCount = count($groupRules);
    $tree = array();

    if ($levelCount === 0) {
        return $tree;
    }

    $openNodes = array();
    $counters = array_fill(0, $levelCount, -1);
    $prevKeys = null;

    foreach ($records as $record) {
        $keys = array();
        for ($lvl = 0; $lvl < $levelCount; $lvl++) {
            $keys[$lvl] = $record['group_raw_value_' . $lvl];
        }

        $divergeLevel = 0;
        if ($prevKeys !== null) {
            $divergeLevel = $levelCount; // sentinel: hiçbir seviye değişmedi
            for ($lvl = 0; $lvl < $levelCount; $lvl++) {
                if ($keys[$lvl] !== $prevKeys[$lvl]) {
                    $divergeLevel = $lvl;
                    break;
                }
            }
        }

        for ($lvl = $divergeLevel; $lvl < $levelCount; $lvl++) {
            $counters[$lvl] = ($lvl === $divergeLevel) ? $counters[$lvl] + 1 : 0;

            $rule = $groupRules[$lvl];
            $rawValue = $keys[$lvl];

            if ($rawValue === null) {
                $display = '(Empty)';
            } elseif ($rule['field_type'] === 'checkbox') {
                $display = ((int) $rawValue === 1) ? 'Checked' : 'Unchecked';
            } else {
                $display = cell_display_text($rule['field_type'], bcc_group_cell_row($rule['column'], $rawValue), $usersById);
            }

            $isLeaf = ($lvl === $levelCount - 1);
            $node = array(
                'level' => $lvl,
                'path' => implode('-', array_slice($counters, 0, $lvl + 1)),
                'display' => $display,
                'count' => 0,
                'is_leaf' => $isLeaf,
                'children' => $isLeaf ? null : array(),
                'records' => $isLeaf ? array() : null,
            );

            if ($lvl === 0) {
                $tree[] = $node;
                $openNodes[0] = &$tree[count($tree) - 1];
            } else {
                $openNodes[$lvl - 1]['children'][] = $node;
                $openNodes[$lvl] = &$openNodes[$lvl - 1]['children'][count($openNodes[$lvl - 1]['children']) - 1];
            }
        }

        $openNodes[$levelCount - 1]['records'][] = $record;

        for ($lvl = 0; $lvl < $levelCount; $lvl++) {
            $openNodes[$lvl]['count']++;
        }

        $prevKeys = $keys;
    }

    unset($openNodes);

    return $tree;
}

// Bir grup düğümünü (başlık satırı) ve altındakileri basar — iç içe her seviye
// için ayrı bir fonksiyon KOPYALANMAZ, bu tek fonksiyon kendi kendini çağırır
// (özyinelemeli). Girinti, seviyeye göre hesaplanan padding-left ile yapılır;
// 0. seviyede taban değer (0.9rem) CSS'teki mevcut .group-header-toggle
// padding'iyle birebir aynıdır — tek seviyeli gruplama bu yüzden görsel olarak
// bugünküyle birebir aynı kalır. $rowNum referansla geçirilir ki satır numarası
// tüm ağaç boyunca (gruplar VE seviyeler arasında) kesintisiz artsın.
function bcc_render_group_node($node, $groupFieldNames, &$rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $colspan, $usersById = array())
{
    $paddingLeftRem = 0.9 + $node['level'] * 1.1;
    ?>
    <tr class="group-header-row" data-group-header data-group-path="<?php echo htmlspecialchars($node['path'], ENT_QUOTES, 'UTF-8'); ?>" data-group-level="<?php echo (int) $node['level']; ?>">
        <td colspan="<?php echo (int) $colspan; ?>">
            <button type="button" class="group-header-toggle" data-group-toggle aria-expanded="true" style="padding-left: <?php echo htmlspecialchars((string) $paddingLeftRem, ENT_QUOTES, 'UTF-8'); ?>rem;">
                <svg class="group-header-chevron" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 4.5l3 3 3-3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="group-header-info">
                    <span class="group-header-field-name"><?php echo htmlspecialchars(mb_strtoupper($groupFieldNames[$node['level']], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="group-header-value"><?php echo htmlspecialchars($node['display'], ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <span class="group-header-count"><?php echo (int) $node['count']; ?></span>
            </button>
        </td>
    </tr>
    <?php
    if ($node['is_leaf']) {
        foreach ($node['records'] as $record) {
            $rowNum++;
            bcc_render_grid_data_row($record, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $node['path'], $usersById);
        }
    } else {
        foreach ($node['children'] as $child) {
            bcc_render_group_node($child, $groupFieldNames, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $colspan, $usersById);
        }
    }
}

// bcc_render_grid_data_row() artık src/schema.php'de (public/api/record_add.php
// ile paylaşılıyor) — bkz. orada.

// Tablo sekme şeridi için: aynı base'in diğer tabloları (görünüm amaçlı, salt-okunur).
// base_id zaten yukarıda require_team_access($table['team_id']) ile doğrulandı,
// bu yüzden aynı base_id'ye ait kardeş tablolar da güvenle listelenebilir.
$siblingTables = bcc_list_base_tables($table['base_id']);

$gridUser = current_user();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<title>BCC-Core — <?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/grid-shell.css">
</head>
<body class="gs-body">

<aside class="gs-rail">
    <a href="/dashboard.php" class="gs-rail-logo" title="Home'a dön">
        <img src="/assets/bcc-logo.svg" alt="BCC-Core" class="gs-rail-logo-img">
        <svg class="gs-rail-back-icon" width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M12.5 4.5L6 10l6.5 5.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <div class="gs-rail-bottom">
        <button type="button" class="gs-rail-icon-btn" aria-label="Bildirimler">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M10 2.5c-2.4 0-4.2 1.9-4.2 4.3v2.6c0 .5-.2 1.3-.5 1.7L4.4 12.5c-.6.8-.2 1.9.8 2.2 3.3 1 6.9 1 10.2 0 .9-.3 1.3-1.4.7-2.2l-.9-1.4c-.3-.4-.5-1.2-.5-1.7V6.8c0-2.4-1.9-4.3-4.2-4.3z" stroke="#ccc" stroke-width="1.3" stroke-linejoin="round"/><path d="M8.2 16.5a1.8 1.8 0 003.6 0" stroke="#ccc" stroke-width="1.3" stroke-linecap="round"/></svg>
        </button>
        <?php
        $accountMenuPrefix = 'gs';
        $accountMenuUser = $gridUser;
        require __DIR__ . '/../src/partials/account_menu.php';
        ?>
    </div>
</aside>

<div class="gs-main-col">
    <header class="gs-topbar">
        <div class="gs-topbar-left">
            <span class="gs-base-icon">▤</span>
            <span class="gs-base-name"><?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <nav class="gs-topbar-tabs">
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>" class="gs-topbar-tab is-active">Data</a>
            <button type="button" class="gs-topbar-tab">Automations</button>
            <button type="button" class="gs-topbar-tab">Interfaces</button>
            <button type="button" class="gs-topbar-tab">Forms</button>
        </nav>
        <div class="gs-topbar-right">
            <button type="button" class="gs-rail-icon-btn gs-icon-btn-dark" aria-label="Geçmiş">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 5.5V10l3 2" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.3 6.5A6.5 6.5 0 1110 16.5" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/><path d="M3 3.5v3.3h3.3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="gs-btn-ghost">Launch</button>
            <button type="button" class="gs-btn-primary">Share</button>
        </div>
    </header>

    <div class="gs-table-tabs">
        <div class="gs-table-tabs-scroll">
            <?php foreach ($siblingTables as $st):
                $isActiveTab = (int) $st['id'] === (int) $table['id'];
            ?>
                <div class="gs-table-tab-wrap <?php echo $isActiveTab ? 'is-active' : ''; ?>">
                    <a
                        href="/grid.php?table_id=<?php echo (int) $st['id']; ?>"
                        class="gs-table-tab <?php echo $isActiveTab ? 'is-active' : ''; ?>"
                    ><?php echo htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <details class="gs-table-tab-menu" name="gs-table-tab-menu">
                        <summary class="gs-table-tab-caret" aria-label="Sekme seçenekleri">
                            <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M3 4.5l3 3 3-3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="gs-table-tab-menu-panel">
                            <button type="button" class="gs-table-tab-menu-item">Import data</button>
                            <div class="gs-table-tab-menu-divider"></div>
                            <button type="button" class="gs-table-tab-menu-item gs-table-tab-menu-item-danger">Clear data</button>
                        </div>
                    </details>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
        <a href="/base_tables.php?base_id=<?php echo (int) $table['base_id']; ?>" class="gs-table-tab-add" title="Yeni tablo">+</a>
        <?php endif; ?>
        <details class="gs-table-tab-menu gs-all-tables-menu" name="gs-table-tab-menu">
            <summary class="gs-table-tab-caret gs-all-tables-caret" aria-label="Tüm tablolar (Ctrl+J)">
                <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M3 4.5l3 3 3-3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="gs-kbd-tooltip">
                    <span class="gs-kbd-tooltip-label">All tables</span>
                    <span class="gs-kbd-badge"><span class="gs-kbd-mac">⌘ J</span><span class="gs-kbd-other">Ctrl J</span></span>
                </span>
            </summary>
            <div class="gs-table-tab-menu-panel gs-all-tables-panel">
                <div class="gs-all-tables-search">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <input type="text" placeholder="Find a table" data-all-tables-search>
                </div>
                <div class="gs-all-tables-list">
                    <?php foreach ($siblingTables as $st):
                        $isActiveTab = (int) $st['id'] === (int) $table['id'];
                    ?>
                        <a
                            href="/grid.php?table_id=<?php echo (int) $st['id']; ?>"
                            class="gs-all-tables-row <?php echo $isActiveTab ? 'is-active' : ''; ?>"
                            data-all-tables-row
                        >
                            <span class="gs-all-tables-row-check"><?php if ($isActiveTab): ?>✓<?php endif; ?></span>
                            <span class="gs-all-tables-row-name"><?php echo htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <div class="gs-all-tables-empty" data-all-tables-empty hidden>Sonuç yok</div>
                </div>
            </div>
        </details>
    </div>

    <div class="gs-view-toolbar">
        <div class="gs-view-toolbar-left">
            <button type="button" class="gs-icon-btn" id="gs-view-panel-toggle" aria-label="Görünüm panelini aç/kapat">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M2.5 5.5h15M2.5 10h15M2.5 14.5h15" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
            </button>
            <div class="gs-view-trigger">
                <span class="gs-view-label">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="2" stroke="#1a73e8" stroke-width="1.4"/><path d="M3 8h14M8 3v14" stroke="#1a73e8" stroke-width="1.2"/></svg>
                    <span
                        class="gs-view-name"
                        data-view-name
                        <?php if ($canEdit): ?>data-view-id="<?php echo (int) $view['id']; ?>"<?php endif; ?>
                    ><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <div class="gs-view-info-popover">
                    <div class="gs-view-info-title"><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="gs-view-info-row">
                        <span class="gs-view-info-label">Editing</span>
                        <span class="gs-view-info-value">Everyone can edit the view configuration.</span>
                    </div>
                    <?php if (!empty($view['created_by_name'])): ?>
                    <div class="gs-view-info-row">
                        <span class="gs-view-info-label">Created by</span>
                        <span class="gs-view-info-value"><?php echo htmlspecialchars($view['created_by_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <details class="gs-table-tab-menu gs-view-options-menu" name="gs-table-tab-menu">
                <summary class="gs-table-tab-caret gs-view-options-caret" aria-label="Görünüm seçenekleri">
                    <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M3 4.5l3 3 3-3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </summary>
                <div class="gs-table-tab-menu-panel gs-view-options-panel">
                    <div class="gs-view-options-collab">
                        <span class="gs-view-options-collab-text">
                            <span class="gs-view-options-collab-label">Collaborative view</span>
                            <span class="gs-view-options-collab-desc">Editors and up can edit the view configuration.</span>
                        </span>
                        <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M4.5 3l3 3-3 3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="gs-table-tab-menu-divider"></div>
                    <?php if ($canEdit): ?>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-save-state-btn">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 4.5A1.5 1.5 0 015.5 3h8l3.5 3.5v9a1.5 1.5 0 01-1.5 1.5h-10A1.5 1.5 0 014 15.5v-11z" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/><path d="M6.5 3v4h6V3M6.5 17v-5h7v5" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        Save view
                    </button>
                    <div class="gs-table-tab-menu-divider"></div>
                    <?php endif; ?>
                    <button type="button" class="gs-table-tab-menu-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M13.5 3.5l3 3-9.5 9.5H4v-3l9.5-9.5z" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        Rename view
                    </button>
                    <!-- "Rename view" tıklaması, satır içi düzenleme işi tamamlanınca burada bağlanacak -->
                    <button type="button" class="gs-table-tab-menu-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="#5f6368" stroke-width="1.3"/><path d="M10 9v5M10 6.5v.01" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                        Edit view description
                    </button>
                    <div class="gs-table-tab-menu-divider"></div>
                    <button type="button" class="gs-table-tab-menu-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="7" y="7" width="9" height="9" rx="1.5" stroke="#5f6368" stroke-width="1.3"/><path d="M4 13V5.5A1.5 1.5 0 015.5 4H13" stroke="#5f6368" stroke-width="1.3"/></svg>
                        Duplicate view
                    </button>
                    <div class="gs-table-tab-menu-divider"></div>
                    <button type="button" class="gs-table-tab-menu-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3-3m3 3l3-3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 14v1.5A1.5 1.5 0 005.5 17h9a1.5 1.5 0 001.5-1.5V14" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                        Download CSV
                    </button>
                    <button type="button" class="gs-table-tab-menu-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="5" y="3" width="10" height="5" stroke="#5f6368" stroke-width="1.3"/><rect x="3" y="8" width="14" height="6" rx="1" stroke="#5f6368" stroke-width="1.3"/><rect x="6" y="12" width="8" height="5" stroke="#5f6368" stroke-width="1.3"/></svg>
                        Print view
                    </button>
                    <button type="button" class="gs-table-tab-menu-item gs-table-tab-menu-item-danger">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="#c62828" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Delete view
                    </button>
                </div>
            </details>
        </div>
        <div class="gs-view-toolbar-right">
            <?php if (!empty($fields)): ?>
            <details class="hide-fields-panel gs-tool-details">
                <summary class="gs-tool-btn <?php echo !empty($hiddenFieldIds) ? 'hide-fields-btn-active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M2.5 6h15M2.5 10h10M2.5 14h6" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <?php if (empty($hiddenFieldIds)): ?>
                        Hide fields
                    <?php elseif (count($hiddenFieldIds) === 1): ?>
                        1 hidden field
                    <?php else: ?>
                        <?php echo count($hiddenFieldIds); ?> hidden fields
                    <?php endif; ?>
                </summary>
                <form method="get" action="/grid.php" class="hide-fields-form" id="hide-fields-form">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <input type="hidden" name="visible_fields_submitted" value="1">
                    <?php bcc_render_grid_state_hidden_inputs($sortState + $filterState + $groupState + $rowHeightState + $wrapHeadersState); ?>
                    <input type="text" class="hide-fields-search" placeholder="Find a field" data-hide-fields-search>
                    <?php foreach ($fields as $f):
                        if ((int) $f['id'] === $primaryFieldId) {
                            continue; // birincil alan Airtable'daki gibi panelde listelenmez, hep görünür
                        }
                    ?>
                        <label class="hide-field-row">
                            <input
                                type="checkbox"
                                class="hide-field-toggle-input"
                                name="visible_fields[]"
                                value="<?php echo (int) $f['id']; ?>"
                                <?php echo !in_array((int) $f['id'], $hiddenFieldIds, true) ? 'checked' : ''; ?>
                            >
                            <span class="hide-field-toggle" aria-hidden="true"></span>
                            <span class="hide-field-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                    <div class="hide-fields-actions">
                        <button type="submit" class="btn-sm" data-hide-fields-apply>Uygula</button>
                        <?php if (!empty($hiddenFieldIds)): ?>
                            <a class="btn-sm" href="/grid.php?<?php echo htmlspecialchars($showAllFieldsQueryString, ENT_QUOTES, 'UTF-8'); ?>">Tümünü göster</a>
                        <?php endif; ?>
                        <?php if (count($hiddenFieldIds) < count($nonPrimaryFieldIds)): ?>
                            <a class="btn-sm" href="/grid.php?<?php echo htmlspecialchars($hideAllFieldsQueryString, ENT_QUOTES, 'UTF-8'); ?>">Tümünü gizle</a>
                        <?php endif; ?>
                    </div>
                </form>
            </details>
            <?php endif; ?>

            <?php if (!empty($fields)): ?>
            <details class="filter-panel gs-tool-details">
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M3 4h14l-5.5 6.5V16l-3-1.5v-4L3 4z" stroke="#5f6368" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    Filter<?php echo !empty($filterRules) ? ' (' . count($filterRules) . ')' : ''; ?>
                </summary>
                <form method="get" action="/grid.php" class="filter-form">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <?php bcc_render_grid_state_hidden_inputs($hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState); ?>
                    <?php for ($slot = 1; $slot <= 5; $slot++):
                        $currentRule = null;
                        foreach ($filterRules as $rule) {
                            if ($rule['slot'] === $slot) {
                                $currentRule = $rule;
                                break;
                            }
                        }
                        $currentFieldId = $currentRule ? $currentRule['field_id'] : 0;
                        $currentFieldType = $currentRule ? $currentRule['field_type'] : null;
                        $currentOp = $currentRule ? $currentRule['operator'] : '';
                        $currentValue = $currentRule ? $currentRule['raw_value'] : '';
                        $opsForField = $currentFieldType ? $GLOBALS['BCC_FILTER_OPERATORS'][$currentFieldType] : array();
                        $valueHidden = in_array($currentOp, $GLOBALS['BCC_FILTER_NO_VALUE_OPS'], true);
                        $valueInputType = 'text';
                        if ($currentFieldType === 'number') {
                            $valueInputType = 'number';
                        } elseif ($currentFieldType === 'date') {
                            $valueInputType = 'date';
                        } elseif ($currentFieldType === 'time') {
                            $valueInputType = 'time';
                        }
                        // 'user' değerleri (users.id) serbest metin yerine takım
                        // üyelerinden bir <select> ile seçilir (grid-filter.js alan
                        // değişince aynı düğümü inşa eder) — id yazmak insan için
                        // anlamsız olurdu, diğer tüm tipler <input> olarak kalır.
                        $isUserFilter = ($currentFieldType === 'user');
                    ?>
                        <div class="filter-row">
                            <select name="filter_field_<?php echo $slot; ?>" class="filter-field-select">
                                <option value="">— yok —</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?php echo (int) $f['id']; ?>" <?php echo $currentFieldId === (int) $f['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="filter_cond_<?php echo $slot; ?>" class="filter-cond-select" <?php echo $opsForField ? '' : 'disabled'; ?>>
                                <?php if (empty($opsForField)): ?>
                                    <option value="">— önce alan seçin —</option>
                                <?php else: ?>
                                    <?php foreach ($opsForField as $opKey => $opLabel): ?>
                                        <option value="<?php echo htmlspecialchars($opKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentOp === $opKey ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($opLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if ($isUserFilter): ?>
                                <select
                                    name="filter_value_<?php echo $slot; ?>"
                                    class="filter-value-input filter-value-user-select"
                                    <?php echo $valueHidden ? 'style="display:none"' : ''; ?>
                                >
                                    <option value="">— seç —</option>
                                    <?php foreach ($usersById as $uid => $uname): ?>
                                        <option value="<?php echo (int) $uid; ?>" <?php echo ((string) $currentValue === (string) $uid) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($uname, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input
                                    type="<?php echo $valueInputType; ?>"
                                    name="filter_value_<?php echo $slot; ?>"
                                    class="filter-value-input"
                                    value="<?php echo htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="değer"
                                    <?php echo $valueHidden ? 'style="display:none"' : ''; ?>
                                >
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                    <div class="filter-logic-row">
                        <label><input type="radio" name="filter_logic" value="and" <?php echo $filterLogic === 'AND' ? 'checked' : ''; ?>> VE (tüm kurallar)</label>
                        <label><input type="radio" name="filter_logic" value="or" <?php echo $filterLogic === 'OR' ? 'checked' : ''; ?>> VEYA (herhangi biri)</label>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-sm">Uygula</button>
                        <?php if (!empty($filterRules)): ?>
                            <a class="btn-sm" href="/grid.php?<?php echo htmlspecialchars($clearFilterQueryString, ENT_QUOTES, 'UTF-8'); ?>">Temizle</a>
                        <?php endif; ?>
                    </div>
                </form>
            </details>
            <?php endif; ?>

            <?php if (!empty($fields)): ?>
            <details class="group-panel gs-tool-details">
                <summary class="gs-tool-btn <?php echo !empty($groupRules) ? 'hide-fields-btn-active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="6" cy="6" r="2" stroke="#5f6368" stroke-width="1.3"/><circle cx="14" cy="14" r="2" stroke="#5f6368" stroke-width="1.3"/><path d="M8 6h9M3 14h3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                    Group<?php echo !empty($groupRules) ? ' (' . count($groupRules) . ')' : ''; ?>
                </summary>
                <?php if (empty($groupRules)): ?>
                    <div class="group-form" id="group-form-empty">
                        <input type="text" class="hide-fields-search" placeholder="Find a field" data-group-search>
                        <div class="group-field-list">
                            <?php foreach ($fields as $f): ?>
                                <a
                                    class="group-field-option"
                                    href="/grid.php?<?php echo htmlspecialchars(http_build_query($groupFieldLinkBase + array('group_field_1' => $f['id'], 'group_dir_1' => 'asc')), ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <span class="field-badge" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($typeBadges[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <form method="get" action="/grid.php" class="group-form" id="group-form">
                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                        <?php bcc_render_grid_state_hidden_inputs($sortState + $filterState + $hiddenFieldsState + $rowHeightState + $wrapHeadersState); ?>
                        <div class="group-panel-header">
                            <button type="button" class="btn-sm" data-group-collapse-all>Collapse all</button>
                            <button type="button" class="btn-sm" data-group-expand-all>Expand all</button>
                        </div>
                        <div class="group-level-rows" id="group-level-rows">
                            <?php for ($slot = 1; $slot <= 3; $slot++):
                                $activeIdx = null;
                                $activeRule = null;
                                foreach ($groupRules as $idx => $rule) {
                                    if ($rule['slot'] === $slot) {
                                        $activeIdx = $idx;
                                        $activeRule = $rule;
                                        break;
                                    }
                                }
                                $isActive = ($activeRule !== null);
                                $currentFieldId = $isActive ? $activeRule['field_id'] : 0;
                                $currentDir = $isActive ? strtolower($activeRule['dir']) : 'asc';
                                if ($isActive) {
                                    $slotDirLabels = $GLOBALS['BCC_GROUP_DIR_LABELS'][$activeRule['field_type']];
                                } else {
                                    $slotDirLabels = array('asc' => 'artan', 'desc' => 'azalan');
                                }
                            ?>
                                <div class="group-level-row" data-level="<?php echo $slot; ?>" <?php echo (!$isActive && $slot > 1) ? 'hidden' : ''; ?>>
                                    <select name="group_field_<?php echo $slot; ?>">
                                        <option value="">— seç —</option>
                                        <?php foreach ($fields as $f): ?>
                                            <option value="<?php echo (int) $f['id']; ?>" <?php echo $currentFieldId === (int) $f['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="group_dir_<?php echo $slot; ?>">
                                        <option value="asc" <?php echo $currentDir === 'asc' ? 'selected' : ''; ?>><?php echo htmlspecialchars($slotDirLabels['asc'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <option value="desc" <?php echo $currentDir === 'desc' ? 'selected' : ''; ?>><?php echo htmlspecialchars($slotDirLabels['desc'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    </select>
                                    <?php if ($isActive): ?>
                                        <a class="group-remove-btn" href="/grid.php?<?php echo htmlspecialchars($groupRemoveLinks[$activeIdx], ENT_QUOTES, 'UTF-8'); ?>" title="Bu seviyeyi kaldır" aria-label="Bu seviyeyi kaldır">
                                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="#c62828" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <?php if (count($groupRules) < 3): ?>
                            <button type="button" class="link-btn" id="group-add-subgroup">+ Add subgroup</button>
                        <?php endif; ?>
                        <div class="hide-fields-actions">
                            <button type="submit" class="btn-sm" data-group-apply>Uygula</button>
                            <a class="btn-sm" href="/grid.php?<?php echo htmlspecialchars($clearGroupQueryString, ENT_QUOTES, 'UTF-8'); ?>">Temizle</a>
                        </div>
                    </form>
                <?php endif; ?>
            </details>
            <?php endif; ?>

            <?php if (!empty($fields)): ?>
            <details class="sort-panel gs-tool-details">
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 5h9M4 10h6M4 15h3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/><path d="M15 4v11m0 0l-2.5-2.5M15 15l2.5-2.5" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Sort<?php echo !empty($sortRules) ? ' (' . count($sortRules) . ')' : ''; ?>
                </summary>
                <form method="get" action="/grid.php" class="sort-form">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <?php bcc_render_grid_state_hidden_inputs($hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState); ?>
                    <?php for ($slot = 1; $slot <= 3; $slot++):
                        $currentFieldId = 0;
                        $currentDir = 'asc';
                        foreach ($sortRules as $rule) {
                            if ($rule['slot'] === $slot) {
                                $currentFieldId = $rule['field_id'];
                                $currentDir = strtolower($rule['dir']);
                                break;
                            }
                        }
                    ?>
                        <div class="sort-row">
                            <select name="sort_field_<?php echo $slot; ?>">
                                <option value="">— yok —</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?php echo (int) $f['id']; ?>" <?php echo $currentFieldId === (int) $f['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="sort_dir_<?php echo $slot; ?>">
                                <option value="asc" <?php echo $currentDir === 'asc' ? 'selected' : ''; ?>>artan</option>
                                <option value="desc" <?php echo $currentDir === 'desc' ? 'selected' : ''; ?>>azalan</option>
                            </select>
                        </div>
                    <?php endfor; ?>
                    <div class="sort-actions">
                        <button type="submit" class="btn-sm">Uygula</button>
                        <?php if (!empty($sortRules)): ?>
                            <a class="btn-sm" href="/grid.php?<?php echo htmlspecialchars($clearSortQueryString, ENT_QUOTES, 'UTF-8'); ?>">Temizle</a>
                        <?php endif; ?>
                    </div>
                </form>
            </details>
            <?php endif; ?>

            <button type="button" class="gs-tool-btn">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="6.5" stroke="#5f6368" stroke-width="1.4"/><path d="M10 3.5a6.5 6.5 0 010 13" fill="#5f6368"/></svg>
                Color
            </button>
            <?php if (!empty($fields)): ?>
            <details class="row-height-panel gs-tool-details">
                <summary class="gs-tool-btn <?php echo $rowHeight !== 'short' ? 'hide-fields-btn-active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="4" rx="1" stroke="#5f6368" stroke-width="1.3"/><rect x="3" y="12" width="14" height="4" rx="1" stroke="#5f6368" stroke-width="1.3"/></svg>
                    Row height
                </summary>
                <div class="row-height-form">
                    <?php foreach ($GLOBALS['BCC_ROW_HEIGHT_LABELS'] as $rhKey => $rhLabel):
                        $rhOptState = ($rhKey !== 'short') ? array('row_height' => $rhKey) : array();
                        $rhQuery = http_build_query($rowHeightPanelBase + $rhOptState + $wrapHeadersState);
                    ?>
                        <a class="row-height-option" href="/grid.php?<?php echo htmlspecialchars($rhQuery, ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="row-height-option-label"><?php echo htmlspecialchars($rhLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($rowHeight === $rhKey): ?>
                                <svg class="row-height-check" width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 10.5l4 4L16 6" stroke="#1a73e8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    <div class="row-height-divider"></div>
                    <?php
                        $wrapToggleState = $wrapHeaders ? array() : array('wrap_headers' => '1');
                        $wrapQuery = http_build_query($rowHeightPanelBase + $rowHeightState + $wrapToggleState);
                    ?>
                    <a class="row-height-option" href="/grid.php?<?php echo htmlspecialchars($wrapQuery, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="row-height-option-label">Wrap headers</span>
                        <?php if ($wrapHeaders): ?>
                            <svg class="row-height-check" width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 10.5l4 4L16 6" stroke="#1a73e8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php endif; ?>
                    </a>
                </div>
            </details>
            <?php endif; ?>
            <button type="button" class="gs-tool-btn">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M14 6.5a2 2 0 10-1.9-2.6M6 10a2 2 0 10-1.9 2.6M14 13.5a2 2 0 10-1.9 2.6M5.8 9l6.4-3.4M5.8 11l6.4 3.4" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                Share and sync
            </button>

            <?php if (!empty($fields)): ?>
            <div class="gs-search">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#5f6368" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                <input type="text" id="grid-search" placeholder="Ara…">
                <span class="gs-search-nav" id="grid-search-nav" hidden>
                    <span class="gs-search-count" id="grid-search-count"></span>
                    <button type="button" class="gs-search-nav-btn" id="grid-search-prev" aria-label="Önceki eşleşme" disabled>
                        <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M9 7.2L6 4.2 3 7.2" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button type="button" class="gs-search-nav-btn" id="grid-search-next" aria-label="Sonraki eşleşme" disabled>
                        <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M3 4.8L6 7.8 9 4.8" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <div class="gs-view-drawer" id="gs-view-drawer">
            <button type="button" class="gs-view-drawer-create">+ Create new...</button>
            <div class="gs-view-drawer-search">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
                <input type="text" placeholder="Find a view">
            </div>
            <div class="gs-view-drawer-list">
                <div class="gs-view-drawer-view is-selected">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="2" stroke="#1a73e8" stroke-width="1.4"/><path d="M3 8h14M8 3v14" stroke="#1a73e8" stroke-width="1.2"/></svg>
                    <span data-view-name-mirror><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <main class="gs-main">
        <p class="gs-fields-link">
            <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">Alanları yönet</a>
        </p>

        <?php require __DIR__ . '/../src/partials/flash.php'; ?>

        <?php if (empty($fields)): ?>
            <div class="card">
                <p>Bu tabloda henüz alan yok. Önce <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">alan ekleyin</a>.</p>
            </div>
        <?php else: ?>
            <div class="grid-wrap">
                <table class="grid row-h-<?php echo htmlspecialchars($rowHeight, ENT_QUOTES, 'UTF-8'); ?> <?php echo $wrapHeaders ? 'wrap-headers' : ''; ?>">
                    <thead>
                        <tr>
                            <th class="grid-rownum">#</th>
                            <?php foreach ($visibleFields as $f): ?>
                                <th>
                                    <span class="field-badge" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($typeBadges[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) $f['is_required'] === 1): ?><span class="req-mark" title="Zorunlu">*</span><?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <?php if ($canEdit): ?><th class="grid-actions-col">İşlemler</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td class="grid-empty" colspan="<?php echo count($visibleFields) + 1 + ($canEdit ? 1 : 0); ?>">Bu tabloda henüz kayıt yok.</td>
                            </tr>
                        <?php elseif (!empty($groupTree)): ?>
                            <?php
                                $rowNum = 0;
                                $groupColspan = count($visibleFields) + 1 + ($canEdit ? 1 : 0);
                                foreach ($groupTree as $topNode) {
                                    bcc_render_group_node($topNode, $groupFieldNames, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $table['id'], $stateQueryString, $groupColspan, $usersById);
                                }
                            ?>
                        <?php else: ?>
                            <?php foreach ($records as $i => $record):
                                bcc_render_grid_data_row($record, $i + 1, $visibleFields, $cellsByRecord, $canEdit, $table['id'], $stateQueryString, null, $usersById);
                            endforeach; ?>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                            <!-- (b) tablo tabanı + satırı: en son verinin altında, "+" ilk
                                 sütun hizasında. Boş tabloda ve grup açıkken de görünür.
                                 data-grid-add-row (a) ile AYNI JS fonksiyonunu tetikler. -->
                            <tr class="grid-add-row" data-grid-add-row data-tooltip-host>
                                <td class="grid-rownum grid-add-row-plus">+</td>
                                <td colspan="<?php echo count($visibleFields) + 1; ?>" class="grid-add-row-hint">
                                    <span class="gs-kbd-tooltip gs-kbd-tooltip-light">You can also insert a new record anywhere by pressing Shift-Enter</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canEdit): ?>
                <!-- (a) yuvarlak + butonu: JS'siz de çalışır (normal form POST'u,
                     grid.php'nin en üstündeki action=create_record'a gider); JS varken
                     grid.js bu formun submit'ini yakalayıp /api/record_add.php'ye
                     fetch ile bağlar (sayfa yenilenmez). data-grid-add-record, (b)
                     satırıyla AYNI JS fonksiyonunu tetikler — ikinci bir mekanizma yok. -->
                <form method="post" action="/grid.php?<?php echo htmlspecialchars($stateQueryString, ENT_QUOTES, 'UTF-8'); ?>" class="grid-add-record-fab" data-grid-add-record>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_record">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <button type="submit" class="grid-add-record-fab-btn" aria-label="Add record" data-tooltip-host>
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 4v12M4 10h12" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <span class="gs-kbd-tooltip">Add record</span>
                    </button>
                </form>
            <?php else: ?>
                <p class="hint">Bu ekipte kayıt eklemek/düzenlemek için editor veya owner rolü gerekir.</p>
            <?php endif; ?>

            <div class="gs-grid-footer">
                <span class="grid-row-count" id="grid-row-count"></span>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if (!empty($fields)): ?>
<script>
    var BCC_FIELD_TYPES_BY_ID = <?php
        $typesById = array();
        foreach ($fields as $f) {
            $typesById[(int) $f['id']] = $f['field_type'];
        }
        echo json_encode($typesById, JSON_UNESCAPED_UNICODE);
    ?>;
    var BCC_FILTER_OPS = <?php echo json_encode($GLOBALS['BCC_FILTER_OPERATORS'], JSON_UNESCAPED_UNICODE); ?>;
    var BCC_FILTER_NO_VALUE_OPS = <?php echo json_encode($GLOBALS['BCC_FILTER_NO_VALUE_OPS'], JSON_UNESCAPED_UNICODE); ?>;
    // 'user' alanı filtre değeri: takım üyeleri (KVKK — yalnızca bu takım), hücre
    // editöründeki data-options ile AYNI [{"id":..,"name":..}] şekli.
    var BCC_TEAM_MEMBERS = <?php echo json_encode(bcc_user_choices_from_map($usersById), JSON_UNESCAPED_UNICODE); ?>;
    // Kayıt ekleme (Shift+Enter): sort/group aktifken "araya ekleme" görsel olarak
    // anlamsızlaşır (satır zaten sort/group kolonlarına göre yeniden sıralanır) —
    // bu yüzden istemci after_record_id'yi göndermeyip (a)/(b) ile aynı "sona ekle"
    // davranışına sessizce düşer. Filtre aktifken de eklenen boş kayıt filtreyi
    // karşılamayabilir; ikisi de aynı tek uyarı toast'ını tetikler.
    var BCC_SORT_OR_GROUP_ACTIVE = <?php echo (!empty($sortRules) || !empty($groupRules)) ? 'true' : 'false'; ?>;
    var BCC_FILTER_ACTIVE = <?php echo !empty($filterRules) ? 'true' : 'false'; ?>;
    // Sütun dondurma: pozisyonlama (sticky sınıfları) HERKES için uygulanır (viewer
    // dahil); yalnızca sürükleme tutamacı BCC_CAN_EDIT'e bağlıdır.
    var BCC_FROZEN_COLUMN_COUNT = <?php echo (int) $frozenColumnCount; ?>;
    var BCC_MAX_FROZEN_COLUMNS = <?php echo (int) $maxFrozenColumns; ?>;
    var BCC_VIEW_ID = <?php echo (int) $view['id']; ?>;
    var BCC_CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
</script>
<script src="/assets/grid-toolbar.js" defer></script>
<script src="/assets/grid-filter.js" defer></script>
<script src="/assets/grid-hide-fields.js" defer></script>
<script src="/assets/grid-group.js" defer></script>
<script src="/assets/grid-freeze-columns.js" defer></script>
<?php endif; ?>
<?php if ($canEdit && !empty($fields)): ?>
<script src="/assets/grid.js" defer></script>
<?php endif; ?>
<script src="/assets/account-menu.js" defer></script>
<script src="/assets/grid-table-tabs.js" defer></script>
<script>
(function () {
    var drawerToggle = document.getElementById('gs-view-panel-toggle');
    var drawer = document.getElementById('gs-view-drawer');
    if (drawerToggle && drawer) {
        drawerToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            drawer.classList.toggle('is-open');
        });
    }

    document.addEventListener('click', function (e) {
        if (drawer && !drawer.contains(e.target) && e.target !== drawerToggle) {
            drawer.classList.remove('is-open');
        }
    });
})();
</script>
</body>
</html>
