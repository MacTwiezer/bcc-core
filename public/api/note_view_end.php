<?php
// AJAX uçnoktası: bir not incelemesini KAPATIR — note_view_start.php'nin açtığı
// record_view_log satırına closed_at ve duration_seconds yazar.
// Çağrıldığı üç yer (assets/interface.js, Adım 8): başka nota geçiş (selectRow),
// sekmenin gizlenmesi (visibilitychange) ve sayfadan ayrılma (pagehide).
//
// SÜRE SUNUCUDA HESAPLANIR: TIMESTAMPDIFF(SECOND, opened_at, NOW()). İstemci ne
// opened_at'i ne de süreyi gönderir — gönderseydi tarayıcı saati (veya elle
// hazırlanmış bir istek) inceleme süresini istediği gibi yazabilirdi.
//
// ÜST SINIR: bir sekme günlerce açık kalabilir; kapanış olayı çok geç gelirse
// listede "37 sa 12 dk" gibi anlamsız bir değer oluşurdu. LEAST() ile kırpılır.
//
// YETKİLENDİRME — üç katman:
//   1) WHERE user_id = :user_id   -> BAŞKASININ satırı kapatılamaz.
//   2) WHERE closed_at IS NULL    -> aynı satır İKİ KEZ kapatılamaz; süre ilk
//                                    kapanışta donar, ikinci istek onu uzatamaz.
//   3) require_team_access()      -> projenin KVKK zinciri; team_id İSTEKTEN
//                                    DEĞİL, satırın kendisinden okunur.
//
// ⚠️ bcc_is_representative() BURADA KONTROL EDİLMEZ (note_view_start.php'den
// FARKLI): kullanıcının rolü açılış ile kapanış arasında değişmiş olabilir
// (commenter -> editor terfi). Kapanışı reddetseydik satır sonsuza dek
// closed_at = NULL kalırdı. Satırın kime ait olduğu ROLE değil, user_id'ye bağlı.
//
// BULUNAMAYAN / BAŞKASINA AİT / ZATEN KAPALI SATIR 404 DEĞİL, SESSİZ BAŞARI:
// {ok:true, duration_seconds:null}. Gerekçe: kapanış isteği sendBeacon ile
// gönderilir ve tarayıcı yanıtı OKUYAMAZ; ayrıca aynı kapanış iki yoldan
// (visibilitychange + selectRow) gelebilir. İkincisini hata saymak yanlış
// olurdu — işlem idempotenttir. Satır YİNE DE değişmez.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

// 4 saat. Bu eşiğin üstündeki her inceleme bu değere kırpılır.
// ⚠️ PHP int OLARAK kalmalı: config/database.php'deki bcc_bind_type() int'i
// 'i' ile bağlar, string'i 's' ile — string bağlanırsa LEAST() METİNSEL
// karşılaştırma yapar ve ör. 9000 saniyeyi yanlışlıkla 14400'e kırpar.
const BCC_NOTE_VIEW_MAX_SECONDS = 14400;

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;

$user = current_user();

// user_id koşulu BURADA da var: satır benim değilse team_id'sini bile okumam.
$row = bcc_fetch_one(
    'SELECT id, team_id, closed_at FROM record_view_log
      WHERE id = :id AND user_id = :user_id LIMIT 1',
    array('id' => $viewId, 'user_id' => $user['id'])
);

if (!$row || $row['closed_at'] !== null) {
    // Sessiz idempotent çıkış (yukarıdaki nota bakın) — satıra DOKUNULMAZ.
    echo json_encode(array('ok' => true, 'duration_seconds' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

require_team_access($row['team_id']);

$affected = bcc_execute(
    'UPDATE record_view_log
        SET closed_at = NOW(),
            duration_seconds = LEAST(TIMESTAMPDIFF(SECOND, opened_at, NOW()), :max_seconds)
      WHERE id = :id AND user_id = :user_id AND closed_at IS NULL',
    array(
        'id' => $viewId,
        'user_id' => $user['id'],
        'max_seconds' => BCC_NOTE_VIEW_MAX_SECONDS,
    )
);

// Koşullar UPDATE'in WHERE'inde TEKRARLANIYOR — yukarıdaki SELECT'e güvenmek
// yetmez: iki kapanış isteği aynı anda gelirse (sekme kapanışı + nota geçiş)
// ikisi de SELECT'i "açık" görüp UPDATE'e girebilir. Yarışı asıl çözen yer
// BURASI; ikinci istek 0 satır günceller.
if ($affected < 1) {
    echo json_encode(array('ok' => true, 'duration_seconds' => null), JSON_UNESCAPED_UNICODE);
    exit;
}

$updated = bcc_fetch_one(
    'SELECT duration_seconds FROM record_view_log WHERE id = :id LIMIT 1',
    array('id' => $viewId)
);

echo json_encode(array(
    'ok' => true,
    'duration_seconds' => (int) $updated['duration_seconds'],
), JSON_UNESCAPED_UNICODE);
