<?php
// Admin panelindeki "Excel indir" (Ekipler) — gerçek .xlsx,
// src/xlsx_writer.php (dış kütüphane yok, ZipArchive ile elle inşa).

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/xlsx_writer.php';

require_admin();

$memberRows = bcc_fetch_all(
    'SELECT t.name AS team_name, u.email, u.full_name, tm.role
     FROM team_members tm
     INNER JOIN teams t ON t.id = tm.team_id
     INNER JOIN users u ON u.id = tm.user_id
     ORDER BY t.name, u.email'
);

$rows = array();
foreach ($memberRows as $r) {
    $rows[] = array(
        $r['team_name'],
        $r['email'],
        $r['full_name'],
        $GLOBALS['BCC_ROLE_LABELS'][$r['role']],
    );
}

log_audit('team_member.export_xlsx', 'team', null, array('count' => count($memberRows)));

bcc_send_xlsx('ekip_uyelikleri.xlsx', 'Ekip Üyelikleri', array('Ekip', 'E-posta', 'Ad Soyad', 'Rol'), $rows);
