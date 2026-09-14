<?php

/*
 * Profil fotografi uctan uca dogrulamasi — 2026-09-14.
 *
 * Gercek HTTP istekleriyle (Apache, localhost): yukleme, reddetme yollari,
 * sunucu tarafi meta veri ayiklama (EXIF/GPS, PNG metin parcalari), KVKK ekip
 * izolasyonu (baska ekipten biri fotografi goremez), sayfalarda gorunme ve
 * kaldirma.
 *
 * Kendi test kullanicilarini/ekiplerini kurar, sonunda HEPSINI siler: kullanici,
 * ekip, uyelik, audit satirlari, giris denemeleri, fotograf dosyalari. Slack'e
 * dokunan hicbir olay tetiklenmedigi icin webhooklar susturulmaz.
 */

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

function istek($jar, $url, $post = null, $method = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($method !== null) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) {
        return array('code' => 0, 'head' => '', 'body' => '');
    }
    return array('code' => $code, 'head' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen));
}

function baslik($r, $ad)
{
    return preg_match('/^' . preg_quote($ad, '/') . ':\s*(.+?)\r?$/mi', $r['head'], $m) ? trim($m[1]) : null;
}

function jeton($jar, $url)
{
    global $BASE;
    $r = istek($jar, $BASE . $url);
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m)) {
        return $m[1];
    }
    return preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
}

function yukle($jar, $csrf, $bytes, $ad, $tip)
{
    global $BASE;
    $tmp = tempnam(sys_get_temp_dir(), 'bccav');
    file_put_contents($tmp, $bytes);
    $post = array('file' => new CURLFile($tmp, $tip, $ad));
    if ($csrf !== null) {
        $post['csrf_token'] = $csrf;
    }
    $r = istek($jar, $BASE . '/api/avatar_upload.php', $post);
    @unlink($tmp);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

/* Dosyayi Apache sureci yaziyor/siliyor; bu CLI surecinin stat onbellegi
   bundan haberdar olmaz ve ayni yol icin eski sonucu dondurur. Her kontrolde
   onbellek bosaltiliyor. */
function diskte_var($yol)
{
    clearstatcache(true, $yol);
    return is_file($yol);
}

function png_parca($tip, $veri)
{
    return pack('N', strlen($veri)) . $tip . $veri . pack('N', crc32($tip . $veri));
}

function png_yap($w, $h, array $ekParcalar = array())
{
    $satir = "\x00" . str_repeat("\x1a\x56\xdb", $w);
    $ham = str_repeat($satir, $h);
    $png = "\x89PNG\r\n\x1A\n" . png_parca('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0));
    foreach ($ekParcalar as $p) {
        $png .= $p;
    }
    return $png . png_parca('IDAT', gzcompress($ham)) . png_parca('IEND', '');
}

/* Headless tarayicida <canvas>.toDataURL('image/jpeg', 0.9) ile uretilmis
   320x200 JPEG — yani arayuzun sunucuya gonderdigiyle AYNI turden bir dosya
   (APP0 JFIF + APP2 ICC profili tasiyor). */
$JPEG = base64_decode(str_replace(array("\n", "\r", ' '), '', '
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
KElEyhJRPaknKpQkoyUJKJ7Uo5bKElGSi5RPaknKpQkomULKANSTlUouUZKElE9qScvMsouUZKLlHSJq1WOVShJRMoWZJ7Uk5VKL
lGShJQBqSctlCSiZQsontSTlsoWUTKElE9qScqlFyjJRcontSjl9t927RdDb71vOxdcbTj1uDcUsej1VarNh9jm+EvwXKar08/jx
8z1xvnc/7NNbs+t0+37DW267JiqcGrnXam3ivj3a8NZGnw/g0fnngu8OWMmO6x5Iaqbl8OWvRp/A/Rzu1dsMdq/QmJavLL3/AG1T
g10c+d/8uXj5Ul+qZ67535Dv5At6n8XW51uX589R9Oa7pLf9fs25Ynh12izVhyw/mvivo1w0/k0b09sOt6n3vQ7Tt+F59brM04cW
NfGm+PP6L1b+CR6376XZD+P0OLrnbMDeo0ynBuMwuXWPniMn9Vvhv5NfI/ydyrsk8EajrrcsHvV4tPts2vh6ZMq/7V+VfMwryM3j
97Lh63q+y9g7onZvoNl0WDcdjrcdfjxTOfV1rdRDy3x71eGciS8/gkeUe8HpOitm64ybL0VtUaHS7eni1WpnU5c3ts3PvJeO64U+
nl8efkeu+8r2vT2XdDZMWjzKd+3NVg0cprxY1x7+X+qn5fVo/PiqrLdXdO7p+Kqp8tv5snnVv3dkHL7K7vXZth7Tu0jRbbrcLzbT
gitTrZVVPONeSnxTw1zTXo0/U9hLuq9lq/4X/vDVf4p/IdzDoP8AYPQmr6h1GPw6reMvGN1PDWGOUv1rxP8AQ+8cXVGgzdU6jp+c
qe44NLGrvHz/AKlVUr98/vRiW97ed3e/l+e/bj2ex2adpG57Rp8dY9uprUaNVTp+xr0XL83w058/PyP4SUey++f0J+1uk9v6m08c
59ryexzterw215/auP1Z43lE/l313qbxftndsoWUZKElE1qactlFyjJQkontSTlsoWUTKFlAGpRy2UIkTKElE9qScqlCSiZQsont
STlsoSUZKLlE9qScqlCSiZQkoA1KOVShZRMoSUT2pJy2UJKJlCSie1JOXmWUJKJlCSjpE1aqnKoQsomUJKJ7Uk5bKElGSi5QBqUc
qlCSvoZKElE9qScqlFyjJRcontSTlUoSUTKFmQDUo5VK4P7/ALFe0/Vdk3XWi3nE6vRU/Y63Ty/87hbXi8vmvVfVfU/gpQkom8u4
s3NknOr9XseTbOs+nJuXi3Dady0/KfrGXFc/+UwYjaOz/pNSvZ7dsu1aX4+U48UT/wCkfR3ch3/Xbr2b7lodVnebT7drfZaZV6xF
Qqc8/Llv9Te+3vmu23s62vRaXPWHT6/Xez1Mz/tJmHSl/TlJ/Y9f09L1l59vK/a/2lavtV651u9Z3UaXn2Wj09PyxYU/dX5v1f1Z
8B0v0/qequodt2fRpvU63PGCPLnjxPjnj5L1+x8VKPSHct6D/bPWmt6k1GLxafacXs8NNeXtrXHK+qnn+0jyt/mWbPmd3sPYdn0n
SnTuh23TTOHR6DTzin4JTM8c/uPFnTvbTd95quprzf5M1uqe3+vKWmbUQ/y5U1+p7O6v2XU9R9Mbntek172zUazBWCdYsftHi8S4
dKeVy+PqjzVPcU8L8ut/7p/+4Z+38vg0/fa9MdUbBpuq+nNx2jVyr02twXhr8qXHP29T80N+2PU9N75r9q1kONTo894Mia485fHP
5P1P012LQ6jbNl0Oj1erWv1WnwxiyapY/Z+1pJJ14eXxz8uWeQO+J0J+xetdJ1Fp8fGm3bH4czS8lmhJef5zx/ZYfyc/T2s3jrpe
t59lCSjJRcogtVc5bKElGShZQBqSctlCSjJRcontSTlSQkomUJKJ7Uk5VKElGShEie1KOWyhJRMoSUT2pJyqUJKMlCSgDUk5VKLS
MlFyie1JOVShJRkoSUT2pRy8yShJRMoWUdImrVU5VKLlGShJQBqSctlCSiZQsontSjlUoSUTKElE9qSctlCSiZQkontSTlUoWUTM
iSgDUk5VKLlGShJRP5FKOXtLuJeXRHUn9Iz/AAkX35/9Cum/6Qr+Gz6w7tfeB6d7Hend20G86LdNTm1eqWeK0GLHcqVCnh+LJPny
vkX3j+8B072w9PbToNm0W56XNpNU891r8WOJcuHPl4clefLJL79+7Oc+30BKP0W7uvQn8geyvadLlxey12sn8bqk0lXjtJpP8p8K
+x4C6P1O16Dqfa9TvWPPm2rBqIy6jFpomslxL58KVNLz4482vJs9jLvs9DTHhjaOoEkuEvw+BL+MYNWd/bKju/My/wAfb33nN47N
etlsOwaPbdWsOCb1OTXY8ltXXmpXhyTxxPD8/mfXC76/XD/3V0//ANPn/wAY+lesOo8/WHVW671qW3l12ovNxXrKb92fsuF9j4uU
A5OVZvzZQ4j19y9ndgPeT3XtO6tz7Jv2k27SZL07y6WtDjuPFUv3prx3XPk+Vx8mfYHeA6F/l72Y7ppMWJZNdpZ/GaXy8/HHm0vz
nxL7nhLobqfN0X1btO+YOXk0WonK5XrU+lT95bX3PW388/oi54rat+aa4a/D4OP4x4HOENPJt9XwovFx5eMpRco+X6v1W17h1Tum
q2XHnw7Vnz1l0+LUzM5Imnz4WpbXk20uH6JHxco9d5F1tZGd53VKElGShJRPalHLZQkomUJKJ7Uk5VKElEyhZRPaknLZQkoyUXKJ
7Uo5VKElEyhZQBqSctlCSjJQkontSTlsoSUTKElE9qScqlCyiZQkontSTl5klCSjJQko6RNWqxy1ISUTKElAGpJyqULKJlCSie1J
OVSi5RiRcontSjlUoSUTKFlAGpJyqUXKMlCSic1JOWyhJRMoWUAaknKpQkoyUXKJ7Uo5akJKMlCSie1IOWyhZRMoWUT2pRy2UXKM
SElE9qSctlCwiZQsoA1JOWyhJRkouUT2pJyqUJKJlCyie1KOWyhJRkoSUT2pJy2UJKJlCSgDUk5VKFlEyhJRPaknLZQkoyUXKJ7U
o5VKElGShJRPaknKpRcoyUXKJ7Uk5eZZQkoyUXKOkbVqqcqlCSjJQkontSjlUoSUTKElE9qSctlCSiZQsontSTlUoSUTKElAGpJy
2UJKMlFyie1KOVShZXoTK+gkrgntSTlUoSUTKElE9qSctlCSjJQsontSTlsoSUZKLlE9qUctlCSjJQkoA1JOVShJRkouUT2pJypI
SUTKElE9qScqlCSjJQkontSjlsoSUTKElE9qScqlCSiZQsoA1JOVSi5RkouUT2pJyqUJKJlCyie1JOWyhZRMoSUT2pRy2UJKJSFl
AGpJy8yShJRMoWUdImrVU5bK+gsomUJKJ7Uk5bKElEyhJRPalHKpQsrgmZElE9qScqlFyjJRaRPaknKpQkoyUJKANSjlUoSUTKEl
E9qSctlCSjJQkontSTlUoSUZKElE9qSctlFyjJQkoA1KOWyhZRkoSUT2pJy2UIkTKElE9qScqlCSiZQsontSTlsoSUYkXKJ7Uk5V
KElEyhZQBqUctlCyiZQkontSTlqQkomULKJ7Uk5bKFlEyhJRPaknKpRcoxIuUT2pRyqULKJlCSgDUk5eZJQsomUJKOkTVqqcqlFy
jJRcontSTlUoSUTKFmQDUk5VKElEyhJRPalHLZQkoyUJMk9qScqlCSiZQkontSTlUouUZKElE9qUctlCyiZQsontSTlsoSUTKElE
9qSctlCyiZQsoA1JOWyhJRkouUT2pJyqUJKJlCSie1KOVShJRkoSUT2pJy2UJKJlCSie1JOVShJRkoSUAaknLZQkoyUXKJ7Uk5VK
ElEyhZRPalHKpRcoyUJKJ7Uk5bKElEyhZQBqScqlCSiZQkon8ilHLzLKElHHHSR7aqHK5Qko44nvZJySULKOOJ72UcrlCSjjie9k
nJJQso44nvZJySUWkccT+TZJklCSjjifybJOSyhJRxwB7KOVyhJRxxPeyTksISUccT3sk5JKElHHE97JMkoSUccAeyjkkoSUccT3
skyShJRxxPeyTLKLlHHAHskyShJRxxPeyjkkoWUccT3sk5XKElHHE/k2ScklCyjjifybJOSSi5RxxP5Nkm//2Q==
'));

$r = istek(tempnam(sys_get_temp_dir(), 'bccav'), $BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$avDir = bcc_avatar_storage_dir();
$dirOnceVardi = is_dir($avDir);
$dosyaOnce = $dirOnceVardi ? count(glob($avDir . '/*')) : 0;
$kullaniciOnce = (int) bcc_fetch_column('SELECT COUNT(*) FROM users');
$ekipOnce = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams');

$SON = bin2hex(random_bytes(4));
$SIFRE = 'Avatar!' . $SON;
$kisiler = array();
foreach (array('A' => 'Foto Sahibi', 'B' => 'Ayni Ekip', 'C' => 'Baska Ekip') as $k => $ad) {
    $email = 'avtest.' . strtolower($k) . ".$SON@bcc-test.local";
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array('e' => $email, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $ad)
    );
    $kisiler[$k] = array('id' => (int) bcc_last_insert_id(), 'email' => $email, 'jar' => tempnam(sys_get_temp_dir(), 'bccav'));
}

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "AVTEST-1-$SON"));
$EKIP1 = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "AVTEST-2-$SON"));
$EKIP2 = (int) bcc_last_insert_id();

foreach (array(array($EKIP1, 'A'), array($EKIP1, 'B'), array($EKIP2, 'C')) as $uy) {
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $uy[0], 'u' => $kisiler[$uy[1]]['id'], 'r' => 'owner'));
}

$cleanup = function () use (&$kisiler, $EKIP1, $EKIP2, $avDir, $dirOnceVardi) {
    foreach ($kisiler as $k) {
        @unlink(bcc_avatar_path($k['id']));
        foreach ((array) glob(bcc_avatar_path($k['id']) . '.tmp-*') as $t) { @unlink($t); }
        bcc_execute('DELETE FROM audit_log WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $k['email']));
        bcc_execute('DELETE FROM team_members WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $k['id']));
        @unlink($k['jar']);
    }
    bcc_execute('DELETE FROM audit_log WHERE team_id IN (' . (int) $EKIP1 . ',' . (int) $EKIP2 . ')');
    bcc_execute('DELETE FROM teams WHERE id IN (' . (int) $EKIP1 . ',' . (int) $EKIP2 . ')');
    if (!$dirOnceVardi && is_dir($avDir) && count(glob($avDir . '/*')) === 0) {
        @rmdir($avDir);
    }
};
register_shutdown_function($cleanup);

foreach ($kisiler as $k => $kisi) {
    $tok = jeton($kisi['jar'], '/login.php');
    $r = istek($kisi['jar'], $BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode($kisi['email']) . '&password=' . rawurlencode($SIFRE));
    check("$k giris yapti (302)", $r['code'] === 302, $r['code']);
}

$A = $kisiler['A'];
$dosyaA = bcc_avatar_path($A['id']);
$csrfA = jeton($A['jar'], '/account.php');
check('A icin CSRF jetonu alindi', $csrfA !== '');

echo "\nA) Kimlik ve CSRF\n";

$anonim = tempnam(sys_get_temp_dir(), 'bccav');
$r = yukle($anonim, null, $JPEG, 'a.jpg', 'image/jpeg');
@unlink($anonim);
check('girissiz yukleme -> 401', $r['code'] === 401, $r['code']);

$r = yukle($A['jar'], null, $JPEG, 'a.jpg', 'image/jpeg');
check('CSRF jetonsuz yukleme -> 403', $r['code'] === 403, $r['code']);

$r = istek($A['jar'], $BASE . '/api/avatar_upload.php');
check('GET ile yukleme -> 405', $r['code'] === 405, $r['code']);

check('reddedilen isteklerden sonra diskte dosya YOK', !diskte_var($dosyaA));

echo "\nB) Resim olmayan / bozuk / sinir disi dosyalar reddediliyor (422, diske yazilmiyor)\n";

$redler = array(
    'SVG, .png uzantisi ve image/png turuyle' => array('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'a.png', 'image/png'),
    'HTML, .jpg uzantisi ve image/jpeg turuyle' => array("<html><body><script>alert(document.cookie)</script></body></html>", 'a.jpg', 'image/jpeg'),
    'GIF (yalnizca PNG/JPEG izinli)' => array(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'a.gif', 'image/gif'),
    'cok kucuk PNG (8x8)' => array(png_yap(8, 8), 'a.png', 'image/png'),
    'bilinmeyen KRITIK parcali PNG' => array(png_yap(32, 32, array(png_parca('ABCD', 'x'))), 'a.png', 'image/png'),
    'yarida kesilmis JPEG' => array(substr($JPEG, 0, 600), 'a.jpg', 'image/jpeg'),
    '2MB ustu dosya' => array($JPEG . str_repeat("\x00", 2 * 1024 * 1024), 'a.jpg', 'image/jpeg'),
);

foreach ($redler as $ad => $d) {
    $r = yukle($A['jar'], $csrfA, $d[0], $d[1], $d[2]);
    check("$ad -> 422", $r['code'] === 422, $r['code'] . ' ' . substr($r['body'], 0, 80));
}
check('hicbir red diske dosya birakmadi', !diskte_var($dosyaA) && count((array) glob($dosyaA . '.tmp-*')) === 0);

echo "\nC) EXIF/GPS ve yorum iceren JPEG — kabul, meta veri AYIKLANDI\n";

$gps = 'GPS-41.0082N-28.9784E-GIZLI';
$app1 = "Exif\x00\x00" . $gps . str_repeat("\x00", 40);
$com = '<script>alert(1)</script>';
$jpegExif = "\xFF\xD8"
    . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1
    . "\xFF\xFE" . pack('n', strlen($com) + 2) . $com
    . substr($JPEG, 2);

check('test dosyasi GPS ve <script> iceriyor (kendi kontrolu)', strpos($jpegExif, $gps) !== false && strpos($jpegExif, $com) !== false);

$r = yukle($A['jar'], $csrfA, $jpegExif, 'tatil.jpg', 'image/jpeg');
check('yukleme 200 ve ok', $r['code'] === 200 && !empty($r['json']['ok']), $r['code'] . ' ' . substr($r['body'], 0, 100));
$url1 = isset($r['json']['url']) ? $r['json']['url'] : '';
check('adres kullanici id ve surum tasiyor', strpos($url1, '/api/avatar.php?user_id=' . $A['id'] . '&v=') === 0, $url1);
$inline1 = isset($r['json']['inline']) ? (string) $r['json']['inline'] : '';
check('yanit gomulu kopyayi da donuyor (arayuz yeni istek beklemeden gosterir)', strpos($inline1, 'data:image/jpeg;base64,') === 0, substr($inline1, 0, 40));
check('gomulu kopya = diskteki ayiklanmis dosya (GPS\'li ham hali DEGIL)',
    $inline1 !== '' && base64_decode(substr($inline1, strlen('data:image/jpeg;base64,'))) === file_get_contents($dosyaA));
check('dosya diskte', diskte_var($dosyaA));

$kayitli = diskte_var($dosyaA) ? file_get_contents($dosyaA) : '';
check('diskteki dosyada GPS YOK', $kayitli !== '' && strpos($kayitli, $gps) === false);
check('diskteki dosyada <script> yorumu YOK', $kayitli !== '' && strpos($kayitli, $com) === false);
check('APP1 (EXIF) isareti YOK', strpos($kayitli, "\xFF\xE1") === false);
check('APP2 ICC renk profili KORUNDU', strpos($kayitli, 'ICC_PROFILE') !== false);
$bilgi = $kayitli !== '' ? getimagesizefromstring($kayitli) : false;
check('hala gecerli JPEG, boyut degismedi (320x200)', $bilgi && $bilgi[2] === IMAGETYPE_JPEG && $bilgi[0] === 320 && $bilgi[1] === 200);
check('gecici dosya kalmadi', count((array) glob($dosyaA . '.tmp-*')) === 0);

$r = istek($A['jar'], $BASE . $url1);
check('A kendi fotografini goruyor (200)', $r['code'] === 200, $r['code']);
check('Content-Type image/jpeg', baslik($r, 'Content-Type') === 'image/jpeg', (string) baslik($r, 'Content-Type'));
check('nosniff', strtolower((string) baslik($r, 'X-Content-Type-Options')) === 'nosniff');
check("CSP default-src 'none'; sandbox", strpos((string) baslik($r, 'Content-Security-Policy'), 'sandbox') !== false);
check('onbellek private (paylasimli onbellege dusmez)', strpos((string) baslik($r, 'Cache-Control'), 'private') !== false);
check('sunulan bayt = diskteki bayt', $r['body'] === $kayitli);

echo "\nD) Metin parcali PNG — kabul, tEXt/tIME AYIKLANDI, adres surumu degisti\n";

$konum = 'Konum: 41.0082,28.9784';
$pngMeta = png_yap(48, 40, array(png_parca('tEXt', "Comment\x00" . $konum), png_parca('tIME', pack('nCCCCC', 2026, 9, 14, 10, 0, 0))));
$r = yukle($A['jar'], $csrfA, $pngMeta, 'a.png', 'image/png');
check('yukleme 200', $r['code'] === 200 && !empty($r['json']['ok']), $r['code'] . ' ' . substr($r['body'], 0, 100));
$url2 = isset($r['json']['url']) ? $r['json']['url'] : '';
check('yeni adres oncekinden farkli (onbellek bayat kalmaz)', $url2 !== '' && $url2 !== $url1, "$url1 / $url2");

$kayitli = diskte_var($dosyaA) ? file_get_contents($dosyaA) : '';
check('diskteki PNG\'de konum metni YOK', $kayitli !== '' && strpos($kayitli, $konum) === false);
check('tEXt ve tIME parcalari YOK', strpos($kayitli, 'tEXt') === false && strpos($kayitli, 'tIME') === false);
$bilgi = $kayitli !== '' ? getimagesizefromstring($kayitli) : false;
check('hala gecerli PNG (48x40)', $bilgi && $bilgi[2] === IMAGETYPE_PNG && $bilgi[0] === 48 && $bilgi[1] === 40);

$r = istek($A['jar'], $BASE . $url2);
check('PNG image/png olarak sunuluyor', $r['code'] === 200 && baslik($r, 'Content-Type') === 'image/png', $r['code'] . ' ' . baslik($r, 'Content-Type'));

echo "\nE) KVKK ekip izolasyonu\n";

$r = istek($kisiler['B']['jar'], $BASE . $url2);
check('B (ayni ekip) goruyor -> 200', $r['code'] === 200, $r['code']);

$r = istek($kisiler['C']['jar'], $BASE . $url2);
check('C (baska ekip) GOREMIYOR -> 404', $r['code'] === 404, $r['code']);
check('C\'ye resim baytlari sizmadi', strpos($r['body'], "\x89PNG") === false);

$yok = 999999999;
$r2 = istek($kisiler['C']['jar'], $BASE . '/api/avatar.php?user_id=' . $yok);
check('var olmayan kullanici da ayni cevap (404) — varlik sizmiyor', $r2['code'] === 404, $r2['code']);

$anonim = tempnam(sys_get_temp_dir(), 'bccav');
$r = istek($anonim, $BASE . $url2);
@unlink($anonim);
check('girissiz -> girise yonlendirme (302), resim yok', $r['code'] === 302 && strpos($r['body'], "\x89PNG") === false, $r['code']);

$r = istek($A['jar'], $BASE . '/api/avatar.php?user_id=0');
check('user_id=0 -> 404', $r['code'] === 404, $r['code']);

$r = istek($kisiler['C']['jar'], $BASE . '/api/avatar_delete.php', 'csrf_token=' . jeton($kisiler['C']['jar'], '/account.php'));
check('C silme istegi yalnizca KENDI fotografini hedefliyor — A\'ninki duruyor', diskte_var($dosyaA), $r['code']);

echo "\nF) Sayfalarda gorunme\n";

/* 2026-09-14 (ikinci tur): kisinin KENDI avatari artik adresle degil HTML'nin
   icine gomulu (data: URI) geliyor — yeni sekmede once bos daire gorunmesin
   diye. Ayrintili dogrulama: scripts/_verify_avatar_everywhere.php. */
$r = istek($A['jar'], $BASE . '/account.php');
$imgSay = substr_count($r['body'], '<img class="bcc-avatar-img" src="data:image/png;base64,');
check('hesap sayfasi: hem buyuk yuz hem ust cubuk gomulu resim basiyor (2)', $imgSay === 2, (string) $imgSay);
$gomulu = preg_match('/src="data:image\/png;base64,([^"]+)"/', $r['body'], $gm) ? base64_decode($gm[1]) : '';
check('gomulu baytlar diskteki (ayiklanmis) dosyayla birebir ayni', $gomulu !== '' && $gomulu === file_get_contents($dosyaA));
check('"Kaldir" dugmesi gorunur', (bool) preg_match('/data-avatar-remove>Kald/', $r['body']));
check('ust cubuk dugmesinin erisilebilir adi var', strpos($r['body'], 'aria-label="Hesap menüsü"') !== false);
check('avatar JS yuklu', strpos($r['body'], 'account-avatar.js') !== false);

$r = istek($A['jar'], $BASE . '/dashboard.php');
check('ana sayfa ust cubugu da gomulu resmi basiyor', strpos($r['body'], 'src="data:image/png;base64,') !== false);

$r = istek($kisiler['B']['jar'], $BASE . '/account.php');
check('fotografi olmayan B: bas harf, <img> yok, "Kaldir" gizli',
    strpos($r['body'], 'bcc-avatar-img') === false && (bool) preg_match('/data-avatar-remove hidden>/', $r['body']));

echo "\nG) Kaldirma\n";

$r = istek($A['jar'], $BASE . '/api/avatar_delete.php', 'csrf_token=' . $csrfA);
$j = json_decode($r['body'], true);
check('silme 200 ve bas harf donuyor', $r['code'] === 200 && !empty($j['ok']) && isset($j['initial']) && $j['initial'] === 'F', $r['code'] . ' ' . $r['body']);
check('dosya diskten silindi', !diskte_var($dosyaA));

$r = istek($A['jar'], $BASE . $url2);
check('eski adres artik 404', $r['code'] === 404, $r['code']);

$r = istek($A['jar'], $BASE . '/account.php');
check('hesap sayfasi bas harfe dondu, "Kaldir" gizli',
    strpos($r['body'], 'bcc-avatar-img') === false && (bool) preg_match('/data-avatar-remove hidden>/', $r['body']));

$r = istek($A['jar'], $BASE . '/api/avatar_delete.php', 'csrf_token=' . $csrfA);
check('fotograf yokken silme de 200 (tekrar basmak hata vermez)', $r['code'] === 200, $r['code']);

echo "\nH) Denetim kaydi\n";

$guncel = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE user_id = :u AND action = 'user.avatar_updated'", array('u' => $A['id']));
$silme = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE user_id = :u AND action = 'user.avatar_removed'", array('u' => $A['id']));
check('iki basarili yukleme = 2 user.avatar_updated', $guncel === 2, (string) $guncel);
check('iki silme cagrisi = 2 user.avatar_removed', $silme === 2, (string) $silme);

$cleanup();

echo "\nI) Kirlilik\n";
check('kullanici sayisi ayni', (int) bcc_fetch_column('SELECT COUNT(*) FROM users') === $kullaniciOnce);
check('ekip sayisi ayni', (int) bcc_fetch_column('SELECT COUNT(*) FROM teams') === $ekipOnce);
$dosyaSonra = is_dir($avDir) ? count(glob($avDir . '/*')) : 0;
check('storage/avatars dosya sayisi ayni', $dosyaSonra === $dosyaOnce, "$dosyaOnce -> $dosyaSonra");
check('klasor onceden yoksa geride birakilmadi', $dirOnceVardi || !is_dir($avDir));

echo "\nSONUC: $gecti gecti, $kaldi kaldi\n";
exit($kaldi === 0 ? 0 : 1);
