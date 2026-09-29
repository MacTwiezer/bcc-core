<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

/* Calisma alanini (teams satirini) KALICI siler. 2026-09-22'de eklendi:
   musteri bos kalan calisma alanlarini ("aaa") aradan kaldiramiyordu ve
   uygulamada silme hic yoktu.

   Kullanici karari: icerigi ne olursa olsun silinebilsin, yalnizca onay
   kutusu ciksin. Yetki base silmeyle ayni cizgide: o alanda owner (platform
   yoneticisi her ekipte owner sayilir).

   Silme yabanci anahtar zincirini tetikler:
     teams -> team_members, bases (-> tables_meta -> fields/records ->
     cell_values/attachments/comments/views/yildizlar), slack_webhooks,
     slack_routing_rules, slack_watched_fields   (hepsi ON DELETE CASCADE)
     audit_log.team_id ve record_view_log.team_id -> SET NULL, yani GECMIS
     KAYBOLMUYOR, yalnizca ekip bagi kopuyor.
   Kullanici HESAPLARI silinmez, yalnizca bu alandaki uyelikleri kalkar. */

$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;

if ($teamId <= 0) {
    json_fail(400, 'Çalışma alanı belirtilmedi.');
}

$team = bcc_fetch_one('SELECT id, name FROM teams WHERE id = :id LIMIT 1', array(':id' => $teamId));

if (!$team) {
    json_fail(404, 'Çalışma alanı bulunamadı.');
}

require_role($teamId, 'owner');

/* Sayilar denetim kaydi icin: satirlar gidince "ne kadar veri gitti"
   sorusunun cevabi baska hicbir yerde kalmiyor. */
$counts = array(
    'bases' => (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :t', array(':t' => $teamId)),
    'tables' => (int) bcc_fetch_column(
        'SELECT COUNT(*) FROM tables_meta tm INNER JOIN bases b ON b.id = tm.base_id WHERE b.team_id = :t',
        array(':t' => $teamId)
    ),
    'records' => (int) bcc_fetch_column(
        'SELECT COUNT(*) FROM records r
         INNER JOIN tables_meta tm ON tm.id = r.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE b.team_id = :t',
        array(':t' => $teamId)
    ),
    'members' => (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $teamId)),
);

/* Ek dosyalarinin yollari satirlar DURURKEN toplanir, unlink commit'ten SONRA
   yapilir (bkz. api/base_purge.php'deki ayni gerekce). */
$attachmentRows = bcc_fetch_all(
    'SELECT a.stored_name
     FROM attachments a
     INNER JOIN records r ON r.id = a.record_id
     INNER JOIN tables_meta tm ON tm.id = r.table_id
     INNER JOIN bases b ON b.id = tm.base_id
     WHERE b.team_id = :t',
    array(':t' => $teamId)
);

$attachmentPaths = array();
foreach ($attachmentRows as $row) {
    $attachmentPaths[] = bcc_attachment_storage_path($row['stored_name']);
}

$imagePath = bcc_team_image_path($teamId);

try {
    bcc_begin_transaction();

    /* Denetim kaydi silmeden ONCE yazilir: audit_log.team_id yabanci anahtari
       ON DELETE SET NULL oldugu icin satir kaliyor, yalnizca team_id NULL'a
       dusuyor. Silmeden sonra yazilsaydi FK'ya takilirdi. */
    log_audit('team.delete', 'team', $teamId, array_merge(array('name' => $team['name']), $counts), $teamId);

    bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $teamId));

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Çalışma alanı silinemedi (veritabanı hatası).');
}

foreach ($attachmentPaths as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}

if (is_file($imagePath)) {
    @unlink($imagePath);
}

echo json_encode(array(
    'ok' => true,
    'counts' => $counts,
    'redirect_url' => '/workspaces.php',
), JSON_UNESCAPED_UNICODE);
