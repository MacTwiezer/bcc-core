<?php
// AJAX uçnoktası: bir Excel (.xlsx) dosyasını mevcut tabloya YENİ kayıtlar
// olarak aktarır (OpsFlow'un sekme dropdown'ındaki "Import data" karşılığı —
// üstüne yazmaz, ekler). Sekme dropdown'ından çağrılır (public/grid.php,
// assets/grid-table-data.js). Format: view_export_xlsx.php'nin ÜRETTİĞİ AYNI
// düzen (ilk satır alan adları) — dış kütüphane YOK, src/xlsx_reader.php
// (ZipArchive ile elle yazılmış okuyucu, xlsx_writer.php'nin ters yönü).
// Yalnızca tablodaki alan adlarıyla BİREBİR (harf büyüklüğünden bağımsız)
// eşleşen sütunlar aktarılır; eşleşmeyenler atlanır. "attachment" tipi alanlar
// hiç eşleştirilmez (Excel hücresinde dosya verisi olamaz).
// Her hücre normalize_cell_value() (src/schema.php) ile doğrulanır — cell_update.php
// ile AYNI TEK doğrulama/tip-dönüştürme fonksiyonu, ikinci bir doğrulama yazılmaz.
// Geçersiz/eşleşmeyen tek tek HÜCRELER satırı iptal etmez, yalnızca o hücre boş
// bırakılır (best-effort import) — yalnızca gerçek bir DB hatası tüm işlemi geri alır.
// Güvenlik: CSRF + require_role('editor') + table_id doğrulaması.

require __DIR__ . '/../../src/api_bootstrap.php';
require __DIR__ . '/../../src/xlsx_reader.php';

api_require_post();
api_require_login();
api_require_csrf();

const BCC_XLSX_IMPORT_MAX_BYTES = 10 * 1024 * 1024; // 10MB
const BCC_XLSX_IMPORT_MAX_ROWS = 5000;

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

// Excel'den ASLA doldurulamayan alan tipleri — iki AYRI listeden de (aşağıdaki
// $requiredFieldIds ve $fieldByName) hariç tutulurlar, bu yüzden tek yerde
// tanımlı (iki ayrı literal listesi zamanla ayrışırdı).
//   * attachment — Excel hücresinde dosya verisi olamaz (eskiden beri hariçti).
//   * autonumber (Grup C2) — değeri YALNIZCA sunucu yazar
//     (bcc_assign_autonumbers); normalize_cell_value() bu tipi zaten
//     reddediyor. Bulunan gerçek bug: view_export_xlsx.php autonumber
//     sütununu DA dışa aktarıyor (görünür alanların hepsini yazıyor), yani
//     "dışa aktar → düzenle → içe aktar" turunda bu sütun geri geliyordu ve
//     (a) her satırda normalize reddi "N hücre atlandı" uyarısını şişiriyordu,
//     (b) alan is_required=1 ise $filledFieldIds'e HİÇ giremediği için
//     $missingRequired her satırda true oluyor ve içe aktarım SIFIR kayıtla
//     bitiyordu. Kayıtlar numaralarını zaten aşağıda bcc_assign_autonumbers()
//     ile taze alıyor — dosyadaki eski numara hiçbir durumda kullanılmamalı.
$importIgnoredFieldTypes = array('attachment', 'autonumber');

// Zorunlu alanlar — cell_update.php'nin uyguladığı AYNI kural (bulunan gerçek
// bug: import bunu hiç kontrol etmiyordu, cell_update.php'de reddedilen boş bir
// zorunlu değer içe aktarımdan sessizce geçiyordu). Yukarıdaki tipler buradan
// da hariç — yoksa alakasız bir zorunlu dosya-eki alanı TÜM satırları eler.
$requiredFieldIds = array();
foreach ($fields as $f) {
    if (!in_array($f['field_type'], $importIgnoredFieldTypes, true) && (int) $f['is_required'] === 1) {
        $requiredFieldIds[] = (int) $f['id'];
    }
}

// Sütun adı → alan eşlemesi, harf büyüklüğünden bağımsız tam eşleşme.
$fieldByName = array();
foreach ($fields as $f) {
    if (in_array($f['field_type'], $importIgnoredFieldTypes, true)) {
        continue;
    }
    $fieldByName[mb_strtolower(trim($f['name']), 'UTF-8')] = $f;
}

// Her Excel sütununun karşılık geldiği alanı (varsa) baştan çözüyoruz —
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
    json_fail(422, 'Hiçbir sütun tablodaki alanlarla eşleşmedi.');
}

// 'user' tipi hücreler için: normalize_cell_value() ham değeri users.id (rakam)
// bekliyor, ama export'un gösterdiği (ve kullanıcıların dosyada düzenleyeceği)
// değer full_name — bu yüzden import'a ÖZEL, isimden id'ye ters bir eşleme
// hazırlanıyor (normalize_cell_value'nun kendisi DEĞİŞTİRİLMİYOR).
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
        continue; // boş satır
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
            // Gerçek Excel'de oluşturulmuş bir dosyada tarih hücresi zaten
            // xlsx_reader.php tarafından 'Y-m-d'ye çevrilmiş olarak gelir —
            // bu durumda aşağıdaki d.m.Y ayrıştırması round-trip etmez ve ham
            // değer değişmeden normalize_cell_value'nun kendi Y-m-d
            // doğrulamasına düşer, ki bu da onu zaten kabul eder.
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
                // Bulunan gerçek bug: cell_display_text() export'ta 'İşaretli'/
                // 'İşaretsiz' yazıyor (src/schema.php:783) ama normalize_cell_value()
                // yalnızca '1'i işaretli sayıyor — bu satır olmadan işaretli bir
                // kutu export edilip geri içe aktarılınca SESSİZCE işaretsize
                // dönüyordu (aynı hata CSV döneminde de vardı, round-trip testiyle
                // burada yakalandı). Türkçe İ/i büyük/küçük harf dönüşümü locale'e
                // göre tutarsız olduğu için (mb_strtolower riskli) sabit literal
                // ile birebir karşılaştırılıyor — bu string yalnızca export'un
                // kendi ürettiği sabit metin, kullanıcı serbestçe yazmıyor.
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

        // Autonumber (Grup C2): içe aktarılan her satır da kendi numarasını alır.
        // ⚠️ bcc_last_insert_id() ÇAĞRISINDAN SONRA — bcc_assign_autonumbers()
        // LAST_INSERT_ID(expr) ile oturumun last-insert-id'sini EZER; daha önce
        // çağrılsaydı $recordId kayıt id'si değil autonumber olurdu.
        // Zaten açık olan toplu transaction'ın (satır ~146) içinde: import
        // ortasında bir hata olursa sayaç ilerlemesi de geri alınır.
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

    bcc_commit();

    log_audit('table.import_xlsx', 'table', $table['id'], array(
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
