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
