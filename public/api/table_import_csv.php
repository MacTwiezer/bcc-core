<?php
// AJAX uçnoktası: bir CSV dosyasını mevcut tabloya YENİ kayıtlar olarak aktarır
// (Airtable'ın sekme dropdown'ındaki "Import data" karşılığı — üstüne yazmaz,
// ekler). Sekme dropdown'ından çağrılır (public/grid.php, assets/grid-table-data.js).
// Format: view_export_csv.php'nin ÜRETTİĞİ AYNI format (UTF-8 BOM, ";" ayırıcı,
// ilk satır alan adları) — dış kütüphane YOK, native fgetcsv() kullanılır.
// Yalnızca tablodaki alan adlarıyla BİREBİR (harf büyüklüğünden bağımsız)
// eşleşen sütunlar aktarılır; eşleşmeyenler atlanır. "attachment" tipi alanlar
// hiç eşleştirilmez (CSV hücresinde dosya verisi olamaz).
// Her hücre normalize_cell_value() (src/schema.php) ile doğrulanır — cell_update.php
// ile AYNI TEK doğrulama/tip-dönüştürme fonksiyonu, ikinci bir doğrulama yazılmaz.
// Geçersiz/eşleşmeyen tek tek HÜCRELER satırı iptal etmez, yalnızca o hücre boş
// bırakılır (best-effort import) — yalnızca gerçek bir DB hatası tüm işlemi geri alır.
// Güvenlik: CSRF + require_role('editor') + table_id doğrulaması.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

const BCC_CSV_IMPORT_MAX_BYTES = 10 * 1024 * 1024; // 10MB
const BCC_CSV_IMPORT_MAX_ROWS = 5000;

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

$table = find_table_or_404($tableId);
require_role($table['team_id'], 'editor');

if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
    json_fail(422, 'Dosya alınamadı.');
}

$upload = $_FILES['csv_file'];

if ($upload['error'] !== UPLOAD_ERR_OK) {
    $tooBig = ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE);
    json_fail(422, $tooBig ? 'Dosya çok büyük.' : 'Dosya yüklenemedi.');
}

if ($upload['size'] <= 0 || $upload['size'] > BCC_CSV_IMPORT_MAX_BYTES) {
    json_fail(422, 'Dosya boyutu 10MB\'ı aşamaz.');
}

$ext = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    json_fail(422, 'Yalnızca .csv dosyaları desteklenir.');
}

$handle = fopen($upload['tmp_name'], 'r');
if (!$handle) {
    json_fail(422, 'Dosya okunamadı.');
}

$headerRow = fgetcsv($handle, 0, ';');
if ($headerRow === false || empty($headerRow)) {
    fclose($handle);
    json_fail(422, 'Dosyada başlık satırı bulunamadı.');
}

// view_export_csv.php'nin yazdığı UTF-8 BOM'u — yalnızca ilk sütun adının
// başında olabilir, header eşleşmesini bozmaması için temizleniyor.
if (isset($headerRow[0]) && substr($headerRow[0], 0, 3) === "\xEF\xBB\xBF") {
    $headerRow[0] = substr($headerRow[0], 3);
}

$fields = bcc_fetch_all(
    'SELECT id, name, field_type, options, is_required FROM fields WHERE table_id = :tid ORDER BY position, id',
    array(':tid' => $table['id'])
);

// Zorunlu alanlar — cell_update.php'nin uyguladığı AYNI kural (bulunan gerçek
// bug: import bunu hiç kontrol etmiyordu, cell_update.php'de reddedilen boş bir
// zorunlu değer CSV'den sessizce geçiyordu). 'attachment' hiçbir zaman CSV'den
// doldurulamayacağı için (zaten $fieldByName'e hiç girmiyor) buradan da hariç —
// yoksa alakasız bir zorunlu dosya-eki alanı TÜM satırları eler.
$requiredFieldIds = array();
foreach ($fields as $f) {
    if ($f['field_type'] !== 'attachment' && (int) $f['is_required'] === 1) {
        $requiredFieldIds[] = (int) $f['id'];
    }
}

// Sütun adı → alan eşlemesi, harf büyüklüğünden bağımsız tam eşleşme.
// 'attachment' BİLEREK haritaya girmiyor (yorum: CSV hücresinde dosya verisi olamaz).
$fieldByName = array();
foreach ($fields as $f) {
    if ($f['field_type'] === 'attachment') {
        continue;
    }
    $fieldByName[mb_strtolower(trim($f['name']), 'UTF-8')] = $f;
}

// Her CSV sütununun karşılık geldiği alanı (varsa) baştan çözüyoruz —
// satır satır tekrar tekrar isim aramamak için.
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
    fclose($handle);
    json_fail(422, 'Hiçbir sütun tablodaki alanlarla eşleşmedi.');
}

// 'user' tipi hücreler için: normalize_cell_value() ham değeri users.id (rakam)
// bekliyor, ama export'un gösterdiği (ve kullanıcıların CSV'de düzenleyeceği)
// değer full_name — bu yüzden import'a ÖZEL, isimden id'ye ters bir eşleme
// hazırlanıyor (normalize_cell_value'nun kendisi DEĞİŞTİRİLMİYOR).
$usersById = bcc_team_users_by_id($table['team_id']);
$userIdByName = array();
foreach ($usersById as $uid => $uname) {
    $userIdByName[mb_strtolower(trim($uname), 'UTF-8')] = $uid;
}

$rows = array();
$rowCount = 0;
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    if ($row === array(null) || (count($row) === 1 && trim((string) $row[0]) === '')) {
        continue; // boş satır
    }
    $rowCount++;
    if ($rowCount > BCC_CSV_IMPORT_MAX_ROWS) {
        fclose($handle);
        json_fail(422, 'Dosya çok fazla satır içeriyor (limit: ' . BCC_CSV_IMPORT_MAX_ROWS . ').');
    }
    $rows[] = $row;
}
fclose($handle);

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
        // Kaydı DB'ye yazmadan ÖNCE tüm hücreleri normalize edip zorunlu alan
        // kapsamını kontrol ediyoruz (bulunan gerçek bug: import hiçbir zorunlu
        // alan kontrolü yapmıyordu, cell_update.php'de reddedilen boş bir zorunlu
        // değer buradan sessizce geçiyordu) — satır zorunlu bir alanı boş
        // bırakıyorsa hiç INSERT yapılmadan atlanır, "yarım" kayıt oluşmaz.
        $cellsToInsert = array();
        $filledFieldIds = array();
        $rowSkippedCells = 0;

        foreach ($columnFields as $colIndex => $field) {
            if ($field === null || !array_key_exists($colIndex, $row)) {
                continue;
            }

            $rawValue = (string) $row[$colIndex];
            if (trim($rawValue) === '') {
                continue; // boş hücre — yazılacak bir şey yok
            }

            // 'date'/'user' için export-format → normalize_cell_value'nun
            // beklediği ham girdi biçimine dönüştürme (yukarıdaki yorum).
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

    bcc_commit();

    log_audit('table.import_csv', 'table', $table['id'], array(
        'imported' => $imported,
        'skipped_cells' => $skippedCells,
        'skipped_rows' => $skippedRows,
        'unmatched_columns' => $unmatchedColumns,
    ), $table['team_id']);
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
