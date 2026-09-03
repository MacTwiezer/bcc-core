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

$GLOBALS['BCC_NOTIFICATION_ACTIONS'] = array(

    'record.create' => 'data',
    'view.rename' => 'data',

    'slack.notify_sent' => 'integration',
    'slack.notify_failed' => 'integration',

    'team_member.assign' => 'members',
    'team_member.role_change' => 'members',
);

function bcc_notification_actions_for_role($role)
{
    $actions = array();

    foreach ($GLOBALS['BCC_NOTIFICATION_ACTIONS'] as $action => $audience) {
        $visible = false;

        switch ($audience) {
            case 'data':

                $visible = $role !== null;
                break;
            case 'integration':
                $visible = bcc_can_manage_schema($role);
                break;
            case 'members':
                $visible = bcc_can_manage_members($role);
                break;
        }

        if ($visible) {
            $actions[] = $action;
        }
    }

    return $actions;
}

function bcc_notification_scope_clause()
{
    $teamRoles = current_user_team_roles();
    if (empty($teamRoles)) {
        return null;
    }

    $groups = array();
    $params = array();

    foreach ($teamRoles as $teamId => $role) {
        $actions = bcc_notification_actions_for_role($role);
        if (empty($actions)) {
            continue;
        }

        $actionPlaceholders = implode(',', array_fill(0, count($actions), '?'));
        $groups[] = "(al.team_id = ? AND al.action IN ($actionPlaceholders))";
        $params[] = (int) $teamId;
        foreach ($actions as $action) {
            $params[] = $action;
        }
    }

    if (empty($groups)) {
        return null;
    }

    return array('sql' => '(' . implode(' OR ', $groups) . ')', 'params' => $params);
}

function bcc_fetch_notifications($limit = 30)
{
    $scope = bcc_notification_scope_clause();
    if ($scope === null) {
        return array();
    }

    $sql = "SELECT al.id, al.action, al.entity_type, al.entity_id, al.details, al.created_at, u.full_name AS actor_name
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE {$scope['sql']}
            ORDER BY al.created_at DESC
            LIMIT " . (int) $limit;

    return bcc_fetch_all($sql, $scope['params']);
}

function bcc_read_notification_ids($auditIds)
{
    $user = current_user();
    if ($user === null || empty($auditIds)) {
        return array();
    }

    $auditIds = array_map('intval', $auditIds);
    $placeholders = implode(',', array_fill(0, count($auditIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT audit_log_id FROM user_read_notifications
         WHERE user_id = ? AND audit_log_id IN ($placeholders)",
        array_merge(array((int) $user['id']), $auditIds)
    );

    $map = array();
    foreach ($rows as $r) {
        $map[(int) $r['audit_log_id']] = true;
    }

    return $map;
}

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
        case 'team_member.role_change':
            return $actor . ' bir ekip üyesinin rolünü güncelledi.';
        default:
            return $actor . ' bir işlem yaptı.';
    }
}
