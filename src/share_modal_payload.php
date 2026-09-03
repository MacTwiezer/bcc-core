<?php

function bcc_share_modal_payload($teamId, $myRole)
{
    $teamId = (int) $teamId;
    $myRank = $GLOBALS['BCC_ROLE_RANK'][$myRole];
    $canManage = bcc_can_manage_members($myRole);
    $currentUser = current_user();
    $myUserId = (int) $currentUser['id'];

    $assignableRoles = $canManage ? bcc_assignable_roles($myRank) : array();
    $assignable = array();
    foreach ($assignableRoles as $r) {
        $assignable[] = array('value' => $r, 'label' => $GLOBALS['BCC_ROLE_LABELS'][$r]);
    }

    $collaborators = array();
    $pending = array();

    foreach (bcc_team_members_with_roles($teamId) as $m) {
        $memberRank = $GLOBALS['BCC_ROLE_RANK'][$m['role']];
        $isSelf = (int) $m['id'] === $myUserId;

        $manageable = $canManage && $memberRank <= $myRank;

        $row = array(
            'id' => (int) $m['id'],
            'name' => $m['full_name'],
            'email' => $m['email'],
            'initial' => bcc_user_initial($m),
            'role' => $m['role'],
            'role_label' => $GLOBALS['BCC_ROLE_LABELS'][$m['role']],
            'is_self' => $isSelf,
            'can_change_role' => $manageable,

            'can_remove' => $manageable && !$isSelf,
        );

        if ((int) $m['is_active'] === 1) {
            $collaborators[] = $row;
        } else {
            $pending[] = $row;
        }
    }

    return array(
        'team_id' => $teamId,
        'can_manage' => $canManage,
        'my_role' => $myRole,
        'my_role_label' => $GLOBALS['BCC_ROLE_LABELS'][$myRole],
        'my_user_id' => $myUserId,
        'assignable_roles' => $assignable,
        'collaborators' => $collaborators,
        'pending' => $pending,
    );
}
