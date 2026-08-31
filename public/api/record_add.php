<?php
// AJAX uçnoktası: yeni (boş) kayıt ekler. (a) yuvarlak + butonu, (b) tablo tabanı
// + satırı VE (c) Shift+Enter kısayolu — ÜÇÜ DE bu TEK uç noktayı çağırır
// (grid.php / assets/grid.js) — ikinci bir "kayıt ekle" mekanizması yazılmaz.
// Güvenlik: CSRF + require_role('editor') + table_id doğrulaması; after_record_id
// verilmişse gerçekten bu tabloya ait olduğu kontrol edilir (yoksa sessizce sona eklenir).

require __DIR__ . '/../../src/api_bootstrap.php';

// Tek istekte acilabilecek azami bos satir. Sunum/veri girisi icin fazlasiyla
// yeterli; ustu istemciden gelen kazara buyuk sayilara karsi koruma.
const BCC_ADD_MAX_ROWS = 500;

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$afterRecordId = isset($_POST['after_record_id']) ? (int) $_POST['after_record_id'] : 0;
// Silme formunun action URL'i için — grid.php'nin kendi $stateQueryString'i ile aynı
// mantıkla, istemci mevcut adres çubuğunun query string'ini olduğu gibi geri gönderir.
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

// Toplu ekleme: istemci bir SAYI gonderir, o kadar bos kayit TEK transaction
// icinde acilir. Gonderilmezse 1 - yani (a)/(b)/(c) yollarinin davranisi
// BIREBIR eskisi gibi kalir, ikinci bir "kayit ekle" uc noktasi yazilmaz.
$count = isset($_POST['count']) ? (int) $_POST['count'] : 1;
if ($count < 1) {
    $count = 1;
}
if ($count > BCC_ADD_MAX_ROWS) {
    json_fail(422, 'Tek seferde en fazla ' . BCC_ADD_MAX_ROWS . ' satir eklenebilir.');
}

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array(':table_id' => $table['id'])
    );

    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;

    // Görünür alan listesi grid.php ile AYNI whitelist fonksiyonundan gelir —
    // hangi alanların "Hide fields" ile kapatıldığını yeniden yazmıyoruz.
    $stateParams = array();
    parse_str($stateQueryString, $stateParams);
    $hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);

    $visibleFields = array();
    foreach ($fields as $f) {
        if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
            $visibleFields[] = $f;
        }
    }

    bcc_begin_transaction();

    $newPos = null;

    if ($afterRecordId > 0) {
        // Adım 3c: silinmiş bir kaydın "hemen altına" araya eklemek anlamsız —
        // deleted_at IS NULL eklenince zaten var olan "bulunamadıysa sona ekle"
        // fallback'ine (aşağıdaki $afterRecord boş kalır) doğal düşer, hata YOK.
        $afterRecord = bcc_fetch_one(
            'SELECT id, position FROM records WHERE id = :id AND table_id = :tid AND deleted_at IS NULL LIMIT 1',
            array(':id' => $afterRecordId, ':tid' => $table['id'])
        );

        if ($afterRecord) {
            // Araya ekleme: after_record_id'den sonraki kayıtların position'ı bir
            // kaydırılır, yeni kayıt açılan boşluğa yerleşir.
            bcc_execute(
                // updated_at = updated_at: MySQL'in ON UPDATE CURRENT_TIMESTAMP'i
                // aksi halde bu satır DEĞİŞMESE bile (yalnızca position kayması,
                // içerik değil — Grup B2 tasarım ilkesi) otomatik bumplardı.
                // Kendi değerine EXPLICIT atama bu otomatik tetiklemeyi bastırıyor
                // (MySQL kuralı: kolona açıkça değer atanırsa otomatik davranış devre dışı kalır).
                'UPDATE records SET position = position + :cnt, updated_at = updated_at WHERE table_id = :tid AND position > :pos',
                array(':tid' => $table['id'], ':pos' => $afterRecord['position'], ':cnt' => $count)
            );
            $newPos = $afterRecord['position'] + 1;
        }
    }

    if ($newPos === null) {
        // (a)/(b) her zaman, (c) ise after_record_id gönderilmediğinde (sıralama/
        // gruplama aktifken istemci bilerek göndermez) sona ekler.
        $newPos = (int) bcc_fetch_column(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :tid',
            array(':tid' => $table['id'])
        );
    }

    $user = current_user();
    // $count kayit ART ARDA acilir. Tek satirlik yol ($count === 1) bu dongunun
    // ozel hali - ikinci bir INSERT kod yolu YOK.
    $newRecordIds = array();
    for ($n = 0; $n < $count; $n++) {
        bcc_execute(
            'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)',
            array(':tid' => $table['id'], ':pos' => $newPos + $n, ':uid' => $user['id'])
        );
        $rid = (int) bcc_last_insert_id();
        $newRecordIds[] = $rid;
        // Autonumber (Grup C2): bu tablonun her autonumber alanı için birer numara
        // ayrılıp cell_values'a yazılır. ⚠️ bcc_last_insert_id() ÇAĞRISINDAN SONRA
        // gelmek ZORUNDA — bcc_assign_autonumbers() LAST_INSERT_ID(expr) kullanır ve
        // oturumun last-insert-id'sini EZER (bkz. o fonksiyonun yorumu). Zaten açık
        // olan transaction'ın içinde: sayaç ilerleyip hücre yazılamazsa numara "yanar".
        bcc_assign_autonumbers($table['id'], $rid);
    }

    $newRecordId = $newRecordIds[0];

    // log_audit() commit'TEN ÖNCE, AYNI transaction içinde — bulunan gerçek bug:
    // burada bir istisna atarsa (nadir ama mümkün) ve bcc_commit() ÖNCE
    // çağrılmış olsaydı, bcc_rollback() artık geri alacak bir şey bulamaz;
    // istemciye "kaydedilemedi" dönerken kayıt aslında DB'de kalırdı (tekrar
    // denenirse sessizce fazladan boş satır birikir). Slack bildirimi (DB
    // mutasyonu değil, kendi try/catch'i zaten var) commit'ten SONRA kalıyor.
    if ($count === 1) {
        log_audit('record.create', 'record', $newRecordId, array('table_id' => $table['id'], 'after_record_id' => $afterRecordId ?: null), $table['team_id']);
    } else {
        // Toplu eklemede 500 ayri audit satiri yazmak yerine TEK ozet satiri:
        // hangi kayitlarin acildigi record_ids'te duruyor.
        log_audit('record.create_bulk', 'table', $table['id'], array('table_id' => $table['id'], 'count' => $count, 'record_ids' => $newRecordIds, 'after_record_id' => $afterRecordId ?: null), $table['team_id']);
    }

    bcc_commit();

    // Toplu eklemede Slack'e 500 ayri "yeni kayit" mesaji atilmaz - bos satirlar
    // zaten icerik tasimiyor; bildirim, hucreler doldurulunca cell_update'ten gider.
    if ($count === 1) {
        bcc_notify_slack_new_record($table['id'], $newRecordId, $user['full_name']);
    }
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

// Yeni satırın HTML'i grid.php'nin ilk sayfa render'ıyla AYNI fonksiyondan üretilir
// (bcc_render_grid_data_row, src/schema.php) — ikinci bir satır şablonu yazılmaz.
// $usersById: 'user' tipi hücrelerin editör seçeneği (data-options) için gerekir.
$usersById = bcc_team_users_by_id($table['team_id']);

// Bulunan gerçek bug (Grup B1'den beri sessizce duruyordu, Grup C2'de yakalandı):
// burası eskiden $record = array('id' => $newRecordId) kuruyordu, ama
// bcc_cell_row_for_field() created_time/created_by/last_modified_time/
// last_modified_by tipleri için $record['created_at']/['created_by']/['updated_at']/
// ['updated_by']'a KORUMASIZ erişiyor — bu dört tipten biri olan bir tabloda
// "Undefined index" notice'ı üretip hücreyi BOŞ render ediyordu (grid.php'nin
// ilk yüklemesinde doğru görünüyor, yalnızca YENİ EKLENEN satırda boş kalıyordu).
// Artık gerçek satır DB'den okunuyor — hem o bug kapanıyor hem de autonumber
// (Grup C2) için gereken cell_values satırı geliyor.
$records = bcc_fetch_all(
    'SELECT id, created_at, created_by, updated_at, updated_by FROM records WHERE id IN (' . implode(',', array_map('intval', $newRecordIds)) . ') ORDER BY position, id'
);
// Yeni kayitta artik hucre OLABILIR: bcc_assign_autonumbers() bu tablonun her
// autonumber alani icin birer cell_values satiri yazmis olabilir. Eskiden
// buraya sabit array() geciliyordu - autonumber hucresi bos gorunurdu.
$cellsByRecord = bcc_fetch_cells_by_record($newRecordIds);

$rowsHtml = array();
foreach ($records as $rec) {
    ob_start();
    // Yeni kayitta henuz hic ek dosya yok - bos dizi (bcc_fetch_attachments_by_record'a
    // ikinci bir sorgu atmaya gerek yok).
    bcc_render_grid_data_row($rec, 0, $visibleFields, $cellsByRecord, true, $table['id'], $stateQueryString, null, $usersById, $fields, array());
    $rowsHtml[] = ob_get_clean();
}

echo json_encode(array(
    'ok' => true,
    // Tekil alanlar geriye donuk uyumluluk icin duruyor (ilk kayit).
    'record_id' => $newRecordId,
    'row_html' => isset($rowsHtml[0]) ? $rowsHtml[0] : '',
    'count' => count($rowsHtml),
    'record_ids' => $newRecordIds,
    'rows_html' => $rowsHtml,
), JSON_UNESCAPED_UNICODE);
