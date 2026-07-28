<?php
// Admin panelindeki "CSV indir" (Ekipler) — tüm ekiplerin üye/rol dökümü,
// view_export_csv.php ile AYNI UTF-8 BOM'lu düz CSV deseni.

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$rows = bcc_fetch_all(
    'SELECT t.name AS team_name, u.email, u.full_name, tm.role
     FROM team_members tm
     INNER JOIN teams t ON t.id = tm.team_id
     INNER JOIN users u ON u.id = tm.user_id
     ORDER BY t.name, u.email'
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ekip_uyelikleri.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, array('Ekip', 'E-posta', 'Ad Soyad', 'Rol'));

foreach ($rows as $r) {
    fputcsv($out, array(
        $r['team_name'],
        $r['email'],
        $r['full_name'],
        $GLOBALS['BCC_ROLE_LABELS'][$r['role']],
    ));
}

fclose($out);

log_audit('team_member.export_csv', 'team', null, array('count' => count($rows)));
