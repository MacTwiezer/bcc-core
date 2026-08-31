<?php
// Toplu satir ekleme UI'si TARAYICI testi icin GECICI fikstur.
// "setup" kurar, "teardown" siler. Gercek/canli hicbir hesaba veya base'e
// DOKUNMAZ — kendi takimini, kullanicisini ve base'ini yaratir
// (_colresize_browse_fixture.php ile AYNI desen, ikinci bir mekanizma yok).
//
// Calistirma: C:\php73\php.exe scripts\_bulkadd_browse_fixture.php setup|teardown

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('TEST_EMAIL', 'bulkadd.browse@bcc-test.local');
define('TEST_PASS', 'BulkAddBrowse!2026');
define('TEST_TEAM', 'ZZ Bulk Add Browse');

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
    bcc_execute('DELETE FROM teams WHERE name = :n', array(':n' => TEST_TEAM));
}

if ($mode === 'teardown') {
    teardown();
    echo "Temizlik tamam.\n";
    exit(0);
}

if ($mode !== 'setup') {
    echo "Kullanim: _bulkadd_browse_fixture.php setup|teardown\n";
    exit(1);
}

teardown();

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEST_TEAM));
$teamId = (int) bcc_last_insert_id();

bcc_execute(
    'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'BulkAdd Browse')
);
$userId = (int) bcc_last_insert_id();
bcc_execute(
    'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
    array(':t' => $teamId, ':u' => $userId, ':r' => 'owner')
);

bcc_execute(
    'INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
    array(':t' => $teamId, ':n' => 'BulkAdd Browse', ':u' => $userId)
);
$baseId = (int) bcc_last_insert_id();

bcc_execute(
    'INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
    array(':b' => $baseId, ':n' => 'Katilimcilar')
);
$tableId = (int) bcc_last_insert_id();

$fields = array(
    array('Ad Soyad', 'single_line_text'),
    array('E-posta', 'email'),
    array('Puan', 'number'),
);
foreach ($fields as $i => $f) {
    bcc_execute(
        'INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)',
        array(':t' => $tableId, ':n' => $f[0], ':ft' => $f[1], ':p' => $i)
    );
}

// Renkli tekli-secim alani: dark temada .choice-chip okunabilirligi ancak
// gercek renkli rozetlerle gorulur.
$durumOptions = json_encode(array(
    'choices' => array('Yeni', 'Gorusuluyor', 'Kazanildi', 'Kaybedildi'),
    'colors' => array('Yeni' => 'blue', 'Gorusuluyor' => 'yellow', 'Kazanildi' => 'green', 'Kaybedildi' => 'red'),
), JSON_UNESCAPED_UNICODE);
bcc_execute(
    'INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, :o, :p)',
    array(':t' => $tableId, ':n' => 'Durum', ':ft' => 'single_select', ':o' => $durumOptions, ':p' => 3)
);
$durumFieldId = (int) bcc_last_insert_id();

// Birkac dolu satir: "+" satirinin veri satirlariyla ayni yukseklikte olup
// olmadigi ancak ustunde gercek satirlar varken gorunur.
$primaryFieldId = (int) bcc_fetch_column(
    'SELECT id FROM fields WHERE table_id = :t ORDER BY position, id LIMIT 1',
    array(':t' => $tableId)
);
$names = array('Ayse Yilmaz', 'Mehmet Demir', 'Zeynep Kaya');
foreach ($names as $i => $name) {
    bcc_execute(
        'INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
        array(':t' => $tableId, ':p' => $i, ':u' => $userId)
    );
    $recordId = (int) bcc_last_insert_id();
    bcc_execute(
        'INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
        array(':r' => $recordId, ':f' => $primaryFieldId, ':v' => $name)
    );
    $durumlar = array('Yeni', 'Gorusuluyor', 'Kazanildi');
    bcc_execute(
        'INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
        array(':r' => $recordId, ':f' => $durumFieldId, ':v' => $durumlar[$i % 3])
    );
}

echo "Fikstur hazir.\n";
echo 'E-posta : ' . TEST_EMAIL . "\n";
echo 'Sifre   : ' . TEST_PASS . "\n";
echo 'Grid    : http://localhost/grid.php?table_id=' . $tableId . "\n";
echo 'table_id: ' . $tableId . "\n";
