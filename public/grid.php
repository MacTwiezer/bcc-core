<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

// Her erişimde KVKK ekip izolasyonu: bu tablonun ekibine üye olmayan hiçbir şey göremez.
require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);

// Yetenekler TEK KAYNAKTAN gelir (src/auth.php) — burada "in_array($role,
// array('editor','owner'))" gibi elle yazılmış bir eşik YOK; arayüzün gizlediği
// ile uçnoktaların reddettiği aynı fonksiyonlardan okunur.
$canEdit = bcc_can_edit_records($role);          // kayıt/görünüm/içe aktarma
$canComment = bcc_can_comment($role);            // yorum (Read-only hariç)
$isOwner = bcc_can_manage_schema($role);         // alan/tablo şeması
$canManageMembers = bcc_can_manage_members($role); // "Paylaş" popup'ındaki atama

// D1 "Paylaş" popup (OpsFlow Share davranışı, görsel 1) — "People with access"
// özeti. Katılımcı EKLEME/ROL DEĞİŞTİRME/ÇIKARMA artık sayfa üstünde açılan
// "Paylaş" MODALINDA (src/partials/share_modal.php + assets/share-modal.js);
// popup'ın eskiden team_members.php'ye tam sayfa POST eden hızlı atama formu
// KALDIRILDI — modalın davet kutusu aynı işi yönlendirme olmadan yapıyor.
// Tam yönetim ekranı (arama/rol filtresi/toplu çıkarma/Excel indir/"Ekleyen"
// ve "Eklenme tarihi" kolonları) team_members.php'de YAŞAMAYA DEVAM EDİYOR;
// modalın altındaki "Tüm üye ayarları →" oraya gider.
require_once __DIR__ . '/../src/share_modal_payload.php';

$myRank = $GLOBALS['BCC_ROLE_RANK'][$role];

// Modalın TEK veri kaynağı — uçnoktaların mutasyondan sonra döndürdüğü yapının
// AYNISI (bkz. src/share_modal_payload.php). Popup'taki özet de buradan
// türetiliyor: iki ayrı sorgu olsaydı aynı sayı iki farklı yerde hesaplanırdı.
$shareModalPayload = bcc_share_modal_payload($table['team_id'], $role);
$shareModalTeamId = (int) $table['team_id'];
$shareModalTeamName = $table['base_name'];

$shareCollaborators = $shareModalPayload['collaborators'];
$shareCollaboratorPreview = array_slice($shareCollaborators, 0, 4);
$shareCollaboratorExtraCount = count($shareCollaborators) - count($shareCollaboratorPreview);

// Davet kutusunun <datalist> önerileri: takımın HENÜZ üyesi olmayan aktif
// kullanıcılar (bekleyen/pasif hesaplar da hariç — onlar zaten üye).
// team_members.php'nin tam sayfa "Kullanıcı" seçicisinin AKSİNE mevcut üyeler
// listelenmez; onların rolünü değiştirmek modalın LİSTE bölümünün işi.
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

// GÖRÜNÜM TÜRÜ YÖNLENDİRMESİ (Grup View-Form) — view_type'ın OKUNUP dallandığı
// İLK nokta. Bu dosyanın geri kalanının tamamı tablo varsayımı üzerine kurulu
// (sort/filter/group panelleri, dondurulmuş sütunlar, satır yüksekliği), bu
// yüzden başka türler için erken dal DEĞİL, ayrı sayfaya YÖNLENDİRME yapılıyor.
// Böylece kullanıcının elindeki eski bir grid linki (ya da bir yer imi) doğru
// sayfaya düşer, "form görünümünü tablo olarak render etmeye çalışma" durumu
// hiç oluşmaz.
// ⚠️ POST istekleri (kayıt ekle vb.) YÖNLENDİRİLMEZ — 302 gövdeyi düşürürdü;
// zaten form görünümünde bu formlar hiç render edilmiyor.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($view['view_type']) && $view['view_type'] !== 'grid') {
    header('Location: ' . bcc_view_route_for($view['view_type'], $table['id'], $view['id']));
    exit;
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
        // Bu, kayıt oluşturmanın DÖRDÜNCÜ noktası (JS'siz form yolu — diğer üçü
        // record_add.php / record_duplicate.php / table_import_xlsx.php).
        // Grup C2'ye kadar transaction'ı YOKTU: tek bir INSERT olduğu için
        // sorun değildi, ama bcc_assign_autonumbers() artık sayaç UPDATE'i +
        // hücre INSERT'i de ekliyor — üçü ayrı ayrı commit edilirse araya düşen
        // bir hata numarayı "yakar" (sayaç ilerlemiş, kayıtta numara yok).
        // Diğer üç nokta ile TUTARLI hale getirildi.
        $nextPos = (int) bcc_fetch_column('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :table_id', array(':table_id' => $table['id']));

        $user = current_user();
        try {
            bcc_begin_transaction();
            bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:table_id, :position, :created_by)', array(':table_id' => $table['id'], ':position' => $nextPos, ':created_by' => $user['id']));
            $newId = bcc_last_insert_id();
            // ⚠️ bcc_last_insert_id() çağrısından SONRA — LAST_INSERT_ID(expr) onu ezer.
            bcc_assign_autonumbers($table['id'], $newId);
            // log_audit() commit'TEN ÖNCE, AYNI transaction içinde —
            // record_add.php'deki AYNI gerekçe (orada "bulunan gerçek bug"
            // olarak belgelenmiş: audit yazması patlarsa kayıt DB'de kalmasın).
            log_audit('record.create', 'record', $newId, array('table_id' => $table['id']), $table['team_id']);
            bcc_commit();
        } catch (Throwable $e) {
            bcc_rollback();
            throw $e;
        }

        // Slack bildirimi commit'ten SONRA (DB mutasyonu değil, kendi try/catch'i
        // zaten var) — record_add.php ile AYNI sıra.
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
// çekildiği için ilk eleman) OpsFlow'daki gibi hiçbir zaman gizlenemez — bu kural
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

// Sütun genişliği (sürükle-boyutlandır) — OPT-IN: harita BOŞSA tablo bugünkü
// otomatik yerleşiminde (`width:auto; min-width:100%`, table-layout auto) kalır
// ve hiçbir görünüm değişmez. Kullanıcı bir kenarı ilk kez sürüklediğinde
// grid-column-resize.js o anki TÜM genişlikleri ölçüp kaydeder; bundan sonra
// burası `table-layout: fixed` + <colgroup> üretir ve sütunlar birebir
// kaydedilen piksel değerinde olur (auto layout'ta bir <th> width'i yalnızca
// ÖNERİdir — min-width:100% esnemesi onu ezerdi).
//
// İKİNCİ BİR KALICILIK YOLU DAHA VAR: grid-column-resize.js her sürükleme
// sonunda haritayı localStorage'a da yazıyor ve BURASI hiç genişlik render
// etmediyse onu istemcide uyguluyor (sunucu kaydı her zaman ÖNCELİKLİ). Bu,
// (a) POST başarısız olduğunda genişliğin F5'te kaybolmamasını, (b) yalnızca
// okuma yetkisiyle bakan kullanıcının da kendi genişliklerini saklayabilmesini
// sağlıyor — view_config_update.php 'editor' rolü istiyor.
$columnWidths = bcc_get_column_widths($view['config'], $visibleFields);
$hasColumnWidths = !empty($columnWidths);

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
// diğer sort/group kurallarının YERİNİ alır (OpsFlow'un tek-tık kısayolu
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

// ---- Sıralama panelinde BASILACAK satırlar --------------------------------
// Eskiden panel KOŞULSUZ 3 boş satır basıyordu. Artık yalnızca mevcut kurallar,
// hiç yoksa TEK boş satır; gerisini "+ Sıralama ekle" ekliyor.
//
// SUNUCU SÖZLEŞMESİ DEĞİŞMEDİ: satırlar yine sort_field_N/sort_dir_N olarak
// 1'den numaralanıyor ve parse_grid_sort_rules() boş slotları atlıyor.
$sortPanelRows = array();
foreach ($sortRules as $rule) {
    $sortPanelRows[] = array(
        'field_id' => (int) $rule['field_id'],
        'field_type' => $fieldsById[$rule['field_id']]['field_type'],
        'dir' => strtolower($rule['dir']),
    );
}
if (empty($sortPanelRows)) {
    $sortPanelRows[] = array('field_id' => 0, 'field_type' => null, 'dir' => 'asc');
}

// ---- Filtre panelinde BASILACAK satırlar ----------------------------------
// Eskiden panel KOŞULSUZ 5 boş satır basıyordu: kullanıcı tek bir filtre için
// dört ölü satıra bakıyordu. Artık yalnızca (a) mevcut kurallar, (b) sütun
// başlığından "bu alana göre filtrele" ile gelindiyse ön-seçili alan, (c)
// hiçbiri yoksa TEK boş satır basılıyor; gerisini "+ Filtre ekle" ekliyor.
//
// SUNUCU SÖZLEŞMESİ DEĞİŞMEDİ: satırlar yine filter_field_N/filter_cond_N/
// filter_value_N olarak 1'den başlayarak numaralanıyor ve parse_grid_filter_rules()
// boş slotları zaten atlıyor. Kurallar 1..N olarak YENİDEN numaralanıyor (kaynak
// slot'ları korunmuyor) — parse tarafı slot'u yalnızca sıra için okuduğundan bu
// güvenli ve panelin satır ekleme/silme mantığını basitleştiriyor.
$filterPanelRows = array();
foreach ($filterRules as $rule) {
    $filterPanelRows[] = array(
        'field_id' => (int) $rule['field_id'],
        'field_type' => $rule['field_type'],
        'operator' => $rule['operator'],
        'value' => $rule['raw_value'],
    );
}
if ($openFilterFieldId !== 0 && count($filterPanelRows) < $GLOBALS['BCC_FILTER_MAX_SLOTS']) {
    // Başlıktan gelen ön-seçim: alan dolu, operatör/değer boş — gerçek bir kural
    // DEĞİL, kullanıcı tamamlayacak (önceki davranışın aynısı).
    $filterPanelRows[] = array(
        'field_id' => $openFilterFieldId,
        'field_type' => $fieldsById[$openFilterFieldId]['field_type'],
        'operator' => '',
        'value' => '',
    );
}
if (empty($filterPanelRows)) {
    $filterPanelRows[] = array('field_id' => 0, 'field_type' => null, 'operator' => '', 'value' => '');
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

// Her seviye için "yönü çevir" linki (A→Z ↔ Z→A). "Bu seviyeyi kaldır" ile AYNI
// desen: seviyeler olduğu gibi yeniden kurulur, yalnızca hedef seviyenin dir'i
// tersine döner. Böylece yön değiştirmek bir <select> + "Uygula" değil, tek
// tıklık bir bağlantı — JS gerekmiyor ve sunucu sözleşmesi (group_dir_N)
// değişmiyor.
$groupDirToggleLinks = array();
foreach ($groupRules as $flipIdx => $ruleToFlip) {
    $rebuilt = array();
    $newSlot = 1;
    foreach ($groupRules as $idx => $rule) {
        $dir = strtolower($rule['dir']);
        if ($idx === $flipIdx) {
            $dir = ($dir === 'asc') ? 'desc' : 'asc';
        }
        $rebuilt['group_field_' . $newSlot] = $rule['field_id'];
        $rebuilt['group_dir_' . $newSlot] = $dir;
        $newSlot++;
    }
    $groupDirToggleLinks[$flipIdx] = http_build_query($groupFieldLinkBase + $rebuilt);
}

// Alan listesindeki her alan için "bunu (alt) grup olarak ekle" linki: mevcut
// seviyeler korunur, alan SONA eklenir. Zaten gruplanmış alanlar ve 3 seviye
// dolduğunda tüm alanlar link ALMAZ (listede aktif/pasif olarak gösterilirler) —
// aksi hâlde aynı alan iki seviyede birden görünebilir ya da 4. seviye sessizce
// yok sayılırdı.
$groupedFieldIds = array();
foreach ($groupRules as $rule) {
    $groupedFieldIds[(int) $rule['field_id']] = (int) $rule['slot'];
}
$groupAddLinks = array();
if (count($groupRules) < 3) {
    foreach ($fields as $f) {
        $fid = (int) $f['id'];
        if ($f['field_type'] === 'attachment' || isset($groupedFieldIds[$fid])) {
            continue;
        }
        $appended = array();
        $newSlot = 1;
        foreach ($groupRules as $rule) {
            $appended['group_field_' . $newSlot] = $rule['field_id'];
            $appended['group_dir_' . $newSlot] = strtolower($rule['dir']);
            $newSlot++;
        }
        $appended['group_field_' . $newSlot] = $fid;
        $appended['group_dir_' . $newSlot] = 'asc';
        $groupAddLinks[$fid] = http_build_query($groupFieldLinkBase + $appended);
    }
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

// bcc_build_grouped_tree() src/schema.php'e TAŞINDI: artık yalnızca grid.php
// değil, Duyuru arayüzünün kayıt listesi uç noktası da (public/api/
// interface_records.php) AYNI ağacı kuruyor — ikinci bir gruplama
// mantığı yazılmadı. Çağrı yeri değişmedi.

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
<title><?php echo htmlspecialchars(bcc_page_title($table['base_name'], $table['name']), ENT_QUOTES, 'UTF-8'); ?></title>
<?php // Yedek ikon: page-identity.js base rozetiyle DEĞİŞTİRİR (JS kapalıysa bu kalır). ?>
<link rel="icon" type="image/svg+xml" href="<?php echo bcc_asset_url('favicon.svg'); ?>">
<?php echo bcc_page_identity_meta($table['base_id'], $table['base_name'], $table['name']), "\n"; ?>
<script src="<?php echo bcc_asset_url('page-identity.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('style.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('grid-shell.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('home.css'); ?>">
<?php // Dışa aktarma (PDF + PNG) ORTAK kuralları. media="print" olduğu için
      // yazdırmada tarayıcı kendiliğinden uygular (JS'e bağımlı DEĞİL); "PNG
      // olarak indir" ise html2canvas'ın onclone KOPYASINDA bu link'in
      // media'sını "all"a çevirerek AYNI kuralları kullanır — bu yüzden
      // data-grid-export-css kancası var (bkz. grid-export.css başlığı). ?>
<link rel="stylesheet" href="<?php echo bcc_asset_url('grid-export.css'); ?>" media="print" data-grid-export-css>
</head>
<body class="gs-body">

<aside class="gs-rail">
    <?php
    // Sol şeridin en üstü: SABİT "Ana sayfa" düğmesi.
    //
    // Önceki hâl bir bcc logosuydu ve hover'da geri okuna DÖNÜŞÜYORDU
    // (.gs-rail-logo-img gizlenip .gs-rail-back-icon açılıyordu). İki sorunu
    // vardı: (1) simge hover'da değiştiği için düğmenin ne yaptığı ancak
    // üzerine gelince anlaşılıyordu, (2) "geri" oku yanıltıcıydı — bağlantı
    // hiçbir zaman tarayıcı geçmişine değil her zaman /dashboard.php'ye
    // gidiyordu. Artık tek ve değişmeyen bir ev simgesi var; hover yalnızca
    // zemini aydınlatır (bkz. grid-shell.css .gs-rail-home).
    //
    // Simge: Lucide "house" (24'lük ızgara, currentColor) — base kartlarındaki
    // ikon sistemiyle AYNI çizim dili (bkz. src/schema.php BCC_BASE_ICON_PATHS).
    ?>
    <a href="/dashboard.php" class="gs-rail-home" title="Ana sayfa" aria-label="Ana sayfa">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/>
            <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        </svg>
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
            <span class="gs-base-icon" style="background: <?php echo htmlspecialchars(bcc_base_icon_color($table['base_id']), ENT_QUOTES, 'UTF-8'); ?>;"><?php echo bcc_base_icon_svg(14, $table['base_name']); ?></span>
            <span class="gs-base-name"><?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <div class="gs-topbar-right">
            <?php
            // Genel arama (Ctrl K) — home_shell_top.php'nin bastığı AYNI partial.
            // grid.php kendi kabuğunu kullandığı için bu bileşeni HİÇ almıyordu;
            // artık dashboard/workspaces/team_members ile aynı DOM ve aynı
            // davranış (assets/global-search.js) burada da var, bağlam olarak
            // KAYITLARI arar. Aşağıdaki toolbar'daki #grid-search ise farklı bir
            // araçtır ve KALIR: o, eşleşen tüm hücreleri yerinde vurgulayıp
            // ileri/geri gezdirir; bu ise hızlı atlama listesi.
            $searchPlaceholder = 'Kayıt ara...';
            $searchEmptyText = 'Aramanızla eşleşen kayıt yok.';
            $searchTriggerClass = 'gs-topbar-search';
            require __DIR__ . '/../src/partials/global_search.php';
            ?>
            <details class="gs-tool-details collab-popover-trigger" name="gs-table-tab-menu">
                <summary class="gs-btn-ghost">Paylaş</summary>
                <div class="collab-popover-form">
                    <div class="collab-popover-title">"<?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?>" paylaş</div>

                    <?php if ($canManageMembers): ?>
                        <?php // Eskiden burada team_members.php'ye tam sayfa POST eden bir
                              // kullanıcı seçici + rol <select> vardı; gönderim sayfayı
                              // TERK EDİYORDU. Aynı iş (e-posta + rol + Davet Et) artık
                              // modalın davet kutusunda, yönlendirme olmadan. ?>
                        <button type="button" class="collab-popover-add-btn" data-share-modal-open>Katılımcı ekle</button>
                    <?php else: ?>
                        <?php // Owner değil: ekleme yolu HİÇ basılmaz. Katılımcı
                              // listesi (aşağıdaki satır) görünür kalır — kimin
                              // erişimi olduğunu görmek yetki gerektirmez, modal da
                              // salt-okunur açılır. ?>
                        <p class="collab-popover-note">Katılımcı eklemek için Owner yetkisi gerekir.</p>
                    <?php endif; ?>

<?php // ARTIK YÖNLENDİRME YOK: bu satır team_members.php'ye gitmek yerine
                          // sayfa üstünde "Paylaş" modalını açıyor (src/partials/share_modal.php
                          // + assets/share-modal.js). <a href> yerine <button>: yönlendirme
                          // kaldırıldığına göre gidilecek bir adresi olmayan bir bağlantı
                          // bırakmak (href="#" ya da tıklaması engellenen bir <a>) yanlış
                          // olurdu. Tam yönetim ekranı kayboldu DEĞİL — modalın altındaki
                          // "Tüm üye ayarları →" bağlantısı hâlâ oraya gidiyor. ?>
                    <button type="button" class="collab-popover-people" data-share-modal-open>
                        <div class="collab-popover-avatars">
                            <?php // Satırlar bcc_share_modal_payload()'dan geliyor: 'name' /
                                  // 'initial' anahtarları orada hazırlanmış (modaldakiyle AYNI). ?>
                            <?php foreach ($shareCollaboratorPreview as $c): ?>
                                <div class="ws-collab-avatar collab-popover-avatar" title="<?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c['initial'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php // data-share-people-label: modalda biri eklenip çıkarıldığında
                              // share-modal.js bu özeti de tazeliyor (arkadaki sayı bayatlamasın). ?>
                        <span class="collab-popover-people-label" data-share-people-label>
                            <?php echo count($shareCollaborators); ?> kişinin erişimi var<?php echo $shareCollaboratorExtraCount > 0 ? ' (+' . (int) $shareCollaboratorExtraCount . ')' : ''; ?>
                        </span>
                    </button>
                </div>
            </details>
            <details class="gs-tool-details share-popover-trigger" name="gs-table-tab-menu">
                <summary class="gs-btn-ghost">Bağlantı</summary>
                <?php
                // Kutu gövdesi PAYLAŞILAN partial'dan — aynı blok bu dosyada bir
                // kez daha ("Paylaş ve Senkronize Et") ve interface.php'de geçiyor.
                $shareLinkUrl = $interfaceShareUrl;
                $shareLinkLabel = 'Bağlantıyı paylaş';
                require __DIR__ . '/../src/partials/share_link_popover.php';
                ?>
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

                            <?php /* "Ad veya açıklama değiştir" ve "Sil" OWNER'a
                                     özel — menünün kendisi $canEdit (editor+) ile
                                     açık ama bu ikisi ŞEMA işidir (base_tables.php
                                     require_role('owner')). Editor'a gösterip
                                     sunucuda 403 vermek, bu projede tablo "+" ve
                                     alan "+" düğmelerinde ZATEN iki kez yaşanmış
                                     hataydı.

                                     "Verileri temizle" BİLEREK KALDI ve editor'a
                                     açık: o VERİYİ siler, tabloyu değil
                                     (table_clear_data.php, require_role('editor')).
                                     "Sil" ile karışmasın diye ikisi ayrı bölümde
                                     ve farklı yetki seviyesinde. */ ?>
                            <?php if ($isOwner): ?>
                                <div class="gs-table-tab-menu-divider"></div>
                                <button
                                    type="button"
                                    class="gs-table-tab-menu-item"
                                    data-table-rename="<?php echo (int) $st['id']; ?>"
                                    data-table-name="<?php echo htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-table-desc="<?php echo htmlspecialchars((string) $st['description'], ENT_QUOTES, 'UTF-8'); ?>"
                                >Ad veya açıklama değiştir</button>
                            <?php endif; ?>

                            <div class="gs-table-tab-menu-divider"></div>
                            <button type="button" class="gs-table-tab-menu-item gs-table-tab-menu-item-danger" data-table-clear="<?php echo (int) $st['id']; ?>">Verileri temizle</button>

                            <?php if ($isOwner): ?>
                                <button
                                    type="button"
                                    class="gs-table-tab-menu-item gs-table-tab-menu-item-danger"
                                    data-table-delete="<?php echo (int) $st['id']; ?>"
                                    data-table-name="<?php echo htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                >Sil</button>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php /* $isOwner, $canEdit DEĞİL — tablo oluşturma base_tables.php'de
                 owner-only (require_role($base['team_id'], 'owner')). $canEdit
                 (editor+) ile gösterildiğinde editor "+"yı görüyor, tıklıyor,
                 ayrı sayfaya gidiyor ve orada "owner rolü gerekir" duvarına
                 çarpıyordu.
                 ⚠️ Bu, ALAN oluşturma "+"sında ZATEN düzeltilmiş olan hatanın
                 aynısıydı (bkz. bu dosyanın başındaki $isOwner tanımı ve
                 gerekçesi) — tablo "+"sı o düzeltmeden pay almamıştı. */ ?>
        <?php if ($isOwner): ?>
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
<?php // Etiket "Görünümü yazdır" DEĞİL "Yazdır": aksiyon (window.print())
                          // ve id AYNEN korundu, ikinci bir kalem EKLENMEDİ — tarayıcının print
                          // diyaloğunda varsayılan hedef zaten "Yazdır", etiket
                          // kullanıcının gördüğü sonucu anlatıyor. Excel/PNG kardeşleriyle de
                          // artık aynı "<format> olarak indir" kalıbında. ?>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-print-item">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="5" y="3" width="10" height="5" stroke="#5f6368" stroke-width="1.3"/><rect x="3" y="8" width="14" height="6" rx="1" stroke="#5f6368" stroke-width="1.3"/><rect x="6" y="12" width="8" height="5" stroke="#5f6368" stroke-width="1.3"/></svg>
                        Yazdır
                    </button>
<?php // html2canvas YEREL dosya (assets/vendor/, MIT 1.4.1) — CDN YOK. Yol
                          // buradan veriliyor: bcc_asset_url mtime cache-bust'ını üretsin ve
                          // istemci '/assets/...' dizgisini kendi kurmasın. Kütüphane sayfa
                          // açılışında DEĞİL, ilk tıklamada yükleniyor (bkz. grid-export-png.js). ?>
                    <button type="button" class="gs-table-tab-menu-item" id="gs-view-download-png-item"
                            data-html2canvas-src="<?php echo htmlspecialchars(bcc_asset_url('vendor/html2canvas.min.js'), ENT_QUOTES, 'UTF-8'); ?>">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="12" rx="1.5" stroke="#5f6368" stroke-width="1.3"/><circle cx="7.5" cy="8.5" r="1.3" stroke="#5f6368" stroke-width="1.3"/><path d="M3.5 14l4-4 3.5 3.5L13.5 11l3 3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        PNG olarak indir
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
        <?php // Backdrop (.gs-view-desc-overlay) PAYLAŞILIYOR — yalnızca iç kutu
              // .gs-import-modal modifier'ıyla ayrışıyor. .gs-view-desc-modal'ın
              // KENDİSİ değiştirilmedi: onu "görünüm açıklamasını düzenle"
              // popover'ı da kullanıyor, genişlik/dolgu oradan da değişirdi. ?>
        <div class="gs-view-desc-overlay" id="gs-table-import-overlay" hidden>
            <div class="gs-view-desc-modal gs-import-modal" role="dialog" aria-modal="true" aria-labelledby="gs-import-title">
                <div class="gs-import-header">
                    <span class="gs-import-header-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <path d="M14 2v6h6"/>
                            <path d="m9 13 6 6"/>
                            <path d="m15 13-6 6"/>
                        </svg>
                    </span>
                    <div class="gs-import-header-text">
                        <div class="gs-import-title" id="gs-import-title">Veri içe aktar</div>
                        <div class="gs-import-subtitle">Excel tablosundan yeni kayıtlar ekleyin</div>
                    </div>
                    <button type="button" class="gs-import-close" id="gs-table-import-close" aria-label="Kapat">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M18 6 6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <?php // Dropzone bir <label>: tıklama, dosya seçiciyi ikinci bir JS
                      // dinleyicisi olmadan açar ve klavyeyle de odaklanılabilir.
                      // <input> görsel olarak gizli ama DOM'da duruyor (display:none
                      // DEĞİL — o hâlde bazı tarayıcılar odaklanmayı reddediyor). ?>
                <label class="gs-import-dropzone" id="gs-table-import-dropzone" for="gs-table-import-file" tabindex="0">
                    <span class="gs-import-dropzone-icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <path d="M7 9l5-5 5 5"/>
                            <path d="M12 4v12"/>
                        </svg>
                    </span>
                    <span class="gs-import-dropzone-text">
                        Excel dosyanızı buraya sürükleyin veya <strong>dosya seçin</strong>
                    </span>
                    <span class="gs-import-badges">
                        <span class="gs-import-badge">.xlsx</span>
                        <span class="gs-import-badge gs-import-badge-muted">en fazla 10MB</span>
                    </span>
                    <input type="file" class="gs-import-file-input" id="gs-table-import-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                </label>

                <?php // Seçim sonrası önizleme kartı — dropzone'un YERİNE geçer
                      // (ikisi aynı anda görünmez, bkz. grid-table-data.js). ?>
                <div class="gs-import-file-card" id="gs-table-import-file-card" hidden>
                    <span class="gs-import-file-check" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                    </span>
                    <span class="gs-import-file-meta">
                        <span class="gs-import-file-name" id="gs-table-import-file-name"></span>
                        <span class="gs-import-file-size" id="gs-table-import-file-size"></span>
                    </span>
                    <button type="button" class="gs-import-file-change" id="gs-table-import-file-change">Dosyayı Değiştir</button>
                </div>

                <div class="gs-import-callout">
                    Dosyadaki ilk satır alan adları olmalı. Yalnızca tablodaki alan
                    adlarıyla <strong>birebir eşleşen</strong> sütunlar aktarılır,
                    eşleşmeyenler atlanır. "Excel indir" ile aldığınız dosya bu
                    formattadır. Dosya eki (attachment) alanları içe aktarılamaz.
                </div>

                <div class="gs-import-result" id="gs-table-import-result" hidden></div>

                <div class="gs-view-desc-actions gs-import-actions">
                    <button type="button" class="gs-import-btn-secondary" id="gs-table-import-cancel">İptal</button>
                    <button type="button" class="gs-import-btn-primary" id="gs-table-import-submit">İçe Aktar</button>
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

                    <?php // ---- Arama + sayaç (üst bölüm) ----------------------------
                          // Sayaç sunucudan DOĞRU değerle basılıyor; JS her toggle'da
                          // yeniden yazıyor (bkz. grid-hide-fields.js) — böylece
                          // JS'siz de doğru, JS'liyken de anlık. ?>
                    <div class="hide-fields-top">
                        <div class="hide-fields-search-wrap">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            <input type="text" class="hide-fields-search" placeholder="Alan ara" aria-label="Alan ara" autocomplete="off" data-hide-fields-search>
                        </div>
                        <p class="hide-fields-counter" data-hide-fields-counter
                           data-total="<?php echo count($nonPrimaryFieldIds); ?>">
                            <?php echo count($hiddenFieldIds); ?> / <?php echo count($nonPrimaryFieldIds); ?> alan gizli
                        </p>
                    </div>

                    <div class="hide-fields-list" data-hide-fields-list>
                        <?php foreach ($fields as $f):
                            if ((int) $f['id'] === $primaryFieldId) {
                                continue; // birincil alan OpsFlow'daki gibi panelde listelenmez, hep görünür
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
                                <?php // Alan tipi ikonu: grid başlığı ve gruplama panelinin
                                      // KULLANDIĞI AYNI bileşen (.field-badge--<tip>,
                                      // assets/theme.css) — 21 tipin tamamı orada tanımlı,
                                      // burada ikinci bir ikon seti YOK. ?>
                                <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                <span class="hide-field-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <p class="hide-fields-empty" data-hide-fields-empty hidden>Eşleşen alan yok.</p>
                    </div>

                    <?php // ---- İki eylem, YAN YANA, HER ZAMAN basılır ----------------
                          // Eskiden ikisi de KOŞULLUYDU ("Tümünü göster" yalnızca gizli
                          // alan varken, "Tümünü gizle" yalnızca gösterilecek alan
                          // varken) — yani panelde çoğu zaman TEK buton görünüyordu ve
                          // yerleşim seçime göre zıplıyordu. Artık ikisi de her zaman
                          // var; uygulanamayan taraf devre dışı (aria-disabled + tabindex)
                          // görünür, böylece düzen sabit kalıyor.
                          //
                          // <a> olarak kalıyorlar: ikisi de GERÇEK bir GET adresine
                          // gidiyor (sunucu durumu URL'de taşıyor), yani JS olmadan da
                          // çalışıyorlar. ?>
                    <div class="hide-fields-actions">
                        <?php $allHidden = count($hiddenFieldIds) >= count($nonPrimaryFieldIds); ?>
                        <?php if ($allHidden): ?>
                            <span class="hide-fields-btn is-disabled" aria-disabled="true">Tümünü gizle</span>
                        <?php else: ?>
                            <a class="hide-fields-btn" href="/grid.php?<?php echo htmlspecialchars($hideAllFieldsQueryString, ENT_QUOTES, 'UTF-8'); ?>">Tümünü gizle</a>
                        <?php endif; ?>

                        <?php if (empty($hiddenFieldIds)): ?>
                            <span class="hide-fields-btn is-disabled" aria-disabled="true">Tümünü göster</span>
                        <?php else: ?>
                            <a class="hide-fields-btn" href="/grid.php?<?php echo htmlspecialchars($showAllFieldsQueryString, ENT_QUOTES, 'UTF-8'); ?>">Tümünü göster</a>
                        <?php endif; ?>

                        <?php // JS varken gizleniyor (her değişiklikte otomatik submit
                              // ediliyor); JS yokken tek uygulama yolu bu. ?>
                        <button type="submit" class="hide-fields-btn hide-fields-apply" data-hide-fields-apply>Uygula</button>
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
                <form method="get" action="/grid.php" class="filter-form" data-filter-form>
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <?php bcc_render_grid_state_hidden_inputs($hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState); ?>

                    <?php
                    // BAĞLAÇ (VE/VEYA) SUNUCUDA TEK DEĞERDİR: filter_logic tüm
                    // kurallara birden uygulanır (bkz. bcc_build_grid_records_query).
                    // Bu yüzden "her satırda ayrı bağlaç" YAPILMADI — öyle bir arayüz,
                    // motorun desteklemediği bir söz verirdi.
                    //
                    // Gerçek kontrol 2. SATIRDAKİ <select name="filter_logic">;
                    // 3. ve sonraki satırlar aynı değeri YANSITAN, ada sahip olmayan
                    // (yani forma girmeyen) devre dışı kopyalardır — OpsFlow de
                    // tekdüze mantıkta sonraki bağlaçları pasif gösterir.
                    // Tek satır varken 2. satır yoktur; değer kaybolmasın diye
                    // gizli input basılır (JS ikinci satırı eklerken onu kaldırıp
                    // yerine select koyar).
                    $filterRowCount = count($filterPanelRows);
                    $filterLogicValue = $filterLogic === 'OR' ? 'or' : 'and';
                    ?>
                    <?php if ($filterRowCount < 2): ?>
                        <input type="hidden" name="filter_logic" value="<?php echo $filterLogicValue; ?>" data-filter-logic-hidden>
                    <?php endif; ?>

                    <div class="filter-rows" data-filter-rows>
                        <?php foreach ($filterPanelRows as $i => $row):
                            $slot = $i + 1;
                            $currentFieldId = (int) $row['field_id'];
                            $currentFieldType = $row['field_type'];
                            $currentOp = $row['operator'];
                            $currentValue = $row['value'];

                            $opsForField = $currentFieldType ? $GLOBALS['BCC_FILTER_OPERATORS'][$currentFieldType] : array();
                            // Değer kutusu gizlenir: (a) değer almayan operatörlerde
                            // ("boş"/"boş değil"), (b) HENÜZ ALAN SEÇİLMEMİŞKEN —
                            // grid-filter.js alan değişince zaten aynı kuralı
                            // uyguluyordu, ilk render'da uygulanmıyordu ve boş satır
                            // kullanılamaz bir "değer" kutusu gösteriyordu.
                            $valueHidden = ($currentFieldType === null)
                                || in_array($currentOp, $GLOBALS['BCC_FILTER_NO_VALUE_OPS'], true);
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
                            <div class="filter-row" data-filter-row data-slot="<?php echo $slot; ?>">
                                <?php // ---- Bağlaç sütunu: 1. satır etiket, 2. satır GERÇEK
                                      // kontrol, 3+ yansıtma. Üçü de AYNI genişlikte, böylece
                                      // alan/operatör/değer sütunları satırlar arasında hizalı. ?>
                                <span class="filter-conj" data-filter-conj>
                                    <?php if ($slot === 1): ?>
                                        <span class="filter-conj-label">Koşul</span>
                                    <?php elseif ($slot === 2): ?>
                                        <select name="filter_logic" class="filter-conj-select" data-filter-logic aria-label="Kurallar arası bağlaç">
                                            <option value="and" <?php echo $filterLogicValue === 'and' ? 'selected' : ''; ?>>VE</option>
                                            <option value="or" <?php echo $filterLogicValue === 'or' ? 'selected' : ''; ?>>VEYA</option>
                                        </select>
                                    <?php else: ?>
                                        <span class="filter-conj-mirror" data-filter-conj-mirror title="Bağlaç tüm kurallara birlikte uygulanır"><?php echo $filterLogicValue === 'or' ? 'VEYA' : 'VE'; ?></span>
                                    <?php endif; ?>
                                </span>

                                <?php // Alan tipi ikonu: SEÇİLİ alanın tipini gösterir ve
                                      // grid-filter.js alan değişince tazeler. Native <option>
                                      // içine ikon konulamadığı için (HTML sınırı) rozet
                                      // select'in YANINDA duruyor — ikon seti yine ortak
                                      // .field-badge (theme.css), ikinci bir set YOK. ?>
                                <span class="field-badge filter-field-badge <?php echo $currentFieldType ? 'field-badge--' . htmlspecialchars($currentFieldType, ENT_QUOTES, 'UTF-8') : 'is-empty'; ?>" data-filter-field-badge aria-hidden="true"></span>

                                <select name="filter_field_<?php echo $slot; ?>" class="filter-field-select" aria-label="Filtre alanı">
                                    <option value="">— alan seçin —</option>
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

                                <select name="filter_cond_<?php echo $slot; ?>" class="filter-cond-select" aria-label="Koşul" <?php echo $opsForField ? '' : 'disabled'; ?>>
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
                                        aria-label="Değer"
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
                                        aria-label="Değer"
                                        <?php echo $valueHidden ? 'style="display:none"' : ''; ?>
                                    >
                                <?php endif; ?>

                                <?php // Satır silme. JS YOKKEN de anlamlı: butonun kendisi
                                      // JS ile bağlanıyor ama JS yoksa kullanıcı alanı
                                      // "— alan seçin —"e çekerek kuralı zaten iptal
                                      // edebiliyor (sunucu boş slotu atlıyor). ?>
                                <button type="button" class="filter-row-remove" data-filter-remove aria-label="Bu filtre kuralını sil" title="Kuralı sil">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6M8.5 9v5M11.5 9v5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="filter-add-row">
                        <button type="button" class="filter-add-btn" data-filter-add>
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            Filtre ekle
                        </button>
                        <span class="filter-slot-note" data-filter-slot-note hidden>En fazla <?php echo (int) $GLOBALS['BCC_FILTER_MAX_SLOTS']; ?> kural eklenebilir.</span>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="filter-btn filter-btn--primary">Uygula</button>
                        <?php if (!empty($filterRules)): ?>
                            <a class="filter-btn" href="/grid.php?<?php echo htmlspecialchars($clearFilterQueryString, ENT_QUOTES, 'UTF-8'); ?>">Filtreleri temizle</a>
                        <?php else: ?>
                            <span class="filter-btn is-disabled" aria-disabled="true">Filtreleri temizle</span>
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
                <?php // TEK PANEL (eskiden İKİ ayrı görünüm vardı: gruplama yokken
                      // bir alan listesi, varken <select>'li bir form). İkisi
                      // birleştirildi: üstte aktif seviyeler, altta her zaman
                      // görünen aranabilir alan listesi.
                      //
                      // SUNUCU SÖZLEŞMESİ DEĞİŞMEDİ: hâlâ group_field_N/group_dir_N
                      // (N = 1..3). Fark, bunları bir <form> yerine ÖNCEDEN kurulmuş
                      // BAĞLANTILARIN taşıması — seviye ekleme, yön çevirme ve
                      // kaldırma tek tık, "Uygula" gerekmiyor. Bu aynı zamanda
                      // JS'siz de tam çalışan bir panel demek. ?>
                <div class="group-form group-panel-box">

                    <?php if (empty($groupRules)): ?>
                        <?php // İSTEK 4 — boş durum rehberi. Yalnızca gruplama YOKKEN. ?>
                        <p class="group-hint">Kayıtları gruplamak için bir alan seçin.</p>
                    <?php else: ?>
                        <div class="group-active">
                            <div class="group-active-head">
                                <span class="group-active-title">Gruplama</span>
                                <span class="sp-count group-active-count"><?php echo count($groupRules); ?></span>
                            </div>

                            <?php foreach ($groupRules as $idx => $rule):
                                $rf = $fieldsById[$rule['field_id']];
                                $dir = strtolower($rule['dir']);
                                // Yön etiketi alan TİPİNE göre ("A → Z" / "1 → 9" /
                                // "Erken → Geç"...). isset() koruması artık BURADA
                                // DEĞİL, ortak bcc_dir_labels()'ta (src/schema.php) —
                                // sıralama paneli de aynı etiketleri kullandığı için
                                // kontrolün ikinci bir kopyası gerekmesin.
                                $dirLabels = bcc_dir_labels($rule['field_type']);
                            ?>
                                <div class="group-active-row">
                                    <span class="group-level-badge"><?php echo (int) $rule['slot']; ?></span>

                                    <?php // İSTEK 1 — aktif alan accent zeminli rozet olarak. ?>
                                    <span class="group-active-field">
                                        <span class="field-badge field-badge--<?php echo htmlspecialchars($rf['field_type'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($typeLabels[$rf['field_type']], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <span class="group-active-name"><?php echo htmlspecialchars($rf['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>

                                    <?php // İSTEK 2 — satır içi yön düğmesi. <select> DEĞİL:
                                          // iki durumu olan bir ayarda açılır liste fazladan
                                          // bir tık istiyordu. Bağlantı, yönü çevrilmiş tam
                                          // durumu taşıyor. ?>
                                    <a
                                        class="group-dir-toggle"
                                        href="/grid.php?<?php echo htmlspecialchars($groupDirToggleLinks[$idx], ENT_QUOTES, 'UTF-8'); ?>"
                                        title="Yönü çevir"
                                        aria-label="<?php echo htmlspecialchars($rf['name'] . ' — yönü çevir', ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <span class="group-dir-text"><?php echo htmlspecialchars($dirLabels[$dir], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <svg width="11" height="11" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M6 4v12m0 0l-2.5-2.5M6 16l2.5-2.5M14 16V4m0 0l-2.5 2.5M14 4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </a>

                                    <a class="group-remove-btn" href="/grid.php?<?php echo htmlspecialchars($groupRemoveLinks[$idx], ENT_QUOTES, 'UTF-8'); ?>" title="Bu seviyeyi kaldır" aria-label="<?php echo htmlspecialchars($rf['name'] . ' — bu seviyeyi kaldır', ENT_QUOTES, 'UTF-8'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6M8.5 9v5M11.5 9v5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </a>
                                </div>
                            <?php endforeach; ?>

                            <?php // Grid'deki grup başlıklarını topluca aç/kapa — panelin
                                  // kendi durumuna değil, tabloya etki eder (grid-group.js). ?>
                            <div class="group-collapse-row">
                                <button type="button" class="group-mini-btn" data-group-collapse-all>Tümünü daralt</button>
                                <button type="button" class="group-mini-btn" data-group-expand-all>Tümünü genişlet</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // İSTEK 3 — arama + alan listesi. Artık HER İKİ durumda da
                          // görünür (eskiden yalnızca gruplama yokken vardı; gruplama
                          // varken alan eklemek için <select> açmak gerekiyordu). ?>
                    <div class="group-pick">
                        <div class="group-search-wrap">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                            <input type="text" class="group-search" placeholder="Alan ara" aria-label="Alan ara" autocomplete="off" data-group-search>
                        </div>

                        <div class="group-field-list" data-group-field-list>
                            <?php
                            $groupAtMax = count($groupRules) >= 3;
                            foreach ($fields as $f):
                                if ($f['field_type'] === 'attachment') {
                                    continue; // dosya eki alanlarına göre gruplanamaz (cell_values karşılığı yok)
                                }
                                $fid = (int) $f['id'];
                                $activeSlot = isset($groupedFieldIds[$fid]) ? $groupedFieldIds[$fid] : null;
                                $addLink = isset($groupAddLinks[$fid]) ? $groupAddLinks[$fid] : null;
                            ?>
                                <?php if ($activeSlot !== null): ?>
                                    <?php // Zaten gruplanmış: listede AKTİF görünür ama tekrar
                                          // eklenemez (aynı alan iki seviyede olamaz). ?>
                                    <span class="group-field-option is-active" data-group-field-name="<?php echo htmlspecialchars(mb_strtolower($f['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" title="Bu alan zaten <?php echo (int) $activeSlot; ?>. seviyede gruplu">
                                        <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <span class="group-field-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="group-field-flag"><?php echo (int) $activeSlot; ?>. seviye</span>
                                    </span>
                                <?php elseif ($addLink !== null): ?>
                                    <a class="group-field-option" href="/grid.php?<?php echo htmlspecialchars($addLink, ENT_QUOTES, 'UTF-8'); ?>" data-group-field-name="<?php echo htmlspecialchars(mb_strtolower($f['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <span class="group-field-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </a>
                                <?php else: ?>
                                    <?php // 3 seviye dolu: eklenemez, ama liste eksik görünmesin. ?>
                                    <span class="group-field-option is-disabled" data-group-field-name="<?php echo htmlspecialchars(mb_strtolower($f['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>" title="En fazla 3 seviye gruplanabilir">
                                        <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <span class="group-field-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <p class="group-empty" data-group-empty hidden>Eşleşen alan yok.</p>
                        </div>

                        <?php if ($groupAtMax): ?>
                            <p class="group-max-note">En fazla 3 seviye gruplanabilir.</p>
                        <?php endif; ?>
                    </div>

                    <?php // İSTEK 1 — tek tıkla tüm gruplamayı kaldır. Gruplama yokken
                          // de basılır ama devre dışı: alt satır seçime göre zıplamasın
                          // ("Alanları gizle"/"Filtrele" panellerindeki AYNI karar). ?>
                    <div class="group-actions">
                        <?php if (!empty($groupRules)): ?>
                            <a class="group-clear-btn" href="/grid.php?<?php echo htmlspecialchars($clearGroupQueryString, ENT_QUOTES, 'UTF-8'); ?>">Gruplamayı kaldır</a>
                        <?php else: ?>
                            <span class="group-clear-btn is-disabled" aria-disabled="true">Gruplamayı kaldır</span>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
            <?php endif; ?>

            <?php if (!empty($fields)): ?>
            <details class="sort-panel gs-tool-details" name="gs-table-tab-menu">
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 5h9M4 10h6M4 15h3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/><path d="M15 4v11m0 0l-2.5-2.5M15 15l2.5-2.5" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Sırala<?php echo !empty($sortRules) ? ' (' . count($sortRules) . ')' : ''; ?>
                </summary>
                <form method="get" action="/grid.php" class="sort-form" data-sort-form>
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <?php bcc_render_grid_state_hidden_inputs($hiddenFieldsState + $groupState + $rowHeightState + $wrapHeadersState); ?>

                    <div class="sort-rows" data-sort-rows>
                        <?php foreach ($sortPanelRows as $i => $row):
                            $slot = $i + 1;
                            $currentFieldId = (int) $row['field_id'];
                            $currentType = $row['field_type'];
                            $currentDir = $row['dir'];
                            // Yön etiketleri alan TİPİNE göre; ortak yardımcıdan
                            // (gruplama paneliyle AYNI kaynak, isset() koruması orada).
                            // Alan henüz seçilmemişse jenerik varsayılan gelir ve
                            // grid-sort.js alan seçilince etiketleri tazeler.
                            $dirLabels = bcc_dir_labels($currentType);
                        ?>
                            <div class="sort-row" data-sort-row data-slot="<?php echo $slot; ?>">
                                <?php // Sıra numarası: çok seviyeli sıralamada önceliği
                                      // gösterir (1. kurala göre sırala, eşitlerde 2. ...). ?>
                                <span class="sort-level-badge"><?php echo $slot; ?></span>

                                <?php // Alan tipi ikonu: native <option> içine markup
                                      // konulamadığı için (HTML sınırı) rozet select'in
                                      // YANINDA ve SEÇİLİ alanın tipini gösteriyor.
                                      // Ortak .field-badge bileşeni (theme.css). ?>
                                <span class="field-badge sort-field-badge <?php echo $currentType ? 'field-badge--' . htmlspecialchars($currentType, ENT_QUOTES, 'UTF-8') : 'is-empty'; ?>" data-sort-field-badge aria-hidden="true"></span>

                                <select name="sort_field_<?php echo $slot; ?>" class="sort-field-select" aria-label="Sıralama alanı">
                                    <option value="">— alan seçin —</option>
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

                                <?php // YÖN: iki seçenekli <select> olarak KALIYOR (buton
                                      // değil). Gerekçe: bu satırlar henüz UYGULANMAMIŞ bir
                                      // formun parçası, yani yön değerinin form gönderimine
                                      // katılması gerekiyor; JS'siz çalışan tek yol bu.
                                      // Bildirilen asıl kusur metnin KIRPILMASIYDI — kutu
                                      // artık min-width ile ölçülendi ve etiketler alan
                                      // tipine göre ("A → Z" / "1 → 9" / "Erken → Geç").
                                      // (Gruplama panelindeki yön DÜĞMESİ farklı: orada kural
                                      // zaten uygulanmış durumda, o yüzden tek tıklık link.) ?>
                                <select name="sort_dir_<?php echo $slot; ?>" class="sort-dir-select" data-sort-dir aria-label="Sıralama yönü">
                                    <option value="asc" <?php echo $currentDir === 'asc' ? 'selected' : ''; ?>><?php echo htmlspecialchars($dirLabels['asc'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <option value="desc" <?php echo $currentDir === 'desc' ? 'selected' : ''; ?>><?php echo htmlspecialchars($dirLabels['desc'], ENT_QUOTES, 'UTF-8'); ?></option>
                                </select>

                                <button type="button" class="sort-row-remove" data-sort-remove aria-label="Bu sıralama kuralını sil" title="Kuralı sil">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6M8.5 9v5M11.5 9v5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="sort-add-row">
                        <button type="button" class="sort-add-btn" data-sort-add>
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            Sıralama ekle
                        </button>
                        <span class="sort-slot-note" data-sort-slot-note hidden>En fazla <?php echo (int) $GLOBALS['BCC_SORT_MAX_SLOTS']; ?> kural eklenebilir.</span>
                    </div>

                    <div class="sort-actions">
                        <button type="submit" class="sort-btn sort-btn--primary">Uygula</button>
                        <?php if (!empty($sortRules)): ?>
                            <a class="sort-btn" href="/grid.php?<?php echo htmlspecialchars($clearSortQueryString, ENT_QUOTES, 'UTF-8'); ?>">Sıralamayı temizle</a>
                        <?php else: ?>
                            <span class="sort-btn is-disabled" aria-disabled="true">Sıralamayı temizle</span>
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
                <?php
                $shareLinkUrl = $gridViewShareUrl;
                $shareLinkLabel = 'Şu görünüme bağlantı paylaş';
                require __DIR__ . '/../src/partials/share_link_popover.php';
                ?>
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
            <?php /* Tip seçici — liste BCC_VIEW_TYPES'tan DÖNER, elle yazılmaz
                     (alan tipi sihirbazının $fieldTypeLabels döngüsüyle AYNI
                     desen): Kanban/Calendar eklenince menü kendiliğinden büyür.
                     <details name="gs-table-tab-menu">: projedeki diğer popover'larla
                     AYNI ad, böylece biri açılınca diğerleri otomatik kapanır. */ ?>
            <details class="gs-view-create-menu" name="gs-table-tab-menu">
                <summary class="gs-view-drawer-create">+ Yeni oluştur...</summary>
                <div class="gs-view-create-panel">
                    <?php /* SIRA BİLİNÇLİ: "Boş tablo oluştur" EN ÜSTTE.
                             Kullanıcıların bu menüde en sık aradığı iş oydu ve
                             eskiden buradan hiç erişilemiyordu (yalnızca tablo
                             sekmelerindeki "+"). Ayrı bir bölüm başlığı YOK —
                             etiketlerin kendisi ayrımı taşıyor: "Boş tablo
                             oluştur" yeni bir TABLO, "Form"/"Kanban" ise AYNI
                             tablonun yeni GÖRÜNÜMÜ açar. */ ?>
                    <?php if ($isOwner): ?>
                        <?php /* <button>, <a> DEĞİL: tıklama aynı sayfadaki
                                 modalı açar, base_tables.php'ye YÖNLENDİRMEZ.
                                 data-view-type ÖZNİTELİĞİ YOK ve bu ŞART:
                                 grid-view-manage.js'in delege dinleyicisi
                                 .gs-view-create-option sınıfına bakıyor, önceki
                                 <a> sürümü o sınıfı taşıdığı için dinleyici onu
                                 da yakalıyor ve view_create.php'ye BOŞ tür
                                 gönderip "Geçersiz görünüm türü" hatası
                                 veriyordu — bağlantı hiç açılmıyordu. Dinleyici
                                 artık yalnızca data-view-type TAŞIYAN
                                 seçenekleri işliyor. */ ?>
                        <button
                            type="button"
                            class="gs-view-create-option gs-view-create-option--table"
                            id="gs-create-table-btn"
                        >
                            <span class="gs-view-create-plus" aria-hidden="true">+</span>
                            <span class="view-type-label">Boş tablo oluştur</span>
                        </button>
                    <?php endif; ?>
                    <?php /* 'grid' ATLANIR — "Tablo görünümü" seçeneği menüden
                             kaldırıldı (ürün kararı): aynı tablonun ikinci bir
                             grid görünümü artık buradan açılmaz.
                             ⚠️ BCC_VIEW_TYPES'tan SİLİNMEDİ, yalnızca menü
                             atlıyor. Silinseydi MEVCUT grid görünümleri kırılırdı:
                             o dizi hem view_create.php'nin tür whitelist'i hem de
                             ad üreticisi ("Tablo görünümü 2"); varsayılan görünüm
                             de 'grid' türündedir. Kopyalama/çoğaltma
                             (view_duplicate.php) de etkilenmez. */ ?>
                    <?php foreach ($GLOBALS['BCC_VIEW_TYPES'] as $viewTypeKey => $viewTypeLabel): ?>
                        <?php if ($viewTypeKey === 'grid') { continue; } ?>
                        <button
                            type="button"
                            class="gs-view-create-option"
                            data-view-type="<?php echo htmlspecialchars($viewTypeKey, ENT_QUOTES, 'UTF-8'); ?>"
                        >
                            <span class="view-type-badge view-type-badge--<?php echo htmlspecialchars($viewTypeKey, ENT_QUOTES, 'UTF-8'); ?>"></span>
                            <span class="view-type-label"><?php echo htmlspecialchars($viewTypeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </details>
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
                        <?php /* Hedef adres TEK yönlendirme noktasından (bcc_view_route_for)
                                 gelir — sabit '/grid.php?...' değil, çünkü form
                                 görünümleri form_edit.php'ye gitmeli. Rozet de türe
                                 göre (view-type-badge--<tür>), sabit tablo ikonu değil. */ ?>
                        <a class="gs-view-drawer-view" href="<?php echo htmlspecialchars(bcc_view_route_for($v['view_type'], $table['id'], $v['id']), ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="view-type-badge view-type-badge--<?php echo htmlspecialchars($v['view_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
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
                <?php
                // Kaydedilmiş genişlikler VARSA: table-layout:fixed + her sütun için
                // bir <col>. Toplam genişlik tabloya inline yazılır ve `min-width:100%`
                // devre dışı kalır (.grid-has-col-widths) — aksi hâlde tablo
                // kapsayıcıya esner ve fixed layout artan alanı sütunlara oransal
                // dağıtarak sürüklenen değeri BOZARDI.
                $colWidthFor = function ($key) use ($columnWidths) {
                    return isset($columnWidths[$key]) ? (int) $columnWidths[$key] : (int) $GLOBALS['BCC_DEFAULT_COLUMN_WIDTH'];
                };
                // Satır no sütunu haritadan OKUNMUYOR (bkz. BCC_ROW_COLUMN_WIDTH):
                // sürüklenemeyen bir sütun olduğu için haritadaki 'row' değeri
                // kullanıcı tercihi değil, eski yoğunluğun ölçülmüş kalıntısı —
                // satır içi <col style> olarak basılınca CSS'i yenip sütunu
                // 80px'te tutuyordu.
                $rowColWidth = (int) $GLOBALS['BCC_ROW_COLUMN_WIDTH'];
                $addFieldColWidth = 40; // "+" sütunu: ikon genişliği, kullanıcı ayarlayamaz
                $totalColWidth = 0;
                if ($hasColumnWidths) {
                    $totalColWidth = $rowColWidth;
                    foreach ($visibleFields as $f) {
                        $totalColWidth += $colWidthFor('f' . (int) $f['id']);
                    }
                    if ($isOwner) {
                        $totalColWidth += $addFieldColWidth;
                    }
                }
                ?>
                <table class="grid row-h-<?php echo htmlspecialchars($rowHeight, ENT_QUOTES, 'UTF-8'); ?> <?php echo $wrapHeaders ? 'wrap-headers' : ''; ?> <?php echo $hasColumnWidths ? 'grid-has-col-widths' : ''; ?>"<?php echo $hasColumnWidths ? ' style="width: ' . $totalColWidth . 'px;"' : ''; ?>>
                    <?php if ($hasColumnWidths): ?>
                    <colgroup>
                        <col style="width: <?php echo $rowColWidth; ?>px;">
                        <?php foreach ($visibleFields as $f): ?>
                        <col data-col-key="f<?php echo (int) $f['id']; ?>" style="width: <?php echo $colWidthFor('f' . (int) $f['id']); ?>px;">
                        <?php endforeach; ?>
                        <?php if ($isOwner): ?>
                        <col style="width: <?php echo $addFieldColWidth; ?>px;">
                        <?php endif; ?>
                    </colgroup>
                    <?php endif; ?>
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
<?php // data-col-key: grid-column-resize.js sürüklenen başlığı <col>'una ve
                                      // kaydedilecek anahtara bağlar (colgroup'taki data-col-key ile AYNI değer),
                                      // DOM sırasına/index'e güvenmeden. ?>
                                <th data-col-key="f<?php echo (int) $f['id']; ?>">
                                    <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"></span>
<?php // .grid-th-label: sabit-genişlik modunda kırpma HÜCREYE değil ADA
                                          // uygulanıyor (th'ye overflow:hidden vermek, kenardan dışarı taşan
                                          // dondurma/genişlik tutamaçlarını yarıdan keserdi — bkz. style.css). ?>
                                    <span class="grid-th-label"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></span>
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

<?php // "Paylaş" modalı — .gs-main-col'un DIŞINDA, doğrudan <body> altında:
      // backdrop position:fixed olsa da, kabuk kutularının içinde kalsaydı
      // ileride bir ata elemana eklenecek transform/filter onu o kutuya
      // hapsederdi. Yazdırma/PNG'de .gs-view-desc-overlay sınıfı sayesinde
      // zaten gizleniyor (grid-export.css). ?>
<?php require __DIR__ . '/../src/partials/share_modal.php'; ?>

<script src="<?php echo bcc_asset_url('dismissable-panel.js'); ?>" defer></script>
<?php // Genel arama (Ctrl K) — dismissable-panel.js'ten SONRA yüklenir
      // (bcc_bindDismissable'ı çağırıyor). home_shell_bottom.php'deki AYNI
      // dosya; grid.php'nin kabuğu ayrı olduğu için burada da bildiriliyor. ?>
<script src="<?php echo bcc_asset_url('global-search.js'); ?>" defer></script>
<?php if (!empty($fields)): ?>
<script>
    var BCC_FIELD_TYPES_BY_ID = <?php
        $typesById = array();
        foreach ($fields as $f) {
            $typesById[(int) $f['id']] = $f['field_type'];
        }
        echo json_encode($typesById, JSON_UNESCAPED_UNICODE);
    ?>;
    <?php // Pano yapıştırması (grid-paste.js) hangi sütunlara YAZAMAYACAĞINI
          // bilmeli. Tabloda hiç satır yokken .editable sınıfına bakılamaz
          // (veri hücresi yok), o yüzden tip listesi buradan geliyor.
          // Liste JS'te YİNELENMEZ — tek kaynak BCC_READONLY_FIELD_TYPES. ?>
    var BCC_READONLY_FIELD_TYPES = <?php echo json_encode($GLOBALS['BCC_READONLY_FIELD_TYPES'], JSON_UNESCAPED_UNICODE); ?>;
    var BCC_FILTER_OPS = <?php echo json_encode($GLOBALS['BCC_FILTER_OPERATORS'], JSON_UNESCAPED_UNICODE); ?>;
    var BCC_FILTER_NO_VALUE_OPS = <?php echo json_encode($GLOBALS['BCC_FILTER_NO_VALUE_OPS'], JSON_UNESCAPED_UNICODE); ?>;
    <?php // Azami kural sayısı SUNUCUDAN — "+ Filtre ekle" bu sınırda kapanıyor.
          // İstemcide ikinci bir sabit YOK (parse_grid_filter_rules aynı değeri
          // okuyor, bkz. src/schema.php BCC_FILTER_MAX_SLOTS). ?>
    var BCC_FILTER_MAX_SLOTS = <?php echo (int) $GLOBALS['BCC_FILTER_MAX_SLOTS']; ?>;
    <?php // Sıralama paneli: azami kural sayısı + alan tipine göre yön etiketleri.
          // İkisi de SUNUCUDAN — istemcide ikinci bir sabit/etiket tablosu YOK
          // (bcc_dir_labels() ile aynı diziyi okuyor, bkz. src/schema.php). ?>
    var BCC_SORT_MAX_SLOTS = <?php echo (int) $GLOBALS['BCC_SORT_MAX_SLOTS']; ?>;
    var BCC_DIR_LABELS = <?php echo json_encode($GLOBALS['BCC_GROUP_DIR_LABELS'], JSON_UNESCAPED_UNICODE); ?>;
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
    // Sütun genişliği sürükleme — sınırlar SUNUCUDAN geliyor (src/schema.php),
    // istemcide ikinci bir sabit YOK: view_config_update.php aynı değerlerle
    // yeniden kırpıyor, yani istemci yalnızca aynı davranışı ÖNCEDEN gösteriyor.
    var BCC_MIN_COLUMN_WIDTH = <?php echo (int) $GLOBALS['BCC_MIN_COLUMN_WIDTH']; ?>;
    var BCC_MAX_COLUMN_WIDTH = <?php echo (int) $GLOBALS['BCC_MAX_COLUMN_WIDTH']; ?>;
    var BCC_HAS_COLUMN_WIDTHS = <?php echo $hasColumnWidths ? 'true' : 'false'; ?>;
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
<script src="<?php echo bcc_asset_url('grid-sort.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-hide-fields.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-group.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-column-drag.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-column-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-freeze-columns.js'); ?>" defer></script>
<?php // Sütun genişliği sürükleme — grid-freeze-columns.js'ten SONRA yükleniyor
      // (defer sırayı korur): boyutlandırma her adımda window.BCC_reapplyFreeze()
      // çağırıyor ve localStorage'dan geri yükleme de onu ÇALIŞTIRMADAN ÖNCE
      // tanımlanmış olmasını gerektiriyor. Ters yönde ise freeze dosyası
      // window.BCC_relayoutColumnResize()'ı (varsa) çağırıyor. ?>
<script src="<?php echo bcc_asset_url('grid-column-resize.js'); ?>" defer></script>
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
<!-- Genişlet paneli TÜM takım üyelerine açık (OpsFlow: kayıt görüntüleme
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
<!-- "Kaydı gönder" modalı (OpsFlow "Send record" davranışı) — kayıt detay
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
<?php /* Yeni tablo modalı — YALNIZCA owner'a basılır (menüdeki tetikleyiciyle
         AYNI kapı; sunucu tarafında api/table_create.php ayrıca kontrol eder).
         dashboard.php'deki "Yeni Base Oluştur" modalıyla AYNI .home-modal-*
         sınıfları: grid.php home.css'i zaten yüklüyor (bkz. <head>), ikinci bir
         modal stili YAZILMADI.
         base_id data-* ile taşınıyor — JS'in URL'den base'i çıkarmasına gerek
         kalmasın (URL yalnızca table_id taşıyor). */ ?>
<?php /* Tablo adı/açıklama değiştirme + silme onayı — sayfa İÇİNDE küçük
         pencere, native confirm() DEĞİL ("localhost diyor ki…"). Yeni tablo
         penceresiyle AYNI .home-modal-* sınıfları (grid.php home.css'i zaten
         yüklüyor), ikinci bir modal stili YAZILMADI. */ ?>
<?php if ($isOwner): ?>
<div class="home-modal-backdrop" id="gs-table-rename-modal" hidden>
    <div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="gs-table-rename-title">
        <div class="home-modal-head">
            <h2 id="gs-table-rename-title">Ad veya Açıklama Değiştir</h2>
            <button type="button" class="home-modal-close" id="gs-table-rename-close" aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <?php // method/action YOK: gönderim AJAX, sayfa terk edilmesin. ?>
        <form class="home-modal-form" id="gs-table-rename-form">
            <label class="home-modal-field">
                <span class="home-modal-label">Tablo adı</span>
                <input type="text" name="name" class="home-modal-input" maxlength="150" required autocomplete="off">
            </label>
            <label class="home-modal-field">
                <span class="home-modal-label">Açıklama <span class="home-modal-optional">(opsiyonel)</span></span>
                <input type="text" name="description" class="home-modal-input" maxlength="500" autocomplete="off">
            </label>
            <p class="home-modal-error" id="gs-table-rename-error" hidden></p>
            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" id="gs-table-rename-cancel">Vazgeç</button>
                <button type="submit" class="home-modal-btn home-modal-btn-primary">Kaydet</button>
            </div>
        </form>
    </div>
</div>

<div class="home-modal-backdrop" id="gs-table-delete-modal" hidden>
    <div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="gs-table-delete-title">
        <div class="home-modal-head">
            <h2 id="gs-table-delete-title">Tabloyu sil</h2>
            <button type="button" class="home-modal-close" id="gs-table-delete-close" aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div class="home-modal-form">
            <?php // Özet JS tarafından doldurulur (tablo adı + ne kaybedileceği). ?>
            <p class="home-modal-label" id="gs-table-delete-summary"></p>
            <p class="home-modal-error" id="gs-table-delete-error" hidden></p>
            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" id="gs-table-delete-cancel">Vazgeç</button>
                <button type="button" class="home-modal-btn home-modal-btn-primary" id="gs-table-delete-confirm">Kalıcı olarak sil</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php /* Yapıştırma onayı — sayfa İÇİNDE, native confirm() DEĞİL. window.confirm
         tarayıcının kendi kutusunu açar ("localhost diyor ki…") ve sayfanın
         görsel diliyle hiç ilgisi yoktur; ayrıca arkadaki kesik çizgili hedef
         alanı göstermeye izin vermez. Aynı .home-modal-* sınıfları
         (grid.php home.css'i zaten yüklüyor), ikinci bir modal stili YAZILMADI. */ ?>
<?php if ($canEdit): ?>
<div class="home-modal-backdrop" id="gs-paste-modal" hidden>
    <div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="gs-paste-title">
        <div class="home-modal-head">
            <h2 id="gs-paste-title">Yapıştırmayı onaylayın</h2>
            <button type="button" class="home-modal-close" id="gs-paste-close" aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <div class="home-modal-form">
            <?php // Özet JS tarafından doldurulur; hedef alan arkada kesik
                  // çizgiyle boyalı durur, kullanıcı NEREYE yazılacağını görür. ?>
            <p class="home-modal-label" id="gs-paste-summary"></p>

            <p class="home-modal-error" id="gs-paste-error" hidden></p>

            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" id="gs-paste-cancel">Vazgeç</button>
                <button type="button" class="home-modal-btn home-modal-btn-primary" id="gs-paste-confirm">Yapıştır</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($isOwner): ?>
<div class="home-modal-backdrop" id="gs-create-table-modal" data-base-id="<?php echo (int) $table['base_id']; ?>" hidden>
    <div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="gs-create-table-title">
        <div class="home-modal-head">
            <h2 id="gs-create-table-title">Yeni Tablo Oluştur</h2>
            <button type="button" class="home-modal-close" id="gs-create-table-close" aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <?php /* method/action YOK: gönderim JS ile AJAX (submit olayı
                 preventDefault ediliyor). Sayfa terk edilmesin diye. */ ?>
        <form class="home-modal-form" id="gs-create-table-form">
            <label class="home-modal-field">
                <span class="home-modal-label">Tablo adı</span>
                <input type="text" name="name" class="home-modal-input" maxlength="150" required autocomplete="off" placeholder="Örn. Müşteriler">
            </label>

            <label class="home-modal-field">
                <span class="home-modal-label">Açıklama <span class="home-modal-optional">(opsiyonel)</span></span>
                <input type="text" name="description" class="home-modal-input" maxlength="500" autocomplete="off">
            </label>

            <p class="home-modal-error" id="gs-create-table-error" hidden></p>

            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" id="gs-create-table-cancel">Vazgeç</button>
                <button type="submit" class="home-modal-btn home-modal-btn-primary">Oluştur</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<script src="<?php echo bcc_asset_url('account-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-table-tabs.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-view-manage.js'); ?>" defer></script>
<?php // Hücre seçimi — yapıştırma çapasını belirler. grid.js'ten SONRA
      // yüklenmeli (defer sırayı korur): window.BCC_GRID'in hazır olmasına
      // bağlı değil ama yapıştırma modülü ikisine de bağlı. ?>
<script src="<?php echo bcc_asset_url('grid-cell-select.js'); ?>" defer></script>
<?php // Pano yapıştırması — grid-cell-select.js'ten SONRA (window.BCC_GRID_SELECT
      // ona bağlı). YALNIZCA düzenleme yetkisi olanda yüklenir; sunucu tarafı
      // kapı ayrıca api/cells_bulk_update.php'de (require_role('editor')). ?>
<?php if ($canEdit): ?>
<script src="<?php echo bcc_asset_url('grid-paste.js'); ?>" defer></script>
<?php endif; ?>
<script src="<?php echo bcc_asset_url('grid-export-png.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('grid-table-data.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('share-popover.js'); ?>" defer></script>
<?php // "Paylaş" modalı — dismissable-panel.js'ten SONRA yükleniyor (defer sırayı
      // korur): kapanma davranışı için window.bcc_bindDismissable'a bağlı. ?>
<script src="<?php echo bcc_asset_url('share-modal.js'); ?>" defer></script>
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
