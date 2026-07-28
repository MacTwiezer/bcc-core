<?php
// Admin panelindeki "CSV indir" (Kullanıcılar) — view_export_csv.php ile
// AYNI desen: UTF-8 BOM'lu düz CSV (dış kütüphane yok, onaylanmış karar).

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$users = bcc_fetch_all('SELECT email, full_name, is_admin, is_active, created_at FROM users ORDER BY email');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="kullanicilar.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, array('E-posta', 'Ad Soyad', 'Admin', 'Aktif', 'Oluşturuldu'));

foreach ($users as $u) {
    fputcsv($out, array(
        $u['email'],
        $u['full_name'],
        (int) $u['is_admin'] === 1 ? 'Evet' : 'Hayır',
        (int) $u['is_active'] === 1 ? 'Evet' : 'Hayır',
        $u['created_at'],
    ));
}

fclose($out);

log_audit('user.export_csv', 'user', null, array('count' => count($users)));
