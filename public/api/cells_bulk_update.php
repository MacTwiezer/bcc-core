<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

const BCC_PASTE_MAX_ROWS = 5000;
const BCC_PASTE_MAX_COLS = 500;

const BCC_PASTE_MAX_CELLS = 100000;

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
    json_fail(422, 'Bu tablo ' . BCC_PASTE_MAX_COLS . ' sütun sınırını aşıyor.');
}

$usersById = bcc_team_users_by_id($table['team_id']);

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

$pendingUpdates = array();
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

    foreach (array_keys($touchedRecords) as $rid) {
        bcc_touch_record_modified($rid);
    }

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

$bccWatchedIds = bcc_slack_watched_field_ids($table['id']);

if (!empty($bccWatchedIds)) {
    $bccChangedNames = array();
    $bccChangedCount = 0;

    foreach ($pendingUpdates as $bccCell) {
        if (in_array((int) $bccCell['field_id'], $bccWatchedIds, true)) {
            $bccChangedCount++;

            $bccFid = (int) $bccCell['field_id'];
            if (isset($fieldById[$bccFid])) {
                $bccChangedNames[$bccFid] = $fieldById[$bccFid]['name'];
            }
        }
    }

    if ($bccChangedCount > 0) {
        $bccPasteUser = current_user();

        bcc_notify_slack_bulk_cell_change(
            (int) $table['id'],
            array_values($bccChangedNames),
            $bccChangedCount,
            isset($bccPasteUser['full_name']) ? $bccPasteUser['full_name'] : null
        );
    }
}

echo json_encode(array(
    'ok' => true,
    'written_cells' => $written,
    'created_rows' => $created,
    'skipped_cells' => $skipped,
    'skipped_rows' => $skippedRows,
), JSON_UNESCAPED_UNICODE);
