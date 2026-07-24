<?php
// "Download CSV" — mevcut kayıtları AKTİF sort/filter/hidden-fields durumuyla
// (grid.php'nin o an gösterdiği hâliyle BİREBİR) düz CSV olarak indirir.
// PhpSpreadsheet gibi bir kütüphane YOK (dış kütüphane yasak) — gerçek .xlsx
// yerine UTF-8 BOM'lu CSV üretiliyor (onaylanmış karar): Excel BOM'u görünce
// Türkçe karakterleri doğru açar, asıl sorun (encoding) bu şekilde çözülüyor.
// Salt-okunur işlem (viewer'a da açık) — require_role('editor') YOK.
//
// Sorgu mantığı: grid.php'nin KULLANDIĞI AYNI parse_grid_*() + AYNI
// bcc_build_grid_records_query() (src/schema.php) — paralel bir sorgu-kurma
// mantığı YOK, URL'deki sort/filter/hidden_fields parametreleri grid.php'de
// nasıl yorumlanıyorsa burada da AYNEN öyle yorumlanır.

require __DIR__ . '/../../src/bootstrap.php';

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$table = find_table_or_404($tableId);
require_team_access($table['team_id']);

$fields = bcc_fetch_all('SELECT id, name, field_type, options, position FROM fields WHERE table_id = :table_id ORDER BY position, id', array('table_id' => $table['id']));
$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}

$primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
$hiddenFieldIds = parse_grid_hidden_fields($_GET, $fieldsById, $primaryFieldId);

$visibleFields = array();
foreach ($fields as $f) {
    if (!in_array((int) $f['id'], $hiddenFieldIds, true)) {
        $visibleFields[] = $f;
    }
}

$sortRules = parse_grid_sort_rules($_GET, $fieldsById);
$filterRules = parse_grid_filter_rules($_GET, $fieldsById);
$filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';

list($recordsSql, $recordsParams) = bcc_build_grid_records_query($table['id'], array(), $sortRules, $filterRules, $filterLogic);
$records = bcc_fetch_all($recordsSql, $recordsParams);

$cellsByRecord = bcc_fetch_cells_by_record(array_column($records, 'id'));
$usersById = bcc_team_users_by_id($table['team_id']);

$fileName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $table['name']);
$fileName = $fileName !== '' ? $fileName : 'grid';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');

// UTF-8 BOM — Excel bunu görünce dosyayı UTF-8 olarak açar (Türkçe karakterler
// bozulmaz); BOM olmadan Excel çoğu zaman Windows-1254 varsayar.
fwrite($out, "\xEF\xBB\xBF");

$headerRow = array();
foreach ($visibleFields as $f) {
    $headerRow[] = $f['name'];
}
fputcsv($out, $headerRow);

foreach ($records as $rec) {
    $cellsForRecord = isset($cellsByRecord[$rec['id']]) ? $cellsByRecord[$rec['id']] : array();
    $row = array();
    foreach ($visibleFields as $f) {
        $cellRow = isset($cellsForRecord[$f['id']]) ? $cellsForRecord[$f['id']] : null;
        $displayText = cell_display_text($f['field_type'], $cellRow, $usersById);
        // long_text'in salt-okunur çıktısı sanitize edilmiş HTML — CSV düz metin
        // istediği için strip_tags ile metne indirgeniyor (grid içi gösterimin
        // TEK istisnası burada da geçerli değil, CSV'de HTML anlamsız).
        if ($f['field_type'] === 'long_text') {
            $displayText = strip_tags($displayText);
        }
        $row[] = $displayText;
    }
    fputcsv($out, $row);
}

fclose($out);

log_audit('view.export_csv', 'table', $table['id'], array('record_count' => count($records)), $table['team_id']);
