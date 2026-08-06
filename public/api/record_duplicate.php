<?php
// AJAX uçnoktası: "Kaydı çoğalt" (Airtable "Duplicate record" paritesi).
// record_add.php ile AYNI iskelet + "araya ekleme" (position kaydırma)
// mantığı — çoğaltma özünde "değerleri önceden dolu, orijinalin hemen
// altına eklenen yeni kayıt". Güvenlik: comment_add.php ile AYNI desen,
// team_id record_id üzerinden bcc_find_record() ile DB'den (istekten değil).
//
// KOPYALANAN: normal alan değerleri (cell_values, dosya ekleri HARİÇ).
// KOPYALANMAYAN: dosya ekleri (attachments tablosu, bu turda BİLEREK boş
// bırakılıyor), yorumlar (comments — hiç dokunulmuyor, yeni kayıt zaten
// temiz), "otomatik" alanlar — projede böyle bir FIELD TİPİ yok (yalnızca
// 10 gerçek tip var, $GLOBALS['BCC_FIELD_TYPES']), tek "otomatik" şey
// records.created_by/created_at kolonları ki onlar zaten YENİ INSERT'te
// (orijinalden kopyalanmadan) oturumdaki kullanıcı + o anki zamanla dolar.
//
// BİRİNCİL ALAN: index 0 (position sıralı) — değeri value_text kolonunu
// kullanan bir tipse (single_line_text/long_text/single_select/time —
// pratikte hep budur) sonuna " copy" eklenir; nadir durumda (sayı/tarih/
// kullanıcı tipi birincil alan) değer AYNEN kopyalanır, ek yapılmaz.

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
    foreach ($fields as $f) {
        if ($f['field_type'] === 'attachment') {
            $attachmentFieldIds[] = (int) $f['id'];
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
    $excludeIds = $attachmentFieldIds;
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

    // Birincil alan — " copy" eki yalnızca value_text kullanan tiplerde.
    if ($primaryFieldId !== null) {
        $origPrimaryCell = bcc_fetch_one(
            'SELECT value_text, value_number, value_date, value_json FROM cell_values WHERE record_id = :rid AND field_id = :fid LIMIT 1',
            array(':rid' => $recordId, ':fid' => $primaryFieldId)
        );

        if ($origPrimaryCell) {
            $primaryColumn = isset($GLOBALS['BCC_FIELD_VALUE_COLUMN'][$primaryFieldType])
                ? $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$primaryFieldType]
                : null;

            $newValueText = $origPrimaryCell['value_text'];
            if ($primaryColumn === 'value_text') {
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

$newRecord = array('id' => $newRecordId);
ob_start();
bcc_render_grid_data_row($newRecord, 0, $visibleFields, $cellsByRecord, true, $tableId, $stateQueryString, null, $usersById, $fields, $attachmentsByRecord);
$rowHtml = ob_get_clean();

echo json_encode(array(
    'ok' => true,
    'record_id' => $newRecordId,
    'row_html' => $rowHtml,
), JSON_UNESCAPED_UNICODE);
