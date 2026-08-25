<?php
// AJAX uçnoktası: "Mark all as read" — users.last_seen_notifications_at'i
// NOW()'a çeker. require_team_access() burada anlamsız (başka hiçbir
// kullanıcı/takım verisine dokunmuyor, yalnızca oturumdaki kullanıcının KENDİ
// satırı) — CSRF + is_logged_in() yeterli.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();

try {
    bcc_execute('UPDATE users SET last_seen_notifications_at = NOW() WHERE id = :id', array('id' => $user['id']));

    // Damga NOW()'a çekilince bu kullanıcının TEK TEK işaretlemeleri
    // (user_read_notifications, migrations/021) TAMAMEN GEREKSİZ hâle gelir:
    // damgadan eski her satır zaten okunmuş sayılıyor. Silinmezlerse tablo
    // yalnızca büyür ve hiçbir sorunun cevabını değiştirmez.
    //
    // Tablonun ASIL temizlenme yolu budur — ayrı bir zamanlanmış görev (cron)
    // eklenmedi: "tümünü okundu" zaten en sık kullanılan yol ve her çağrısında
    // kullanıcının birikimini sıfırlıyor.
    bcc_execute('DELETE FROM user_read_notifications WHERE user_id = :id', array('id' => $user['id']));
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
