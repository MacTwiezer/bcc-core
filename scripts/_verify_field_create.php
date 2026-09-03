<?php
// api/field_create.php: yetki, dogrulama ve COP KUTUSUNDAKI base korumasi.
//
// CALISTIRMA: C:/php73/php.exe scripts/_verify_field_create.php
// Apache ayakta olmali. Kendi ekip/kullanici/base'ini kurar ve siler.

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

$COOKIE = tempnam(sys_get_temp_dir(), 'bccfc');

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
$SIFRE = 'FcTest!' . $SON;

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'FcTeam ' . $SON));
$teamId = (int) bcc_last_insert_id();

foreach (array('owner', 'editor') as $rol) {
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
        array('e' => "fc.$rol.$SON@bcc-test.local", 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => "Fc $rol"));
    ${$rol . 'Id'} = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)',
        array('t' => $teamId, 'u' => ${$rol . 'Id'}, 'r' => $rol));
}

$aktifBase = bcc_create_base($teamId, 'FcAktif ' . $SON, '', $ownerId)['id'];
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b,:n,0)', array('b' => $aktifBase, 'n' => 'Tablo'));
$aktifTable = (int) bcc_last_insert_id();

$copBase = bcc_create_base($teamId, 'FcCop ' . $SON, '', $ownerId)['id'];
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b,:n,0)', array('b' => $copBase, 'n' => 'Tablo'));
$copTable = (int) bcc_last_insert_id();
bcc_execute('UPDATE bases SET deleted_at = NOW() WHERE id = :i', array('i' => $copBase));

// Temizlik BURADA baglanir, sonda degil: ekip adi ve e-postalar rastgele ek
// tasiyor (FcTeam <hex>), yani betik ortada olurse (Apache dusmesi, fatal,
// Ctrl+C) kalan ekip/kullanicilari SONRAKI kosu de bulamaz.
// bases/tables_meta/fields ayrica silinmiyor: FK zinciri CASCADE, ekip
// silinince hepsi kendiliginden gider.
$cleanup = function () use ($teamId, $ownerId, $editorId, $SON, $COOKIE) {
    bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM login_attempts WHERE email LIKE :e', array('e' => "fc.%.$SON@bcc-test.local"));
    bcc_execute('DELETE FROM users WHERE id IN (:a, :b)', array('a' => $ownerId, 'b' => $editorId));
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "Ortam: aktif tablo=$aktifTable, cop kutusundaki tablo=$copTable\n\n";

function girisYap($BASE, $email, $sifre) {
    global $COOKIE; @unlink($COOKIE);
    $t = jeton($BASE . '/login.php');
    istek($BASE . '/login.php', 'csrf_token=' . $t . '&email=' . rawurlencode($email) . '&password=' . rawurlencode($sifre));
}

// ---------------------------------------------------------------------------
echo "A) EDITOR sutun ekleyemez (owner gerekir)\n";
// ---------------------------------------------------------------------------
girisYap($BASE, "fc.editor.$SON@bcc-test.local", $SIFRE);
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/field_create.php', 'csrf_token=' . $tok . '&table_id=' . $aktifTable . '&name=X&field_type=single_line_text');
check('editor -> 403', $r['code'] === 403, $r['code'] . ' ' . substr($r['body'], 0, 60));

// ---------------------------------------------------------------------------
echo "\nB) OWNER aktif tabloya sutun ekler\n";
// ---------------------------------------------------------------------------
girisYap($BASE, "fc.owner.$SON@bcc-test.local", $SIFRE);
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/field_create.php', 'csrf_token=' . $tok . '&table_id=' . $aktifTable . '&name=Musteri&field_type=single_line_text');
check('owner -> 200', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 80));
check('alan gercekten olustu', (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :t', array('t' => $aktifTable)) === 1);

$r = istek($BASE . '/api/field_create.php', 'csrf_token=' . $tok . '&table_id=' . $aktifTable . '&name=Musteri&field_type=single_line_text');
check('ayni isim tekrar -> 422', $r['code'] === 422, $r['code'] . ' ' . substr($r['body'], 0, 70));

$r = istek($BASE . '/api/field_create.php', 'csrf_token=' . $tok . '&table_id=' . $aktifTable . '&name=&field_type=single_line_text');
check('bos isim -> 422', $r['code'] === 422, $r['code']);

$r = istek($BASE . '/api/field_create.php', 'csrf_token=' . $tok . '&table_id=' . $aktifTable . '&name=X&field_type=uydurma_tip');
check('gecersiz alan tipi -> 422', $r['code'] === 422, $r['code'] . ' ' . substr($r['body'], 0, 70));

// ---------------------------------------------------------------------------
echo "\nC) DUZELTME — COP KUTUSUNDAKI base'in tablosuna sutun EKLENEMEZ\n";
// ---------------------------------------------------------------------------
$r = istek($BASE . '/api/field_create.php', 'csrf_token=' . $tok . '&table_id=' . $copTable . '&name=Sizinti&field_type=single_line_text');
check('cop kutusundaki tablo -> 404', $r['code'] === 404, $r['code'] . ' ' . substr($r['body'], 0, 80));
check('yanit JSON', json_decode($r['body'], true) !== null, substr($r['body'], 0, 60));
check('cop kutusundaki tabloya alan EKLENMEDI',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :t', array('t' => $copTable)) === 0);

$r = istek($BASE . '/api/field_create.php', 'csrf_token=' . $tok . '&table_id=99999999&name=X&field_type=single_line_text');
check('olmayan tablo -> 404', $r['code'] === 404, $r['code']);

// --- temizlik ---
$cleanup();

$kalan = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :t', array('t' => $teamId))
       + (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE id IN (:a, :b)', array('a' => $ownerId, 'b' => $editorId));
check('test verisi tamamen silindi', $kalan === 0, $kalan);

echo "\n" . str_repeat('-', 50) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
