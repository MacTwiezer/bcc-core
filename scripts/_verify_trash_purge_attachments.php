<?php
// COP KUTUSU 7 GUNLUK OTOMATIK KALICI SILME — dosya ekleri de DISKTEN gidiyor mu?
//
// ⚠️ BULUNAN GERCEK SIZINTI (QA turunda olculdu, bu betik onun regresyonu):
// api/trash_records_list.php `DELETE FROM records WHERE id IN (...)` yapiyor
// ama hicbir dosya temizligi CAGIRMIYORDU. attachments satirlari ON DELETE
// CASCADE ile gidiyor, FIZIKSEL DOSYALAR diskte kaliyordu -- ve projede oksuz
// dosyalari supuren baska hicbir mekanizma YOK. Yani kullaniciya "kalici
// silindi" denen kaydin eki sunucuda sonsuza dek duruyordu (depolama sinirsiz
// buyur + "silindi" sozu tam tutulmaz). Diger silme yollari (record_delete,
// table_delete, table_clear_data, alan silme) bu temizligi ZATEN yapiyordu;
// yalnizca bu yol atlanmisti.
//
// Kapsam:
//   A) Toplu yardimci var ve DB satirini SILMIYOR (cascade'in isi)
//   B) Purge, dosya temizligini SILME SORGUSUNDAN ONCE cagiriyor (sira kritik)
//   C) Butun silme yollari dosya temizligi yapiyor (aile regresyonu)
//   D) CANLI: 7 gunu DOLDURMUS kayit -> DB satiri VE dosya gidiyor
//   E) CANLI: 7 gunu DOLDURMAMIS kayit -> ne kayit ne dosya siliniyor
//   F) CANLI: BASKA kaydin dosyasina DOKUNULMUYOR
//
// ⚠️ GERCEK HESAPLARA/VERIYE DOKUNMAZ: kendi ekibini kurar, sonunda siler.
//
// On kosul: Apache ayakta. Calistirma:
//   C:\php73\php.exe scripts\_verify_trash_purge_attachments.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

// Bu betik gercek uc noktalardan yaziyor; olusan denetim satirlari test
// kullanicisi silinince audit_log'da OKSUZ kaliyordu. Kapanista yalnizca bu
// kosunun urettigi ve aktoru artik var olmayan satirlar temizlenir.
require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

define('BASE_URL', 'http://localhost');
define('MAIL', 'tpa.owner@bcc-test.local');
define('PASS', 'TpaTest!2026');
define('TEAM', 'TPA Test Alani');

$results = array();
function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) { echo '         detay: ' . $detail . "\n"; }
}

function http_request($method, $path, $cookie = null, $post = null)
{
    $h = array();
    if ($cookie !== null) { $h[] = 'Cookie: ' . $cookie; }
    $o = array('http' => array('method' => $method, 'ignore_errors' => true));
    if ($method === 'POST') {
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
        $o['http']['content'] = http_build_query($post);
    }
    $o['http']['header'] = implode("\r\n", $h);
    $b = @file_get_contents(BASE_URL . $path, false, stream_context_create($o));
    $st = 0; $nc = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $x) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $x, $m)) { $st = (int) $m[1]; }
            if (stripos($x, 'Set-Cookie:') === 0) { $p = explode(';', substr($x, 11)); $nc = trim($p[0]); }
        }
    }
    return array('body' => (string) $b, 'cookie' => $nc, 'status' => $st);
}

// ⚠️ Yorumlar ayiklanir: "su cagri var mi" kontrolleri, cagrinin adini
// ACIKLAMA YORUMUNDA gecen bir satir yuzunden yanlis GECTI verebilir.
function strip_php_comments($s)
{
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    return preg_replace('#^\s*//.*$#m', '', $s);
}

$root = dirname(__DIR__);
$purgeSrc = file_get_contents($root . '/public/api/trash_records_list.php');
$purgeLive = strip_php_comments($purgeSrc);
$schemaLive = strip_php_comments(file_get_contents($root . '/src/schema.php'));

// =====================================================================
echo "\n--- A) Toplu yardimci ---\n";
check('A) bcc_delete_attachment_files_by_records tanimli',
    strpos($schemaLive, 'function bcc_delete_attachment_files_by_records') !== false);
check('A) tek sorguda topluyor (kayit basina cagri DEGIL)',
    preg_match('#function bcc_delete_attachment_files_by_records[\s\S]{0,600}?WHERE record_id IN#', $schemaLive) === 1);
check('A) DB satirini SILMIYOR (cascade in isi)',
    preg_match('#function bcc_delete_attachment_files_by_records[\s\S]{0,600}?DELETE FROM attachments#', $schemaLive) === 0);
check('A) bos dizide sorgu ACMIYOR',
    preg_match('#function bcc_delete_attachment_files_by_records[\s\S]{0,300}?if \(!\$ids\)#', $schemaLive) === 1);

// =====================================================================
echo "\n--- B) SIRA: temizlik silme sorgusundan ONCE ---\n";
$posClean = strpos($purgeLive, 'bcc_delete_attachment_files_by_records(');
$posDelete = strpos($purgeLive, 'DELETE FROM records WHERE id IN');
check('B) purge dosya temizligini cagiriyor', $posClean !== false);
check('B) cagri DELETE ten ONCE geliyor (sonra olsaydi okuyacak satir kalmazdi)',
    $posClean !== false && $posDelete !== false && $posClean < $posDelete,
    'temizlik@' . var_export($posClean, true) . ' delete@' . var_export($posDelete, true));

// =====================================================================
echo "\n--- C) Aile regresyonu: TUM silme yollari temizliyor ---\n";
foreach (array(
    'public/api/record_delete.php' => 'bcc_delete_attachment_files_by_records(',
    'public/api/table_delete.php' => 'bcc_delete_attachment_files_by_table(',
    'public/base_tables.php' => 'bcc_delete_attachment_files_by_table(',
    'public/api/table_clear_data.php' => 'bcc_delete_attachment_files_by_table(',
    'public/table_fields.php' => 'bcc_delete_attachment_files_by_field(',
    'public/api/trash_records_list.php' => 'bcc_delete_attachment_files_by_records(',
) as $file => $needle) {
    check('C) ' . basename($file) . ' -> ' . rtrim($needle, '('),
        strpos(strip_php_comments(file_get_contents($root . '/' . $file)), $needle) !== false, $file);
}

// =====================================================================
// D-F) CANLI
// =====================================================================
$dir = bcc_attachment_storage_dir();
$wipe = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => TEAM)) as $r) {
        bcc_execute('DELETE FROM teams WHERE id = :i', array(':i' => $r['id']));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => MAIL));
    foreach (glob(bcc_attachment_storage_dir() . '/TPATEST*') as $f) { @unlink($f); }
};
$wipe();
register_shutdown_function($wipe);

try {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM));
    $team = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO users (email,password_hash,full_name,is_admin,is_active) VALUES (:e,:h,:n,0,1)',
        array(':e' => MAIL, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => 'TPA Owner'));
    $uid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)',
        array(':t' => $team, ':u' => $uid, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id,name,created_by) VALUES (:t,:n,:u)',
        array(':t' => $team, ':n' => 'TPA Base', ':u' => $uid));
    $base = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id,name,position) VALUES (:b,:n,0)',
        array(':b' => $base, ':n' => 'TPA Tablo'));
    $table = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id,name,field_type,position) VALUES (:t,:n,:ft,0)',
        array(':t' => $table, ':n' => 'Dosya', ':ft' => 'attachment'));
    $field = (int) bcc_last_insert_id();

    // Uc kayit: (1) 8 gunluk silinmis, (2) 2 gunluk silinmis, (3) hic silinmemis
    $mk = function ($label, $deletedSql) use ($table, $uid, $field, $dir) {
        bcc_execute('INSERT INTO records (table_id,position,created_by) VALUES (:t,0,:u)',
            array(':t' => $table, ':u' => $uid));
        $rid = (int) bcc_last_insert_id();
        $stored = 'TPATEST' . $label . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($dir . '/' . $stored, 'tpa ' . $label);
        bcc_execute('INSERT INTO attachments (field_id,record_id,original_name,stored_name,mime_type,file_size,uploaded_by)
                     VALUES (:f,:r,:o,:s,"text/plain",8,:u)',
            array(':f' => $field, ':r' => $rid, ':o' => $label . '.txt', ':s' => $stored, ':u' => $uid));
        if ($deletedSql !== null) {
            bcc_execute("UPDATE records SET deleted_at = $deletedSql, deleted_by = :u WHERE id = :r",
                array(':u' => $uid, ':r' => $rid));
        }
        return array('id' => $rid, 'file' => $stored);
    };
    $expired = $mk('ESKI', 'DATE_SUB(NOW(), INTERVAL 8 DAY)');
    $fresh   = $mk('YENI', 'DATE_SUB(NOW(), INTERVAL 2 DAY)');
    $alive   = $mk('CANLI', null);

    // Giris + cop kutusunu ac (otomatik purge tetiklenir)
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $r['body'], $m);
    $r = http_request('POST', '/login.php', $c, array('email' => MAIL, 'password' => PASS, 'csrf_token' => isset($m[1]) ? $m[1] : ''));
    $c = $r['cookie'] ? $r['cookie'] : $c;
    $tr = http_request('GET', '/api/trash_records_list.php', $c);
    check('D) cop kutusu ucnoktasi 200', $tr['status'] === 200, 'HTTP ' . $tr['status']);

    $exists = function ($rid) { return (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE id = :r', array(':r' => $rid)) > 0; };

    echo "\n--- D) 7 gunu DOLDURMUS kayit ---\n";
    check('D) kayit DB den silindi', !$exists($expired['id']));
    check('D) FIZIKSEL DOSYA da silindi (asil duzeltme)',
        !is_file($dir . '/' . $expired['file']), $expired['file']);

    echo "\n--- E) 7 gunu DOLDURMAMIS kayit KORUNUYOR ---\n";
    check('E) kayit hala cop kutusunda', $exists($fresh['id']));
    check('E) dosyasi diskte DURUYOR', is_file($dir . '/' . $fresh['file']));

    echo "\n--- F) Silinmemis kaydin dosyasina DOKUNULMADI ---\n";
    check('F) kayit duruyor', $exists($alive['id']));
    check('F) dosyasi duruyor', is_file($dir . '/' . $alive['file']));

} catch (Throwable $e) {
    echo "\n[HATA] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $results[] = false;
}

$wipe();
check('Z) test verisi temizlendi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => TEAM)) === 0
    && count(glob($dir . '/TPATEST*')) === 0);

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($pass === $total ? 'GECTI' : 'KALDI') . " ($pass/$total)\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
