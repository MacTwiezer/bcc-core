<?php
// base_delete / base_restore ucusu + geri yuklemede isim cakismasi korumasi.
//
// CALISTIRMA: C:/php73/php.exe scripts/_verify_base_trash_flow.php
// Apache ayakta olmali. KENDI ekip/kullanici/base'ini kurar ve siler.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

// Bu betik gercek uc noktalardan yaziyor; olusan denetim satirlari test
// kullanicisi silinince audit_log'da OKSUZ kaliyordu. Kapanista yalnizca bu
// kosunun urettigi ve aktoru artik var olmayan satirlar temizlenir.
require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

$BASE = 'http://localhost';
$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$COOKIE = tempnam(sys_get_temp_dir(), 'bccbt');

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

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

// --- izole ortam ---
$SON = bin2hex(random_bytes(4));
$SIFRE = 'BtTest!' . $SON;
$AD = 'BtBase ' . $SON;

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'BtTeam ' . $SON));
$teamId = (int) bcc_last_insert_id();

foreach (array('owner', 'editor') as $rol) {
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
        array('e' => "bt.$rol.$SON@bcc-test.local", 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => "Bt $rol"));
    $id = (int) bcc_last_insert_id();
    ${$rol . 'Id'} = $id;
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)', array('t' => $teamId, 'u' => $id, 'r' => $rol));
}

$baseId = bcc_create_base($teamId, $AD, '', $ownerId)['id'];

// Temizlik BURADA baglanir, sonda degil: ekip adi ve e-postalar rastgele ek
// tasiyor (BtTeam <hex>), yani betik ortada olurse (Apache dusmesi, fatal,
// Ctrl+C) kalan ekip/kullanicilari SONRAKI kosu de bulamaz.
// Base'ler ayrica silinmiyor: bases.team_id -> teams FK'si CASCADE, ekip
// silinince test sirasinda olusturulan TUM base'ler (sonradan eklenen ikinci
// base dahil) kendiliginden gider.
$cleanup = function () use ($teamId, $ownerId, $editorId, $SON, $COOKIE) {
    bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM login_attempts WHERE email LIKE :e', array('e' => "bt.%.$SON@bcc-test.local"));
    bcc_execute('DELETE FROM users WHERE id IN (:a, :b)', array('a' => $ownerId, 'b' => $editorId));
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);
echo "Ortam: team=$teamId base=$baseId ('$AD')\n\n";

function girisYap($BASE, $email, $sifre) {
    global $COOKIE; @unlink($COOKIE);
    $t = jeton($BASE . '/login.php');
    istek($BASE . '/login.php', 'csrf_token=' . $t . '&email=' . rawurlencode($email) . '&password=' . rawurlencode($sifre));
}

// ---------------------------------------------------------------------------
echo "A) EDITOR silemez (owner gerekir)\n";
// ---------------------------------------------------------------------------
girisYap($BASE, "bt.editor.$SON@bcc-test.local", $SIFRE);
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/base_delete.php', 'csrf_token=' . $tok . '&base_id=' . $baseId);
check('editor silme -> 403', $r['code'] === 403, $r['code'] . ' ' . substr($r['body'], 0, 60));

// ---------------------------------------------------------------------------
echo "\nB) OWNER siler (soft-delete)\n";
// ---------------------------------------------------------------------------
girisYap($BASE, "bt.owner.$SON@bcc-test.local", $SIFRE);
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/base_delete.php', 'csrf_token=' . $tok . '&base_id=' . $baseId);
check('owner silme -> 200', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 60));
$row = bcc_fetch_one('SELECT deleted_at, deleted_by FROM bases WHERE id = :i', array('i' => $baseId));
check('deleted_at dolu (gercek DELETE degil)', $row && $row['deleted_at'] !== null);
check('deleted_by = silen kullanici', $row && (int) $row['deleted_by'] === $ownerId, $row ? $row['deleted_by'] : 'yok');

$r = istek($BASE . '/api/base_delete.php', 'csrf_token=' . $tok . '&base_id=' . $baseId);
check('ikinci silme -> 404 (zaten silinmis)', $r['code'] === 404, $r['code']);

// ---------------------------------------------------------------------------
echo "\nC) DUZELTME — cakisan isimle geri yukleme REDDEDILIR\n";
// ---------------------------------------------------------------------------
$ikinciId = bcc_create_base($teamId, $AD, '', $ownerId)['id'];
check('cop kutusundakiyle AYNI adla yeni base acilabildi', $ikinciId > 0, $ikinciId);

$r = istek($BASE . '/api/base_restore.php', 'csrf_token=' . $tok . '&base_id=' . $baseId);
check('geri yukleme -> 422 (isim cakismasi)', $r['code'] === 422, $r['code'] . ' ' . substr($r['body'], 0, 80));
$aktif = bcc_fetch_all('SELECT id FROM bases WHERE team_id = :t AND name = :n AND deleted_at IS NULL', array('t' => $teamId, 'n' => $AD));
check('ayni isimli AKTIF base sayisi 1', count($aktif) === 1, count($aktif));

// ---------------------------------------------------------------------------
echo "\nD) Cakisma giderilince geri yukleme CALISIR\n";
// ---------------------------------------------------------------------------
bcc_execute('UPDATE bases SET name = :n WHERE id = :i', array('n' => $AD . ' (2)', 'i' => $ikinciId));
$r = istek($BASE . '/api/base_restore.php', 'csrf_token=' . $tok . '&base_id=' . $baseId);
check('geri yukleme -> 200', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 60));
$row = bcc_fetch_one('SELECT deleted_at FROM bases WHERE id = :i', array('i' => $baseId));
check('deleted_at NULL oldu', $row && $row['deleted_at'] === null);

// --- temizlik ---
$cleanup();

$kalan = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :t', array('t' => $teamId))
       + (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :t', array('t' => $teamId));
check('test verisi tamamen silindi', $kalan === 0, $kalan);

echo "\n" . str_repeat('-', 50) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
