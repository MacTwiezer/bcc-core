<?php
// Sutun genisligi surukle-boyutlandirma TARAYICI testi icin GECICI fikstur.
// "setup" kurar, "teardown" siler. Gercek/canli hicbir hesaba veya base'e
// DOKUNMAZ — kendi test kullanicisini ve kendi base'ini yaratir
// (_a_browse_fixture.php ile AYNI desen, ikinci bir mekanizma yok).
//
// Calistirma: C:\php73\php.exe scripts\_colresize_browse_fixture.php setup|teardown

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('TEST_EMAIL', 'colresize.browse@bcc-test.local');
define('TEST_PASS', 'ColResizeBrowse!2026');

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
    echo "Kullanim: _colresize_browse_fixture.php setup|teardown\n";
    exit(1);
}

teardown();

$teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
if (!$teamId) {
    echo "HATA: TY ekibi yok.\n";
    exit(1);
}

bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'ColResize Browse'));
$userId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
    array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));

bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
    array(':t' => $teamId, ':n' => 'ColResize Browse', ':u' => $userId));
$baseId = (int) bcc_last_insert_id();

bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
    array(':b' => $baseId, ':n' => 'Genislik'));
$tableId = (int) bcc_last_insert_id();

$fieldIds = array();
$names = array('Ad', 'Kod', 'Not', 'Etiket');
foreach ($names as $pos => $name) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)',
        array(':t' => $tableId, ':n' => $name, ':ft' => 'single_line_text', ':p' => $pos));
    $fieldIds[$name] = (int) bcc_last_insert_id();
}

// 12 satir: govde yeterince uzun olsun (tam boy tutamac govdede de test edilecek).
for ($i = 0; $i < 12; $i++) {
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
        array(':t' => $tableId, ':p' => $i, ':u' => $userId));
    $recordId = (int) bcc_last_insert_id();
    foreach ($names as $k => $name) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
            array(':r' => $recordId, ':f' => $fieldIds[$name], ':v' => $name . ' ' . ($i + 1)));
    }
}

echo "TABLE_ID=" . $tableId . "\n";
echo "AD_FIELD_ID=" . $fieldIds['Ad'] . "\n";
echo "KOD_FIELD_ID=" . $fieldIds['Kod'] . "\n";
echo "EMAIL=" . TEST_EMAIL . "\n";
echo "PASS=" . TEST_PASS . "\n";
echo "GRID=http://localhost/grid.php?table_id=" . $tableId . "\n";
