<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

// Her erişimde KVKK ekip izolasyonu: bu tablonun ekibine üye olmayan hiçbir şey göremez.
require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = in_array($role, array('editor', 'owner'), true);
// Yorum ekleyebilme (Airtable: Owner/Editor/Commenter, Read-only HARİÇ) —
// satır genişletme panelindeki yorum girişini gösterip göstermeme burada.
$canComment = in_array($role, array('commenter', 'editor', 'owner'), true);
// Alan oluşturma artık owner-only (bkz. table_fields.php/field_create.php) —
// "+" yeni alan butonu $canEdit'ten ayrıldı, aksi hâlde editor butonu görür
// ama sunucu 403 döner.
$isOwner = ($role === 'owner');

// D1 "Paylaş" popup (Airtable Share paritesi, görsel 1) — "People with access"
// özeti + mevcut kullanıcılar arasından hızlı atama. Tam yönetim ekranı (görsel 2,
// arama/filtre/Excel indir/hiyerarşik rol değişikliği) team_members.php'de YAŞIYOR — burada
// mantık TEKRARLANMIYOR, atama formu doğrudan team_members.php'ye POST eder.
$myRank = $GLOBALS['BCC_ROLE_RANK'][$role];
$shareAssignableRoles = bcc_assignable_roles($myRank);
$shareCollaborators = bcc_fetch_all(
    'SELECT u.id, u.full_name, u.email
     FROM team_members tm
     INNER JOIN users u ON u.id = tm.user_id
     WHERE tm.team_id = :team_id AND u.is_active = 1
     ORDER BY u.full_name',
    array('team_id' => $table['team_id'])
);
$shareCollaboratorPreview = array_slice($shareCollaborators, 0, 4);
$shareCollaboratorExtraCount = count($shareCollaborators) - count($shareCollaboratorPreview);

// Ata kutusu: takımın HENÜZ üyesi olmayan aktif kullanıcılar — team_members.php'nin
// tam sayfa "Kullanıcı" seçicisinin AKSİNE burada zaten üye olanlar hariç tutulur
// (popup'ın amacı YENİ birini eklemek; mevcut üyeleri autocomplete'te göstermek
// gürültü olurdu, onların rolünü değiştirmek görsel 2'nin işi).
$shareExistingIds = array_map('intval', array_column($shareCollaborators, 'id'));
$shareCandidateUsers = bcc_fetch_all('SELECT id, email, full_name FROM users WHERE is_active = 1 ORDER BY full_name');
if (!empty($shareExistingIds)) {
    $shareCandidateUsers = array_values(array_filter($shareCandidateUsers, function ($candidate) use ($shareExistingIds) {
        return !in_array((int) $candidate['id'], $shareExistingIds, true);
    }));
}

// 'user' alan tipi (görüntüleme + hücre/filtre editörü seçenek listesi) için TEK
// kaynak — yalnızca bu takımın (KVKK) aktif üyeleri, bkz. bcc_team_users_by_id().
$usersById = bcc_team_users_by_id($table['team_id']);

// Çoklu view desteği: ?view_id= verilmişse VE bu tabloya aitse o kullanılır
// (bcc_find_view — başka bir tablonun view_id'sini geçmeye çalışan istek
// sessizce reddedilir), yoksa/geçersizse tablonun varsayılan (en eski)
// view'ına düşülür — bcc_get_or_create_default_view() tek-view'lı eski
// davranışla TAM uyumlu, hiçbir çağıran kod yolu bozulmaz.
$user = current_user();
$requestedViewId = isset($_GET['view_id']) ? (int) $_GET['view_id'] : 0;
$view = $requestedViewId ? bcc_find_view($requestedViewId, $table['id']) : null;
if (!$view) {
    $view = bcc_get_or_create_default_view($table['id']);
}

// Sol Views paneli: tablonun TÜM view'ları (favoriler önce) — aynı sorgu,
// paralel bir "view listesi" mantığı yazılmadı.
$allViews = bcc_list_table_views($table['id'], $user ? $user['id'] : null);

// D2/D3 "Share" / "Share and Sync" popover'ları için mutlak linkler —
// src/slack.php'deki bcc_slack_send_webhook() ÇAĞIRAN kodun AYNI scheme+HTTP_HOST
// deseni (ikinci bir URL-inşa yardımcı fonksiyonu yazılmadı, yalnızca 2 kullanım
// yeri var). Bilinçli olarak GÜVENLİ: bu linkler yalnızca normal sayfa URL'leri
// (view_id/base_id taşıyan) — oturumsuz erişim YOK, tıklayan kişi zaten
// oturum açmış + takım üyesi değilse login'e düşer (KVKK izolasyonuna dokunulmadı).
$bccShareScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$bccShareHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$bccShareOrigin = $bccShareScheme . '://' . $bccShareHost;
$interfaceShareUrl = $bccShareOrigin . '/interface.php?base_id=' . (int) $table['base_id'];
$gridViewShareUrl = $bccShareOrigin . '/grid.php?table_id=' . (int) $table['id'] . '&view_id=' . (int) $view['id'];

// Kaydedilebilir görünümler (docs/PROJE-DURUM.md #8): URL'de HİÇ grid state
// parametresi yoksa (yalnızca table_id ile açılmış "çıplak" istek) ve view'ın
// kayıtlı bir grid_state'i varsa, o state'e yönlendirilir — URL kayıtlı görünümü
// yansıtır, aşağıdaki parse_grid_* çağrıları redirect sonrası isteği normal
// şekilde işler (ayrı bir kod yolu yok). POST istekleri (kayıt ekle/sil) etkilenmez.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && bcc_grid_state_is_empty($_GET)) {
    $savedGridState = bcc_get_view_grid_state($view['config']);
    if (!empty($savedGridState)) {
        $redirectQuery = http_build_query(array('table_id' => $table['id'], 'view_id' => $view['id']) + $savedGridState);
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
        bcc_notify_slack_new_record($table['id'], $newId, $user['full_name']);
        $success = 'Kayıt eklendi.';
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

// Sorgu kurma mantığı bcc_build_grid_records_query()'ye taşındı (src/schema.php)
// — public/api/view_export_xlsx.php (Excel indir, aktif sort/filter'ı AYNEN
// uygulamalı) de AYNI fonksiyonu çağırıyor, paralel bir sorgu-kurma mantığı YOK.
list($recordsSql, $recordsParams) = bcc_build_grid_records_query($table['id'], $groupRules, $sortRules, $filterRules, $filterLogic);

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

$baseState = array('table_id' => $table['id'], 'view_id' => $view['id']);
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

// D4 — sütun başlığı "▾" menüsü: her alan için Sort/Filter/Group/Hide
// linkleri, MEVCUT panel state helper'ları ÜZERİNE inşa edilir (yeni bir
// state mantığı YOK). Sort/Group tek tıkla UYGULANIR ve slot 1'e YAZILIR —
// diğer sort/group kurallarının YERİNİ alır (Airtable'ın tek-tık kısayolu
// gibi hızlı/şaşırtıcı-olmayan davranış, onaylandı). Filter ise değer
// gerektirdiği için yalnızca panelde bu alanı ÖN SEÇİLİ+AÇIK gösterir,
// mevcut filtre kurallarına dokunmaz (bkz. aşağıdaki $openFilterFieldId).
function bcc_grid_th_menu_links($f, $baseState, $filterState, $groupState, $hiddenFieldsState, $rowHeightState, $wrapHeadersState, $sortState, $filterRules, $hiddenFieldIds)
{
    $fieldId = (int) $f['id'];

    $sortAscQuery = http_build_query($baseState + array('sort_field_1' => $fieldId, 'sort_dir_1' => 'asc') + $filterState + $hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState);
    $sortDescQuery = http_build_query($baseState + array('sort_field_1' => $fieldId, 'sort_dir_1' => 'desc') + $filterState + $hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState);
    $groupQuery = http_build_query($baseState + $sortState + $filterState + $hiddenFieldsState + array('group_field_1' => $fieldId, 'group_dir_1' => 'asc') + $rowHeightState + $wrapHeadersState);
    $filterQuery = http_build_query($baseState + $sortState + $filterState + $hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState + array('open_filter_field' => $fieldId));

    $newHiddenIds = array_values(array_unique(array_merge($hiddenFieldIds, array($fieldId))));
    $hideQuery = http_build_query($baseState + $sortState + $filterState + $groupState + $rowHeightState + $wrapHeadersState + array('hidden_fields' => implode(',', $newHiddenIds)));

    return array(
        'sort_asc' => $sortAscQuery,
        'sort_desc' => $sortDescQuery,
        'group' => $groupQuery,
        'filter' => $filterQuery,
        'hide' => $hideQuery,
    );
}

// Filtre panelini "bu alana göre filtrele" ile açan geçici (kalıcı state'e
// karışmayan) işaret — ilk BOŞ slotu bulur, mevcut filtre kurallarının
// hiçbirinin yerini almaz (parse_grid_filter_rules zaten cond/value'suz
// satırları sessizce eler, bu yüzden $filterState'e YAZILMAZ).
$openFilterFieldId = isset($_GET['open_filter_field']) ? (int) $_GET['open_filter_field'] : 0;
if ($openFilterFieldId !== 0 && !isset($fieldsById[$openFilterFieldId])) {
    $openFilterFieldId = 0;
}
$openFilterSlot = 0;
if ($openFilterFieldId !== 0) {
    for ($s = 1; $s <= 5; $s++) {
        $slotTaken = false;
        foreach ($filterRules as $rule) {
            if ($rule['slot'] === $s) {
                $slotTaken = true;
                break;
            }
        }
        if (!$slotTaken) {
            $openFilterSlot = $s;
            break;
        }
    }
}

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

// 'attachment' alanları cell_values'ta değil, ayrı bir tabloda yaşıyor — aynı
// toplu-sorgu deseni (bcc_fetch_cells_by_record ile paralel).
$attachmentsByRecord = !empty($records) ? bcc_fetch_attachments_by_record(array_column($records, 'id')) : array();

$typeLabels = $GLOBALS['BCC_FIELD_TYPES'];

// Çok seviyeli gruplama: $records üzerinde TEK geçişte, sıralı gelen kayıtları
// iç içe bir ağaca böler. Segmentleme HAM DEĞER (group_raw_value_N, SQL'in
// GROUP BY değil ORDER BY ile getirdiği ham kolon) üzerinden karşılaştırılır;
// cell_display_text() yalnızca başlıkta GÖSTERİM için çağrılır, karşılaştırmaya
// hiç girmez. Bir seviyenin ham değeri bir önceki kayıttan farklıysa, o seviye
// VE ondan sonraki (daha iç) tüm seviyeler için yeni segment açılır — iç
// sayaçlar bu noktada sıfırlanır (bkz. $counters), böylece dıştaki bir grup
// değişince içteki "0-1" gibi bir path yanlışlıkla eski sayaçtan devam etmez.
// Tüm tipler (checkbox dahil — cell_display_text() artık 'İşaretli'/'İşaretsiz'
// döndürüyor) cell_display_text() ile (tarih formatı, seçim etiketleri vb.
// doğru çıksın diye) biçimlendirilir. (Empty) davranışı: tek seviyeli
// gruplamadaki gibi.
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
                $display = '(Boş)';
            } else {
                $display = cell_display_text($rule['field_type'], bcc_group_cell_row($rule['column'], $rawValue), $usersById, $rule['options']);
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
function bcc_render_group_node($node, $groupFieldNames, &$rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $colspan, $usersById = array(), $allFields = null, $attachmentsByRecord = array())
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
            bcc_render_grid_data_row($record, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $node['path'], $usersById, $allFields, $attachmentsByRecord);
        }
    } else {
        foreach ($node['children'] as $child) {
            bcc_render_group_node($child, $groupFieldNames, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $colspan, $usersById, $allFields, $attachmentsByRecord);
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
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('style.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('grid-shell.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('home.css'); ?>">
</head>
<body class="gs-body">

<aside class="gs-rail">
    <a href="/dashboard.php" class="gs-rail-logo" title="Home'a dön">
        <img src="/assets/logo.png" alt="BCC-Core" class="gs-rail-logo-img">
        <svg class="gs-rail-back-icon" width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M12.5 4.5L6 10l6.5 5.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <div class="gs-rail-bottom">
        <?php
        // Bildirim paneli: Home ile AYNI partial (src/partials/notifications_panel.php)
        // — veri hazırlama + DOM tek yerde, ikinci bir kopya YOK. Yalnızca tetikleyici
        // görünümü (koyu zemin) farklı olduğu için parametrelenmiş.
        $notifUser = $gridUser;
        $notifTriggerClass = 'gs-rail-icon-btn';
        $notifIconSize = 18;
        $notifIconStroke = '#ccc';
        require __DIR__ . '/../src/partials/notifications_panel.php';
        ?>
        <?php
        $accountMenuPrefix = 'gs';
        $accountMenuUser = $gridUser;
        require __DIR__ . '/../src/partials/account_menu.php';
        ?>
    </div>
</aside>

<div class="gs-main-col">
    <header class="gs-topbar">
        <a href="/dashboard.php" class="gs-topbar-left" title="Ana sayfaya dön">
            <span class="gs-base-icon" style="background: <?php echo htmlspecialchars(bcc_base_icon_color($table['base_id']), ENT_QUOTES, 'UTF-8'); ?>;"><?php echo bcc_base_icon_svg(14); ?></span>
            <span class="gs-base-name"><?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <div class="gs-topbar-right">
            <details class="gs-tool-details collab-popover-trigger" name="gs-table-tab-menu">
                <summary class="gs-btn-ghost">Paylaş</summary>
                <div class="collab-popover-form">
                    <div class="collab-popover-title">"<?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?>" paylaş</div>

                    <form class="collab-popover-assign" method="post" action="/team_members.php?team_id=<?php echo (int) $table['team_id']; ?>">
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

                    <a class="collab-popover-people" href="/team_members.php?team_id=<?php echo (int) $table['team_id']; ?>">
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
            <details class="gs-tool-details share-popover-trigger" name="gs-table-tab-menu">
                <summary class="gs-btn-ghost">Bağlantı</summary>
                <div class="share-popover-form">
                    <div class="share-popover-label">Bağlantıyı paylaş</div>
                    <div class="share-popover-row">
                        <input type="text" class="share-popover-input" data-share-url-input readonly value="<?php echo htmlspecialchars($interfaceShareUrl, ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select()">
                        <button type="button" class="btn-sm" data-share-copy-btn>Kopyala</button>
                    </div>
                    <p class="share-popover-note">Bu bağlantı yalnızca oturum açmış takım üyeleri için çalışır.</p>
                </div>
            </details>
            <a href="/interface.php?base_id=<?php echo (int) $table['base_id']; ?>" class="gs-btn-ghost">Başlat</a>
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
                    <?php if ($canEdit): ?>
                    <details class="gs-table-tab-menu gs-table-tab-import-menu" name="gs-table-tab-menu">
                        <summary class="gs-table-tab-caret" aria-label="Sekme seçenekleri">
                            <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M3 4.5l3 3 3-3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="gs-table-tab-menu-panel gs-table-tab-import-menu-panel">
                            <button type="button" class="gs-table-tab-menu-item" data-table-import="<?php echo (int) $st['id']; ?>">Veri içe aktar</button>
                            <div class="gs-table-tab-menu-divider"></div>
                            <button type="button" class="gs-table-tab-menu-item gs-table-tab-menu-item-danger" data-table-clear="<?php echo (int) $st['id']; ?>">Verileri temizle</button>
                        </div>
                    </details>
                    <?php endif; ?>
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
                    <span class="gs-kbd-tooltip-label">Tüm tablolar</span>
                    <span class="gs-kbd-badge"><span class="gs-kbd-mac">⌘ J</span><span class="gs-kbd-other">Ctrl J</span></span>
                </span>
            </summary>
            <div class="gs-table-tab-menu-panel gs-all-tables-panel">
                <div class="gs-all-tables-search">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <input type="text" placeholder="Tablo ara" data-all-tables-search>
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
                        data-view-sync-id="<?php echo (int) $view['id']; ?>"
                        <?php if ($canEdit): ?>data-view-id="<?php echo (int) $view['id']; ?>"<?php endif; ?>
                    ><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <div class="gs-view-info-popover">
                    <div class="gs-view-info-title" data-view-sync-id="<?php echo (int) $view['id']; ?>"><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="gs-view-info-row">
                        <span class="gs-view-info-label">Düzenleme</span>
                        <span class="gs-view-info-value">Herkes görünüm yapılandırmasını düzenleyebilir.</span>
                    </div>
                    <?php if (!empty($view['created_by_name'])): ?>
                    <div class="gs-view-info-row">
                        <span class="gs-view-info-label">Oluşturan</span>
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
                            <span class="gs-view-options-collab-label">Ortak görünüm</span>
                            <span class="gs-view-options-collab-desc">Düzenleyici ve üzeri roller görünüm yapılandırmasını düzenleyebilir.</span>
                        </span>
                        <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M4.5 3l3 3-3 3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="gs-table-tab-menu-divider"></div>
                    <?php if ($canEdit): ?>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-save-state-btn">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 4.5A1.5 1.5 0 015.5 3h8l3.5 3.5v9a1.5 1.5 0 01-1.5 1.5h-10A1.5 1.5 0 014 15.5v-11z" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/><path d="M6.5 3v4h6V3M6.5 17v-5h7v5" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        Görünümü kaydet
                    </button>
                    <div class="gs-table-tab-menu-divider"></div>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-rename-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M13.5 3.5l3 3-9.5 9.5H4v-3l9.5-9.5z" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        Görünümü yeniden adlandır
                    </button>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-edit-desc-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="#5f6368" stroke-width="1.3"/><path d="M10 9v5M10 6.5v.01" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                        Görünüm açıklamasını düzenle
                    </button>
                    <div class="gs-table-tab-menu-divider"></div>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-duplicate-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="7" y="7" width="9" height="9" rx="1.5" stroke="#5f6368" stroke-width="1.3"/><path d="M4 13V5.5A1.5 1.5 0 015.5 4H13" stroke="#5f6368" stroke-width="1.3"/></svg>
                        Görünümü çoğalt
                    </button>
                    <div class="gs-table-tab-menu-divider"></div>
                    <?php endif; ?>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-download-xlsx-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0l-3-3m3 3l3-3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 14v1.5A1.5 1.5 0 005.5 17h9a1.5 1.5 0 001.5-1.5V14" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                        Excel indir
                    </button>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-print-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="5" y="3" width="10" height="5" stroke="#5f6368" stroke-width="1.3"/><rect x="3" y="8" width="14" height="6" rx="1" stroke="#5f6368" stroke-width="1.3"/><rect x="6" y="12" width="8" height="5" stroke="#5f6368" stroke-width="1.3"/></svg>
                        Görünümü yazdır
                    </button>
                    <?php if ($canEdit): ?>
                    <button type="button" class="gs-table-tab-menu-item gs-table-tab-menu-item-danger" id="gs-view-delete-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="#c62828" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Görünümü sil
                    </button>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php if ($canEdit): ?>
        <div class="gs-view-desc-overlay" id="gs-view-desc-overlay" hidden>
            <div class="gs-view-desc-modal">
                <div class="gs-view-desc-title">Görünüm açıklamasını düzenle</div>
                <textarea class="gs-view-desc-textarea" id="gs-view-desc-textarea" maxlength="500" placeholder="Bu görünüm hakkında kısa bir açıklama..."><?php echo htmlspecialchars((string) $view['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div class="gs-view-desc-actions">
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-desc-cancel">İptal</button>
                    <button type="button" class="gs-btn-primary" id="gs-view-desc-save">Kaydet</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
        <div class="gs-view-desc-overlay" id="gs-table-import-overlay" hidden>
            <div class="gs-view-desc-modal">
                <div class="gs-view-desc-title">Veri içe aktar (Excel)</div>
                <p class="gs-import-help">
                    Dosyadaki ilk satır alan adları olmalı. Yalnızca tablodaki
                    alan adlarıyla BİREBİR eşleşen sütunlar aktarılır, eşleşmeyenler
                    atlanır. Yalnızca <strong>.xlsx</strong> dosyaları desteklenir
                    ("Excel indir" ile aldığınız dosyayla aynı format).
                    Dosya eki (attachment) alanları içe aktarılamaz.
                </p>
                <input type="file" class="gs-import-file-input" id="gs-table-import-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                <div class="gs-import-result" id="gs-table-import-result" hidden></div>
                <div class="gs-view-desc-actions">
                    <button type="button" class="gs-table-tab-menu-item" id="gs-table-import-cancel">İptal</button>
                    <button type="button" class="gs-btn-primary" id="gs-table-import-submit">İçe Aktar</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="gs-view-toolbar-right">
            <?php if ($canEdit): ?>
            <button type="button" class="gs-tool-btn gs-delete-selected-btn" id="gs-delete-selected-btn" hidden data-table-id="<?php echo (int) $table['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span id="gs-delete-selected-count">0</span> seçileni sil
            </button>
            <?php endif; ?>
            <?php if (!empty($fields)): ?>
            <details class="hide-fields-panel gs-tool-details" name="gs-table-tab-menu">
                <summary class="gs-tool-btn <?php echo !empty($hiddenFieldIds) ? 'hide-fields-btn-active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M2.5 6h15M2.5 10h10M2.5 14h6" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <?php if (empty($hiddenFieldIds)): ?>
                        Alanları gizle
                    <?php elseif (count($hiddenFieldIds) === 1): ?>
                        1 gizli alan
                    <?php else: ?>
                        <?php echo count($hiddenFieldIds); ?> gizli alan
                    <?php endif; ?>
                </summary>
                <form method="get" action="/grid.php" class="hide-fields-form" id="hide-fields-form">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <input type="hidden" name="visible_fields_submitted" value="1">
                    <?php bcc_render_grid_state_hidden_inputs($sortState + $filterState + $groupState + $rowHeightState + $wrapHeadersState); ?>
                    <input type="text" class="hide-fields-search" placeholder="Alan ara" data-hide-fields-search>
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
            <details class="filter-panel gs-tool-details" name="gs-table-tab-menu" <?php echo $openFilterSlot !== 0 ? 'open' : ''; ?>>
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M3 4h14l-5.5 6.5V16l-3-1.5v-4L3 4z" stroke="#5f6368" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    Filtrele<?php echo !empty($filterRules) ? ' (' . count($filterRules) . ')' : ''; ?>
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
                        // Sütun başlığı "Bu alana göre filtrele" ile gelindiyse (bkz.
                        // $openFilterFieldId/$openFilterSlot yukarıda) — bu, ilk boş
                        // slotu bu alanla ön-seçili gösterir (henüz operatör/değer yok,
                        // kullanıcı seçecek), gerçek bir filtre kuralı OLUŞTURMAZ.
                        if ($currentRule === null && $slot === $openFilterSlot) {
                            $currentFieldId = $openFilterFieldId;
                            $currentFieldType = $fieldsById[$openFilterFieldId]['field_type'];
                        }
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
                                <?php foreach ($fields as $f):
                                    if ($f['field_type'] === 'attachment') {
                                        continue; // dosya eki alanları filtrelenemez (cell_values karşılığı yok)
                                    }
                                ?>
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
            <details class="group-panel gs-tool-details" name="gs-table-tab-menu">
                <summary class="gs-tool-btn <?php echo !empty($groupRules) ? 'hide-fields-btn-active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="6" cy="6" r="2" stroke="#5f6368" stroke-width="1.3"/><circle cx="14" cy="14" r="2" stroke="#5f6368" stroke-width="1.3"/><path d="M8 6h9M3 14h3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                    Grupla<?php echo !empty($groupRules) ? ' (' . count($groupRules) . ')' : ''; ?>
                </summary>
                <?php if (empty($groupRules)): ?>
                    <div class="group-form" id="group-form-empty">
                        <input type="text" class="hide-fields-search" placeholder="Alan ara" data-group-search>
                        <div class="group-field-list">
                            <?php foreach ($fields as $f):
                                if ($f['field_type'] === 'attachment') {
                                    continue; // dosya eki alanlarına göre gruplanamaz (cell_values karşılığı yok)
                                }
                            ?>
                                <a
                                    class="group-field-option"
                                    href="/grid.php?<?php echo htmlspecialchars(http_build_query($groupFieldLinkBase + array('group_field_1' => $f['id'], 'group_dir_1' => 'asc')), ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"></span>
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
                            <button type="button" class="btn-sm" data-group-collapse-all>Tümünü daralt</button>
                            <button type="button" class="btn-sm" data-group-expand-all>Tümünü genişlet</button>
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
                                        <?php foreach ($fields as $f):
                                            if ($f['field_type'] === 'attachment') {
                                                continue;
                                            }
                                        ?>
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
                            <button type="button" class="link-btn" id="group-add-subgroup">+ Alt grup ekle</button>
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
            <details class="sort-panel gs-tool-details" name="gs-table-tab-menu">
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 5h9M4 10h6M4 15h3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/><path d="M15 4v11m0 0l-2.5-2.5M15 15l2.5-2.5" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Sırala<?php echo !empty($sortRules) ? ' (' . count($sortRules) . ')' : ''; ?>
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
                                <?php foreach ($fields as $f):
                                    if ($f['field_type'] === 'attachment') {
                                        continue; // dosya eki alanlarına göre sıralanamaz (cell_values karşılığı yok)
                                    }
                                ?>
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

            <?php if (!empty($fields)): ?>
            <details class="row-height-panel gs-tool-details" name="gs-table-tab-menu">
                <summary class="gs-tool-btn <?php echo $rowHeight !== 'short' ? 'hide-fields-btn-active' : ''; ?>">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="4" rx="1" stroke="#5f6368" stroke-width="1.3"/><rect x="3" y="12" width="14" height="4" rx="1" stroke="#5f6368" stroke-width="1.3"/></svg>
                    Satır yüksekliği
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
                        <span class="row-height-option-label">Başlıkları sarmala</span>
                        <?php if ($wrapHeaders): ?>
                            <svg class="row-height-check" width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 10.5l4 4L16 6" stroke="#1a73e8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php endif; ?>
                    </a>
                </div>
            </details>
            <?php endif; ?>

            <details class="gs-tool-details share-popover-trigger" name="gs-table-tab-menu">
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M14 6.5a2 2 0 100-4 2 2 0 000 4zM6 11.5a2 2 0 100-4 2 2 0 000 4zM14 16.5a2 2 0 100-4 2 2 0 000 4z" stroke="#5f6368" stroke-width="1.3"/><path d="M7.7 10.3l4.6-2.6M7.7 9.7l4.6 2.6" stroke="#5f6368" stroke-width="1.3"/></svg>
                    Paylaş ve Senkronize Et
                </summary>
                <div class="share-popover-form">
                    <div class="share-popover-label">Şu görünüme bağlantı paylaş</div>
                    <div class="share-popover-row">
                        <input type="text" class="share-popover-input" data-share-url-input readonly value="<?php echo htmlspecialchars($gridViewShareUrl, ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select()">
                        <button type="button" class="btn-sm" data-share-copy-btn>Kopyala</button>
                    </div>
                    <p class="share-popover-note">Bu bağlantı yalnızca oturum açmış takım üyeleri için çalışır.</p>
                </div>
            </details>

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
    </div>

    <div class="gs-body-row">
        <div class="gs-view-drawer" id="gs-view-drawer">
            <?php if ($canEdit): ?>
            <button type="button" class="gs-view-drawer-create">+ Yeni oluştur...</button>
            <?php endif; ?>
            <div class="gs-view-drawer-search">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
                <input type="text" id="gs-view-search-input" placeholder="Görünüm ara" autocomplete="off">
            </div>
            <div class="gs-view-drawer-list" id="gs-view-drawer-list">
                <?php foreach ($allViews as $v):
                    $isActiveView = (int) $v['id'] === (int) $view['id'];
                    $tooltip = !empty($v['created_by_name']) ? 'Oluşturan: ' . $v['created_by_name'] : '';
                ?>
                    <div
                        class="gs-view-drawer-row<?php echo $isActiveView ? ' is-selected' : ''; ?>"
                        data-view-row-id="<?php echo (int) $v['id']; ?>"
                        data-view-row-name="<?php echo htmlspecialchars(mb_strtolower($v['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $tooltip !== '' ? 'title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
                    >
                        <?php if ($canEdit): ?>
                        <span class="gs-view-drag-handle" data-view-drag-handle title="Sürükleyerek sırala">
                            <svg width="10" height="14" viewBox="0 0 10 14" fill="none"><circle cx="2.5" cy="2.5" r="1.2" fill="#9a9aa0"/><circle cx="7.5" cy="2.5" r="1.2" fill="#9a9aa0"/><circle cx="2.5" cy="7" r="1.2" fill="#9a9aa0"/><circle cx="7.5" cy="7" r="1.2" fill="#9a9aa0"/><circle cx="2.5" cy="11.5" r="1.2" fill="#9a9aa0"/><circle cx="7.5" cy="11.5" r="1.2" fill="#9a9aa0"/></svg>
                        </span>
                        <?php endif; ?>
                        <!-- Favori: kullanıcı tercihi, içerik değişikliği DEĞİL — view_rename.php'nin
                             aksine viewer'a da açık (bkz. star_base.php'deki AYNI karar, önceki iş). -->
                        <button
                            type="button"
                            class="gs-view-star-btn"
                            data-view-id="<?php echo (int) $v['id']; ?>"
                            aria-label="Favorilere ekle/çıkar"
                            aria-pressed="<?php echo $v['is_favorite'] ? 'true' : 'false'; ?>"
                        >
                            <svg width="13" height="13" viewBox="0 0 20 20" class="gs-view-star-icon"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        </button>
                        <a class="gs-view-drawer-view" href="/grid.php?table_id=<?php echo (int) $table['id']; ?>&amp;view_id=<?php echo (int) $v['id']; ?>">
                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="2" stroke="#1a73e8" stroke-width="1.4"/><path d="M3 8h14M8 3v14" stroke="#1a73e8" stroke-width="1.2"/></svg>
                            <span data-view-sync-id="<?php echo (int) $v['id']; ?>"><?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                        <?php if ($canEdit): ?>
                        <details class="gs-table-tab-menu gs-view-row-menu" name="gs-table-tab-menu">
                            <summary class="gs-view-row-menu-btn" aria-label="Diğer aksiyonlar">
                                <svg width="13" height="13" viewBox="0 0 20 20"><circle cx="4" cy="10" r="1.6" fill="#5f6368"/><circle cx="10" cy="10" r="1.6" fill="#5f6368"/><circle cx="16" cy="10" r="1.6" fill="#5f6368"/></svg>
                            </summary>
                            <div class="gs-table-tab-menu-panel gs-view-row-menu-panel">
                                <button type="button" class="gs-table-tab-menu-item" data-view-rename data-view-id="<?php echo (int) $v['id']; ?>">Yeniden adlandır</button>
                                <div class="gs-table-tab-menu-divider"></div>
                                <button type="button" class="gs-table-tab-menu-item gs-table-tab-menu-item-danger" data-view-delete data-view-id="<?php echo (int) $v['id']; ?>">Sil</button>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="gs-view-drawer-empty" id="gs-view-drawer-empty" hidden>Sonuç yok</div>
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
                            <th class="grid-rownum">
                                <?php if ($canEdit): ?><input type="checkbox" class="grid-rownum-selectall" id="grid-rownum-selectall" aria-label="Tüm satırları seç"><?php else: ?>#<?php endif; ?>
                            </th>
                            <?php foreach ($visibleFields as $f):
                                $thLinks = bcc_grid_th_menu_links($f, $baseState, $filterState, $groupState, $hiddenFieldsState, $rowHeightState, $wrapHeadersState, $sortState, $filterRules, $hiddenFieldIds);
                                $thSortable = $f['field_type'] !== 'attachment';
                                $thCanHide = (int) $f['id'] !== $primaryFieldId;
                            ?>
                                <th>
                                    <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                    <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) $f['is_required'] === 1): ?><span class="req-mark" title="Zorunlu">*</span><?php endif; ?>
                                    <?php if ($thSortable || $thCanHide): ?>
                                    <details class="grid-th-menu" name="gs-table-tab-menu">
                                        <summary class="grid-th-menu-btn" aria-label="Alan seçenekleri">
                                            <svg width="9" height="9" viewBox="0 0 12 12" fill="none"><path d="M2.5 4.5l3.5 3.5 3.5-3.5" stroke="#5f6368" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </summary>
                                        <div class="grid-th-menu-panel">
                                            <?php if ($thSortable): ?>
                                            <a class="gs-table-tab-menu-item" href="/grid.php?<?php echo htmlspecialchars($thLinks['sort_asc'], ENT_QUOTES, 'UTF-8'); ?>">Sırala A → Z</a>
                                            <a class="gs-table-tab-menu-item" href="/grid.php?<?php echo htmlspecialchars($thLinks['sort_desc'], ENT_QUOTES, 'UTF-8'); ?>">Sırala Z → A</a>
                                            <div class="gs-table-tab-menu-divider"></div>
                                            <a class="gs-table-tab-menu-item" href="/grid.php?<?php echo htmlspecialchars($thLinks['filter'], ENT_QUOTES, 'UTF-8'); ?>">Bu alana göre filtrele</a>
                                            <a class="gs-table-tab-menu-item" href="/grid.php?<?php echo htmlspecialchars($thLinks['group'], ENT_QUOTES, 'UTF-8'); ?>">Bu alana göre grupla</a>
                                            <div class="gs-table-tab-menu-divider"></div>
                                            <?php endif; ?>
                                            <?php if ($thCanHide): ?>
                                            <a class="gs-table-tab-menu-item" href="/grid.php?<?php echo htmlspecialchars($thLinks['hide'], ENT_QUOTES, 'UTF-8'); ?>">Alanı gizle</a>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <?php if ($isOwner): ?>
                            <th class="grid-add-field-th">
                                <details class="grid-add-field-menu gs-tool-details" name="gs-table-tab-menu">
                                    <summary class="grid-add-field-btn" aria-label="Yeni alan ekle">+</summary>
                                    <div class="grid-add-field-panel">
                                        <form class="stacked" id="new-field-form" data-grid-add-field>
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                            <input type="hidden" name="field_type" id="new-field-type-input" required>

                                            <?php
                                            // table_fields.php ile AYNI partial — tip-önce-isim-sonra
                                            // DOM iskeleti iki kez yazılmıyor. Bu popup kompakt kalsın
                                            // diye "Zorunlu alan" onay kutusu burada YOK (önceki hâliyle
                                            // AYNI davranış — table_fields.php'de zaten var).
                                            $fieldTypeLabels = $typeLabels;
                                            $fieldWizardShowRequired = false;
                                            require __DIR__ . '/../src/partials/field_type_wizard_fields.php';
                                            ?>
                                        </form>
                                    </div>
                                </details>
                            </th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td class="grid-empty" colspan="<?php echo count($visibleFields) + 1 + ($isOwner ? 1 : 0); ?>">Bu tabloda henüz kayıt yok.</td>
                            </tr>
                        <?php elseif (!empty($groupTree)): ?>
                            <?php
                                $rowNum = 0;
                                $groupColspan = count($visibleFields) + 1 + ($isOwner ? 1 : 0);
                                foreach ($groupTree as $topNode) {
                                    bcc_render_group_node($topNode, $groupFieldNames, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $table['id'], $stateQueryString, $groupColspan, $usersById, $fields, $attachmentsByRecord);
                                }
                            ?>
                        <?php else: ?>
                            <?php foreach ($records as $i => $record):
                                bcc_render_grid_data_row($record, $i + 1, $visibleFields, $cellsByRecord, $canEdit, $table['id'], $stateQueryString, null, $usersById, $fields, $attachmentsByRecord);
                            endforeach; ?>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                            <!-- Tablo tabanı "+" satırı: en son verinin altında, "+" ilk
                                 sütun hizasında. Boş tabloda ve grup açıkken de görünür. -->
                            <tr class="grid-add-row" data-grid-add-row data-tooltip-host>
                                <td class="grid-rownum grid-add-row-plus">+</td>
                                <td colspan="<?php echo count($visibleFields) + 1; ?>" class="grid-add-row-hint">
                                    <span class="gs-kbd-tooltip gs-kbd-tooltip-light">Shift-Enter'a basarak herhangi bir yere yeni kayıt da ekleyebilirsiniz</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$canEdit): ?>
                <p class="hint">Bu ekipte kayıt eklemek/düzenlemek için editor veya owner rolü gerekir.</p>
            <?php endif; ?>

            <div class="gs-grid-footer">
                <span class="grid-row-count" id="grid-row-count"></span>
            </div>
        <?php endif; ?>
    </main>
    </div>
</div>

<script src="<?php echo bcc_asset_url('dismissable-panel.js'); ?>" defer></script>
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
    // bu yüzden istemci after_record_id'yi göndermeyip "sona ekle" davranışına
    // sessizce düşer. Filtre aktifken de eklenen boş kayıt filtreyi karşılamayabilir;
    // ikisi de aynı tek uyarı toast'ını tetikler.
    var BCC_SORT_OR_GROUP_ACTIVE = <?php echo (!empty($sortRules) || !empty($groupRules)) ? 'true' : 'false'; ?>;
    var BCC_FILTER_ACTIVE = <?php echo !empty($filterRules) ? 'true' : 'false'; ?>;
    // Sütun dondurma: pozisyonlama (sticky sınıfları) HERKES için uygulanır (viewer
    // dahil); yalnızca sürükleme tutamacı BCC_CAN_EDIT'e bağlıdır.
    var BCC_FROZEN_COLUMN_COUNT = <?php echo (int) $frozenColumnCount; ?>;
    var BCC_MAX_FROZEN_COLUMNS = <?php echo (int) $maxFrozenColumns; ?>;
    var BCC_VIEW_ID = <?php echo (int) $view['id']; ?>;
    var BCC_CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
    var BCC_CAN_COMMENT = <?php echo $canComment ? 'true' : 'false'; ?>;
    // "Kaydı gönder" modalının otomatik Konu/Mesaj metni için — DOM'dan
    // scrape etmek yerine (ör. .gs-account-name) diğer BCC_* globalleriyle
    // AYNI desen, tek kaynak sunucudan gelir.
    var BCC_CURRENT_USER_NAME = <?php echo json_encode($gridUser['full_name'], JSON_UNESCAPED_UNICODE); ?>;
    var BCC_TABLE_NAME = <?php echo json_encode($table['name'], JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="<?php echo bcc_asset_url('grid-toolbar.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-filter.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-hide-fields.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-group.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-column-drag.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-column-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-freeze-columns.js'); ?>" defer></script>
<?php endif; ?>
<?php if ($canEdit && !empty($fields)): ?>
<script src="<?php echo bcc_asset_url('grid.js'); ?>" defer></script>
<?php endif; ?>
<?php if ($isOwner && !empty($fields)): ?>
<script>
    var BCC_SELECT_FIELD_TYPES = <?php echo json_encode($GLOBALS['BCC_SELECT_FIELD_TYPES'], JSON_UNESCAPED_UNICODE); ?>;
</script>
<!-- Tip-önce-isim-sonra sihirbazı: table_fields.php ile PAYLAŞILAN aynı script,
     ikinci bir kopya YOK. Gönderim (fetch + kapat + reload) grid-add-field.js'de. -->
<script src="<?php echo bcc_asset_url('field-type-wizard.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-add-field.js'); ?>" defer></script>
<?php endif; ?>
<?php if (!empty($fields)): ?>
<!-- Genişlet paneli TÜM takım üyelerine açık (Airtable: kayıt görüntüleme
     herkese açık, yorum commenter+, hücre düzenleme editor+) — grid-row-detail.js
     içeride BCC_CAN_EDIT/BCC_CAN_COMMENT'e göre salt-okunur/düzenlenebilir/
     yorum-yazılabilir dallara ayrılır, ikinci bir panel YOK. -->
<div class="grid-detail-overlay" id="grid-detail-overlay" hidden>
    <div class="grid-detail-modal">
        <div class="grid-detail-header">
            <div class="grid-detail-nav">
                <button type="button" class="grid-detail-nav-btn" id="grid-detail-prev" aria-label="Önceki kayıt">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 7.2L6 3.8l3.5 3.4" stroke="#5f6368" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button type="button" class="grid-detail-nav-btn" id="grid-detail-next" aria-label="Sonraki kayıt">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 4.8L6 8.2l3.5-3.4" stroke="#5f6368" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
            <div class="grid-detail-title" id="grid-detail-title"></div>
            <details class="grid-detail-more-menu" id="grid-detail-more-menu">
                <summary class="grid-detail-nav-btn" aria-label="Diğer seçenekler">
                    <svg width="14" height="14" viewBox="0 0 20 20"><circle cx="4" cy="10" r="1.6" fill="#5f6368"/><circle cx="10" cy="10" r="1.6" fill="#5f6368"/><circle cx="16" cy="10" r="1.6" fill="#5f6368"/></svg>
                </summary>
                <div class="gs-table-tab-menu-panel grid-detail-more-panel">
                    <?php if ($canEdit): ?>
                    <!-- Frontend kapısı yalnızca UX içindir — commenter/viewer'da
                         buton DOM'da bile yok. Asıl karar record_send.php'de
                         require_role($teamId, 'editor'), burada TEKRAR edilmiyor. -->
                    <button type="button" class="gs-table-tab-menu-item" id="grid-detail-send-btn">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="3" y="5" width="14" height="10" rx="1.5" stroke="#5f6368" stroke-width="1.3"/><path d="M3.5 6l6.5 5 6.5-5" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Kaydı gönder
                    </button>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                    <!-- Frontend kapısı yalnızca UX içindir — commenter/viewer'da
                         buton DOM'da bile yok. Asıl karar record_duplicate.php'de
                         require_role($teamId, 'editor'), burada TEKRAR edilmiyor. -->
                    <button type="button" class="gs-table-tab-menu-item" id="grid-detail-duplicate-btn">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="6.5" y="6.5" width="10" height="10" rx="1.5" stroke="#5f6368" stroke-width="1.3"/><path d="M13.5 6.5V5a1.5 1.5 0 0 0-1.5-1.5H5A1.5 1.5 0 0 0 3.5 5v7A1.5 1.5 0 0 0 5 13.5h1.5" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Kaydı çoğalt
                    </button>
                    <?php endif; ?>
                    <button type="button" class="gs-table-tab-menu-item" id="grid-detail-print-btn">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="5" y="7.5" width="10" height="5.5" rx="1" stroke="#5f6368" stroke-width="1.3"/><path d="M6 7.5V4.5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v3M6 13v2.5a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V13" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Kaydı yazdır
                    </button>
                    <?php if ($canEdit): ?>
                    <div class="gs-table-tab-menu-divider"></div>
                    <!-- Frontend kapısı yalnızca UX içindir — commenter/viewer'da
                         buton (ve tek amacı onu ayırmak olan divider) DOM'da bile
                         yok. Asıl karar record_soft_delete.php'de
                         require_role($teamId, 'editor'), burada TEKRAR edilmiyor. -->
                    <button type="button" class="gs-table-tab-menu-item gs-table-tab-menu-item-danger" id="grid-detail-delete-btn">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="#c62828" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Kaydı sil
                    </button>
                    <?php endif; ?>
                </div>
            </details>
            <button type="button" class="grid-detail-nav-btn" id="grid-detail-copy-link" aria-label="Kayıt bağlantısını kopyala">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5.5 8.5l3-3M6.2 4.3l.6-.6a2 2 0 0 1 2.8 2.8l-.6.6M7.8 9.7l-.6.6a2 2 0 0 1-2.8-2.8l.6-.6" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="grid-detail-nav-btn" id="grid-detail-comments-toggle" aria-label="Yorumları göster/gizle">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 3.5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H6l-2.5 2v-2H3a1 1 0 0 1-1-1v-5z" stroke="#5f6368" stroke-width="1.4" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="grid-detail-close" id="grid-detail-close" aria-label="Kapat">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="#5f6368" stroke-width="1.5" stroke-linecap="round"/></svg>
            </button>
        </div>
        <!-- Yazdırma-özel üst/alt bilgi: ekranda hep gizli (style.css), JS
             "Kaydı yazdır"a basılınca window.print()'ten HEMEN önce dolduruyor
             (bkz. grid-row-detail.js preparePrintView()) — sunucu tarafında
             hesaplanmıyor çünkü base adı zaten sayfada (.gs-base-name) var,
             ikinci bir sorgu YOK. -->
        <div class="grid-detail-print-meta" id="grid-detail-print-meta-top"></div>
        <div class="grid-detail-body">
            <div class="grid-detail-fields" id="grid-detail-fields"></div>
            <div class="grid-detail-comments">
                <div class="grid-detail-comments-header">Tüm yorumlar</div>
                <div class="grid-detail-comments-list" id="grid-detail-comments-list"></div>
                <?php if ($canComment): ?>
                <form class="grid-detail-comments-form" id="grid-detail-comments-form">
                    <input type="text" id="grid-detail-comments-input" placeholder="Yorum bırakın" maxlength="4000" autocomplete="off">
                    <button type="submit">Gönder</button>
                </form>
                <?php else: ?>
                <p class="hint grid-detail-comments-hint">Yorum yapmak için commenter, editor veya owner rolü gerekir.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="grid-detail-print-meta" id="grid-detail-print-meta-bottom"></div>
    </div>
</div>
<!-- "Kaydı gönder" modalı (Airtable "Send record" paritesi) — kayıt detay
     overlay'inden AYRI, kendi backdrop'ı olan bir ikinci overlay (grid-shell.css
     .gs-view-desc-overlay/grid-table-data.js import overlay'iyle AYNI kanıtlanmış
     [hidden]+açık override deseni, bkz. style.css). Konu/Mesaj/alan önizlemesi
     TAMAMEN JS'te dolduruluyor (grid-row-detail.js) — sunucu burada yalnızca
     boş iskeleti basıyor, ikinci bir PHP render yolu YOK. -->
<div class="grid-send-overlay" id="grid-send-overlay" hidden>
    <div class="grid-send-modal">
        <div class="grid-send-header">
            <div class="grid-send-title">Kaydı gönder</div>
            <button type="button" class="grid-detail-close" id="grid-send-close" aria-label="Kapat">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="#5f6368" stroke-width="1.5" stroke-linecap="round"/></svg>
            </button>
        </div>
        <div class="grid-send-body">
            <label class="grid-send-label" for="grid-send-to">Kime</label>
            <input type="text" id="grid-send-to" class="grid-send-input" autocomplete="off" placeholder="Alıcılar (birden fazla e-posta adresini virgülle ayırın). En fazla 15 alıcı.">
            <div class="grid-send-error" id="grid-send-to-error" hidden>En fazla 15 alıcı ekleyebilirsiniz.</div>

            <label class="grid-send-label" for="grid-send-subject">Konu</label>
            <input type="text" id="grid-send-subject" class="grid-send-input" autocomplete="off">

            <label class="grid-send-label" for="grid-send-message">Mesaj</label>
            <textarea id="grid-send-message" class="grid-send-input grid-send-textarea"></textarea>

            <div class="grid-send-preview" id="grid-send-preview"></div>

            <label class="grid-send-toggle-row">
                <span class="grid-send-toggle-switch">
                    <input type="checkbox" id="grid-send-use-grid-layout">
                    <span class="grid-send-toggle-track"></span>
                </span>
                Tablo düzenini kullan
            </label>
            <label class="grid-send-toggle-row">
                <span class="grid-send-toggle-switch">
                    <input type="checkbox" id="grid-send-copy-self">
                    <span class="grid-send-toggle-track"></span>
                </span>
                Bir kopyasını bana gönder
            </label>
        </div>
        <!-- Backend hatası (rol reddi/geçersiz alıcı/SMTP hatası) burada
             gösterilir — 15-alıcı uyarısıyla AYNI .grid-send-error sınıfı,
             ikinci bir hata stili YAZILMADI. -->
        <div class="grid-send-error grid-send-form-error" id="grid-send-form-error" hidden></div>
        <div class="grid-send-footer">
            <button type="button" class="gs-btn-ghost" id="grid-send-cancel">İptal</button>
            <button type="button" class="gs-btn-primary" id="grid-send-submit">Gönder</button>
        </div>
    </div>
</div>
<script src="<?php echo bcc_asset_url('grid-row-detail.js'); ?>" defer></script>
<?php endif; ?>
<script src="<?php echo bcc_asset_url('account-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-table-tabs.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-view-manage.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-table-data.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('share-popover.js'); ?>" defer></script>
<!-- Bildirim paneli JS'i home.js'de yaşıyor (#home-notif elemanına bağlanır) —
     dosyadaki diğer bloklar (arama, yıldız, sidebar) kendi elemanları burada
     bulunmadığı için no-op kalır, ikinci bir bildirim mekanizması YAZILMADI. -->
<script src="<?php echo bcc_asset_url('home.js'); ?>" defer></script>
<script>
(function () {
    // Görünüm paneli artık açılır/kapanır bir dropdown DEĞİL, home.js'deki
    // #home-sidebar-toggle ile AYNI desende kalıcı/daraltılabilir bir sol
    // panel (bkz. grid-shell.css .gs-view-drawer.is-collapsed) — bu yüzden
    // dışarı tıklayınca kapanma YOK (kalıcı panelde anlamsız).
    var drawerToggle = document.getElementById('gs-view-panel-toggle');
    var drawer = document.getElementById('gs-view-drawer');
    if (drawerToggle && drawer) {
        drawerToggle.addEventListener('click', function () {
            drawer.classList.toggle('is-collapsed');
        });
    }
})();
</script>
</body>
</html>
