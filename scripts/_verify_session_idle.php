<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

/* Bosta kalma siniri (2026-09-29). Musteri: temsilci surekli logout olup tekrar
   giris yapmasin. Onceden PHP varsayilani (24 dk GC, ortak sistem klasoru)
   gecerliydi; artik oturumlar storage/sessions'ta ve sinir
   BCC_SESSION_IDLE_SECONDS (8 saat). Betik oturum dosyasindaki son istek
   zamanini geriye cekip Apache uzerinden ne oldugunu olcer. */

require __DIR__ . '/../src/bootstrap.php';
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

$COOKIE = tempnam(sys_get_temp_dir(), 'bccsi');

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
    return array('code' => $code, 'head' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen));
}

function oturumId()
{
    global $COOKIE;
    foreach (file($COOKIE) as $satir) {
        $p = explode("\t", trim($satir));
        if (count($p) === 7 && $p[5] === session_name()) { return $p[6]; }
    }
    return '';
}

/* Oturum dosyasindaki bcc_last_request degerini $saniye oncesine ceker. */
function geriCek($saniye)
{
    $dosya = __DIR__ . '/../storage/sessions/sess_' . oturumId();
    if (!is_file($dosya)) { return false; }
    $icerik = file_get_contents($dosya);
    $yeni = preg_replace('/bcc_last_request\|i:\d+;/', 'bcc_last_request|i:' . (time() - $saniye) . ';', $icerik, 1, $n);
    if ($n !== 1) { return false; }
    return file_put_contents($dosya, $yeni) !== false;
}

function girisli()
{
    global $BASE;
    $r = istek($BASE . '/dashboard.php');
    return $r && $r['code'] === 200;
}

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$SON = bin2hex(random_bytes(4));
$MAIL = 'si.user.' . $SON . '@bcc-test.local';
$SIFRE = 'SiTest!' . $SON;

$temizle = function () use ($MAIL, $COOKIE) {
    $u = bcc_fetch_one('SELECT id FROM users WHERE email = :e', array('e' => $MAIL));
    if ($u) {
        bcc_execute('DELETE FROM audit_log WHERE user_id = :i', array('i' => $u['id']));
        bcc_execute('DELETE FROM users WHERE id = :i', array('i' => $u['id']));
    }
    bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $MAIL));
    if (is_file($COOKIE)) {
        $sid = oturumId();
        if ($sid !== '') { @unlink(__DIR__ . '/../storage/sessions/sess_' . $sid); }
        @unlink($COOKIE);
    }
};
register_shutdown_function($temizle);

bcc_execute(
    'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array('e' => $MAIL, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => 'Si Test')
);

function girisYap()
{
    global $BASE, $MAIL, $SIFRE, $COOKIE;
    file_put_contents($COOKIE, '');
    $r = istek($BASE . '/login.php');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
    istek($BASE . '/login.php', 'csrf_token=' . (isset($m[1]) ? $m[1] : '') . '&email=' . urlencode($MAIL) . '&password=' . urlencode($SIFRE));
}

echo "PHP " . PHP_VERSION . " - oturum bosta kalma siniri testi\n\n";

echo "A) Yapilandirma\n";
check('A) sinir 8 saat', BCC_SESSION_IDLE_SECONDS === 28800, (string) BCC_SESSION_IDLE_SECONDS);
check('A) storage/sessions klasoru var', is_dir(__DIR__ . '/../storage/sessions'));

echo "\nB) Oturum dosyasi kendi klasorumuzde\n";
girisYap();
check('B) giris yapildi', girisli());
$sid = oturumId();
check('B) oturum dosyasi storage/sessions altinda', $sid !== '' && is_file(__DIR__ . '/../storage/sessions/sess_' . $sid), $sid);
check('B) son istek zamani yaziliyor',
    $sid !== '' && strpos((string) @file_get_contents(__DIR__ . '/../storage/sessions/sess_' . $sid), 'bcc_last_request|i:') !== false);

echo "\nC) Eski 24 dakikalik sinirin cok otesinde oturum DUSMUYOR\n";
check('C) 2 saat bosta (dosya geri cekildi)', geriCek(2 * 3600));
check('C) 2 saat sonra hala girisli', girisli());
check('C) 7 saat 50 dk bosta (dosya geri cekildi)', geriCek(7 * 3600 + 50 * 60));
check('C) 7 saat 50 dk sonra hala girisli', girisli());

echo "\nD) 8 saati asinca oturum dusuyor\n";
check('D) 8 saat 5 dk bosta (dosya geri cekildi)', geriCek(8 * 3600 + 5 * 60));
$r = istek($BASE . '/dashboard.php');
check('D) dashboard girise yonlendiriyor', $r && $r['code'] === 302 && stripos($r['head'], 'login.php') !== false,
    $r ? $r['code'] : 'null');
check('D) sonraki istekte de girissiz', !girisli());

echo "\nE) Yeniden giris calisiyor\n";
girisYap();
check('E) tekrar giris yapildi', girisli());

echo "\nSonuc: $gecti gecti, $kaldi kaldi\n";
exit($kaldi > 0 ? 1 : 0);
