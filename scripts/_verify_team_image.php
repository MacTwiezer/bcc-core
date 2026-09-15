<?php

/*
 * Calisma alani resmi uctan uca dogrulamasi — 2026-09-15.
 *
 * Gercek HTTP istekleriyle (Apache, localhost): yukleme/kaldirma yetkisi (yalniz
 * owner), reddetme yollari, meta veri ayiklama, KVKK ekip izolasyonu (ekip
 * disindan biri resmi goremez), resmin Calisma Alanlari sayfasinda, ana sayfa
 * grup basliginda ve kenar cubugunda gorunmesi.
 *
 * Kendi test kullanicilarini/ekiplerini/base'lerini kurar, sonunda HEPSINI
 * siler: kullanici, ekip, uyelik, base, audit satirlari, giris denemeleri,
 * resim dosyalari. Slack'e dokunan olay tetiklenmez.
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

function yukle($jar, $csrf, $teamId, $bytes, $ad, $tip)
{
    global $BASE;
    $tmp = tempnam(sys_get_temp_dir(), 'bccti');
    file_put_contents($tmp, $bytes);
    $post = array('file' => new CURLFile($tmp, $tip, $ad), 'team_id' => (string) $teamId);
    if ($csrf !== null) {
        $post['csrf_token'] = $csrf;
    }
    $r = istek($jar, $BASE . '/api/team_image_upload.php', $post);
    @unlink($tmp);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

function kaldir($jar, $csrf, $teamId)
{
    global $BASE;
    $r = istek($jar, $BASE . '/api/team_image_delete.php', http_build_query(array('csrf_token' => $csrf, 'team_id' => $teamId)));
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

/* Dosyayi Apache sureci yaziyor/siliyor; CLI surecinin stat onbellegi bosaltiliyor. */
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
    $satir = "\x00" . str_repeat("\xf0\x80\x20", $w);
    $png = "\x89PNG\r\n\x1A\n" . png_parca('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0));
    foreach ($ekParcalar as $p) {
        $png .= $p;
    }
    return $png . png_parca('IDAT', gzcompress(str_repeat($satir, $h))) . png_parca('IEND', '');
}

$tiDir = bcc_team_image_storage_dir();
$dirOnceVardi = is_dir($tiDir);
$dosyaOnce = $dirOnceVardi ? count(glob($tiDir . '/*')) : 0;
$kullaniciOnce = (int) bcc_fetch_column('SELECT COUNT(*) FROM users');
$ekipOnce = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams');
$baseOnce = (int) bcc_fetch_column('SELECT COUNT(*) FROM bases');

$SON = bin2hex(random_bytes(4));
$SIFRE = 'Ekip!' . $SON;
$kisiler = array();
foreach (array('O' => 'Ekip Sahibi', 'E' => 'Ekip Editoru', 'X' => 'Baska Ekip') as $k => $ad) {
    $email = 'titest.' . strtolower($k) . ".$SON@bcc-test.local";
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array('e' => $email, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $ad)
    );
    $kisiler[$k] = array('id' => (int) bcc_last_insert_id(), 'email' => $email, 'jar' => tempnam(sys_get_temp_dir(), 'bccti'));
}

$ekipler = array();
foreach (array(1, 2, 3) as $n) {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "TITEST-$n-$SON"));
    $ekipler[$n] = (int) bcc_last_insert_id();
}
$EKIP1 = $ekipler[1];
$EKIP2 = $ekipler[2];
$EKIP3 = $ekipler[3];

/* O: iki ekibin owner'i (ana sayfa gruplu, kenar cubugunda ekip listesi cikar).
   E: ekip 1'de editor. X: yalniz ekip 3'te. */
foreach (array(array($EKIP1, 'O', 'owner'), array($EKIP2, 'O', 'owner'), array($EKIP1, 'E', 'editor'), array($EKIP3, 'X', 'owner')) as $uy) {
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $uy[0], 'u' => $kisiler[$uy[1]]['id'], 'r' => $uy[2]));
}
$baseIds = array();
foreach (array($EKIP1, $EKIP2) as $t) {
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array('t' => $t, 'n' => "TI Base $t", 'u' => $kisiler['O']['id']));
    $baseIds[] = (int) bcc_last_insert_id();
}

$cleanup = function () use (&$kisiler, $ekipler, $baseIds, $tiDir, $dirOnceVardi) {
    foreach ($baseIds as $b) {
        bcc_execute('DELETE FROM bases WHERE id = :b', array('b' => $b));
    }
    foreach ($ekipler as $t) {
        @unlink(bcc_team_image_path($t));
        foreach ((array) glob(bcc_team_image_path($t) . '.tmp-*') as $tmp) { @unlink($tmp); }
    }
    foreach ($kisiler as $k) {
        bcc_execute('DELETE FROM audit_log WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $k['email']));
        bcc_execute('DELETE FROM team_members WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $k['id']));
        @unlink($k['jar']);
    }
    bcc_execute('DELETE FROM audit_log WHERE team_id IN (' . implode(',', array_map('intval', $ekipler)) . ')');
    bcc_execute('DELETE FROM teams WHERE id IN (' . implode(',', array_map('intval', $ekipler)) . ')');
    if (!$dirOnceVardi && is_dir($tiDir) && count(glob($tiDir . '/*')) === 0) {
        @rmdir($tiDir);
    }
};
register_shutdown_function($cleanup);

foreach ($kisiler as $k => $kisi) {
    $tok = jeton($kisi['jar'], '/login.php');
    $r = istek($kisi['jar'], $BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode($kisi['email']) . '&password=' . rawurlencode($SIFRE));
    check("$k giris yapti (302)", $r['code'] === 302, $r['code']);
}

$O = $kisiler['O'];
$E = $kisiler['E'];
$X = $kisiler['X'];
$dosya1 = bcc_team_image_path($EKIP1);
$csrfO = jeton($O['jar'], '/workspaces.php?team_id=' . $EKIP1);
$csrfE = jeton($E['jar'], '/workspaces.php?team_id=' . $EKIP1);
$csrfX = jeton($X['jar'], '/workspaces.php');
check('CSRF jetonlari alindi', $csrfO !== '' && $csrfE !== '' && $csrfX !== '');

$PNG = png_yap(60, 40, array(png_parca('tEXt', "Comment\x00konum: 41.0082,28.9784")));

echo "\nA) Kimlik ve CSRF\n";
$anonim = tempnam(sys_get_temp_dir(), 'bccti');
$r = yukle($anonim, null, $EKIP1, $PNG, 'a.png', 'image/png');
check('girissiz yukleme -> 401', $r['code'] === 401, $r['code']);
@unlink($anonim);
$r = yukle($O['jar'], null, $EKIP1, $PNG, 'a.png', 'image/png');
check('CSRF jetonsuz yukleme -> 403', $r['code'] === 403, $r['code']);
$r = istek($O['jar'], $BASE . '/api/team_image_upload.php');
check('GET ile yukleme -> 405', $r['code'] === 405, $r['code']);

echo "\nB) Yetki: yalniz owner, yalniz kendi ekibi\n";
$r = yukle($E['jar'], $csrfE, $EKIP1, $PNG, 'a.png', 'image/png');
check('ayni ekipte EDITOR yukleyemez -> 403', $r['code'] === 403, $r['code'] . ' ' . $r['body']);
$r = yukle($X['jar'], $csrfX, $EKIP1, $PNG, 'a.png', 'image/png');
check('ekip disindan owner bile yukleyemez -> 404 (ekibin varligi sizmaz)', $r['code'] === 404, $r['code']);
$r = yukle($O['jar'], $csrfO, 0, $PNG, 'a.png', 'image/png');
check('team_id=0 -> 404', $r['code'] === 404, $r['code']);
check('reddedilen isteklerden sonra diskte dosya YOK', !diskte_var($dosya1));

echo "\nC) Icerik dogrulamasi (ortak src/image_upload.php)\n";
foreach (array(
    'uzantisi png olan duz metin' => array('<?php echo 1; ?>', 'x.png', 'image/png'),
    'SVG' => array('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg', 'image/svg+xml'),
    'cok kucuk PNG (8x8)' => array(png_yap(8, 8), 'k.png', 'image/png'),
) as $ad => $d) {
    $r = yukle($O['jar'], $csrfO, $EKIP1, $d[0], $d[1], $d[2]);
    check("$ad -> 422", $r['code'] === 422, $r['code'] . ' ' . substr($r['body'], 0, 80));
}
check('hicbir red diske dosya birakmadi', !diskte_var($dosya1) && count((array) glob($dosya1 . '.tmp-*')) === 0);

echo "\nD) Owner yuklemesi\n";
$r = yukle($O['jar'], $csrfO, $EKIP1, $PNG, 'ekip.png', 'image/png');
check('yukleme 200 ve ok', $r['code'] === 200 && !empty($r['json']['ok']), $r['code'] . ' ' . substr($r['body'], 0, 100));
$url1 = isset($r['json']['url']) ? (string) $r['json']['url'] : '';
check('adres ekip id ve surum tasiyor', strpos($url1, '/api/team_image.php?team_id=' . $EKIP1 . '&v=') === 0, $url1);
check('dosya diskte', diskte_var($dosya1));
$kayitli = diskte_var($dosya1) ? file_get_contents($dosya1) : '';
check('diskteki PNG\'de konum metni YOK (tEXt ayiklandi)', $kayitli !== '' && strpos($kayitli, 'konum') === false && strpos($kayitli, 'tEXt') === false);
$bilgi = $kayitli !== '' ? @getimagesizefromstring($kayitli) : false;
check('hala gecerli PNG (60x40)', $bilgi && $bilgi[2] === IMAGETYPE_PNG && $bilgi[0] === 60 && $bilgi[1] === 40);
check('gecici dosya kalmadi', count((array) glob($dosya1 . '.tmp-*')) === 0);
check('baska ekibin dosyasi olusmadi', !diskte_var(bcc_team_image_path($EKIP2)));

echo "\nE) Sunum ve KVKK ekip izolasyonu\n";
$r = istek($O['jar'], $BASE . $url1);
check('owner goruyor (200, image/png)', $r['code'] === 200 && baslik($r, 'Content-Type') === 'image/png', $r['code'] . ' ' . baslik($r, 'Content-Type'));
check('nosniff + CSP sandbox + private onbellek',
    strtolower((string) baslik($r, 'X-Content-Type-Options')) === 'nosniff'
    && strpos((string) baslik($r, 'Content-Security-Policy'), 'sandbox') !== false
    && strpos((string) baslik($r, 'Cache-Control'), 'private') !== false);
check('sunulan bayt = diskteki bayt', $r['body'] === $kayitli);
$r = istek($E['jar'], $BASE . $url1);
check('ayni ekipteki editor goruyor (200)', $r['code'] === 200, $r['code']);
$r = istek($X['jar'], $BASE . $url1);
check('ekip disindaki X GOREMIYOR (404) ve bayt sizmadi', $r['code'] === 404 && strpos($r['body'], "\x89PNG") === false, $r['code']);
$r = istek($X['jar'], $BASE . '/api/team_image.php?team_id=' . $EKIP2);
check('resmi olmayan yabanci ekip de ayni cevap (404) — varlik sizmiyor', $r['code'] === 404, $r['code']);
$anonim = tempnam(sys_get_temp_dir(), 'bccti');
$r = istek($anonim, $BASE . $url1);
check('girissiz -> girise yonlendirme (302), resim yok', $r['code'] === 302 && strpos($r['body'], "\x89PNG") === false, $r['code']);
@unlink($anonim);

echo "\nF) Sayfalarda gorunme\n";
$urlHtml = htmlspecialchars($url1, ENT_QUOTES, 'UTF-8');
$r = istek($O['jar'], $BASE . '/workspaces.php?team_id=' . $EKIP1);
$b = $r['body'];
check('Calisma Alanlari (owner): baslikta yukleme koku + dosya secici', strpos($b, 'data-team-image-root data-team-id="' . $EKIP1 . '"') !== false && strpos($b, 'data-team-image-input') !== false);
check('liste karti + baslik kutusu resmi basiyor (en az 2)', substr_count($b, 'src="' . $urlHtml . '"') >= 2, (string) substr_count($b, 'src="' . $urlHtml . '"'));
check('"Resmi kaldır" gorunur', (bool) preg_match('/data-team-image-remove>/', $b));
check('image-square.js + team-image.js yuklu', strpos($b, 'image-square.js') !== false && strpos($b, 'team-image.js') !== false);
check('kenar cubugu Katilimcilar listesinde ekip 1 resimli, ekip 2 resimsiz',
    (bool) preg_match('/home-sidenav-team-face has-image" data-team-face="' . $EKIP1 . '"/', $b)
    && (bool) preg_match('/home-sidenav-team-face" data-team-face="' . $EKIP2 . '"/', $b));
$r = istek($O['jar'], $BASE . '/workspaces.php?team_id=' . $EKIP2);
check('resimsiz ekipte "Resmi kaldır" gizli', (bool) preg_match('/data-team-image-remove hidden>/', $r['body']));
$r = istek($E['jar'], $BASE . '/workspaces.php?team_id=' . $EKIP1);
check('editor: resmi goruyor ama yukleme koku/secici YOK',
    strpos($r['body'], 'src="' . $urlHtml . '"') !== false
    && strpos($r['body'], 'data-team-image-root') === false && strpos($r['body'], 'data-team-image-input') === false);
$r = istek($O['jar'], $BASE . '/dashboard.php');
check('ana sayfa grup basligi: ekip 1 resimli, ekip 2 resimsiz',
    (bool) preg_match('/home-ws-face has-image" data-team-face="' . $EKIP1 . '"><img class="bcc-team-face-img" alt="" src="' . preg_quote($urlHtml, '/') . '">/', $r['body'])
    && (bool) preg_match('/home-ws-face" data-team-face="' . $EKIP2 . '"><img class="bcc-team-face-img" alt="" hidden>/', $r['body']));
$r = istek($X['jar'], $BASE . '/workspaces.php');
check('X\'in sayfalarinda ekip 1 resim adresi YOK', strpos($r['body'], '/api/team_image.php?team_id=' . $EKIP1) === false);

echo "\nG) Kaldirma\n";
$r = kaldir($E['jar'], $csrfE, $EKIP1);
check('editor kaldiramaz -> 403, dosya duruyor', $r['code'] === 403 && diskte_var($dosya1), $r['code']);
$r = kaldir($X['jar'], $csrfX, $EKIP1);
check('ekip disindan kaldirma -> 404, dosya duruyor', $r['code'] === 404 && diskte_var($dosya1), $r['code']);
$r = kaldir($O['jar'], $csrfO, $EKIP1);
check('owner kaldirma 200', $r['code'] === 200 && !empty($r['json']['ok']), $r['code'] . ' ' . $r['body']);
check('dosya diskten silindi', !diskte_var($dosya1));
$r = istek($O['jar'], $BASE . $url1);
check('eski adres artik 404', $r['code'] === 404, $r['code']);
$r = istek($O['jar'], $BASE . '/dashboard.php');
check('ana sayfa grup basligi resimsize dondu', strpos($r['body'], 'home-ws-face has-image') === false);
$r = kaldir($O['jar'], $csrfO, $EKIP1);
check('resim yokken kaldirma da 200 (tekrar basmak hata vermez)', $r['code'] === 200, $r['code']);

echo "\nH) Denetim kaydi\n";
$guncel = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE team_id = :t AND action = 'team.image_updated' AND user_id = :u", array('t' => $EKIP1, 'u' => $O['id']));
$silme = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE team_id = :t AND action = 'team.image_removed' AND user_id = :u", array('t' => $EKIP1, 'u' => $O['id']));
check('bir basarili yukleme = 1 team.image_updated (ekip id ile)', $guncel === 1, (string) $guncel);
check('iki basarili kaldirma cagrisi = 2 team.image_removed', $silme === 2, (string) $silme);
$red = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action IN ('team.image_updated','team.image_removed') AND user_id IN (:e, :x)", array('e' => $E['id'], 'x' => $X['id']));
check('reddedilen istekler denetim kaydi uretmedi', $red === 0, (string) $red);

$cleanup();

echo "\nI) Kirlilik\n";
check('kullanici sayisi ayni', (int) bcc_fetch_column('SELECT COUNT(*) FROM users') === $kullaniciOnce);
check('ekip sayisi ayni', (int) bcc_fetch_column('SELECT COUNT(*) FROM teams') === $ekipOnce);
check('base sayisi ayni', (int) bcc_fetch_column('SELECT COUNT(*) FROM bases') === $baseOnce);
$dosyaSonra = is_dir($tiDir) ? count(glob($tiDir . '/*')) : 0;
check('storage/team_images dosya sayisi ayni', $dosyaSonra === $dosyaOnce, "$dosyaOnce -> $dosyaSonra");
check('klasor onceden yoksa geride birakilmadi', $dirOnceVardi || !is_dir($tiDir));

echo "\nSONUC: $gecti gecti, $kaldi kaldi\n";
exit($kaldi === 0 ? 0 : 1);
