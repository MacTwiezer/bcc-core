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
