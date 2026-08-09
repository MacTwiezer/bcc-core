<?php
// Grid disa aktarma (PDF/PNG) tarayici testi icin GECICI fikstur.
// "setup" kurar, "teardown" siler. Gercek/canli hicbir hesaba ve gercek
// base'e (id 15) DOKUNMAZ -- kendi test kullanicisini ve kendi base'ini yaratir.
// Calistirma: C:\php73\php.exe scripts\_export_browse_fixture.php setup|teardown

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('TEST_EMAIL', 'export.browse@bcc-test.local');
define('TEST_PASS', 'ExportBrowse!2026');

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
    echo "Kullanim: _export_browse_fixture.php setup|teardown\n";
    exit(1);
}

teardown();

$teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'Export Browse'));
$ownerId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
    array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));

bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
    array(':t' => $teamId, ':n' => 'Export Browse Test', ':u' => $ownerId));
$baseId = (int) bcc_last_insert_id();

$mkTable = function ($name, $pos) use ($baseId) {
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, :p)', array(':b' => $baseId, ':n' => $name, ':p' => $pos));
    return (int) bcc_last_insert_id();
};
$mkField = function ($tableId, $name, $pos) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)',
        array(':t' => $tableId, ':n' => $name, ':ft' => 'single_line_text', ':p' => $pos));
    return (int) bcc_last_insert_id();
};
$mkRecord = function ($tableId, $pos) use ($ownerId) {
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
        array(':t' => $tableId, ':p' => $pos, ':u' => $ownerId));
    return (int) bcc_last_insert_id();
};
$setCell = function ($rid, $fid, $val) {
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
        array(':r' => $rid, ':f' => $fid, ':v' => $val));
};

// --- COK SATIRLI + COK SUTUNLU tablo: sayfa bolme + tekrarlayan baslik +
//     landscape testleri icin. 8 alan, 90 kayit (birden fazla A4 sayfasi).
$tMulti = $mkTable('Cok Satirli', 0);
$names = array('Ad', 'Sehir', 'Departman', 'Unvan', 'Telefon', 'Adres', 'Notlar', 'Durum');
$fids = array();
foreach ($names as $i => $n) { $fids[$n] = $mkField($tMulti, $n, $i); }

$sehirler = array('Ankara', 'Istanbul', 'Izmir');
for ($i = 0; $i < 90; $i++) {
    $rid = $mkRecord($tMulti, $i);
    $setCell($rid, $fids['Ad'], 'Kisi ' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT));
    $setCell($rid, $fids['Sehir'], $sehirler[$i % 3]);
    $setCell($rid, $fids['Departman'], 'Dept ' . ($i % 4));
    $setCell($rid, $fids['Unvan'], 'Unvan ' . $i);
    $setCell($rid, $fids['Telefon'], '0555' . str_pad((string) $i, 7, '0', STR_PAD_LEFT));
    $setCell($rid, $fids['Adres'], 'Adres satiri ' . $i);
    $setCell($rid, $fids['Notlar'], 'Not ' . $i);
    $setCell($rid, $fids['Durum'], ($i % 2 === 0) ? 'Aktif' : 'Pasif');
}

// --- KUCUK tablo: PNG'nin ekranla gorsel karsilastirmasi icin (tek ekrana sigar)
$tSmall = $mkTable('Kucuk', 1);
$sAd = $mkField($tSmall, 'Ad', 0);
$sKod = $mkField($tSmall, 'Kod', 1);
for ($i = 0; $i < 5; $i++) {
    $rid = $mkRecord($tSmall, $i);
    $setCell($rid, $sAd, 'Satir ' . ($i + 1));
    $setCell($rid, $sKod, 'K-' . ($i + 1));
}

// --- BUYUK tablo: PNG uyari diyalogu (esik 500) icin 520 kayit
$tBig = $mkTable('Buyuk', 2);
$bAd = $mkField($tBig, 'Ad', 0);
bcc_begin_transaction();
for ($i = 0; $i < 520; $i++) { $setCell($mkRecord($tBig, $i), $bAd, 'Buyuk satir ' . $i); }
bcc_commit();

echo "E-posta: " . TEST_EMAIL . "\n";
echo "Sifre  : " . TEST_PASS . "\n";
echo "COK_SATIRLI_TABLE_ID=" . $tMulti . "\n";
echo "KUCUK_TABLE_ID=" . $tSmall . "\n";
echo "BUYUK_TABLE_ID=" . $tBig . "\n";
echo "SEHIR_FIELD_ID=" . $fids['Sehir'] . "\n";
echo "TELEFON_FIELD_ID=" . $fids['Telefon'] . "\n";
echo "NOTLAR_FIELD_ID=" . $fids['Notlar'] . "\n";
