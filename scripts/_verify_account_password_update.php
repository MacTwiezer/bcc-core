<?php
// api/account_update_password.php: sifre degisince bekleyen sifirlama token'i
// temizleniyor mu ve oturum kimligi yenileniyor mu?
//
// CALISTIRMA: C:/php73/php.exe scripts/_verify_account_password_update.php
// Apache ayakta olmali. KENDI test kullanicisini kurar ve siler; gercek
// hesaplara DOKUNMAZ.

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

$COOKIE = tempnam(sys_get_temp_dir(), 'bccpw');

function istek($url, $post = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $COOKIE,
        CURLOPT_COOKIEFILE => $COOKIE,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) { return null; }

    $head = substr($raw, 0, $hlen);
    preg_match_all('/^set-cookie:\s*PHPSESSID=([^;]+)/mi', $head, $m);

    return array(
        'code' => $code,
        'body' => substr($raw, $hlen),
        'sid'  => !empty($m[1]) ? end($m[1]) : null,
    );
}

function jeton($url)
{
    $r = istek($url);
    return ($r && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m)) ? $m[1] : '';
}

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

// --- kendi test kullanicimiz ---
$SON   = bin2hex(random_bytes(4));
$EMAIL = "pwtest.$SON@bcc-test.local";
$ESKI  = 'EskiSifre!' . $SON;
$YENI  = 'YeniSifre!' . $SON;

bcc_execute(
    'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array('e' => $EMAIL, 'h' => password_hash($ESKI, PASSWORD_DEFAULT), 'n' => 'Sifre Testi')
);
$UID = (int) bcc_last_insert_id();
echo "Test kullanicisi: $EMAIL (id=$UID)\n\n";

// --- bekleyen bir sifirlama token'i yerlestir ---
$TOKEN = bin2hex(random_bytes(32));
bcc_execute(
    'UPDATE users SET password_reset_token = :t, password_reset_expires_at = :e WHERE id = :id',
    array('t' => hash('sha256', $TOKEN), 'e' => date('Y-m-d H:i:s', time() + 3600), 'id' => $UID)
);
$var = bcc_fetch_column('SELECT password_reset_token IS NOT NULL FROM users WHERE id = :id', array('id' => $UID));
check('bekleyen sifirlama token\'i kuruldu', (int) $var === 1, $var);

// --- giris ---
$tok = jeton($BASE . '/login.php');
$r = istek($BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode($EMAIL) . '&password=' . rawurlencode($ESKI));
check('giris yapildi (302)', $r['code'] === 302, $r['code']);
$sidOnce = $r['sid'];

// ---------------------------------------------------------------------------
echo "\nA) Red yollari\n";
// ---------------------------------------------------------------------------
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/account_update_password.php', 'new_password=' . rawurlencode($YENI));
check('CSRF\'siz -> 403', $r['code'] === 403, $r['code']);

$r = istek($BASE . '/api/account_update_password.php',
    'csrf_token=' . $tok . '&current_password=yanlis&new_password=' . rawurlencode($YENI) . '&confirm_password=' . rawurlencode($YENI));
check('yanlis mevcut sifre -> 422', $r['code'] === 422, $r['code']);

$r = istek($BASE . '/api/account_update_password.php',
    'csrf_token=' . $tok . '&current_password=' . rawurlencode($ESKI) . '&new_password=kisa&confirm_password=kisa');
check('kisa yeni sifre -> 422', $r['code'] === 422, $r['code']);

$r = istek($BASE . '/api/account_update_password.php',
    'csrf_token=' . $tok . '&current_password=' . rawurlencode($ESKI) . '&new_password=' . rawurlencode($YENI) . '&confirm_password=baska');
check('tekrar eslesmiyor -> 422', $r['code'] === 422, $r['code']);

// ---------------------------------------------------------------------------
echo "\nB) Basarili degisiklik\n";
// ---------------------------------------------------------------------------
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/account_update_password.php',
    'csrf_token=' . $tok . '&current_password=' . rawurlencode($ESKI) . '&new_password=' . rawurlencode($YENI) . '&confirm_password=' . rawurlencode($YENI));
check('200 dondu', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 60));

$hash = bcc_fetch_column('SELECT password_hash FROM users WHERE id = :id', array('id' => $UID));
check('yeni sifre gecerli', password_verify($YENI, $hash));
check('eski sifre ARTIK gecersiz', !password_verify($ESKI, $hash));

// ---------------------------------------------------------------------------
echo "\nC) DUZELTME 1 — bekleyen sifirlama token'i temizlendi mi?\n";
// ---------------------------------------------------------------------------
$row = bcc_fetch_one('SELECT password_reset_token, password_reset_expires_at FROM users WHERE id = :id', array('id' => $UID));
check('password_reset_token NULL', $row['password_reset_token'] === null, var_export($row['password_reset_token'], true));
check('password_reset_expires_at NULL', $row['password_reset_expires_at'] === null, var_export($row['password_reset_expires_at'], true));

// ---------------------------------------------------------------------------
echo "\nD) DUZELTME 2 — oturum kimligi yenilendi mi?\n";
// ---------------------------------------------------------------------------
check('yanit yeni PHPSESSID gonderdi', $r['sid'] !== null && $r['sid'] !== $sidOnce,
    'once=' . substr((string) $sidOnce, 0, 10) . ' sonra=' . substr((string) $r['sid'], 0, 10));

// --- temizlik ---
bcc_execute('DELETE FROM audit_log WHERE user_id = :id', array('id' => $UID));
bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $EMAIL));
bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $UID));
$kalan = (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE id = :id', array('id' => $UID));
check('test kullanicisi silindi', $kalan === 0, $kalan);
@unlink($COOKIE);

echo "\n" . str_repeat('-', 50) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
