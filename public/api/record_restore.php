<?php
// AJAX uçnoktası: Çöp kutusu modalının "Kayıtlar" bölümündeki "Geri Yükle" —
// record_soft_delete.php'nin TERSİ (deleted_at/deleted_by NULL'lanır).
// base_restore.php ile AYNI desen — TEK fark: bases'te yalnızca 'owner' geri
// yükleyebiliyor, kayıtlarda Airtable paritesi gereği editor+owner (task'ta
// AÇIKÇA belirtildi, base_restore.php'nin owner-only kuralı KÖRLEMESİNE
// kopyalanmadı).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

// ROL KONTROLÜ — editor+owner geri yükleyebilir, commenter/viewer YAPAMAZ.
require_role($record['team_id'], 'editor');

try {
    $existing = bcc_fetch_one('SELECT deleted_at FROM records WHERE id = :id LIMIT 1', array(':id' => $recordId));
    if (!$existing) {
        json_fail(404, 'Kayıt bulunamadı.');
    }
    if ($existing['deleted_at'] === null) {
        json_fail(422, 'Bu kayıt zaten aktif, geri yüklemeye gerek yok.');
    }

    $affected = bcc_execute(
        'UPDATE records SET deleted_at = NULL, deleted_by = NULL WHERE id = :id AND deleted_at IS NOT NULL',
        array(':id' => $recordId)
    );
    if ((int) $affected === 0) {
        json_fail(422, 'Bu kayıt zaten aktif, geri yüklemeye gerek yok.');
    }

    log_audit('record.restore', 'record', $recordId, array('table_id' => $record['table_id']), $record['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
