<?php
// AJAX uçnoktası: TEK bir bildirimi "okundu" işaretler (göz ikonu) —
// user_read_notifications'a bir satır yazar (migrations/021).
//
// notifications_mark_read.php ("tümünü okundu") ile AYNI güvenlik deseni:
// CSRF + oturum. require_team_access() burada da anlamsız DEĞİL ama farklı bir
// biçimde çözülüyor (aşağıya bkz.): yazılan satır yalnızca oturumdaki
// kullanıcının KENDİ okundu kaydı, başka kimsenin durumunu değiştirmiyor.
//
// ⚠️ YİNE DE KVKK KONTROLÜ VAR: kullanıcının GÖREMEYECEĞİ bir audit_log
// satırının id'si gönderilirse reddedilir. Kabul edilseydi veri sızmazdı ama
// "bu id var mı" sorusu yanıt koduyla cevaplanabilir hâle gelirdi; ayrıca
// başka takımların satırlarıyla dolu bir okundu tablosu anlamsız olurdu.
// Kontrol bcc_fetch_notifications()'ın kullandığı AYNI iki koşula dayanır
// (takım üyeliği + whitelist'teki action), o yüzden ikinci bir kural yazılmadı.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$notificationId = isset($_POST['notification_id']) ? (int) $_POST['notification_id'] : 0;

if ($notificationId <= 0) {
    json_fail(422, 'Geçersiz bildirim.');
}

try {
    $teamIds = current_user_team_ids();
    if (empty($teamIds)) {
        json_fail(403, 'Bu bildirime erişim yetkiniz yok.');
    }

    $actions = $GLOBALS['BCC_NOTIFICATION_ACTIONS'];
    $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
    $actionPlaceholders = implode(',', array_fill(0, count($actions), '?'));

    $row = bcc_fetch_one(
        "SELECT id FROM audit_log
         WHERE id = ? AND team_id IN ($teamPlaceholders) AND action IN ($actionPlaceholders)
         LIMIT 1",
        array_merge(array($notificationId), $teamIds, $actions)
    );

    if (!$row) {
        json_fail(403, 'Bu bildirime erişim yetkiniz yok.');
    }

    // INSERT IGNORE: aynı bildirime iki kez basmak (çift tık, iki sekme) hata
    // değil no-op olmalı — UNIQUE(user_id, audit_log_id) çakışmayı zaten
    // engelliyor, IGNORE onu 500'e çevirmiyor.
    bcc_execute(
        'INSERT IGNORE INTO user_read_notifications (user_id, audit_log_id) VALUES (:uid, :aid)',
        array('uid' => (int) $user['id'], 'aid' => $notificationId)
    );

    // ---- TEMİZLİK: GÜN SAYISIYLA DEĞİL, GEREKSİZLİKLE --------------------
    // Soru "kaç gün saklayalım?" idi; ölçüm gün sayısının YANLIŞ ölçüt
    // olduğunu gösterdi. Bugün günde ~92 bildirim üretiliyor ve panel yalnızca
    // EN YENİ 30'u gösteriyor, yani bir işaret ~8 saatte görünmez oluyor —
    // buradan bakınca 30 gün bile fazla. AMA sessiz bir ekipte (haftada birkaç
    // bildirim) 30 satır AYLARA yayılır; sabit bir gün sınırı orada HÂLÂ
    // EKRANDA OLAN bir işareti silip bildirimi yeniden "okunmamış" gösterirdi.
    //
    // Bu yüzden ölçüt zaman değil, GEREKSİZLİK: bir işaret, ait olduğu satır
    // zaten damgadan (last_seen_notifications_at) eski kaldığı anda anlamsızdır
    // — o satır işaret olmadan da okunmuş sayılır. Böyle satırları silmek
    // hiçbir durumda görünürlüğü değiştirmez, yani "erken sildim" riski YOK.
    //
    // NEDEN CRON DEĞİL: depoda zamanlanmış görev altyapısı yok ve bunun için
    // bir tane kurmak orantısız. Temizlik iki yerden bedavaya geliyor:
    //   1) "Tümünü okundu işaretle" -> o kullanıcının TÜM işaretleri silinir
    //      (damga hepsini kapsar, bkz. notifications_mark_read.php)
    //   2) burası -> her işaretlemede o kullanıcının gereksizleri silinir
    // İkisi de user_id ile sınırlı (UNIQUE anahtarın soldan öneki), tablo
    // taraması yapılmaz.
    bcc_execute(
        'DELETE urn FROM user_read_notifications urn
         INNER JOIN audit_log al ON al.id = urn.audit_log_id
         INNER JOIN users u ON u.id = urn.user_id
         WHERE urn.user_id = :uid
           AND u.last_seen_notifications_at IS NOT NULL
           AND al.created_at <= u.last_seen_notifications_at',
        array('uid' => (int) $user['id'])
    );

    // ---- ÜST SINIR: 30 gün (kullanıcı kararı) -----------------------------
    // Yukarıdaki gereksizlik kuralı tek başına yeterli ve RİSKSİZ; bu ikinci
    // kural kullanıcının açık isteği üzerine EK bir tavan olarak duruyor:
    // hiçbir işaret 30 günden uzun yaşamasın.
    //
    // ⚠️ BİLİNEN VE KABUL EDİLMİŞ YAN ETKİ: çok sessiz bir ekipte (30 bildirim
    // aylara yayılıyorsa) 30 günden eski bir işaret HÂLÂ EKRANDA olabilir;
    // silinince o bildirim yeniden "okunmamış" görünür. Kullanıcı bu ödünü
    // bilerek seçti. Sınırı kaldırmak/uzatmak tek satırlık bir değişiklik.
    bcc_execute(
        'DELETE FROM user_read_notifications
         WHERE user_id = :uid AND created_at < (NOW() - INTERVAL 30 DAY)',
        array('uid' => (int) $user['id'])
    );
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
