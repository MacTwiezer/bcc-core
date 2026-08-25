<?php
// Bildirim paneli (zil ikonu + panel) — Home kabuğu (src/partials/home_shell_top.php)
// VE grid.php'nin sol şeridi TARAFINDAN PAYLAŞILIR; veri hazırlama (bcc_fetch_notifications,
// $lastSeenAt, $unreadCount hesaplama) VE tüm DOM burada tek yerde, ikinci bir kopya YOK.
// JS (home.js, #home-notif elemanına bağlanır) ve endpoint (/api/notifications_mark_read.php)
// zaten ortaktı, değişmedi.
//
// Beklenen değişkenler (include eden dosya ayarlar):
//   $notifUser          - current_user() dizisi (last_seen_notifications_at için)
//   $notifTriggerClass  - (opsiyonel) summary'nin ekstra class'ı — bağlama göre farklı
//                         görünüm (home topbar'ı açık, grid'in sol şeridi koyu zemin)
//   $notifIconSize      - (opsiyonel) zil ikonunun width/height'ı (px)
//   $notifIconStroke    - (opsiyonel) zil ikonunun stroke rengi

if (!isset($notifTriggerClass)) {
    $notifTriggerClass = 'home-icon-btn';
}
if (!isset($notifIconSize)) {
    $notifIconSize = 19;
}
if (!isset($notifIconStroke)) {
    $notifIconStroke = '#5f6368';
}

$notifications = bcc_fetch_notifications();
$lastSeenAt = $notifUser['last_seen_notifications_at'];

// Okundu/okunmadı İKİ kaynaktan gelir (bkz. migrations/021):
//   1) $lastSeenAt        — "Tümünü okundu işaretle"nin çektiği toplu damga
//   2) $readIds           — göz ikonuyla TEK TEK işaretlenenler
// okunmamış = damgadan yeni VE tek tek işaretlenmemiş. Tek sorgu, bildirim
// başına ayrı sorgu yok.
$readIds = bcc_read_notification_ids(array_column($notifications, 'id'));

$isUnreadFn = function ($n) use ($lastSeenAt, $readIds) {
    if (isset($readIds[(int) $n['id']])) {
        return false;
    }

    return $lastSeenAt === null || $n['created_at'] > $lastSeenAt;
};

$unreadCount = 0;
foreach ($notifications as $n) {
    if ($isUnreadFn($n)) {
        $unreadCount++;
    }
}
?>
<details class="home-notif" id="home-notif">
    <summary class="<?php echo htmlspecialchars($notifTriggerClass, ENT_QUOTES, 'UTF-8'); ?> home-notif-toggle" aria-label="Bildirimler">
        <svg width="<?php echo (int) $notifIconSize; ?>" height="<?php echo (int) $notifIconSize; ?>" viewBox="0 0 20 20" fill="none"><path d="M10 2.5c-2.4 0-4.2 1.9-4.2 4.3v2.6c0 .5-.2 1.3-.5 1.7L4.4 12.5c-.6.8-.2 1.9.8 2.2 3.3 1 6.9 1 10.2 0 .9-.3 1.3-1.4.7-2.2l-.9-1.4c-.3-.4-.5-1.2-.5-1.7V6.8c0-2.4-1.9-4.3-4.2-4.3z" stroke="<?php echo htmlspecialchars($notifIconStroke, ENT_QUOTES, 'UTF-8'); ?>" stroke-width="1.3" stroke-linejoin="round"/><path d="M8.2 16.5a1.8 1.8 0 003.6 0" stroke="<?php echo htmlspecialchars($notifIconStroke, ENT_QUOTES, 'UTF-8'); ?>" stroke-width="1.3" stroke-linecap="round"/></svg>
        <?php if ($unreadCount > 0): ?><span class="home-notif-badge"><?php echo $unreadCount > 9 ? '9+' : (int) $unreadCount; ?></span><?php endif; ?>
    </summary>
    <div class="home-notif-panel">
        <div class="home-notif-header">
            <div class="home-notif-tabs">
                <button type="button" class="home-notif-tab is-active" data-notif-tab="unread">Okunmamış</button>
                <button type="button" class="home-notif-tab" data-notif-tab="read">Okunmuş</button>
            </div>
            <button type="button" class="home-notif-mark-all" id="home-notif-mark-all">Tümünü okundu işaretle</button>
        </div>
        <div class="home-notif-search">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" id="home-notif-search-input" placeholder="Ara" autocomplete="off">
        </div>
        <div class="home-notif-list" id="home-notif-list">
            <?php if (empty($notifications)): ?>
                <div class="home-notif-empty">Bildirim yok</div>
            <?php else: ?>
                <?php foreach ($notifications as $n):
                    $isUnread = $isUnreadFn($n);
                    $message = bcc_notification_message($n);
                    $initial = ($n['actor_name'] !== null && $n['actor_name'] !== '') ? mb_strtoupper(mb_substr($n['actor_name'], 0, 1, 'UTF-8'), 'UTF-8') : '?';
                ?>
                    <div
                        class="home-notif-item<?php echo $isUnread ? ' is-unread' : ''; ?>"
                        data-notif-unread="<?php echo $isUnread ? '1' : '0'; ?>"
                        data-notif-id="<?php echo (int) $n['id']; ?>"
                        data-notif-text="<?php echo htmlspecialchars(mb_strtolower($message, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                    >
                        <div class="home-notif-avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="home-notif-body">
                            <div class="home-notif-message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php // Kaba yakınlık ("Bugün") DEĞİL kesin saat: aynı gün
                                  // içinde onlarca bildirim birikiyor ve hepsi "Bugün"
                                  // yazınca hangisinin ne zaman geldiği okunamıyordu.
                                  // Bugünse yalnızca saat, diğer günlerde tarih + saat
                                  // (bkz. bcc_notification_time_text). Base kartlarının
                                  // "Açıldı: 10 gün önce" biçimi DEĞİŞMEDİ. ?>
                            <div class="home-notif-time"><?php echo htmlspecialchars(bcc_notification_time_text($n['created_at']), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php // Tek tek "okundu" — göz ikonu. YALNIZCA okunmamış
                              // satırlarda basılır: zaten okunmuş bir bildirimi
                              // yeniden okundu yapmanın anlamı yok, buton da
                              // "tıklayınca hiçbir şey olmayan" bir öğeye dönerdi.
                              // Okundu işaretlemeyi GERİ ALMA bu turun kapsamı
                              // dışında (uçnokta yalnızca INSERT yapıyor). ?>
                        <?php if ($isUnread): ?>
                            <button type="button" class="home-notif-read-btn" data-notif-read="<?php echo (int) $n['id']; ?>" title="Okundu işaretle" aria-label="Okundu işaretle">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="home-notif-no-match" id="home-notif-no-match" hidden>Sonuç yok</div>
        </div>
    </div>
</details>
