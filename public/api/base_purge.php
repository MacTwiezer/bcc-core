<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

/* Base'i KALICI siler. Iki asamali bilerek: yalnizca COP KUTUSUNDAKI
   (deleted_at dolu) bir base kalici silinebilir. Boylece tek tiklamayla
   "geri donusu olmayan" bir yol acilmiyor; once cope tasi, sonra kalici sil.
   Yetki base_delete ile ayni: o calisma alaninda owner (platform yoneticisi
   her ekipte owner sayiliyor).

   Silme yabanci anahtar zincirini tetikler: tables_meta -> fields/records ->
   cell_values/attachments/comments/views/slack kurallari/yildizlar (hepsi
   ON DELETE CASCADE). audit_log ve record_view_log'un base'e bagi yok,
   gecmis duruyor. */

$baseId = isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0;

$base = bcc_fetch_one(
    'SELECT id, team_id, name FROM bases WHERE id = :id AND deleted_at IS NOT NULL LIMIT 1',
    array(':id' => $baseId)
);

if (!$base) {
    json_fail(404, 'Silinmiş base bulunamadı. Kalıcı silmek için base önce çöp kutusunda olmalı.');
}

require_role($base['team_id'], 'owner');

/* Sayilar yalnizca denetim kaydi icin: base gidince "ne kadar veri gitti"
   sorusunun cevabi baska hicbir yerde kalmiyor. */
$tableCount = (int) bcc_fetch_column(
    'SELECT COUNT(*) FROM tables_meta WHERE base_id = :id',
    array(':id' => $base['id'])
);
$recordCount = (int) bcc_fetch_column(
    'SELECT COUNT(*) FROM records r INNER JOIN tables_meta tm ON tm.id = r.table_id WHERE tm.base_id = :id',
    array(':id' => $base['id'])
);

/* Dosya yollari SATIRLAR DURURKEN toplanir; silme islemi commit olduktan
   sonra unlink edilir. Tersi (once dosya, sonra DELETE) table_delete.php'de
   var ama orada commit patlarsa dosyalar ucmus, satirlar yerinde kalmis
   olur. Burada risk ters yone cevrildi: en kotu durumda birkac yetim dosya
   diskte kalir, veri tutarsizligi olmaz. */
$attachmentPaths = bcc_attachment_paths_by_base($base['id']);

try {
    bcc_begin_transaction();

    bcc_execute(
        'DELETE FROM bases WHERE id = :id AND deleted_at IS NOT NULL',
        array(':id' => $base['id'])
    );

    log_audit(
        'base.purge',
        'base',
        $base['id'],
        array('name' => $base['name'], 'tables' => $tableCount, 'records' => $recordCount),
        $base['team_id']
    );

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Base kalıcı silinemedi (veritabanı hatası).');
}

foreach ($attachmentPaths as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
