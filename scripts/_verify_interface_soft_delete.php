<?php
// Duyuru arayuzu (interface.php / interface_search.php / interface_records.php)
// COP KUTUSUNDAKI kayitlari gostermemeli.
//
// BULUNAN GERCEK BUG: bcc_interface_fetch_records() "WHERE r.table_id = ?"
// diyordu, "AND r.deleted_at IS NULL" YOKTU. grid.php'nin sorgusu
// (bcc_build_grid_records_query) o filtreyi tasidigi icin kayit tabloda
// kayboluyor ama Duyuru ekraninda durmaya devam ediyordu.
//
// CALISTIRMA: C:/php73/php.exe scripts/_verify_interface_soft_delete.php
// Apache ayakta olmali. Kendi verisini kurar ve siler.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$BASE = 'http://localhost';
$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$COOKIE = tempnam(sys_get_temp_dir(), 'bccif');

function istek($url, $post = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $COOKIE, CURLOPT_COOKIEFILE => $COOKIE,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) { return null; }
    return array('code' => $code, 'body' => substr($raw, $hlen));
}

function jeton($url)
{
    $r = istek($url);
    return ($r && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m)) ? $m[1] : '';
}

function idler($body)
{
    $j = json_decode($body, true);
    return (is_array($j) && isset($j['record_ids'])) ? array_map('intval', $j['record_ids']) : null;
}

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

// --- izole ortam ---
$SON = bin2hex(random_bytes(4));
$SIFRE = 'IfTest!' . $SON;

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'IfTeam ' . $SON));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array('e' => "if.owner.$SON@bcc-test.local", 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => 'If Owner'));
$ownerId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)', array('t' => $teamId, 'u' => $ownerId, 'r' => 'owner'));

$baseId = bcc_create_base($teamId, 'IfBase ' . $SON, '', $ownerId)['id'];
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b,:n,0)', array('b' => $baseId, 'n' => 'Tablo'));
$tableId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:ft,0)',
    array('t' => $tableId, 'n' => 'Baslik', 'ft' => 'single_line_text'));
$fieldId = (int) bcc_last_insert_id();

$kayitlar = array();
foreach (array('Kalan Kayit', 'Silinecek Kayit') as $i => $ad) {
    bcc_execute('INSERT INTO records (table_id, position) VALUES (:t, :p)', array('t' => $tableId, 'p' => $i));
    $rid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)',
        array('r' => $rid, 'f' => $fieldId, 'v' => $ad));
    $kayitlar[$ad] = $rid;
}
$kalan = $kayitlar['Kalan Kayit'];
$silinecek = $kayitlar['Silinecek Kayit'];

echo "Ortam: tablo=$tableId, kayitlar=$kalan (kalacak) / $silinecek (silinecek)\n\n";

@unlink($COOKIE);
$tok = jeton($BASE . '/login.php');
istek($BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode("if.owner.$SON@bcc-test.local") . '&password=' . rawurlencode($SIFRE));

// ---------------------------------------------------------------------------
echo "A) SILMEDEN ONCE — iki kayit da gorunuyor\n";
// ---------------------------------------------------------------------------
$r = istek($BASE . '/api/interface_records.php?table_id=' . $tableId);
$ids = idler($r['body']);
check('interface_records 2 kayit', is_array($ids) && count($ids) === 2, is_array($ids) ? count($ids) : $r['body']);

$r = istek($BASE . '/api/interface_search.php?table_id=' . $tableId . '&q=' . rawurlencode('Kayit'));
$ids = idler($r['body']);
check('interface_search 2 kayit', is_array($ids) && count($ids) === 2, is_array($ids) ? count($ids) : $r['body']);

// ---------------------------------------------------------------------------
echo "\nB) COP KUTUSUNA ATILDIKTAN SONRA — silinmis kayit GORUNMEMELI\n";
// ---------------------------------------------------------------------------
bcc_execute('UPDATE records SET deleted_at = NOW() WHERE id = :i', array('i' => $silinecek));

$r = istek($BASE . '/api/interface_records.php?table_id=' . $tableId);
$ids = idler($r['body']);
check('interface_records 1 kayit', is_array($ids) && count($ids) === 1, is_array($ids) ? count($ids) : $r['body']);
check('silinmis kayit LISTEDE DEGIL', is_array($ids) && !in_array($silinecek, $ids, true));
check('kalan kayit listede', is_array($ids) && in_array($kalan, $ids, true));

$r = istek($BASE . '/api/interface_search.php?table_id=' . $tableId . '&q=' . rawurlencode('Kayit'));
$ids = idler($r['body']);
check('interface_search 1 kayit', is_array($ids) && count($ids) === 1, is_array($ids) ? count($ids) : $r['body']);
check('arama silinmis kaydi getirmiyor', is_array($ids) && !in_array($silinecek, $ids, true));

$r = istek($BASE . '/api/interface_search.php?table_id=' . $tableId . '&q=' . rawurlencode('Silinecek'));
$ids = idler($r['body']);
check('silinmis kaydi ADIYLA aramak da bos donuyor', is_array($ids) && count($ids) === 0, is_array($ids) ? count($ids) : $r['body']);

// ---------------------------------------------------------------------------
echo "\nC) grid.php'nin sorgusuyla AYNI sonuc\n";
// ---------------------------------------------------------------------------
list($sql, $p) = bcc_build_grid_records_query($tableId, array(), array(), array(), 'AND');
$gridIds = array_map('intval', array_column(bcc_fetch_all($sql, $p), 'id'));
check('grid ve interface ayni kayit kumesi', $gridIds === array($kalan), implode(',', $gridIds));

// ---------------------------------------------------------------------------
echo "\nD) LIKE joker karakterleri kacisiliyor\n";
// ---------------------------------------------------------------------------
$r = istek($BASE . '/api/interface_search.php?table_id=' . $tableId . '&q=' . rawurlencode('%'));
$ids = idler($r['body']);
check('"%" aramasi HER SEYI getirmiyor', is_array($ids) && count($ids) === 0, is_array($ids) ? count($ids) : $r['body']);

// --- temizlik ---
bcc_execute('DELETE FROM cell_values WHERE record_id IN (:a, :b)', array('a' => $kalan, 'b' => $silinecek));
bcc_execute('DELETE FROM records WHERE table_id = :t', array('t' => $tableId));
bcc_execute('DELETE FROM fields WHERE table_id = :t', array('t' => $tableId));
bcc_execute('DELETE FROM tables_meta WHERE id = :t', array('t' => $tableId));
bcc_execute('DELETE FROM bases WHERE id = :b', array('b' => $baseId));
bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $teamId));
bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $teamId));
bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));
bcc_execute('DELETE FROM login_attempts WHERE email LIKE :e', array('e' => "if.%.$SON@bcc-test.local"));
bcc_execute('DELETE FROM users WHERE id = :u', array('u' => $ownerId));
@unlink($COOKIE);

$k = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :t', array('t' => $teamId))
   + (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE id = :u', array('u' => $ownerId));
check('test verisi tamamen silindi', $k === 0, $k);

echo "\n" . str_repeat('-', 50) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
