<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

function call_api($endpoint, $userId, $params, $method = 'POST', $withCsrf = true)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_note_view_case.php')
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg((string) $userId)
        . ' ' . escapeshellarg(base64_encode(json_encode($params)))
        . ' ' . escapeshellarg($method)
        . ' ' . escapeshellarg($withCsrf ? '1' : '0');

    $raw = (string) shell_exec($cmd . ' 2>&1');

    $status = 200;
    if (preg_match('/\|HTTP=(\d+)\s*$/', $raw, $m)) {
        $status = (int) $m[1];
        $raw = preg_replace('/\|HTTP=\d+\s*$/', '', $raw);
    }

    return array('status' => $status, 'body' => trim($raw), 'json' => json_decode(trim($raw), true));
}

echo "=== A) Sema ===\n";

$cols = array();
foreach (bcc_fetch_all("SHOW COLUMNS FROM record_view_log") as $c) {
    $cols[$c['Field']] = $c;
}

foreach (array('id', 'record_id', 'user_id', 'team_id', 'role_at_view', 'opened_at', 'closed_at', 'duration_seconds') as $col) {
    check('kolon var: ' . $col, isset($cols[$col]));
}

check('role_at_view ENUM team_members.role ile ayni',
    isset($cols['role_at_view']) && $cols['role_at_view']['Type'] === "enum('owner','editor','commenter','viewer')",
    isset($cols['role_at_view']) ? $cols['role_at_view']['Type'] : 'kolon yok');

check('opened_at NOT NULL (uygulamadan NOW() ile yazilir)',
    isset($cols['opened_at']) && $cols['opened_at']['Null'] === 'NO');
check('closed_at NULL olabilir (tamamlanmamis inceleme)',
    isset($cols['closed_at']) && $cols['closed_at']['Null'] === 'YES');

$fks = array();
foreach (bcc_fetch_all(
    "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
       FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'record_view_log'") as $fk) {
    $fks[$fk['CONSTRAINT_NAME']] = $fk;
}
check('fk_rvl_record -> records CASCADE',
    isset($fks['fk_rvl_record']) && $fks['fk_rvl_record']['DELETE_RULE'] === 'CASCADE');
check('fk_rvl_user -> users CASCADE',
    isset($fks['fk_rvl_user']) && $fks['fk_rvl_user']['DELETE_RULE'] === 'CASCADE');
check('fk_rvl_team -> teams SET NULL',
    isset($fks['fk_rvl_team']) && $fks['fk_rvl_team']['DELETE_RULE'] === 'SET NULL');

$idx = array();
foreach (bcc_fetch_all("SHOW INDEX FROM record_view_log") as $i) {
    $idx[$i['Key_name']][(int) $i['Seq_in_index']] = $i['Column_name'];
}
check('idx_rvl_record_opened = (record_id, opened_at) — filesortsuz sorgu',
    isset($idx['idx_rvl_record_opened']) && array_values($idx['idx_rvl_record_opened']) === array('record_id', 'opened_at'));
check('idx_rvl_opened var (15 gunluk temizlik)', isset($idx['idx_rvl_opened']));

$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
check('schema.sql record_view_log tanimini iceriyor',
    strpos($schemaSql, 'CREATE TABLE IF NOT EXISTS record_view_log') !== false);
check('schema.sql AUTO_INCREMENT dogru yazilmis (AUTO INCREMENT degil)',
    preg_match('/record_view_log \(\s*\n\s*id\s+BIGINT UNSIGNED NOT NULL AUTO_INCREMENT/i', $schemaSql) === 1);

echo "\n=== B) Yetenek fonksiyonlari ===\n";

check('temsilci YALNIZCA commenter',
    bcc_is_representative('commenter') === true
    && bcc_is_representative('owner') === false
    && bcc_is_representative('editor') === false
    && bcc_is_representative('viewer') === false);

check('gecmisi YALNIZCA owner gorur',
    bcc_can_view_record_audits('owner') === true
    && bcc_can_view_record_audits('commenter') === false
    && bcc_can_view_record_audits('editor') === false
    && bcc_can_view_record_audits('viewer') === false);

$repSet = array_values(array_filter(array('owner', 'editor', 'commenter', 'viewer'), 'bcc_is_representative'));
$comSet = array_values(array_filter(array('owner', 'editor', 'commenter', 'viewer'), 'bcc_can_comment'));
check('temsilci kumesi != yorum-yazan kumesi (bcc_can_comment ile karistirilmamis)',
    $repSet !== $comSet, 'temsilci=' . implode(',', $repSet) . ' | yorum=' . implode(',', $comSet));

check('izlenen taraf kendi verisini GORMEZ', bcc_can_view_record_audits('commenter') === false);

foreach (array('uydurma_rol', '', 'admin', 'COMMENTER') as $bad) {
    check("gecersiz rol '" . $bad . "' hicbir kapiyi acmiyor",
        !bcc_is_representative($bad) && !bcc_can_view_record_audits($bad));
}

echo "\n=== C) Gecici kurban verisi kuruluyor ===\n";

bcc_execute("INSERT INTO teams (name) VALUES ('ZZ Not Takip Testi')");
$teamId = (int) bcc_last_insert_id();

bcc_execute("INSERT INTO bases (team_id, name) VALUES (:t, 'ZZ Test Base')", array('t' => $teamId));
$baseId = (int) bcc_last_insert_id();

bcc_execute("INSERT INTO tables_meta (base_id, name, position) VALUES (:b, 'ZZ Test Tablo', 0)", array('b' => $baseId));
$tableId = (int) bcc_last_insert_id();

bcc_execute("INSERT INTO records (table_id, position) VALUES (:t, 0)", array('t' => $tableId));
$recordId = (int) bcc_last_insert_id();

bcc_execute("INSERT INTO records (table_id, position) VALUES (:t, 1)", array('t' => $tableId));
$otherRecordId = (int) bcc_last_insert_id();

$uid = array();
foreach (array('owner', 'editor', 'commenter', 'viewer') as $role) {
    $mail = 'nvl.' . $role . '@bcc-test.local';
    bcc_execute('DELETE FROM users WHERE email = :e', array('e' => $mail));
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active)
         VALUES (:e, :h, :n, 0, 1)',
        array(
            'e' => $mail,
            'h' => password_hash('NvlTest!2026', PASSWORD_DEFAULT),
            'n' => 'NVL ' . ucfirst($role),
        )
    );
    $uid[$role] = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $teamId, 'u' => $uid[$role], 'r' => $role));
}
check('dort test kullanicisi kuruldu (hicbiri is_admin degil)',
    count($uid) === 4
    && (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE id IN (' . implode(',', $uid) . ') AND is_admin = 1') === 0);
echo "  ekip=$teamId base=$baseId tablo=$tableId kayit=$recordId\n";

register_shutdown_function(function () use ($teamId, $uid) {
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));

    if ($uid) {
        bcc_execute('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $uid)) . ')');
    }
});

echo "\n=== D) note_view_start.php ===\n";

$r = call_api('note_view_start.php', $uid['commenter'], array('record_id' => $recordId));
check('commenter: 200 + view_id doner', $r['status'] === 200 && !empty($r['json']['view_id']), $r['body']);
$viewId = isset($r['json']['view_id']) ? (int) $r['json']['view_id'] : 0;

$row = bcc_fetch_one('SELECT * FROM record_view_log WHERE id = :id', array('id' => $viewId));
check('satir dogru yazildi (role_at_view/team_id/opened_at/closed_at)',
    $row && $row['role_at_view'] === 'commenter' && (int) $row['team_id'] === $teamId
    && $row['opened_at'] !== null && $row['closed_at'] === null);

foreach (array('owner', 'editor', 'viewer') as $role) {
    $r = call_api('note_view_start.php', $uid[$role], array('record_id' => $recordId));
    check($role . ': sessiz no-op (view_id null)',
        $r['status'] === 200 && array_key_exists('view_id', (array) $r['json']) && $r['json']['view_id'] === null, $r['body']);
}

$n = (int) bcc_fetch_column('SELECT COUNT(*) FROM record_view_log WHERE record_id = :r', array('r' => $recordId));
check('owner/editor/viewer HIC satir yazmadi (toplam hala 1)', $n === 1, 'toplam=' . $n);

$r = call_api('note_view_start.php', $uid['commenter'], array('record_id' => 99999999));
check('olmayan record_id -> 404', $r['status'] === 404);

$r = call_api('note_view_start.php', $uid['commenter'], array('record_id' => $recordId), 'POST', false);
check('CSRF yok -> 403', $r['status'] === 403);

$r = call_api('note_view_start.php', $uid['commenter'], array('record_id' => $recordId), 'GET');
check('GET -> 405', $r['status'] === 405);

bcc_execute('UPDATE records SET deleted_at = NOW() WHERE id = :id', array('id' => $otherRecordId));
$r = call_api('note_view_start.php', $uid['commenter'], array('record_id' => $otherRecordId));
check('silinmis kayit -> 404', $r['status'] === 404);
bcc_execute('UPDATE records SET deleted_at = NULL WHERE id = :id', array('id' => $otherRecordId));

$r = call_api('note_view_start.php', $uid['commenter'], array('record_id' => $recordId));
$secondViewId = isset($r['json']['view_id']) ? (int) $r['json']['view_id'] : 0;
check('ayni not ikinci kez -> AYRI satir', $secondViewId > 0 && $secondViewId !== $viewId);

echo "\n=== E) note_view_end.php ===\n";

bcc_execute('UPDATE record_view_log SET opened_at = NOW() - INTERVAL 138 SECOND WHERE id = :id', array('id' => $viewId));
$r = call_api('note_view_end.php', $uid['commenter'], array('view_id' => $viewId));

$dur = isset($r['json']['duration_seconds']) ? $r['json']['duration_seconds'] : null;
check('kapanis: sure sunucuda hesaplandi (~138 sn)',
    $r['status'] === 200 && $dur !== null && $dur >= 138 && $dur <= 145, 'olculen=' . var_export($dur, true));
$frozenDuration = (int) $dur;

$r = call_api('note_view_end.php', $uid['commenter'], array('view_id' => $viewId));
$after = bcc_fetch_one('SELECT duration_seconds FROM record_view_log WHERE id = :id', array('id' => $viewId));
check('ayni satir ikinci kez kapatilamaz (sure DONDU)',
    $r['json']['duration_seconds'] === null && (int) $after['duration_seconds'] === $frozenDuration);

$r = call_api('note_view_end.php', $uid['owner'], array('view_id' => $secondViewId));
$other = bcc_fetch_one('SELECT closed_at FROM record_view_log WHERE id = :id', array('id' => $secondViewId));
check('baskasinin view_id\'si kapatilamaz (satir DEGISMEDI)',
    $r['json']['duration_seconds'] === null && $other['closed_at'] === null);

bcc_execute('INSERT INTO record_view_log (record_id,user_id,team_id,role_at_view,opened_at)
             VALUES (:r,:u,:t,\'commenter\', NOW() - INTERVAL 5 HOUR)',
    array('r' => $recordId, 'u' => $uid['commenter'], 't' => $teamId));
$longId = (int) bcc_last_insert_id();
$r = call_api('note_view_end.php', $uid['commenter'], array('view_id' => $longId));
check('5 saatlik inceleme 14400 sn\'ye kirpiliyor', $r['json']['duration_seconds'] === 14400, $r['body']);

bcc_execute('INSERT INTO record_view_log (record_id,user_id,team_id,role_at_view,opened_at)
             VALUES (:r,:u,:t,\'commenter\', NOW() - INTERVAL 9000 SECOND)',
    array('r' => $recordId, 'u' => $uid['commenter'], 't' => $teamId));
$midId = (int) bcc_last_insert_id();
$r = call_api('note_view_end.php', $uid['commenter'], array('view_id' => $midId));

$dur = isset($r['json']['duration_seconds']) ? $r['json']['duration_seconds'] : null;
check('9000 sn KIRPILMIYOR (LEAST sayisal karsilastiriyor)',
    $dur !== null && $dur >= 9000 && $dur <= 9010, 'olculen=' . var_export($dur, true));

$r = call_api('note_view_end.php', $uid['commenter'], array('view_id' => 99999999));
check('olmayan view_id -> sessiz basari', $r['status'] === 200 && $r['json']['duration_seconds'] === null);

$r = call_api('note_view_end.php', $uid['commenter'], array('view_id' => $viewId), 'POST', false);
check('CSRF yok -> 403', $r['status'] === 403);

echo "\n=== F) note_view_list.php ===\n";

bcc_execute('INSERT INTO record_view_log (record_id,user_id,team_id,role_at_view,opened_at,closed_at,duration_seconds)
             VALUES (:r,:u,:t,\'commenter\', NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 16 DAY, 45)',
    array('r' => $recordId, 'u' => $uid['commenter'], 't' => $teamId));
bcc_execute('INSERT INTO record_view_log (record_id,user_id,team_id,role_at_view,opened_at,closed_at,duration_seconds)
             VALUES (:r,:u,:t,\'commenter\', NOW() - INTERVAL 14 DAY, NOW() - INTERVAL 14 DAY, 45)',
    array('r' => $recordId, 'u' => $uid['commenter'], 't' => $teamId));

$r = call_api('note_view_list.php', $uid['owner'], array('record_id' => $recordId), 'GET');
check('owner: 200 + liste', $r['status'] === 200 && isset($r['json']['views']), $r['body']);
$views = isset($r['json']['views']) ? $r['json']['views'] : array();

$opened = array_column($views, 'opened_at');
$sorted = $opened;
rsort($sorted);
check('siralama: EN YENI EN USTTE', $opened === $sorted);

$has16 = false;
$has14 = false;
foreach ($views as $v) {
    $age = (time() - strtotime($v['opened_at'])) / 86400;
    if ($age > 15.5) { $has16 = true; }
    if ($age > 13.5 && $age < 15) { $has14 = true; }
}
check('16 gunluk satir listede YOK (15 gun siniri)', !$has16);
check('14 gunluk satir listede VAR', $has14);

foreach (array('commenter', 'editor', 'viewer') as $role) {
    $r = call_api('note_view_list.php', $uid[$role], array('record_id' => $recordId), 'GET');
    check($role . ': gecmisi goremiyor -> 403', $r['status'] === 403, $r['body']);
}

$durations = array();
foreach ($views as $v) {
    if ($v['duration_display'] !== null) { $durations[] = $v['duration_display']; }
}
check('sure bicimi "sn" (dakika alti) uretiliyor', (bool) preg_grep('/^\d+ sn$/', $durations), implode(' | ', $durations));
check('sure bicimi "dk .. sn" uretiliyor', (bool) preg_grep('/^\d+ dk \d{2} sn$/', $durations), implode(' | ', $durations));
check('sure bicimi "sa .. dk" uretiliyor', (bool) preg_grep('/^\d+ sa \d{2} dk$/', $durations), implode(' | ', $durations));

$openRows = array_filter($views, function ($v) { return $v['is_open'] === true; });
check('tamamlanmamis inceleme is_open=true ve suresi null',
    count($openRows) > 0 && current($openRows)['duration_seconds'] === null);

$r = call_api('note_view_list.php', $uid['owner'], array('record_id' => $otherRecordId), 'GET');
check('kaydi olmayan not -> bos dizi', $r['status'] === 200 && $r['json']['views'] === array());

$r = call_api('note_view_list.php', $uid['owner'], array('record_id' => 99999999), 'GET');
check('olmayan record_id -> 404', $r['status'] === 404);

echo "\n=== G) Arayuz baglantilari ===\n";

$ifPhp = file_get_contents(__DIR__ . '/../public/interface.php');
$ifJs = file_get_contents(__DIR__ . '/../public/assets/interface.js');
$ifCss = file_get_contents(__DIR__ . '/../public/assets/interface.css');

check('interface.php rol bayraklarini SUNUCUDA hesapliyor',
    strpos($ifPhp, 'bcc_is_representative($shareRole)') !== false
    && strpos($ifPhp, 'bcc_can_view_record_audits($shareRole)') !== false);
check('interface.php rolu IKINCI kez sorgulamiyor ($shareRole yeniden kullaniliyor)',
    substr_count($ifPhp, 'current_user_role_in_team(') === 1);
check('BCC_IF_TRACK_VIEWS JS\'e gomuluyor', strpos($ifPhp, 'var BCC_IF_TRACK_VIEWS =') !== false);
check('gecmis blogu SUNUCU tarafinda kapili (CSS ile gizlenmis degil)',
    strpos($ifPhp, 'if ($canViewNoteAudits):') !== false);

check('interface.js selectRow icinde onceki incelemeyi KAPATIYOR',
    preg_match('/function selectRow\(row\) \{\s*(\/\/[^\n]*\n\s*)*endNoteView\(\);/', $ifJs) === 1);
check('interface.js yeni incelemeyi baslatiyor', strpos($ifJs, 'startNoteView(row);') !== false);
check('interface.js sendBeacon kullaniyor (kapanis teslimati)', strpos($ifJs, 'navigator.sendBeacon') !== false);

check('interface.js beforeunload BAGLAMIYOR (mobilde guvenilmez)',
    preg_match('/addEventListener\(\s*[\'"]beforeunload[\'"]/', $ifJs) === 0
    && preg_match('/\bonbeforeunload\s*=/', $ifJs) === 0);
check('interface.js visibilitychange + pagehide dinliyor',
    strpos($ifJs, "'visibilitychange'") !== false && strpos($ifJs, "'pagehide'") !== false);
check('interface.js rol mantigini KOPYALAMIYOR (sunucu bayragina bakiyor)',
    strpos($ifJs, "=== 'commenter'") === false);
check('interface.js isimleri textContent ile basiyor (XSS yok)',
    strpos($ifJs, 'name.textContent = v.user_name') !== false);

check('interface.css .if-audit stilleri var', strpos($ifCss, '.if-audit-item-duration') !== false);
check('interface.css sabit renk yerine tema token\'i kullaniyor',
    preg_match('/\.if-audit \{[^}]*var\(--bcc-border\)/s', $ifCss) === 1);

echo "\n=== H) Temizlik ===\n";

$before = (int) bcc_fetch_column('SELECT COUNT(*) FROM record_view_log WHERE record_id = :r', array('r' => $recordId));
bcc_execute('DELETE FROM records WHERE id = :id', array('id' => $recordId));
$after = (int) bcc_fetch_column('SELECT COUNT(*) FROM record_view_log WHERE record_id = :r', array('r' => $recordId));
check('kayit silinince inceleme gecmisi de gidiyor (fk CASCADE)', $before > 0 && $after === 0);

echo "\n" . str_repeat('-', 60) . "\n";
$passed = count(array_filter($results));
$total = count($results);
echo ($passed === $total ? 'SONUC: ' : 'SONUC: DIKKAT — ') . $passed . '/' . $total . " kontrol gecti\n";
exit($passed === $total ? 0 : 1);
