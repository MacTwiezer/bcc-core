<?php

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/xlsx_writer.php';

require_login();

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
require_role($teamId, 'viewer');

$team = bcc_fetch_one('SELECT id, name FROM teams WHERE id = :id', array('id' => $teamId));
if (!$team) {
    http_response_code(404);
    die('Ekip bulunamadı.');
}

$members = bcc_fetch_all(
    'SELECT u.id, u.full_name, u.email, tm.role, tm.created_at
     FROM team_members tm
     INNER JOIN users u ON u.id = tm.user_id
     WHERE tm.team_id = :team_id
     ORDER BY u.full_name',
    array('team_id' => $teamId)
);
$invitedByMap = bcc_team_members_invited_by($teamId);

$fileName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $team['name']);
$fileName = $fileName !== '' ? $fileName : 'ekip';

$rows = array();
foreach ($members as $m) {
    $invitedBy = isset($invitedByMap[(int) $m['id']]) ? $invitedByMap[(int) $m['id']] : null;

    $rows[] = array(
        $m['full_name'],
        $m['email'],
        $GLOBALS['BCC_ROLE_LABELS'][$m['role']],
        $invitedBy !== null ? $invitedBy : '',
        date('d.m.Y', strtotime($m['created_at'])),
    );
}

log_audit('team_member.export_xlsx', 'team', $teamId, array('member_count' => count($members)), $teamId);

bcc_send_xlsx($fileName . '_uyeler.xlsx', $team['name'], array('İsim', 'E-posta', 'Rol', 'Ekleyen', 'Eklenme tarihi'), $rows);
