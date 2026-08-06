<?php
// AJAX uçnoktası: Hesap menüsündeki Çöp kutusu modalının "Kayıtlar" bölümü —
// trash_list.php (base'ler) ile AYNI desen: kullanıcının üye olduğu
// takımlardaki silinmiş KAYITLAR listelenir (KVKK — current_user_team_ids()
// ile AYNI kaynak). "Geri Yükle" editor+owner'a gösterilir (istemci) —
// sunucu tarafında record_restore.php zaten require_role('editor') ile
// ayrıca zorunlu kılıyor (bases'in owner-only kuralından BİLEREK farklı).
//
// Ebeveyn base zaten silinmişse (bases.deleted_at IS NOT NULL) o kaydın
// kayıtları burada AYRICA listelenmez — o base zaten "Base'ler" bölümünde
// görünüyor, karışık/tekrarlı görünüm olmasın.
//
// Adım 3d — 7 günlük otomatik temizlik ("ziyaret anında kontrol", cron YOK):
// bu uçnokta zaten deleted_at IS NOT NULL satırları taradığı için, aynı
// sonuç üzerinde 7 günü geçenleri ayıklayıp GERÇEK DELETE ile temizler —
// ikinci bir sorgu YAZILMADI, listeleme sorgusunun sonucu yeniden kullanılır.
// Tetikleme noktası BİLEREK grid.php DEĞİL, bu uçnokta (çöp kutusu açılışı)
// — grid çok daha sık ziyaret ediliyor, gecikmeli temizlik kabul edilebilir
// (Airtable'ın kendisi de bunu "bir noktada" arka planda yapıyor).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_login();

$user = current_user();
$teamIds = current_user_team_ids();

$items = array();

if (!empty($teamIds)) {
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT r.id, r.table_id, r.deleted_at, r.deleted_by,
                tm.name AS table_name, b.team_id,
                u.full_name AS deleted_by_name
         FROM records r
         INNER JOIN tables_meta tm ON tm.id = r.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         LEFT JOIN users u ON u.id = r.deleted_by
         WHERE b.team_id IN ($placeholders) AND r.deleted_at IS NOT NULL AND b.deleted_at IS NULL
         ORDER BY r.deleted_at DESC",
        $teamIds
    );

    // 7 günü geçenler: aynı tarama sonucundan ayıklanır, ikinci bir SELECT
    // YAZILMAZ. Gerçek DELETE burada DOĞRU ve BEKLENEN — Airtable'ın 7 gün
    // sonrası davranışı budur, bu noktadan sonra geri dönüş yok.
    $expiredRows = array();
    $activeRows = array();
    foreach ($rows as $row) {
        if (strtotime($row['deleted_at']) < strtotime('-7 days')) {
            $expiredRows[] = $row;
        } else {
            $activeRows[] = $row;
        }
    }

    if (!empty($expiredRows)) {
        $expiredIds = array_map(function ($r) { return (int) $r['id']; }, $expiredRows);
        $expPlaceholders = implode(',', array_fill(0, count($expiredIds), '?'));
        bcc_execute("DELETE FROM records WHERE id IN ($expPlaceholders)", $expiredIds);
        foreach ($expiredRows as $eRow) {
            log_audit('record.purge', 'record', (int) $eRow['id'], array('reason' => '7_day_auto', 'table_id' => (int) $eRow['table_id']), (int) $eRow['team_id']);
        }
    }

    $rows = $activeRows;

    if (!empty($rows)) {
        // can_restore: kullanıcının bu takımlardaki rolü TEK sorguda toplu
        // çekilir — trash_list.php ile AYNI N+1'den kaçınma deseni.
        $roleByTeamId = array();
        $roleRows = bcc_fetch_all(
            "SELECT team_id, role FROM team_members WHERE user_id = ? AND team_id IN ($placeholders)",
            array_merge(array($user['id']), $teamIds)
        );
        foreach ($roleRows as $r) {
            $roleByTeamId[(int) $r['team_id']] = $r['role'];
        }

        // Birincil alan değeri: N+1 yok. (1) her table_id için MIN(position,id)
        // alanı TEK sorguda ("greatest-n-per-group" deseni — $fields[0]/
        // ORDER BY position,id LIMIT 1'in projedeki HER yerdeki AYNI mantığı),
        // (2) o alan id'leriyle cell_values TEK sorguda, (3) cell_display_text()
        // (grid'in KENDİSİNİN kullandığı fonksiyon) her satıra uygulanır —
        // yeni bir "değeri okunur metne çevir" mantığı YAZILMADI.
        $tableIds = array_values(array_unique(array_map(function ($r) { return (int) $r['table_id']; }, $rows)));
        $tablePlaceholders = implode(',', array_fill(0, count($tableIds), '?'));

        $primaryFieldRows = bcc_fetch_all(
            "SELECT f1.table_id, f1.id AS field_id, f1.field_type
             FROM fields f1
             LEFT JOIN fields f2 ON f2.table_id = f1.table_id
                 AND (f2.position < f1.position OR (f2.position = f1.position AND f2.id < f1.id))
             WHERE f2.id IS NULL AND f1.table_id IN ($tablePlaceholders)",
            $tableIds
        );
        $primaryFieldByTable = array();
        foreach ($primaryFieldRows as $pf) {
            $primaryFieldByTable[(int) $pf['table_id']] = array('id' => (int) $pf['field_id'], 'type' => $pf['field_type']);
        }

        $primaryFieldIds = array_values(array_unique(array_map(function ($pf) { return $pf['id']; }, $primaryFieldByTable)));
        $recordIds = array_map(function ($r) { return (int) $r['id']; }, $rows);

        $cellByRecordField = array();
        if (!empty($primaryFieldIds)) {
            $recPlaceholders = implode(',', array_fill(0, count($recordIds), '?'));
            $fldPlaceholders = implode(',', array_fill(0, count($primaryFieldIds), '?'));
            $cellRows = bcc_fetch_all(
                "SELECT record_id, field_id, value_text, value_number, value_date, value_json
                 FROM cell_values WHERE record_id IN ($recPlaceholders) AND field_id IN ($fldPlaceholders)",
                array_merge($recordIds, $primaryFieldIds)
            );
            foreach ($cellRows as $c) {
                $cellByRecordField[(int) $c['record_id']][(int) $c['field_id']] = $c;
            }
        }

        // Birincil alan 'user' tipindeyse ad çözümü için — takım başına TEK
        // sorgu (takım sayısı küçük, kayıt sayısına göre N+1 DEĞİL).
        $usersByTeam = array();
        foreach ($teamIds as $tid) {
            $usersByTeam[$tid] = bcc_team_users_by_id($tid);
        }

        foreach ($rows as $row) {
            $tableId = (int) $row['table_id'];
            $teamId = (int) $row['team_id'];
            $pf = isset($primaryFieldByTable[$tableId]) ? $primaryFieldByTable[$tableId] : null;
            $cellRow = ($pf && isset($cellByRecordField[(int) $row['id']][$pf['id']]))
                ? $cellByRecordField[(int) $row['id']][$pf['id']]
                : null;
            $primaryValue = $pf
                ? cell_display_text($pf['type'], $cellRow, isset($usersByTeam[$teamId]) ? $usersByTeam[$teamId] : array())
                : '';
            if ($primaryValue === '') {
                $primaryValue = '(başlıksız kayıt)';
            }

            $isSelf = $row['deleted_by'] !== null && (int) $row['deleted_by'] === (int) $user['id'];
            $actorName = $row['deleted_by_name'] !== null ? $row['deleted_by_name'] : 'Bir kullanıcı';
            $message = $isSelf
                ? 'Sen bir kayıt sildin: ' . $primaryValue . ' (' . $row['table_name'] . ')'
                : $actorName . ' bir kayıt sildi: ' . $primaryValue . ' (' . $row['table_name'] . ')';

            $items[] = array(
                'id' => (int) $row['id'],
                'message' => $message,
                'relative_date' => bcc_home_relative_date($row['deleted_at']),
                'actor_initial' => $row['deleted_by_name'] !== null ? mb_strtoupper(mb_substr($row['deleted_by_name'], 0, 1, 'UTF-8'), 'UTF-8') : '?',
                'can_restore' => isset($roleByTeamId[$teamId]) && in_array($roleByTeamId[$teamId], array('editor', 'owner'), true),
            );
        }
    }
}

echo json_encode(array('ok' => true, 'items' => $items), JSON_UNESCAPED_UNICODE);
