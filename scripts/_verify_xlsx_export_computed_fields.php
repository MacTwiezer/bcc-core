<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/xlsx_reader.php';

$BASE = 'http://localhost';
$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

function istek($jar, $url, $post = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return $raw === false ? array('code' => 0, 'body' => '') : array('code' => $code, 'body' => substr($raw, $hlen));
}

$r = istek(tempnam(sys_get_temp_dir(), 'bccxc'), $BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$sayim = function () {
    $o = array();
    foreach (array('users', 'teams', 'team_members', 'bases', 'tables_meta', 'fields', 'records', 'cell_values', 'audit_log') as $t) {
        $o[$t] = (int) bcc_fetch_column('SELECT COUNT(*) FROM ' . $t);
    }
    return $o;
};
$once = $sayim();

$SON = bin2hex(random_bytes(4));
$SIFRE = 'Xlsx!' . $SON;
$K = array();
foreach (array('A' => 'Ayla Olusturan', 'B' => 'Bora Degistiren') as $k => $ad) {
    $email = 'xlsxcalc.' . strtolower($k) . ".$SON@bcc-test.local";
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array('e' => $email, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $ad));
    $K[$k] = array('id' => (int) bcc_last_insert_id(), 'email' => $email, 'ad' => $ad, 'jar' => tempnam(sys_get_temp_dir(), 'bccxc'));
}

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "XLSXCALC-$SON"));
$T = (int) bcc_last_insert_id();
bcc_execute("INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, 'owner')", array('t' => $T, 'u' => $K['A']['id']));
bcc_execute("INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, 'editor')", array('t' => $T, 'u' => $K['B']['id']));
bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array('t' => $T, 'n' => "XC Base $SON", 'u' => $K['A']['id']));
$BID = (int) bcc_last_insert_id();

$cleanup = function () use (&$K, $T, $BID) {
    bcc_execute('DELETE FROM bases WHERE id = :id', array('id' => $BID));
    foreach ($K as $k) {
        bcc_execute('DELETE FROM audit_log WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $k['email']));
        bcc_execute('DELETE FROM team_members WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $k['id']));
        @unlink($k['jar']);
    }
    bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $T));
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $T));
};
register_shutdown_function($cleanup);

bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array('b' => $BID, 'n' => 'Kayitlar'));
$TB = (int) bcc_last_insert_id();
$alanlar = array(
    'Gorev' => 'single_line_text',
    'Sorumlu' => 'user',
    'Olusturan' => 'created_by',
    'Son degistiren' => 'last_modified_by',
    'Olusturulma zamani' => 'created_time',
    'Son degisiklik zamani' => 'last_modified_time',
    'Numara' => 'autonumber',
);
$F = array();
$pos = 0;
foreach ($alanlar as $ad => $tip) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)', array('t' => $TB, 'n' => $ad, 'ft' => $tip, 'p' => $pos++));
    $F[$ad] = (int) bcc_last_insert_id();
}

bcc_execute(
    "INSERT INTO records (table_id, position, created_by, updated_by, created_at, updated_at, slack_notified_at)
     VALUES (:t, 0, :a, :b, '2026-09-01 09:15:00', '2026-09-02 17:40:00', NOW())",
    array('t' => $TB, 'a' => $K['A']['id'], 'b' => $K['B']['id'])
);
$R1 = (int) bcc_last_insert_id();
bcc_execute(
    "INSERT INTO records (table_id, position, created_by, updated_by, created_at, updated_at, slack_notified_at)
     VALUES (:t, 1, :a, NULL, '2026-09-03 08:00:00', '2026-09-03 08:00:00', NOW())",
    array('t' => $TB, 'a' => $K['A']['id'])
);
$R2 = (int) bcc_last_insert_id();
foreach (array(array($R1, 'Birinci', $K['B']['id'], 1), array($R2, 'Ikinci', null, 2)) as $s) {
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)', array('r' => $s[0], 'f' => $F['Gorev'], 'v' => $s[1]));
    if ($s[2] !== null) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, :v)', array('r' => $s[0], 'f' => $F['Sorumlu'], 'v' => $s[2]));
    }
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, :v)', array('r' => $s[0], 'f' => $F['Numara'], 'v' => $s[3]));
}

$A = $K['A'];
$lp = istek($A['jar'], $BASE . '/login.php');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $lp['body'], $m);
$r = istek($A['jar'], $BASE . '/login.php', 'csrf_token=' . (isset($m[1]) ? $m[1] : '') . '&email=' . rawurlencode($A['email']) . '&password=' . rawurlencode($SIFRE));
check('A giris yapti (302)', $r['code'] === 302, $r['code']);

$x = istek($A['jar'], $BASE . '/api/view_export_xlsx.php?table_id=' . $TB);
check('Excel indir 200', $x['code'] === 200 && substr($x['body'], 0, 2) === 'PK', 'HTTP ' . $x['code']);
$tmp = sys_get_temp_dir() . '/bcc_xlsxcalc_' . getmypid() . '.xlsx';
file_put_contents($tmp, $x['body']);
$rows = bcc_xlsx_read_first_sheet($tmp);
@unlink($tmp);
$rows = is_array($rows) ? array_values($rows) : array();
check('3 satir (baslik + 2 kayit)', count($rows) === 3, count($rows));

$baslik = isset($rows[0]) ? array_values($rows[0]) : array();
$kol = array_flip($baslik);
$hucre = function ($satir, $ad) use ($rows, $kol) {
    if (!isset($kol[$ad]) || !isset($rows[$satir])) {
        return null;
    }
    $vals = array_values($rows[$satir]);
    return isset($vals[$kol[$ad]]) ? (string) $vals[$kol[$ad]] : '';
};

echo "\nA) Kayittan hesaplanan alanlar (cell_values'ta degil, records kolonlarinda)\n";
check('A) Olusturan = Ayla Olusturan', $hucre(1, 'Olusturan') === 'Ayla Olusturan', var_export($hucre(1, 'Olusturan'), true));
check('A) Son degistiren = Bora Degistiren', $hucre(1, 'Son degistiren') === 'Bora Degistiren', var_export($hucre(1, 'Son degistiren'), true));
check('A) Son degistiren, hic duzenlenmemis kayitta olusturana duser', $hucre(2, 'Son degistiren') === 'Ayla Olusturan', var_export($hucre(2, 'Son degistiren'), true));
check('A) Olusturulma zamani = 01.09.2026 09:15', $hucre(1, 'Olusturulma zamani') === '01.09.2026 09:15', var_export($hucre(1, 'Olusturulma zamani'), true));
check('A) Son degisiklik zamani = 02.09.2026 17:40', $hucre(1, 'Son degisiklik zamani') === '02.09.2026 17:40', var_export($hucre(1, 'Son degisiklik zamani'), true));

echo "\nB) cell_values'ta duran alanlar bozulmadi\n";
check('B) Gorev = Birinci', $hucre(1, 'Gorev') === 'Birinci', var_export($hucre(1, 'Gorev'), true));
check('B) Sorumlu (kullanici alani) = Bora Degistiren', $hucre(1, 'Sorumlu') === 'Bora Degistiren', var_export($hucre(1, 'Sorumlu'), true));
check('B) bos kullanici alani bos', $hucre(2, 'Sorumlu') === '', var_export($hucre(2, 'Sorumlu'), true));
check('B) Numara (otomatik numara) = 1 / 2', $hucre(1, 'Numara') === '1' && $hucre(2, 'Numara') === '2', var_export(array($hucre(1, 'Numara'), $hucre(2, 'Numara')), true));

echo "\nC) Kaynak: disa aktarma grid ile ayni hucre cozucusunu kullaniyor\n";
$src = (string) file_get_contents(__DIR__ . '/../public/api/view_export_xlsx.php');
check('C) bcc_cell_row_for_field kullaniliyor', strpos($src, "bcc_cell_row_for_field(\$f['field_type'], \$rec, \$cellsByRecord, \$f['id'])") !== false);
check('C) cell_values dizisine dogrudan bakilmiyor', strpos($src, '$cellsForRecord[$f[\'id\']]') === false);

$cleanup();
$cleanup = function () {};
$sonra = $sayim();
check('Kirlilik: tum sayaclar oncekiyle ayni', $once === $sonra, json_encode(array_diff_assoc($sonra, $once)));

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
