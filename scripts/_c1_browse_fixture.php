<?php
// Grup C1 tarayici testi icin GECICI fikstur. "setup" kurar, "teardown" siler.
// Gercek/canli hicbir hesaba DOKUNMAZ — kendi test kullanicisini yaratir.
// Calistirma: C:\php73\php.exe scripts\_c1_browse_fixture.php setup|teardown

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('TEST_EMAIL', 'groupc1.browse@bcc-test.local');
define('TEST_PASS', 'GroupC1Browse!2026');

$mode = isset($argv[1]) ? $argv[1] : '';

function teardown()
{
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => TEST_EMAIL)
    ), 'id');
    foreach ($baseIds as $baseId) {
        bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $baseId));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));
}

if ($mode === 'teardown') {
    teardown();
    echo "Temizlik tamam.\n";
    exit(0);
}

if ($mode !== 'setup') {
    echo "Kullanim: _c1_browse_fixture.php setup|teardown\n";
    exit(1);
}

teardown();

$team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
$teamId = (int) $team['id'];

bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'GrupC1 Browse'));
$userId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));

bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array(':t' => $teamId, ':n' => 'GrupC1 Browse', ':u' => $userId));
$baseId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array(':b' => $baseId, ':n' => 'C1 Gorsel'));
$tableId = (int) bcc_last_insert_id();

$defs = array(
    array('Ad', 'single_line_text', null),
    array('Miktar', 'number', null),
    array('Fiyat', 'currency', json_encode(array('currency_symbol' => '₺', 'decimal_places' => 2), JSON_UNESCAPED_UNICODE)),
    array('Oran', 'percent', json_encode(array('decimal_places' => 0), JSON_UNESCAPED_UNICODE)),
    array('Puan', 'rating', json_encode(array('max_rating' => 7), JSON_UNESCAPED_UNICODE)),
    array('OlusturmaZamani', 'created_time', null),
    array('Olusturan', 'created_by', null),
);
$fieldIds = array();
foreach ($defs as $i => $d) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, :o, :p)',
        array(':t' => $tableId, ':n' => $d[0], ':ft' => $d[1], ':o' => $d[2], ':p' => $i));
    $fieldIds[$d[0]] = (int) bcc_last_insert_id();
}

$rows = array(
    array('Elma', 10, 1234.5, 0.45, 5),
    array('Armut', 20, 99.9, 0.8, 3),
    array('Kiraz', 30, 0, 0.07, 0),
);
foreach ($rows as $i => $r) {
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)', array(':t' => $tableId, ':p' => $i, ':u' => $userId));
    $rid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)', array(':r' => $rid, ':f' => $fieldIds['Ad'], ':v' => $r[0]));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, :v)', array(':r' => $rid, ':f' => $fieldIds['Miktar'], ':v' => $r[1]));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, :v)', array(':r' => $rid, ':f' => $fieldIds['Fiyat'], ':v' => $r[2]));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, :v)', array(':r' => $rid, ':f' => $fieldIds['Oran'], ':v' => $r[3]));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, :v)', array(':r' => $rid, ':f' => $fieldIds['Puan'], ':v' => $r[4]));
}

echo "EMAIL=" . TEST_EMAIL . "\n";
echo "PASS=" . TEST_PASS . "\n";
echo "TABLE_ID={$tableId}\n";
echo "PUAN_FIELD_ID={$fieldIds['Puan']}\n";
echo "FIYAT_FIELD_ID={$fieldIds['Fiyat']}\n";
