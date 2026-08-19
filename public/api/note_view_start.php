<?php
// AJAX uçnoktası: bir notun (records satırı) İNCELEME KAYDINI BAŞLATIR.
// interface.php'deki Duyuru ekranında bir kayda tıklandığında çağrılır
// (assets/interface.js -> selectRow). record_view_log'a opened_at = NOW() ile
// bir satır yazar; dönen view_id ile note_view_end.php o satırı tamamlar.
//
// Güvenlik: CSRF + require_team_access. team_id istekten ALINMAZ, record_id
// üzerinden bcc_find_record() ile DB'den TÜRETİLİR (KVKK — comment_add.php
// ile AYNI zincir).
//
// Rol kapısı require_role() DEĞİL: burada bir YETENEK eşiği ("şunu yapabilir
// mi") değil, bir KİMLİK sorusu ("bu kişi temsilci mi") var — bkz. src/auth.php
// bcc_is_representative(). require_role() rütbe karşılaştırması yapar ve
// commenter'ın ÜSTÜNDEKİ rolleri de geçirirdi.
//
// TEMSİLCİ DEĞİLSE 403 DEĞİL, SESSİZ NO-OP: {ok:true, view_id:null}.
// İzleme bir yetki değil bir ölçümdür; owner/editor/viewer'ın Duyuru ekranında
// gezinmesi HATA değildir, yalnızca kaydedilmez. 403 dönseydi önbellekten gelen
// eski bir JS bayrağı yüzünden konsolda hata yığınları oluşurdu. Satır YİNE DE
// yazılmaz — güvenlik sınırı korunur.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_team_access($record['team_id']);

// Silinmiş (çöp kutusundaki) kaydın incelemesi kaydedilmez — comment_add.php
// ile AYNI desen ve AYNI gerekçe: bcc_find_record() deleted_at'e BAKMAZ, bu
// yüzden burada YEREL bir kontrol gerekiyor.
$recordStatus = bcc_fetch_one(
    'SELECT deleted_at FROM records WHERE id = :id LIMIT 1',
    array('id' => $recordId)
);
if (!$recordStatus || $recordStatus['deleted_at'] !== null) {
    json_fail(404, 'Kayıt bulunamadı (silinmiş).');
}

$role = current_user_role_in_team($record['team_id']);

if (!bcc_is_representative($role)) {
    // Sessiz no-op (yukarıdaki nota bakın) — record_view_log'a satır YAZILMAZ.
    echo json_encode(array('ok' => true, 'view_id' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();

// opened_at SUNUCUDA (NOW()) üretilir — istemci saatine güvenilmez.
bcc_execute(
    'INSERT INTO record_view_log (record_id, user_id, team_id, role_at_view, opened_at)
     VALUES (:record_id, :user_id, :team_id, :role_at_view, NOW())',
    array(
        'record_id' => $recordId,
        'user_id' => $user['id'],
        'team_id' => $record['team_id'],
        'role_at_view' => $role,
    )
);

// ⚠️ INSERT'in HEMEN ardından çağrılmalı: araya başka bir sorgu girerse
// insert_id EZİLİR (bkz. src/schema.php:1946 notu).
$viewId = (int) bcc_last_insert_id();

echo json_encode(array('ok' => true, 'view_id' => $viewId), JSON_UNESCAPED_UNICODE);
