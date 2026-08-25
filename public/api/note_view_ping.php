<?php
// AJAX uçnoktası: AÇIK bir not incelemesinin süresini TAZELER (nabız).
// note_view_start.php satırı açar, bu uçnokta süreyi ilerletir,
// note_view_end.php satırı kapatır.
//
// ⚠️ NEDEN EKLENDİ (kullanıcı bildirdi: "kaç saniye baktığı gözükmüyor"):
// süre YALNIZCA kapanışta yazılıyordu. Kapanış olayı (visibilitychange /
// pagehide / başka nota geçiş) ulaşmazsa satır sonsuza dek closed_at = NULL
// kalıyor ve listede "süre kaydedilmedi" yazıyordu. Ölçüldü: canlı tablodaki
// 5 satırın 2'si bu durumdaydı, biri 6 gündür açık. Kapanışın ulaşmadığı
// gerçek durumlar: tarayıcı/işletim sistemi çökmesi, makinenin uykuya alınması,
// sekmenin process olarak öldürülmesi, ağın kopması — hiçbiri istisna değil.
//
// Ayrıca not HÂLÂ AÇIKKEN de artık bir süre görünür: owner listeyi o sırada
// açtığında "şu an 3 dk 20 sn'dir bakıyor" bilgisini alır.
//
// closed_at'e DOKUNULMAZ: "inceleme bitti" anlamını yalnızca gerçek kapanış
// taşımalı. Bu uçnokta sadece duration_seconds'ı ilerletir, yani nabız
// kesilirse süre son nabızda DONAR (gerçeğe en fazla bir nabız aralığı kadar
// uzak) — hiç veri olmamasından kıyaslanamayacak kadar iyi.
//
// YETKİLENDİRME note_view_end.php ile BİREBİR AYNI üç katman:
//   1) WHERE user_id = :user_id  -> başkasının satırı ilerletilemez
//   2) WHERE closed_at IS NULL   -> kapanmış satırın süresi UZATILAMAZ
//   3) require_team_access()     -> KVKK; team_id istekten DEĞİL satırdan
// Rol (bcc_is_representative) burada da kontrol edilmez — note_view_end.php'deki
// AYNI gerekçe: satırın sahibi ROL değil user_id'dir, açılış ile nabız arasında
// rol değişmiş olabilir.
//
// Bulunamayan / başkasına ait / kapanmış satır 404 DEĞİL sessiz başarı:
// nabız bir ölçüm ayrıntısıdır, kullanıcıya hata göstermenin anlamı yok.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

// note_view_end.php ile AYNI üst sınır ve AYNI gerekçe (bkz. o dosya):
// sekme günlerce açık kalırsa "37 sa" gibi anlamsız bir değer oluşmasın.
// PHP int olarak kalmalı — bcc_bind_type() string'i 's' ile bağlar ve LEAST()
// metinsel karşılaştırmaya düşerdi.
const BCC_NOTE_VIEW_MAX_SECONDS = 14400;

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;

$user = current_user();

$row = bcc_fetch_one(
    'SELECT id, team_id, closed_at FROM record_view_log
      WHERE id = :id AND user_id = :user_id LIMIT 1',
    array('id' => $viewId, 'user_id' => $user['id'])
);

if (!$row || $row['closed_at'] !== null) {
    echo json_encode(array('ok' => true, 'duration_seconds' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

require_team_access($row['team_id']);

// Süre yine SUNUCUDA hesaplanır (note_view_end.php'deki AYNI ilke): istemci
// süre göndermez, gönderseydi tarayıcı saatiyle oynayan biri inceleme süresini
// istediği gibi yazabilirdi.
bcc_execute(
    'UPDATE record_view_log
        SET duration_seconds = LEAST(TIMESTAMPDIFF(SECOND, opened_at, NOW()), :max_seconds)
      WHERE id = :id AND user_id = :user_id AND closed_at IS NULL',
    array(
        'id' => $viewId,
        'user_id' => $user['id'],
        'max_seconds' => BCC_NOTE_VIEW_MAX_SECONDS,
    )
);

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
