<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$baseId = isset($_GET['base_id']) ? (int) $_GET['base_id'] : 0;
$base = find_base_or_404($baseId);

// Her erişimde KVKK ekip izolasyonu: bu base'in ekibine üye olmayan hiçbir şey göremez.
require_team_access($base['team_id']);

// Dashboard'daki tarih filtresi için "son açılma" kaydı — hiçbir koşulda
// yönlendirmeyi ENGELLEMEMELİ, bu yüzden hata sessizce yutulur.
try {
    log_base_open($base['id'], $base['team_id']);
} catch (Throwable $e) {
    // base.open kaydı basarisiz olsa bile yönlendirme devam etmeli.
}

// Görünür çıktısı olmayan köprü sayfası: base'in ilk tablosuna (position, id sırasına
// göre) doğrudan atlar — Airtable'da base_tables.php gibi ayrı bir ara ekran yoktur.
$tables = bcc_list_base_tables($base['id']);

if (empty($tables)) {
    header('Location: /base_tables.php?base_id=' . (int) $base['id']);
    exit;
}

header('Location: /grid.php?table_id=' . (int) $tables[0]['id']);
exit;
