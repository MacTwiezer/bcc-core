<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_login();

$user = current_user();
$teamIds = current_user_team_ids();

$items = array();

if (!empty($teamIds)) {
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT r.id, r.table_id, r.deleted_at, r.deleted_by,
                tm.name AS table_name, b.team_id,
                u.full_name AS deleted_by_name
         FROM records r
         INNER JOIN tables_meta tm ON tm.id = r.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         LEFT JOIN users u ON u.id = r.deleted_by
         WHERE b.team_id IN ($placeholders) AND r.deleted_at IS NOT NULL AND b.deleted_at IS NULL
         ORDER BY r.deleted_at DESC",
        $teamIds
    );

    $expiredRows = array();
    $activeRows = array();
    foreach ($rows as $row) {
        if (strtotime($row['deleted_at']) < strtotime('-7 days')) {
            $expiredRows[] = $row;
        } else {
            $activeRows[] = $row;
        }
    }

    if (!empty($expiredRows)) {
        $expiredIds = array_map(function ($r) { return (int) $r['id']; }, $expiredRows);

        bcc_delete_attachment_files_by_records($expiredIds);

        $expPlaceholders = implode(',', array_fill(0, count($expiredIds), '?'));
        bcc_execute("DELETE FROM records WHERE id IN ($expPlaceholders)", $expiredIds);
        foreach ($expiredRows as $eRow) {
            log_audit('record.purge', 'record', (int) $eRow['id'], array('reason' => '7_day_auto', 'table_id' => (int) $eRow['table_id']), (int) $eRow['team_id']);
        }
    }

    $rows = $activeRows;

    if (!empty($rows)) {
        $roleByTeamId = current_user_team_roles();

        $tableIds = array_values(array_unique(array_map(function ($r) { return (int) $r['table_id']; }, $rows)));
        $tablePlaceholders = implode(',', array_fill(0, count($tableIds), '?'));

        $primaryFieldRows = bcc_fetch_all(
            "SELECT f1.table_id, f1.id AS field_id, f1.field_type, f1.options
             FROM fields f1
             LEFT JOIN fields f2 ON f2.table_id = f1.table_id
                 AND (f2.position < f1.position OR (f2.position = f1.position AND f2.id < f1.id))
             WHERE f2.id IS NULL AND f1.table_id IN ($tablePlaceholders)",
            $tableIds
        );
        $primaryFieldByTable = array();
        foreach ($primaryFieldRows as $pf) {
            $primaryFieldByTable[(int) $pf['table_id']] = array('id' => (int) $pf['field_id'], 'type' => $pf['field_type'], 'options' => $pf['options']);
        }

        $primaryFieldIds = array_values(array_unique(array_map(function ($pf) { return $pf['id']; }, $primaryFieldByTable)));
        $recordIds = array_map(function ($r) { return (int) $r['id']; }, $rows);

        $cellByRecordField = array();
        if (!empty($primaryFieldIds)) {
            $recPlaceholders = implode(',', array_fill(0, count($recordIds), '?'));
            $fldPlaceholders = implode(',', array_fill(0, count($primaryFieldIds), '?'));
            $cellRows = bcc_fetch_all(
                "SELECT record_id, field_id, value_text, value_number, value_date, value_json
                 FROM cell_values WHERE record_id IN ($recPlaceholders) AND field_id IN ($fldPlaceholders)",
                array_merge($recordIds, $primaryFieldIds)
            );
            foreach ($cellRows as $c) {
                $cellByRecordField[(int) $c['record_id']][(int) $c['field_id']] = $c;
            }
        }

        $usersByTeam = array();
        foreach (array_unique(array_map(function ($r) { return (int) $r['team_id']; }, $rows)) as $tid) {
            $usersByTeam[$tid] = bcc_team_users_by_id($tid);
        }

        foreach ($rows as $row) {
            $tableId = (int) $row['table_id'];
            $teamId = (int) $row['team_id'];
            $pf = isset($primaryFieldByTable[$tableId]) ? $primaryFieldByTable[$tableId] : null;
            $cellRow = ($pf && isset($cellByRecordField[(int) $row['id']][$pf['id']]))
                ? $cellByRecordField[(int) $row['id']][$pf['id']]
                : null;
            $primaryValue = $pf
                ? cell_display_text($pf['type'], $cellRow, isset($usersByTeam[$teamId]) ? $usersByTeam[$teamId] : array(), $pf['options'])
                : '';
            if ($primaryValue === '') {
                $primaryValue = '(başlıksız kayıt)';
            }

            $isSelf = $row['deleted_by'] !== null && (int) $row['deleted_by'] === (int) $user['id'];
            $actorName = $row['deleted_by_name'] !== null ? $row['deleted_by_name'] : 'Bir kullanıcı';
            $message = $isSelf
                ? 'Sen bir kayıt sildin: ' . $primaryValue . ' (' . $row['table_name'] . ')'
                : $actorName . ' bir kayıt sildi: ' . $primaryValue . ' (' . $row['table_name'] . ')';

            $items[] = array(
                'id' => (int) $row['id'],
                'message' => $message,
                'relative_date' => bcc_home_relative_date($row['deleted_at']),
                'actor_initial' => $row['deleted_by_name'] !== null ? mb_strtoupper(mb_substr($row['deleted_by_name'], 0, 1, 'UTF-8'), 'UTF-8') : '?',
                'can_restore' => isset($roleByTeamId[$teamId]) && in_array($roleByTeamId[$teamId], array('editor', 'owner'), true),
            );
        }
    }
}

echo json_encode(array('ok' => true, 'items' => $items), JSON_UNESCAPED_UNICODE);
