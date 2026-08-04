<?php
// AJAX uçnoktası: seçili kayıt(lar)ı siler — checkbox'la seçilen satırlardaki
// "Seçilenleri sil" butonu (grid.php / grid-row-detail.js) çağırır. record_add.php
// ile AYNI desen: api_bootstrap + api_require_post/login/csrf, find_table_or_404
// + require_role('editor'), transaction içinde audit log commit'ten ÖNCE.
//
// Bulunan gerçek bug'ın onarımı: grid.php'de daha önce bir "delete_record" POST
// action'ı vardı ama satır render'ının bcc_render_grid_data_row()'a taşındığı
// refactor'da o formun HTML'i kaldırılmış, handler hiçbir yerden tetiklenemez
// hâle gelmişti (grid'de HİÇBİR satır silinemiyordu). Bu uçnokta o işlevi
// AJAX'a taşıyarak yeniden kazandırıyor; eski ölü handler grid.php'den kaldırıldı.
//
// $record_ids[] birden fazla id kabul eder (toplu silme) — tabloya ait olmayan/
// geçersiz id'ler sessizce elenir, hata döndürmez (kısmi seçim senaryosu değil,
// istemci zaten yalnızca kendi render ettiği satırların id'lerini gönderir).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$recordIds = isset($_POST['record_ids']) && is_array($_POST['record_ids'])
    ? array_values(array_unique(array_map('intval', $_POST['record_ids'])))
    : array();

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    if (empty($recordIds)) {
        json_fail(422, 'Silinecek kayıt seçilmedi.');
    }

    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $params = array_merge(array($table['id']), $recordIds);
    $existing = bcc_fetch_all(
        "SELECT id FROM records WHERE table_id = ? AND id IN ($placeholders)",
        $params
    );
    $validIds = array_map(function ($row) { return (int) $row['id']; }, $existing);

    if (empty($validIds)) {
        json_fail(422, 'Bu kayıtlar bu tabloya ait değil.');
    }

    bcc_begin_transaction();

    foreach ($validIds as $id) {
        // DB satırı CASCADE ile silinir ama diskteki fiziksel dosyalar otomatik
        // silinmez — bu yüzden DELETE'ten ÖNCE (record_add.php'nin dead-code
        // öncülü delete_record handler'ıyla AYNI sıra, bkz. src/schema.php:671).
        bcc_delete_attachment_files_by_record($id);
        log_audit('record.delete', 'record', $id, array('table_id' => $table['id']), $table['team_id']);
    }

    $deletePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
    bcc_execute(
        "DELETE FROM records WHERE table_id = ? AND id IN ($deletePlaceholders)",
        array_merge(array($table['id']), $validIds)
    );

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array(
    'ok' => true,
    'deleted_record_ids' => $validIds,
), JSON_UNESCAPED_UNICODE);
