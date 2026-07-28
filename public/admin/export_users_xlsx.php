<?php
// Admin panelindeki "Excel indir" (Kullanıcılar) — gerçek .xlsx,
// src/xlsx_writer.php (dış kütüphane yok, ZipArchive ile elle inşa).

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/xlsx_writer.php';

require_admin();

$users = bcc_fetch_all('SELECT email, full_name, is_admin, is_active, created_at FROM users ORDER BY email');

$rows = array();
foreach ($users as $u) {
    $rows[] = array(
        $u['email'],
        $u['full_name'],
        (int) $u['is_admin'] === 1 ? 'Evet' : 'Hayır',
        (int) $u['is_active'] === 1 ? 'Evet' : 'Hayır',
        $u['created_at'],
    );
}

log_audit('user.export_xlsx', 'user', null, array('count' => count($users)));

bcc_send_xlsx('kullanicilar.xlsx', 'Kullanıcılar', array('E-posta', 'Ad Soyad', 'Admin', 'Aktif', 'Oluşturuldu'), $rows);
