<?php

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
    $scope = bcc_notification_scope_clause();
    if ($scope === null) {
        json_fail(403, 'Bu bildirime erişim yetkiniz yok.');
    }

    $row = bcc_fetch_one(
        "SELECT al.id FROM audit_log al
         WHERE al.id = ? AND {$scope['sql']}
         LIMIT 1",
        array_merge(array($notificationId), $scope['params'])
    );

    if (!$row) {
        json_fail(403, 'Bu bildirime erişim yetkiniz yok.');
    }

    bcc_execute(
        'INSERT IGNORE INTO user_read_notifications (user_id, audit_log_id) VALUES (:uid, :aid)',
        array('uid' => (int) $user['id'], 'aid' => $notificationId)
    );

    bcc_execute(
        'DELETE urn FROM user_read_notifications urn
         INNER JOIN audit_log al ON al.id = urn.audit_log_id
         INNER JOIN users u ON u.id = urn.user_id
         WHERE urn.user_id = :uid
           AND u.last_seen_notifications_at IS NOT NULL
           AND al.created_at <= u.last_seen_notifications_at',
        array('uid' => (int) $user['id'])
    );

    bcc_execute(
        'DELETE FROM user_read_notifications
         WHERE user_id = :uid AND created_at < (NOW() - INTERVAL 30 DAY)',
        array('uid' => (int) $user['id'])
    );
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
