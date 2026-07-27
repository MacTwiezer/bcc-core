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
$unreadCount = 0;
foreach ($notifications as $n) {
    if ($lastSeenAt === null || $n['created_at'] > $lastSeenAt) {
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
                    $isUnread = ($lastSeenAt === null || $n['created_at'] > $lastSeenAt);
                    $message = bcc_notification_message($n);
                    $initial = ($n['actor_name'] !== null && $n['actor_name'] !== '') ? mb_strtoupper(mb_substr($n['actor_name'], 0, 1, 'UTF-8'), 'UTF-8') : '?';
                ?>
                    <div
                        class="home-notif-item<?php echo $isUnread ? ' is-unread' : ''; ?>"
                        data-notif-unread="<?php echo $isUnread ? '1' : '0'; ?>"
                        data-notif-text="<?php echo htmlspecialchars(mb_strtolower($message, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                    >
                        <div class="home-notif-avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="home-notif-body">
                            <div class="home-notif-message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="home-notif-time"><?php echo htmlspecialchars(bcc_home_relative_date($n['created_at']), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="home-notif-no-match" id="home-notif-no-match" hidden>Sonuç yok</div>
        </div>
    </div>
</details>
