<?php

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/xlsx_writer.php';
require __DIR__ . '/../../src/note_view_report.php';

require_login();

$recordId = isset($_GET['record_id']) ? (int) $_GET['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    http_response_code(404);
    die('Kayıt bulunamadı.');
}

require_team_access($record['team_id']);

$role = current_user_role_in_team($record['team_id']);

if (!bcc_can_view_record_audits($role)) {
    http_response_code(403);
    die('İnceleme geçmişini görüntüleme yetkiniz yok.');
}

$periodEnd = bcc_db_now();
$periodStart = $periodEnd - (BCC_NOTE_VIEW_WINDOW_DAYS * 86400);

$rowsRaw = bcc_note_view_rows($recordId);

$noteTitle = bcc_note_view_record_title($recordId);

$rows = array();
foreach ($rowsRaw as $r) {
    $isOpen = $r['closed_at'] === null;
    $duration = $r['duration_seconds'] === null
        ? 'süre kaydedilmedi'
        : ($isOpen ? 'en az ' . bcc_note_view_duration_text($r['duration_seconds'])
                   : bcc_note_view_duration_text($r['duration_seconds']));

    $rows[] = array(

        $r['full_name'] !== null ? $r['full_name'] : 'Bilinmeyen kullanıcı',
        isset($GLOBALS['BCC_ROLE_LABELS'][$r['role_at_view']])
            ? $GLOBALS['BCC_ROLE_LABELS'][$r['role_at_view']]
            : $r['role_at_view'],
        date('d.m.Y H:i:s', strtotime($r['opened_at'])),
        $r['closed_at'] !== null ? date('d.m.Y H:i:s', strtotime($r['closed_at'])) : '',
        $duration,

        $r['duration_seconds'] !== null ? (string) (int) $r['duration_seconds'] : '',
    );
}

$preamble = array(
    array('Temsilci İnceleme Raporu'),
    array('Not', $noteTitle),
    array('Dönem başlangıcı', date('d.m.Y H:i', $periodStart)),
    array('Dönem bitişi', date('d.m.Y H:i', $periodEnd)),
    array('Dönem uzunluğu', BCC_NOTE_VIEW_WINDOW_DAYS . ' gün'),
    array('Rapor tarihi', date('d.m.Y H:i')),
    array('Toplam inceleme', (string) count($rows)),
    array(),
);

$fileName = 'temsilci_inceleme_'
    . date('Y-m-d', $periodStart) . '_' . date('Y-m-d', $periodEnd) . '.xlsx';

log_audit(
    'note_view.export_xlsx',
    'record',
    $recordId,
    array(
        'row_count' => count($rows),
        'period_start' => date('c', $periodStart),
        'period_end' => date('c', $periodEnd),
    ),
    $record['team_id']
);

bcc_send_xlsx(
    $fileName,
    'Temsilci İnceleme',
    array('İnceleyen', 'Rol', 'Başlangıç', 'Bitiş', 'Süre', 'Süre (saniye)'),
    $rows,
    $preamble
);
