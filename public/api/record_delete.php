<?php

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

    /* 2026-09-09 — TOPLU silme artik KALICI DEGIL, cop kutusuna tasiyor.
       Onceden bu uc nokta "DELETE FROM records" yapiyordu; satir cekmecesinden
       tek kayit silme ise (record_soft_delete.php) cop kutusuna tasiyordu.
       Ayni islemin iki farkli sonucu olmasi hem kullanici icin sasirticiydi
       hem de Slack'in "silindi" bildirimini toplu yolda calistiramiyordu:
       bildirim, silinen satirin deleted_at ile durmasina dayaniyor.

       DIKKAT — dosya temizligi BILEREK cagrilmiyor: kayit geri yuklenebilir
       oldugu icin eklerinin de diskte durmasi gerekiyor. Ekleri silme isi cop
       kutusunun 7 gunluk sureli temizligine ait
       (trash_records_list.php -> bcc_delete_attachment_files_by_records). */
    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $params = array_merge(array($table['id']), $recordIds);
    $existing = bcc_fetch_all(
        "SELECT id FROM records WHERE table_id = ? AND deleted_at IS NULL AND id IN ($placeholders)",
        $params
    );
    $validIds = array_map(function ($row) { return (int) $row['id']; }, $existing);

    if (empty($validIds)) {
        json_fail(422, 'Bu kayıtlar bu tabloya ait değil ya da zaten silinmiş.');
    }

    bcc_begin_transaction();

    foreach ($validIds as $id) {
        log_audit('record.delete_soft', 'record', $id, array('table_id' => $table['id'], 'toplu' => true), $table['team_id']);
    }

    $user = current_user();

    /* updated_at = updated_at: silme bir "icerik degisikligi" degil, "Son
       degisiklik zamani" alani kaymasin (record_soft_delete.php ile ayni). */
    $softPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
    bcc_execute(
        "UPDATE records SET deleted_at = NOW(), deleted_by = ?, updated_at = updated_at
          WHERE table_id = ? AND deleted_at IS NULL AND id IN ($softPlaceholders)",
        array_merge(array((int) $user['id'], $table['id']), $validIds)
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
