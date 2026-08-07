<?php
// AJAX uçnoktası: "Kaydı çoğalt" (Airtable "Duplicate record" paritesi).
// record_add.php ile AYNI iskelet + "araya ekleme" (position kaydırma)
// mantığı — çoğaltma özünde "değerleri önceden dolu, orijinalin hemen
// altına eklenen yeni kayıt". Güvenlik: comment_add.php ile AYNI desen,
// team_id record_id üzerinden bcc_find_record() ile DB'den (istekten değil).
//
// KOPYALANAN: normal alan değerleri (cell_values, dosya ekleri HARİÇ).
// KOPYALANMAYAN: dosya ekleri (attachments tablosu, bu turda BİLEREK boş
// bırakılıyor), yorumlar (comments — hiç dokunulmuyor, yeni kayıt zaten temiz)
// ve OTOMATİK alan tipleri.
//
// Bu yorum eskiden "'otomatik' alanlar — projede böyle bir FIELD TİPİ yok
// (yalnızca 10 gerçek tip var)" diyordu; bu varsayım Grup B1/B2/C2 ile ÜÇ KEZ
// yanlışlandı, güncellendi. Otomatik tiplerin durumu:
//   * created_time/created_by/last_modified_time/last_modified_by — değerleri
//     cell_values'ta HİÇ YOK (records kolonlarından türetiliyor), bu yüzden
//     aşağıdaki toplu kopyaya doğal olarak GİRMEZLER, ekstra kod gerekmez.
//     Yeni satırın kendi created_by/created_at'i INSERT'te zaten tazedir.
//   * autonumber (Grup C2) — değeri cell_values'ta GERÇEKTEN var, yani toplu
//     kopya onu SESSİZCE TAŞIRDI. Kasıtlı karar: kopya YENİ bir numara alır
//     (Airtable "her kayıt kendine özgü" mantığı; iki kayıt aynı autonumber'ı
//     paylaşırsa "tekil kimlik" amacı bozulur). Bu yüzden $excludeIds'e eklenip
//     kopya sonrası bcc_assign_autonumbers() ile taze numara veriliyor.
//
// BİRİNCİL ALAN: index 0 (position sıralı). Sonuna " copy" eklenip eklenmeyeceği
// bir TİP WHITELIST'inden okunur: $GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES']
// (src/schema.php) — şu an yalnızca single_line_text ve long_text, yani biçim
// sözleşmesi OLMAYAN serbest metin tipleri. Diğer TÜM tipler değeri AYNEN
// kopyalar.
//
// Bu yorum eskiden "değeri value_text kolonunu kullanan bir tipse ... pratikte
// hep budur" diyordu ve karar gerçekten KOLON bazlıydı; value_text'i yedi tip
// paylaştığı için url/email/single_select/time'ın değerini bozuyordu (ayrıntılı
// gerekçe whitelist'in yanında, src/schema.php).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

// ROL KONTROLÜ — yalnızca editor/owner çoğaltabilir. commenter/viewer
// require_role() içinde 403 ile reddedilir (kopya HİÇ oluşturulmaz).
require_role($record['team_id'], 'editor');

try {
    $tableId = (int) $record['table_id'];

    // Adım 3c: silinmiş (çöp kutusundaki) bir kayıt "bulunamadı" sayılır — çoğaltma
    // reddedilir, yeni kod eklemeden mevcut 404 yoluna doğal düşer.
    $original = bcc_fetch_one(
        'SELECT id, position FROM records WHERE id = :id AND table_id = :tid AND deleted_at IS NULL LIMIT 1',
        array(':id' => $recordId, ':tid' => $tableId)
    );
    if (!$original) {
        json_fail(404, 'Kayıt bulunamadı.');
    }

    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array(':table_id' => $tableId)
    );

    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
    $primaryFieldType = !empty($fields) ? $fields[0]['field_type'] : null;

    $attachmentFieldIds = array();
    // autonumber (Grup C2): kopya YENİ numara alacağı için orijinalin numarası
    // toplu kopyadan DIŞLANIR — attachment ile AYNI mekanizma ($excludeIds),
    // ikinci bir dışlama yolu açılmadı.
    $autonumberFieldIds = array();
    foreach ($fields as $f) {
        if ($f['field_type'] === 'attachment') {
            $attachmentFieldIds[] = (int) $f['id'];
        }
        if ($f['field_type'] === 'autonumber') {
            $autonumberFieldIds[] = (int) $f['id'];
        }
    }

    bcc_begin_transaction();

    // "Araya ekleme": orijinalin position'ından BÜYÜK tüm kayıtlar +1
    // kaydırılır — record_add.php'nin after_record_id dalıyla BİREBİR AYNI.
    bcc_execute(
        // updated_at = updated_at: record_add.php'deki AYNI bastırma — pozisyon
        // kayması "içerik değişikliği" sayılmaz (Grup B2 tasarım ilkesi), MySQL'in
        // ON UPDATE CURRENT_TIMESTAMP'i bu satırlara dokunmasın diye.
        'UPDATE records SET position = position + 1, updated_at = updated_at WHERE table_id = :tid AND position > :pos',
        array(':tid' => $tableId, ':pos' => $original['position'])
    );
    $newPos = $original['position'] + 1;

    $user = current_user();
    bcc_execute(
        'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)',
        array(':tid' => $tableId, ':pos' => $newPos, ':uid' => $user['id'])
    );
    $newRecordId = (int) bcc_last_insert_id();

    // Genel alan kopyası — birincil alan ve dosya-eki alanları HARİÇ, tek
    // INSERT...SELECT (tip bilgisine gerek yok, 4 değer kolonu olduğu gibi
    // taşınır; attachment zaten cell_values'ta hiç yoktur, bu filtre ek güvence).
    $excludeIds = array_merge($attachmentFieldIds, $autonumberFieldIds);
    if ($primaryFieldId !== null) {
        $excludeIds[] = $primaryFieldId;
    }

    if (!empty($excludeIds)) {
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
        bcc_execute(
            "INSERT INTO cell_values (record_id, field_id, value_text, value_number, value_date, value_json)
             SELECT ?, field_id, value_text, value_number, value_date, value_json
             FROM cell_values WHERE record_id = ? AND field_id NOT IN ($placeholders)",
            array_merge(array($newRecordId, $recordId), $excludeIds)
        );
    } else {
        bcc_execute(
            'INSERT INTO cell_values (record_id, field_id, value_text, value_number, value_date, value_json)
             SELECT ?, field_id, value_text, value_number, value_date, value_json
             FROM cell_values WHERE record_id = ?',
            array($newRecordId, $recordId)
        );
    }

    // Birincil alan — " copy" eki BCC_DUPLICATE_SUFFIX_FIELD_TYPES whitelist'ine göre.
    // ERKEN ÇIKIŞ: birincil alan autonumber ise bu dal HİÇ çalışmamalı. Yukarıda
    // $excludeIds'e girmesi onu yalnızca TOPLU kopyadan çıkarır; buradaki tekil
    // INSERT ise orijinalin value_number'ını AYNEN kopyalardı (autonumber zaten
    // whitelist'te olmadığı için " copy" eki almazdı, ama NUMARA yine de
    // taşınırdı — tam da önlemek istediğimiz şey). Numara aşağıda
    // bcc_assign_autonumbers() ile taze veriliyor.
    if ($primaryFieldId !== null && $primaryFieldType !== 'autonumber') {
        $origPrimaryCell = bcc_fetch_one(
            'SELECT value_text, value_number, value_date, value_json FROM cell_values WHERE record_id = :rid AND field_id = :fid LIMIT 1',
            array(':rid' => $recordId, ':fid' => $primaryFieldId)
        );

        if ($origPrimaryCell) {
            // " copy" eki KOLONA değil TİPE bakar (whitelist:
            // BCC_DUPLICATE_SUFFIX_FIELD_TYPES, src/schema.php).
            //
            // Bulunan gerçek buglar — eskiden koşul "$primaryColumn === 'value_text'"
            // idi ve value_text'i YEDİ tip paylaştığı için biçim sözleşmesi OLAN
            // tiplerin değerini bozuyordu: url'de link üretilmeye devam ediyor ama
            // boşluk HOST'a karışıp kullanıcıyı var olmayan bir alan adına
            // gönderiyordu (en zararlısı — hata görünmüyordu), email'de mailto
            // linki tamamen kayboluyordu, single_select'te choices listesinde
            // OLMAYAN bir değer yazılıyordu, time'da geçerli olmayan bir saat
            // oluşuyordu. Ayrıntılı gerekçe whitelist'in yanında.
            //
            // Whitelist yaklaşımı bu dört bug'ı TEK kod yoluyla çözüyor — tipe
            // özel dal YAZILMADI.
            $newValueText = $origPrimaryCell['value_text'];
            if (in_array($primaryFieldType, $GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES'], true)) {
                $newValueText = ($newValueText === null || $newValueText === '')
                    ? 'copy'
                    : $newValueText . ' copy';
            }

            bcc_execute(
                'INSERT INTO cell_values (record_id, field_id, value_text, value_number, value_date, value_json)
                 VALUES (:rid, :fid, :vt, :vn, :vd, :vj)',
                array(
                    ':rid' => $newRecordId,
                    ':fid' => $primaryFieldId,
                    ':vt' => $newValueText,
                    ':vn' => $origPrimaryCell['value_number'],
                    ':vd' => $origPrimaryCell['value_date'],
                    ':vj' => $origPrimaryCell['value_json'],
                )
            );
        }
    }

    // Autonumber (Grup C2): kopya orijinalin numarasını TAŞIMAZ, TAZE numara alır.
    // ⚠️ bcc_last_insert_id() çağrısından SONRA (satır ~90) — bu fonksiyon
    // LAST_INSERT_ID(expr) ile oturumun last-insert-id'sini ezer.
    bcc_assign_autonumbers($tableId, $newRecordId);

    log_audit('record.duplicate', 'record', $newRecordId, array('table_id' => $tableId, 'source_record_id' => $recordId), $record['team_id']);

    bcc_commit();

    bcc_notify_slack_new_record($tableId, $newRecordId, $user['full_name']);
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

// Yeni satırın HTML'i grid.php ile AYNI fonksiyondan üretilir — ikinci bir
// satır şablonu yazılmaz. Dosya ekleri kasıtlı kopyalanmadığı için
// bcc_fetch_attachments_by_record() burada zaten boş döner.
$table = find_table_or_404($tableId);
$usersById = bcc_team_users_by_id($table['team_id']);
$cellsByRecord = bcc_fetch_cells_by_record(array($newRecordId));
$attachmentsByRecord = bcc_fetch_attachments_by_record(array($newRecordId));

$stateParams = array();
parse_str($stateQueryString, $stateParams);
$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}
$hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);
$visibleFields = array();
foreach ($fields as $f) {
    if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
        $visibleFields[] = $f;
    }
}

// record_add.php ile AYNI düzeltme: eskiden array('id' => ...) idi, ama
// bcc_cell_row_for_field() created_time/created_by/last_modified_* için
// $record['created_at'] vb.'ye korumasız erişiyor — o dört tipten biri olan
// bir tabloda yeni satır "Undefined index" ile boş render ediliyordu.
$newRecord = bcc_fetch_one(
    'SELECT id, created_at, created_by, updated_at, updated_by FROM records WHERE id = :id',
    array(':id' => $newRecordId)
);
ob_start();
bcc_render_grid_data_row($newRecord, 0, $visibleFields, $cellsByRecord, true, $tableId, $stateQueryString, null, $usersById, $fields, $attachmentsByRecord);
$rowHtml = ob_get_clean();

echo json_encode(array(
    'ok' => true,
    'record_id' => $newRecordId,
    'row_html' => $rowHtml,
), JSON_UNESCAPED_UNICODE);
