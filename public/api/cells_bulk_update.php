<?php
// AJAX uçnoktası: TEK istekte çok sayıda hücreyi yazar — grid'e Excel/Airtable/
// Sheets panosundan yapıştırma (assets/grid-paste.js).
//
// Neden ayrı uçnokta: cell_update.php TEK hücreliktir. 500 hücre için onu 500
// kez çağırmak 500 HTTP isteği, 500 AYRI transaction (yarısı yazılıp yarısı
// yazılmazsa ATOMİKLİK YOK), hücre başına chip/link render'ı ve 500 kez
// bcc_touch_record_modified() + log_audit() demekti. Burada hepsi TEK
// transaction, kayıt başına tek "değişti" damgası, işlem başına tek audit satırı.
//
// Doğrulama mantığı KOPYALANMADI: her hücre yine normalize_cell_value() ile
// (src/schema.php) geçer — cell_update.php ve table_import_xlsx.php ile AYNI
// fonksiyon, aynı tip dönüşümleri, aynı seçim/kullanıcı doğrulaması.
//
// Güvenlik: CSRF + require_role('editor') + table_id doğrulaması. Gelen HER
// field_id ve record_id'nin GERÇEKTEN bu tabloya ait olduğu kontrol edilir —
// istemci başka bir tablonun hücresini bu istekle yazamaz (KVKK).
//
// ⚠️ İSTEK BİÇİMİ: hücreler TEK bir JSON alanında ('payload') gelir, ayrı ayrı
// form alanlarında DEĞİL. Sebep: php.ini'de max_input_vars = 1000; binlerce
// alan gönderilirse PHP fazlasını SESSİZCE atar ve yapıştırmanın bir kısmı
// kaybolurdu. Tek alan bu sınıra hiç takılmaz (post_max_size = 25M).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

// Sınırlar. Satır/sütun tavanları istemcide de uygulanıyor (grid-paste.js) —
// buradakiler SON savunma hattı, istek elle hazırlanırsa da geçerli.
const BCC_PASTE_MAX_ROWS = 5000;
const BCC_PASTE_MAX_COLS = 500;
// Toplam hücre tavanı AYRICA gerekli: 5000 x 500 = 2.5 milyon hücre tek
// transaction'a sığmaz (bellek + kilit süresi). Boyut tavanları tek başına
// yeterli değil, bu üçüncüsü gerçek koruma.
const BCC_PASTE_MAX_CELLS = 100000;
// Tek INSERT'e sığdırılacak hücre sayısı — hücre başına ayrı sorgu çok yavaştı.
const BCC_PASTE_CHUNK = 200;

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$rawPayload = isset($_POST['payload']) ? (string) $_POST['payload'] : '';

$table = find_table_or_404($tableId);
require_role($table['team_id'], 'editor');

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    json_fail(422, 'Yapıştırma verisi okunamadı.');
}

$updates = isset($payload['updates']) && is_array($payload['updates']) ? $payload['updates'] : array();
$creates = isset($payload['creates']) && is_array($payload['creates']) ? $payload['creates'] : array();

$totalCells = count($updates);
foreach ($creates as $row) {
    $totalCells += is_array($row) ? count($row) : 0;
}

if ($totalCells === 0) {
    json_fail(422, 'Yapıştırılacak hücre yok.');
}
if ($totalCells > BCC_PASTE_MAX_CELLS) {
    json_fail(422, 'Tek seferde en fazla ' . BCC_PASTE_MAX_CELLS . ' hücre yapıştırılabilir.');
}
if (count($creates) > BCC_PASTE_MAX_ROWS) {
    json_fail(422, 'Tek seferde en fazla ' . BCC_PASTE_MAX_ROWS . ' yeni satır eklenebilir.');
}

// ---------------------------------------------------------------------------
// Alan haritası — salt-okunur tipler DIŞARIDA
// ---------------------------------------------------------------------------
// created_time/created_by/last_modified_*/autonumber sistem tarafından üretilir;
// bunlara yazmak sessizce yok sayılır (istemci de zaten göndermez, bu ikinci
// savunma). BCC_READONLY_FIELD_TYPES tek kaynak, liste burada YİNELENMEZ.
$fieldById = array();
foreach (bcc_fetch_all(
    'SELECT id, name, field_type, options, is_required FROM fields WHERE table_id = :tid',
    array(':tid' => $table['id'])
) as $f) {
    if (in_array($f['field_type'], $GLOBALS['BCC_READONLY_FIELD_TYPES'], true)) {
        continue;
    }
    $fieldById[(int) $f['id']] = $f;
}

if (count($fieldById) > BCC_PASTE_MAX_COLS) {
    // Tablonun kendisi tavanı aşıyorsa yapıştırma zaten anlamsız.
    json_fail(422, 'Bu tablo ' . BCC_PASTE_MAX_COLS . ' sütun sınırını aşıyor.');
}

// 'user' tipi için ters harita (ad -> id) — table_import_xlsx.php ile AYNI
// gerekçe: panodan gelen değer kullanıcı ADI olur, normalize_cell_value() ise
// id bekler. Fonksiyonun kendisi DEĞİŞTİRİLMİYOR.
$usersById = bcc_team_users_by_id($table['team_id']);

// Güncellenecek kayıtların GERÇEKTEN bu tabloya ait ve silinmemiş olduğu
// kontrolü — istemciden gelen record_id'ye asla güvenilmez.
$recordIds = array();
foreach ($updates as $u) {
    if (isset($u['r'])) {
        $recordIds[(int) $u['r']] = true;
    }
}
$validRecordIds = array();
if (!empty($recordIds)) {
    $ids = array_keys($recordIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (bcc_fetch_all(
        "SELECT id FROM records WHERE table_id = ? AND deleted_at IS NULL AND id IN ($ph)",
        array_merge(array($table['id']), $ids)
    ) as $r) {
        $validRecordIds[(int) $r['id']] = true;
    }
}

$user = current_user();
$skipped = 0;

// ---------------------------------------------------------------------------
// Normalizasyon — YAZMADAN ÖNCE
// ---------------------------------------------------------------------------
// table_import_xlsx.php ile AYNI ilke: önce her şey doğrulanır, sonra yazılır.
// Böylece geçersiz bir hücre yüzünden yarım yazılmış bir satır kalmaz.
$pendingUpdates = array();   // [ ['record_id'=>, 'field_id'=>, 'column'=>, 'value'=>], ... ]
$touchedRecords = array();

foreach ($updates as $u) {
    $rid = isset($u['r']) ? (int) $u['r'] : 0;
    $fid = isset($u['f']) ? (int) $u['f'] : 0;
    $raw = isset($u['v']) ? (string) $u['v'] : '';

    if (!isset($validRecordIds[$rid]) || !isset($fieldById[$fid])) {
        $skipped++;
        continue;
    }

    $field = $fieldById[$fid];
    $result = normalize_cell_value($field['field_type'], $field['options'], $raw, $usersById);

    if (!$result['ok']) {
        $skipped++;
        continue;
    }
    // Zorunlu alan ELLE boşaltılamaz — cell_update.php'deki AYNI kural.
    if ((int) $field['is_required'] === 1 && $result['value'] === null) {
        $skipped++;
        continue;
    }

    $pendingUpdates[] = array(
        'record_id' => $rid,
        'field_id' => $fid,
        'column' => $result['column'],
        'value' => $result['value'],
    );
    $touchedRecords[$rid] = true;
}

// Yeni satırlar: zorunlu alan kapsamı satır bazında kontrol edilir; eksikse
// satır HİÇ oluşturulmaz (xlsx import ile AYNI davranış, "yarım kayıt" olmaz).
$requiredFieldIds = array();
foreach ($fieldById as $fid => $f) {
    if ((int) $f['is_required'] === 1) {
        $requiredFieldIds[] = $fid;
    }
}

$pendingCreates = array();
$skippedRows = 0;

foreach ($creates as $row) {
    if (!is_array($row)) {
        continue;
    }
    $cells = array();
    $filled = array();

    foreach ($row as $c) {
        $fid = isset($c['f']) ? (int) $c['f'] : 0;
        $raw = isset($c['v']) ? (string) $c['v'] : '';

        if (!isset($fieldById[$fid])) {
            $skipped++;
            continue;
        }

        $field = $fieldById[$fid];
        $result = normalize_cell_value($field['field_type'], $field['options'], $raw, $usersById);

        if (!$result['ok'] || $result['value'] === null) {
            if (!$result['ok']) {
                $skipped++;
            }
            continue;
        }

        $cells[] = array('field_id' => $fid, 'column' => $result['column'], 'value' => $result['value']);
        $filled[$fid] = true;
    }

    $missingRequired = false;
    foreach ($requiredFieldIds as $reqId) {
        if (!isset($filled[$reqId])) {
            $missingRequired = true;
            break;
        }
    }
    if ($missingRequired) {
        $skippedRows++;
        continue;
    }

    $pendingCreates[] = $cells;
}

if (empty($pendingUpdates) && empty($pendingCreates)) {
    json_fail(422, 'Yapıştırılabilir geçerli hücre bulunamadı.');
}

// ---------------------------------------------------------------------------
// Yazma — TEK transaction
// ---------------------------------------------------------------------------
// Hücreler DEĞER KOLONUNA göre gruplanıp toplu INSERT ile yazılır. Hücre başına
// ayrı sorgu, tavan seviyesinde (100.000 hücre) on binlerce gidiş-dönüş demekti.
// ON DUPLICATE KEY UPDATE: uq_cell_values_record_field benzersiz anahtarı
// sayesinde var olan hücre güncellenir, olmayan eklenir (cell_update.php'deki
// AYNI ifade, yalnızca çok satırlı).
function bcc_paste_flush($column, &$buffer)
{
    if (empty($buffer)) {
        return;
    }

    $placeholders = array();
    $params = array();
    foreach ($buffer as $i => $cell) {
        $placeholders[] = "(:r{$i}, :f{$i}, :v{$i})";
        $params[":r{$i}"] = $cell['record_id'];
        $params[":f{$i}"] = $cell['field_id'];
        $params[":v{$i}"] = $cell['value'];
    }

    // $column whitelist'ten gelir (normalize_cell_value'nun döndürdüğü sabit
    // kolon adı), istekten DEĞİL — SQL'e gömülmesi güvenli.
    bcc_execute(
        "INSERT INTO cell_values (record_id, field_id, {$column}) VALUES "
        . implode(', ', $placeholders)
        . " ON DUPLICATE KEY UPDATE {$column} = VALUES({$column})",
        $params
    );

    $buffer = array();
}

$created = 0;

try {
    bcc_begin_transaction();

    // --- Yeni satırlar ---
    if (!empty($pendingCreates)) {
        $nextPos = (int) bcc_fetch_column(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :tid',
            array(':tid' => $table['id'])
        );

        foreach ($pendingCreates as $cells) {
            bcc_execute(
                'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)',
                array(':tid' => $table['id'], ':pos' => $nextPos, ':uid' => $user['id'])
            );
            $newRecordId = (int) bcc_last_insert_id();
            $nextPos++;
            $created++;

            // ⚠️ bcc_last_insert_id() ÇAĞRISINDAN SONRA — bcc_assign_autonumbers()
            // LAST_INSERT_ID(expr) ile oturumun last-insert-id'sini EZER (bkz.
            // table_import_xlsx.php'deki aynı not). Ters sırada $newRecordId
            // kayıt id'si değil autonumber olurdu.
            bcc_assign_autonumbers($table['id'], $newRecordId);

            foreach ($cells as $cell) {
                $pendingUpdates[] = array(
                    'record_id' => $newRecordId,
                    'field_id' => $cell['field_id'],
                    'column' => $cell['column'],
                    'value' => $cell['value'],
                );
            }
        }
    }

    // --- Hücreler (mevcut + yeni satırlar birlikte) ---
    $byColumn = array();
    foreach ($pendingUpdates as $cell) {
        $byColumn[$cell['column']][] = $cell;
    }

    $written = 0;
    foreach ($byColumn as $column => $cells) {
        $buffer = array();
        foreach ($cells as $cell) {
            $buffer[] = $cell;
            $written++;
            if (count($buffer) >= BCC_PASTE_CHUNK) {
                bcc_paste_flush($column, $buffer);
            }
        }
        bcc_paste_flush($column, $buffer);
    }

    // "Son değişiklik" damgası — kayıt başına BİR kez (hücre başına değil).
    foreach (array_keys($touchedRecords) as $rid) {
        bcc_touch_record_modified($rid);
    }

    // İşlem başına TEK audit satırı: hücre başına yazmak denetim kaydını
    // kullanılamaz hale getirirdi (tek yapıştırma binlerce satır üretir).
    log_audit('cell.bulk_paste', 'table', $table['id'], array(
        'written_cells' => $written,
        'created_rows' => $created,
        'skipped_cells' => $skipped,
        'skipped_rows' => $skippedRows,
    ), $table['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Yapıştırma kaydedilemedi (veritabanı hatası).');
}

echo json_encode(array(
    'ok' => true,
    'written_cells' => $written,
    'created_rows' => $created,
    'skipped_cells' => $skipped,
    'skipped_rows' => $skippedRows,
), JSON_UNESCAPED_UNICODE);
