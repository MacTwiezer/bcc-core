<?php
// "Paylas" modali TARAYICI testi icin GECICI fikstur. "setup" kurar,
// "teardown" siler. Gercek/canli hicbir hesaba, ekibe veya base'e DOKUNMAZ —
// KENDI ekibini yaratir (_a_browse_fixture.php ile AYNI desen).
//
// Calistirma: C:\php73\php.exe scripts\_share_modal_browse_fixture.php setup|teardown

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('TEAM_NAME', 'ShareModal Browse');
define('TEST_PASS', 'ShareModalBrowse!2026');

$emails = array(
    'owner'   => 'sm.browse.owner@bcc-test.local',
    'editor'  => 'sm.browse.editor@bcc-test.local',
    'viewer'  => 'sm.browse.viewer@bcc-test.local',
    'pending' => 'sm.browse.pending@bcc-test.local',
    'free'    => 'sm.browse.free@bcc-test.local',
);

$mode = isset($argv[1]) ? $argv[1] : '';

function teardown($emails)
{
    $teamIds = array_column(bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => TEAM_NAME)), 'id');
    foreach ($teamIds as $tid) {
        $baseIds = array_column(bcc_fetch_all('SELECT id FROM bases WHERE team_id = :t', array(':t' => $tid)), 'id');
        foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
        bcc_execute('DELETE FROM team_members WHERE team_id = :t', array(':t' => $tid));
        bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $tid));
    }
    foreach ($emails as $e) {
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $e));
    }
}

if ($mode === 'teardown') {
    teardown($emails);
    echo "Temizlik tamam.\n";
    exit(0);
}

if ($mode !== 'setup') {
    echo "Kullanim: _share_modal_browse_fixture.php setup|teardown\n";
    exit(1);
}

teardown($emails);

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM_NAME));
$teamId = (int) bcc_last_insert_id();

$ids = array();
$mkUser = function ($key, $email, $name, $role, $isActive) use ($teamId, &$ids) {
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, :a)',
        array(':e' => $email, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => $name, ':a' => $isActive));
    $uid = (int) bcc_last_insert_id();
    $ids[$key] = $uid;
    if ($role !== null) {
        bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
            array(':t' => $teamId, ':u' => $uid, ':r' => $role));
    }
    return $uid;
};

$mkUser('owner',   $emails['owner'],   'Browse Owner',   'owner',     1);
$mkUser('editor',  $emails['editor'],  'Browse Editor',  'editor',    1);
$mkUser('viewer',  $emails['viewer'],  'Browse Viewer',  'viewer',    1);
// is_active = 0 -> "Bekleyen davetler" sekmesinde gorunmeli
$mkUser('pending', $emails['pending'], 'Browse Pending', 'commenter', 0);
// Ekipte DEGIL -> davet kutusunun <datalist> onerilerinde gorunmeli
$mkUser('free',    $emails['free'],    'Browse Free',    null,        1);

bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
    array(':t' => $teamId, ':n' => 'ShareModal Browse Base', ':u' => $ids['owner']));
$baseId = (int) bcc_last_insert_id();

bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
    array(':b' => $baseId, ':n' => 'Tablo'));
$tableId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
    array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));

echo "TEAM_ID=" . $teamId . "\n";
echo "TABLE_ID=" . $tableId . "\n";
foreach ($ids as $k => $v) { echo strtoupper($k) . "_ID=" . $v . "\n"; }
echo "PASS=" . TEST_PASS . "\n";
echo "GRID=http://localhost/grid.php?table_id=" . $tableId . "\n";
