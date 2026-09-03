<?php
// Grup A tarayici testi icin GECICI fikstur. "setup" kurar, "teardown" siler.
// Gercek/canli hicbir hesaba DOKUNMAZ — kendi test kullanicisini yaratir.
// Calistirma: C:\php73\php.exe scripts\_a_browse_fixture.php setup|teardown

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('TEST_EMAIL', 'groupa.browse@bcc-test.local');
// Sifre HER KURULUMDA YENIDEN URETILIR, depoda sabit DURMAZ. Bu fikstur
// gercek "TY" ekibinde owner yetkili bir hesap aciyor; depo acik oldugu icin
// sabit bir sifre, teardown unutuldugunda yayinlanmis kimlik bilgisi anlamina
// gelirdi. Deger zaten asagida ekrana basiliyor, saklanmasina gerek yok.
define('TEST_PASS', 'bcc-' . bin2hex(random_bytes(9)));

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
    echo "Kullanim: _a_browse_fixture.php setup|teardown\n";
    exit(1);
}

teardown();

// TY yoksa $team false doner; kontrolsuz $team['id'] $teamId'yi 0 yapar ve
// kullanici/base team_id=0 ile yaratilirdi (hicbir ekibe ait olmayan cop veri).
// _colresize_browse_fixture.php ile AYNI kontrol.
$teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'GrupA Browse'));
$userId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
    array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));

bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
    array(':t' => $teamId, ':n' => 'GrupA Browse', ':u' => $userId));
$baseId = (int) bcc_last_insert_id();

bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
    array(':b' => $baseId, ':n' => 'A Gorsel'));
$tableId = (int) bcc_last_insert_id();

$mkField = function ($name, $type, $pos) use ($tableId) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, NULL, :p)',
        array(':t' => $tableId, ':n' => $name, ':ft' => $type, ':p' => $pos));
    return (int) bcc_last_insert_id();
};

$fAd = $mkField('Ad', 'single_line_text', 0);
$fUrl = $mkField('Site', 'url', 1);
$fMail = $mkField('Eposta', 'email', 2);
$fTel = $mkField('Telefon', 'phone', 3);

// Satir 1: hepsi GECERLI -> ikon cikmali
// Satir 2: hepsi GECERSIZ -> ikon CIKMAMALI (XSS degeri dahil)
$rows = array(
    array('Gecerli', 'https://example.com', 'ali@ornek.com', '0212 555 00 00'),
    array('Gecersiz', 'javascript:alert(1)', 'gecersiz-eposta', '555'),
);

foreach ($rows as $i => $vals) {
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
        array(':t' => $tableId, ':p' => $i, ':u' => $userId));
    $recordId = (int) bcc_last_insert_id();

    foreach (array($fAd, $fUrl, $fMail, $fTel) as $k => $fieldId) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
            array(':r' => $recordId, ':f' => $fieldId, ':v' => $vals[$k]));
    }
}

// VIEW_ID BILEREK BASILMIYOR: varsayilan gorunumu uygulama TEMBEL olusturur
// (src/schema.php, grid ilk acildiginda), veritabaninda tetikleyici yok ve bu
// fikstur tablosunu ham SQL ile yaratiyor — yani burada views tablosunda HENUZ
// satir yok. Eskiden buradaki sorgu HER ZAMAN 0 donuyor ve "VIEW_ID=0"
// basiyordu; o degerle kurulan URL calismaz. Gorunum id'si gerekiyorsa
// grid.php bir kez acildiktan SONRA okunmali.

echo "TABLE_ID=" . $tableId . "\n";
echo "URL_FIELD_ID=" . $fUrl . "\n";
echo "MAIL_FIELD_ID=" . $fMail . "\n";
echo "TEL_FIELD_ID=" . $fTel . "\n";
echo "EMAIL=" . TEST_EMAIL . "\n";
echo "PASS=" . TEST_PASS . "\n";
echo "GRID=http://localhost/grid.php?table_id=" . $tableId . "\n";
