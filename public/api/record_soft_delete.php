<?php
// AJAX uçnoktası: "Kaydı sil" (Airtable soft-delete/çöp kutusu paritesi,
// Adım 3b — SADECE işaretleme, sorguların bunu gizlemesi Adım 3c'de).
// comment_add.php/record_send.php/record_duplicate.php ile AYNI desen,
// team_id record_id üzerinden bcc_find_record() ile DB'den (istekten değil).
//
// BİLEREK record_delete.php'den AYRI: o dosya grid araç çubuğundaki
// checkbox ile ÇOKLU seçim "Seçili N kaydı sil" akışına ait ve GERÇEK
// (hard) DELETE yapıyor (table_id + record_ids[]) — burada değiştirilmedi,
// davranışı sessizce soft-delete'e çevrilmedi (kapsam dışı). Bu uçnokta
// TEK kayıt, detay modalındaki "..." menüsünden çağrılır.
//
// GERÇEK DELETE YOK — yalnızca UPDATE records SET deleted_at=NOW(),
// deleted_by=<oturumdaki kullanıcı>. Satır DB'de kalır.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

// ROL KONTROLÜ — yalnızca editor/owner silebilir. commenter/viewer
// require_role() içinde 403 ile reddedilir (UPDATE HİÇ çalışmaz).
require_role($record['team_id'], 'editor');

try {
    $existing = bcc_fetch_one(
        'SELECT deleted_at FROM records WHERE id = :id LIMIT 1',
        array(':id' => $recordId)
    );
    if (!$existing) {
        json_fail(404, 'Kayıt bulunamadı.');
    }
    if ($existing['deleted_at'] !== null) {
        json_fail(422, 'Bu kayıt zaten silinmiş.');
    }

    $user = current_user();

    // "AND deleted_at IS NULL" — çift tıklama/yarış durumuna karşı ikinci
    // bir güvence: 0 satır etkilenirse (araya biri girip silmişse) hata.
    // bcc_execute() etkilenen satır sayısını doğrudan döndürür (mysqli_stmt
    // ->affected_rows), ayrı bir ROW_COUNT() sorgusu gerekmez.
    $affected = bcc_execute(
        'UPDATE records SET deleted_at = NOW(), deleted_by = :uid WHERE id = :id AND deleted_at IS NULL',
        array(':uid' => $user['id'], ':id' => $recordId)
    );
    if ((int) $affected === 0) {
        json_fail(422, 'Bu kayıt zaten silinmiş.');
    }

    // Hard-delete'in 'record.delete' action'ından BİLEREK farklı isim —
    // audit trail'de soft/hard karışmasın.
    log_audit('record.delete_soft', 'record', $recordId, array('table_id' => $record['table_id']), $record['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
