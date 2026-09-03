<?php

require __DIR__ . '/../../src/api_bootstrap.php';
require __DIR__ . '/../../src/xlsx_reader.php';

api_require_post();
api_require_login();
api_require_csrf();

const BCC_XLSX_IMPORT_MAX_BYTES = 10 * 1024 * 1024;
const BCC_XLSX_IMPORT_MAX_ROWS = 5000;
// ACILMIS icerik siniri. Yukaridaki 10 MB SIKISTIRILMIS boyuttur; .xlsx bir zip
// oldugu icin cok yuksek oranlar mumkun (olculdu: 298 KB'lik gecerli bir dosya
// 300 MB aciliyor ve istegi bellek tukenmesiyle olduruyordu).
const BCC_XLSX_IMPORT_MAX_UNCOMPRESSED = 60 * 1024 * 1024;

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

$table = find_table_or_404($tableId);
require_role($table['team_id'], 'editor');

if (!isset($_FILES['xlsx_file']) || !is_uploaded_file($_FILES['xlsx_file']['tmp_name'])) {
    json_fail(422, 'Dosya alınamadı.');
}

$upload = $_FILES['xlsx_file'];

if ($upload['error'] !== UPLOAD_ERR_OK) {
    $tooBig = ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE);
    json_fail(422, $tooBig ? 'Dosya çok büyük.' : 'Dosya yüklenemedi.');
}

if ($upload['size'] <= 0 || $upload['size'] > BCC_XLSX_IMPORT_MAX_BYTES) {
    json_fail(422, 'Dosya boyutu 10MB\'ı aşamaz.');
}

$ext = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    json_fail(422, 'Yalnızca .xlsx dosyaları desteklenir.');
}

$acilmis = bcc_xlsx_uncompressed_size($upload['tmp_name']);
if ($acilmis < 0) {
    json_fail(422, 'Dosya okunamadı veya geçersiz bir Excel dosyası.');
}
if ($acilmis > BCC_XLSX_IMPORT_MAX_UNCOMPRESSED) {
    json_fail(422, 'Dosyanın içeriği çok büyük (açılmış boyut sınırı: '
        . (int) (BCC_XLSX_IMPORT_MAX_UNCOMPRESSED / 1048576) . 'MB).');
}

$sheetRows = bcc_xlsx_read_first_sheet($upload['tmp_name']);
if (empty($sheetRows)) {
    json_fail(422, 'Dosya okunamadı veya geçersiz bir Excel dosyası.');
}

$headerRow = array_shift($sheetRows);
if (empty($headerRow)) {
    json_fail(422, 'Dosyada başlık satırı bulunamadı.');
}

$fields = bcc_fetch_all(
    'SELECT id, name, field_type, options, is_required FROM fields WHERE table_id = :tid ORDER BY position, id',
    array(':tid' => $table['id'])
);

$importIgnoredFieldTypes = array('attachment', 'autonumber');

$requiredFieldIds = array();
foreach ($fields as $f) {
    if (!in_array($f['field_type'], $importIgnoredFieldTypes, true) && (int) $f['is_required'] === 1) {
        $requiredFieldIds[] = (int) $f['id'];
    }
}

$fieldByName = array();
foreach ($fields as $f) {
    if (in_array($f['field_type'], $importIgnoredFieldTypes, true)) {
        continue;
    }
    $fieldByName[mb_strtolower(trim($f['name']), 'UTF-8')] = $f;
}

$columnFields = array();
$unmatchedColumns = array();
foreach ($headerRow as $colIndex => $colName) {
    $key = mb_strtolower(trim((string) $colName), 'UTF-8');
    if (isset($fieldByName[$key])) {
        $columnFields[$colIndex] = $fieldByName[$key];
    } else {
        $columnFields[$colIndex] = null;
        $unmatchedColumns[] = trim((string) $colName);
    }
}

if (empty(array_filter($columnFields))) {
    json_fail(422, 'Hiçbir sütun tablodaki alanlarla eşleşmedi.');
}

$usersById = bcc_team_users_by_id($table['team_id']);
$userIdByName = array();
foreach ($usersById as $uid => $uname) {
    $userIdByName[mb_strtolower(trim($uname), 'UTF-8')] = $uid;
}

$rows = array();
foreach ($sheetRows as $row) {
    $isBlank = true;
    foreach ($row as $cellValue) {
        if (trim((string) $cellValue) !== '') {
            $isBlank = false;
            break;
        }
    }
    if ($isBlank) {
        continue;
    }
    $rows[] = $row;
}

if (count($rows) > BCC_XLSX_IMPORT_MAX_ROWS) {
    json_fail(422, 'Dosya çok fazla satır içeriyor (limit: ' . BCC_XLSX_IMPORT_MAX_ROWS . ').');
}

if (empty($rows)) {
    json_fail(422, 'Dosyada aktarılacak veri satırı bulunamadı.');
}

$user = current_user();
$imported = 0;
$skippedCells = 0;
$skippedRows = 0;

try {
    bcc_begin_transaction();

    $nextPos = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :tid',
        array(':tid' => $table['id'])
    );

    foreach ($rows as $row) {
        $cellsToInsert = array();
        $filledFieldIds = array();
        $rowSkippedCells = 0;

        foreach ($columnFields as $colIndex => $field) {
            if ($field === null || !array_key_exists($colIndex, $row)) {
                continue;
            }

            $rawValue = (string) $row[$colIndex];
            if (trim($rawValue) === '') {
                continue;
            }

            if ($field['field_type'] === 'date') {
                $d = DateTime::createFromFormat('d.m.Y', trim($rawValue));
                if ($d && $d->format('d.m.Y') === trim($rawValue)) {
                    $rawValue = $d->format('Y-m-d');
                }
            } elseif ($field['field_type'] === 'user') {
                $nameKey = mb_strtolower(trim($rawValue), 'UTF-8');
                if (!ctype_digit(trim($rawValue)) && isset($userIdByName[$nameKey])) {
                    $rawValue = (string) $userIdByName[$nameKey];
                }
            } elseif ($field['field_type'] === 'checkbox') {
                $rawValue = (trim($rawValue) === 'İşaretli') ? '1' : '0';
            }

            $result = normalize_cell_value($field['field_type'], $field['options'], $rawValue, $usersById);

            if (!$result['ok'] || $result['value'] === null) {
                if (!$result['ok']) {
                    $rowSkippedCells++;
                }
                continue;
            }

            $fieldId = (int) $field['id'];
            $cellsToInsert[] = array('field_id' => $fieldId, 'column' => $result['column'], 'value' => $result['value']);
            $filledFieldIds[$fieldId] = true;
        }

        $missingRequired = false;
        foreach ($requiredFieldIds as $reqId) {
            if (!isset($filledFieldIds[$reqId])) {
                $missingRequired = true;
                break;
            }
        }

        if ($missingRequired) {
            $skippedRows++;
            continue;
        }

        bcc_execute(
            'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)',
            array(':tid' => $table['id'], ':pos' => $nextPos, ':uid' => $user['id'])
        );
        $recordId = (int) bcc_last_insert_id();
        $nextPos++;

        bcc_assign_autonumbers($table['id'], $recordId);

        foreach ($cellsToInsert as $cell) {
            $column = $cell['column'];
            bcc_execute(
                "INSERT INTO cell_values (record_id, field_id, {$column}) VALUES (:record_id, :field_id, :value)",
                array(':record_id' => $recordId, ':field_id' => $cell['field_id'], ':value' => $cell['value'])
            );
        }

        $skippedCells += $rowSkippedCells;
        $imported++;
    }

    log_audit('table.import_xlsx', 'table', $table['id'], array(
        'imported' => $imported,
        'skipped_cells' => $skippedCells,
        'skipped_rows' => $skippedRows,
        'unmatched_columns' => $unmatchedColumns,
    ), $table['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array(
    'ok' => true,
    'imported' => $imported,
    'skipped_cells' => $skippedCells,
    'skipped_rows' => $skippedRows,
    'unmatched_columns' => $unmatchedColumns,
), JSON_UNESCAPED_UNICODE);
