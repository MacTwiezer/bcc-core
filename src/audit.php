<?php

function log_audit($action, $entityType = null, $entityId = null, $details = null, $teamId = null)
{
    $user = current_user();

    bcc_execute(
        'INSERT INTO audit_log (team_id, user_id, action, entity_type, entity_id, details)
         VALUES (:team_id, :user_id, :action, :entity_type, :entity_id, :details)',
        array(
            'team_id' => $teamId,
            'user_id' => $user ? $user['id'] : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        )
    );
}

// "Son açılma" tarih filtresi (dashboard.php) audit_log'daki base.open olaylarından
// türetilir. Her F5'te yeni satır oluşmasını önlemek için: aynı kullanıcı+base için
// son 5 dakika içinde zaten bir base.open kaydı varsa onu (created_at'ini şimdiye
// çekerek) GÜNCELLER, yoksa yeni satır ekler. audit_log'da bunu garanti eden bir
// UNIQUE kısıt yok (DDL uygulanmıyor) — iki isteğin aynı anda ikisinin de INSERT
// denemesi teorik olarak mümkün, ama sonucu en fazla bir fazla satır, işlevsel bir
// hata değil (views.created_by'daki tekillik garantisi kadar kritik değil).
function log_base_open($baseId, $teamId)
{
    $user = current_user();
    $userId = $user ? $user['id'] : null;

    if ($userId === null) {
        return;
    }

    $recent = bcc_fetch_one(
        "SELECT id FROM audit_log
         WHERE action = 'base.open' AND entity_type = 'base' AND entity_id = :entity_id
           AND user_id = :user_id AND created_at > (NOW() - INTERVAL 5 MINUTE)
         ORDER BY id DESC LIMIT 1",
        array('entity_id' => $baseId, 'user_id' => $userId)
    );

    if ($recent) {
        bcc_execute('UPDATE audit_log SET created_at = NOW() WHERE id = :id', array('id' => $recent['id']));
        return;
    }

    bcc_execute(
        'INSERT INTO audit_log (team_id, user_id, action, entity_type, entity_id) VALUES (:team_id, :user_id, :action, :entity_type, :entity_id)',
        array('team_id' => $teamId, 'user_id' => $userId, 'action' => 'base.open', 'entity_type' => 'base', 'entity_id' => $baseId)
    );
}

// Bildirim paneli (zil ikonu) — YENİ bir notifications tablosu YOK (onaylanan
// "basit" model): audit_log salt-okunur gösterilir, KVKK team_id IN (...) ile
// filtrelenir, yalnızca bu whitelist'teki action'lar bildirim sayılır — user.login
// (368 satırın %66'sı) ve cell.update (%7'si) gibi gürültülü/kişisel olaylar
// KASITLI olarak DIŞARIDA (bkz. PROJE-DURUM.md analiz notu).
$GLOBALS['BCC_NOTIFICATION_ACTIONS'] = array(
    'record.create',
    'view.rename',
    'slack.notify_sent',
    'slack.notify_failed',
    'team_member.assign',
);

// current_user_team_ids() (src/auth.php) ile AYNI kaynaktan — ikinci bir
// "kullanıcının takımları" sorgusu YAZILMADI.
function bcc_fetch_notifications($limit = 30)
{
    $teamIds = current_user_team_ids();
    if (empty($teamIds)) {
        return array();
    }

    $actions = $GLOBALS['BCC_NOTIFICATION_ACTIONS'];
    $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
    $actionPlaceholders = implode(',', array_fill(0, count($actions), '?'));

    $sql = "SELECT al.id, al.action, al.entity_type, al.entity_id, al.details, al.created_at, u.full_name AS actor_name
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.team_id IN ($teamPlaceholders)
              AND al.action IN ($actionPlaceholders)
            ORDER BY al.created_at DESC
            LIMIT " . (int) $limit;

    return bcc_fetch_all($sql, array_merge($teamIds, $actions));
}

// Her action için okunabilir tek cümle — details JSON'undaki alanlar action'a
// göre değişir (bkz. log_audit() çağrı noktaları), bu yüzden switch/case.
function bcc_notification_message($row)
{
    $actor = ($row['actor_name'] !== null && $row['actor_name'] !== '') ? $row['actor_name'] : 'Bir kullanıcı';

    $details = array();
    if ($row['details'] !== null) {
        $decoded = json_decode($row['details'], true);
        if (is_array($decoded)) {
            $details = $decoded;
        }
    }

    switch ($row['action']) {
        case 'record.create':
            return $actor . ' yeni bir kayıt ekledi.';
        case 'view.rename':
            $name = isset($details['name']) ? (string) $details['name'] : '';
            return $actor . ' bir görünümü yeniden adlandırdı' . ($name !== '' ? ': "' . $name . '"' : '') . '.';
        case 'slack.notify_sent':
            return $actor . '\'in eklediği kayıt için Slack bildirimi gönderildi.';
        case 'slack.notify_failed':
            return $actor . '\'in eklediği kayıt için Slack bildirimi gönderilemedi.';
        case 'team_member.assign':
            return $actor . ' ekibe yeni bir üye ekledi.';
        default:
            return $actor . ' bir işlem yaptı.';
    }
}
