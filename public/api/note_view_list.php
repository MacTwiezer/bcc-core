<?php

require __DIR__ . '/../../src/api_bootstrap.php';

require __DIR__ . '/../../src/note_view_report.php';

api_require_login();

$recordId = isset($_GET['record_id']) ? (int) $_GET['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_team_access($record['team_id']);

$role = current_user_role_in_team($record['team_id']);

if (!bcc_can_view_record_audits($role)) {
    json_fail(403, 'İnceleme geçmişini görüntüleme yetkiniz yok.');
}

$rows = bcc_note_view_rows($recordId);

bcc_note_view_sweep_old();

$views = array();
foreach ($rows as $row) {
    $isOpen = $row['closed_at'] === null;

    $hasDuration = $row['duration_seconds'] !== null;

    $views[] = array(
        'id' => (int) $row['id'],
        'user_name' => $row['full_name'] !== null ? $row['full_name'] : 'Bilinmeyen kullanıcı',
        'role_at_view' => $row['role_at_view'],
        'opened_at' => $row['opened_at'],

        'opened_at_display' => date('d.m.Y H:i:s', strtotime($row['opened_at'])),

        'closed_at_display' => $isOpen ? null : date('H:i:s', strtotime($row['closed_at'])),
        'duration_seconds' => $hasDuration ? (int) $row['duration_seconds'] : null,
        'duration_display' => bcc_note_view_duration_text($hasDuration ? $row['duration_seconds'] : null),
        'is_open' => $isOpen,
    );
}

echo json_encode(array('ok' => true, 'views' => $views), JSON_UNESCAPED_UNICODE);
