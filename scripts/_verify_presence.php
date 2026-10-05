<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

/* Temsilci durumu: Aktif / Pasif / Cevrimdisi (2026-10-05). Musteri: temsilci
   beklerken logout OLMASIN, yalnizca "Pasif" gorunsun; donunce tekrar Aktif.
   Betik iki hesap kurar (temsilci + admin), zamani veritabaninda ve oturum
   dosyasinda geri cekerek Apache uzerinden durumu olcer. */

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

$COOKIE_TEMSILCI = tempnam(sys_get_temp_dir(), 'bccpr');
$COOKIE_ADMIN = tempnam(sys_get_temp_dir(), 'bccpa');
$COOKIE = $COOKIE_TEMSILCI;

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

function oturumId($cookie)
{
    foreach (file($cookie) as $satir) {
        $p = explode("\t", trim($satir));
        if (count($p) === 7 && $p[5] === session_name()) { return $p[6]; }
    }
    return '';
}

/* Oturum dosyasindaki bir tamsayi alani degistirir (temsilci cerezi). */
function oturumAlan($alan, $deger)
{
    global $COOKIE_TEMSILCI;
    $dosya = __DIR__ . '/../storage/sessions/sess_' . oturumId($COOKIE_TEMSILCI);
    if (!is_file($dosya)) { return false; }
    $yeni = preg_replace('/' . $alan . '\|i:\d+;/', $alan . '|i:' . (int) $deger . ';', file_get_contents($dosya), 1, $n);
    return $n === 1 && file_put_contents($dosya, $yeni) !== false;
}

/* 45 sn'lik tazeleme araligini sifirlar: yoksa "yazmadi" ile "aralik dolmadigi
   icin yazamadi" ayirt edilemez. */
function araligiSifirla()
{
    return oturumAlan('bcc_seen_touched_at', 0) && oturumAlan('bcc_activity_touched_at', 0);
}

function girisYap($mail, $sifre)
{
    global $BASE, $COOKIE;
    file_put_contents($COOKIE, '');
    $r = istek($BASE . '/login.php');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
    istek($BASE . '/login.php', 'csrf_token=' . (isset($m[1]) ? $m[1] : '') . '&email=' . urlencode($mail) . '&password=' . urlencode($sifre));
}

function sayfaCsrf()
{
    global $BASE;
    $r = istek($BASE . '/dashboard.php');
    if (!$r || $r['code'] !== 200) { return ''; }
    preg_match('/<meta name="csrf-token" content="([a-f0-9]{64})"/', $r['body'], $m);
    return isset($m[1]) ? $m[1] : '';
}

function nabiz($csrf, $aktif)
{
    global $BASE;
    return istek($BASE . '/api/presence_ping.php', 'csrf_token=' . $csrf . '&active=' . ($aktif ? '1' : '0'));
}

function durum($mail)
{
    return bcc_fetch_column(
        'SELECT ' . bcc_presence_case_sql() . ' FROM users WHERE email = :e',
        array('e' => $mail)
    );
}

function geriAl($mail, $kolon, $dakika)
{
    /* $kolon yalnizca asagidaki iki sabit degerle cagrilir. */
    bcc_execute(
        'UPDATE users SET ' . $kolon . ' = (NOW() - INTERVAL ' . (int) $dakika . ' MINUTE) WHERE email = :e',
        array('e' => $mail)
    );
}

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$SON = bin2hex(random_bytes(4));
$MAIL = 'pr.temsilci.' . $SON . '@bcc-test.local';
$ADMIN = 'pr.admin.' . $SON . '@bcc-test.local';
$SIFRE = 'PrTest!' . $SON;

$temizle = function () use ($MAIL, $ADMIN, $COOKIE_TEMSILCI, $COOKIE_ADMIN) {
    foreach (array($MAIL, $ADMIN) as $e) {
        $u = bcc_fetch_one('SELECT id FROM users WHERE email = :e', array('e' => $e));
        if ($u) {
            bcc_execute('DELETE FROM audit_log WHERE user_id = :i', array('i' => $u['id']));
            bcc_execute('DELETE FROM users WHERE id = :i', array('i' => $u['id']));
        }
        bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $e));
    }
    foreach (array($COOKIE_TEMSILCI, $COOKIE_ADMIN) as $c) {
        if (is_file($c)) {
            $sid = oturumId($c);
            if ($sid !== '') { @unlink(__DIR__ . '/../storage/sessions/sess_' . $sid); }
            @unlink($c);
        }
    }
};
register_shutdown_function($temizle);

foreach (array(array($MAIL, 'Pr Temsilci', 0), array($ADMIN, 'Pr Admin', 1)) as $h) {
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,:a,1)',
        array('e' => $h[0], 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $h[1], 'a' => $h[2])
    );
}

echo "PHP " . PHP_VERSION . " - temsilci durumu (Aktif / Pasif / Cevrimdisi) testi\n\n";

echo "A) Yapilandirma\n";
check('A) pasif esigi 30 dakika', BCC_PRESENCE_PASSIVE_MINUTES === 30, (string) BCC_PRESENCE_PASSIVE_MINUTES);
check('A) tazeleme araligi nabizdan (60 sn) kisa', BCC_PRESENCE_TOUCH_INTERVAL < 60);
check('A) users.last_seen_at kolonu var', count(bcc_fetch_all("SHOW COLUMNS FROM users LIKE 'last_seen_at'")) === 1);
foreach (array('src/partials/home_shell_bottom.php', 'public/grid.php', 'public/interface.php') as $kabuk) {
    check('A) presence.js yukleniyor: ' . $kabuk,
        strpos(file_get_contents(__DIR__ . '/../' . $kabuk), "bcc_asset_url('presence.js')") !== false);
}
foreach (array('slack_flush.php', 'note_view_ping.php', 'presence_ping.php') as $uc) {
    check('A) arka plan ucu isaretli: ' . $uc,
        strpos(file_get_contents(__DIR__ . '/../public/api/' . $uc), "define('BCC_BACKGROUND_REQUEST', true);") !== false);
}
check('A) giris yapmamis hesap Cevrimdisi', durum($MAIL) === 'offline', (string) durum($MAIL));

echo "\nB) Giris yapan temsilci Aktif\n";
girisYap($MAIL, $SIFRE);
$csrf = sayfaCsrf();
check('B) giris yapildi, sayfada csrf var', $csrf !== '');
check('B) durum Aktif', durum($MAIL) === 'active', (string) durum($MAIL));
$r = istek($BASE . '/dashboard.php');
check('B) sayfa presence.js yukluyor', $r && strpos($r['body'], '/assets/presence.js') !== false);

echo "\nC) 31 dakika islem yok, nabiz atiyor -> Pasif, oturum ACIK\n";
geriAl($MAIL, 'last_activity_at', 31);
check('C) aralik sifirlandi', araligiSifirla());
$r = nabiz($csrf, false);
check('C) nabiz 200', $r && $r['code'] === 200 && strpos($r['body'], '"ok":true') !== false, $r ? $r['code'] . ' ' . $r['body'] : 'null');
check('C) durum Pasif', durum($MAIL) === 'passive', (string) durum($MAIL));
$satir = null;
foreach (bcc_online_users() as $u) { if ($u['email'] === $MAIL) { $satir = $u; } }
check('C) oturumu acik listesinde ve Pasif', $satir !== null && $satir['presence'] === 'passive');
check('C) etiket "Pasif"', bcc_presence_label('passive') === 'Pasif');

echo "\nD) Arka plan yoklamalari islem sayilmiyor\n";
check('D) aralik sifirlandi', araligiSifirla());
$r = istek($BASE . '/api/note_view_ping.php', 'csrf_token=' . $csrf . '&view_id=0');
check('D) note_view_ping 200', $r && $r['code'] === 200, $r ? (string) $r['code'] : 'null');
check('D) note_view_ping sonrasi hala Pasif', durum($MAIL) === 'passive', (string) durum($MAIL));
check('D) aralik sifirlandi', araligiSifirla());
$r = istek($BASE . '/api/slack_flush.php', 'csrf_token=' . $csrf . '&table_id=0');
check('D) slack_flush sonrasi hala Pasif', durum($MAIL) === 'passive', ($r ? $r['code'] : 'null') . ' ' . durum($MAIL));

echo "\nE) 8 saati asan bekleyis: nabiz oturumu canli tutuyor\n";
check('E) son istek 7 sa 50 dk geri', oturumAlan('bcc_last_request', time() - (7 * 3600 + 50 * 60)));
$r = nabiz($csrf, false);
check('E) nabiz 200', $r && $r['code'] === 200);
check('E) son istek yine 7 sa 50 dk geri (toplam 15 sa 40 dk)', oturumAlan('bcc_last_request', time() - (7 * 3600 + 50 * 60)));
$r = nabiz($csrf, false);
check('E) nabiz 200, oturum dusmedi', $r && $r['code'] === 200, $r ? (string) $r['code'] : 'null');
check('E) durum hala Pasif (Aktif olmadi, cikis da olmadi)', durum($MAIL) === 'passive', (string) durum($MAIL));

echo "\nF) Admin panelinde ve ust barda Pasif gorunuyor\n";
$COOKIE = $COOKIE_ADMIN;
girisYap($ADMIN, $SIFRE);
$r = istek($BASE . '/admin/index.php');
check('F) admin paneli 200', $r && $r['code'] === 200, $r ? (string) $r['code'] : 'null');

function listeBlogu($govde, $mail)
{
    /* Ilk parca listeden ONCEKI sayfa basidir (hesap menusunde e-posta var). */
    foreach (array_slice(explode('<li class="admin-online-item">', (string) $govde), 1) as $parca) {
        $parca = substr($parca, 0, (int) strpos($parca . '</li>', '</li>'));
        if (strpos($parca, $mail) !== false) { return $parca; }
    }
    return '';
}

$blok = listeBlogu($r ? $r['body'] : '', $MAIL);
check('F) temsilci listede', $blok !== '');
check('F) rozet "Pasif" (sari)', strpos($blok, 'admin-pill-amber') !== false && preg_match('#>Pasif</span>#', $blok) === 1);
$blokA = listeBlogu($r ? $r['body'] : '', $ADMIN);
check('F) admin kendisi "Aktif" (yesil)', strpos($blokA, 'admin-pill-green') !== false && preg_match('#>Aktif</span>#', $blokA) === 1);
check('F) ust barda pasif sayaci', $r && strpos($r['body'], 'home-online-dot--passive') !== false
    && preg_match('#<span class="home-online-label">pasif</span>#', $r['body']) === 1);
$sayim = bcc_presence_counts();
check('F) sayim: en az 1 aktif, 1 pasif', $sayim['active'] >= 1 && $sayim['passive'] >= 1, json_encode($sayim));
check('F) toplam == liste uzunlugu', bcc_online_user_count() === count(bcc_online_users()));
$COOKIE = $COOKIE_TEMSILCI;

echo "\nG) Temsilci donuyor -> tekrar Aktif\n";
check('G) aralik sifirlandi', araligiSifirla());
$r = nabiz($csrf, true);
check('G) nabiz (active=1) 200', $r && $r['code'] === 200);
check('G) durum Aktif', durum($MAIL) === 'active', (string) durum($MAIL));
geriAl($MAIL, 'last_activity_at', 31);
check('G) aralik sifirlandi', araligiSifirla());
check('G) (yeniden Pasif yapildi)', durum($MAIL) === 'passive');
$r = istek($BASE . '/dashboard.php');
check('G) sayfa acmak da Aktif yapiyor', $r && $r['code'] === 200 && durum($MAIL) === 'active', (string) durum($MAIL));

echo "\nH) Nabiz kesilince Cevrimdisi; yetkisiz nabiz reddediliyor\n";
geriAl($MAIL, 'last_seen_at', BCC_PRESENCE_WINDOW_MINUTES + 1);
check('H) nabiz ' . (BCC_PRESENCE_WINDOW_MINUTES + 1) . ' dk yok -> Cevrimdisi', durum($MAIL) === 'offline', (string) durum($MAIL));
$var = false;
foreach (bcc_online_users() as $u) { if ($u['email'] === $MAIL) { $var = true; } }
check('H) listede yok', !$var);
geriAl($MAIL, 'last_activity_at', 31);
check('H) aralik sifirlandi', araligiSifirla());
$r = nabiz('yanlis', true);
check('H) CSRF\'siz nabiz 403', $r && $r['code'] === 403, $r ? (string) $r['code'] : 'null');
/* Istek girisli oturumdan geldigi icin nabiz sayilir, ama active=1 ISLENMEZ. */
check('H) reddedilen nabiz Aktif yapmadi', durum($MAIL) === 'passive', (string) durum($MAIL));
check('H) aralik sifirlandi', araligiSifirla());
$r = nabiz($csrf, false);
check('H) gecerli nabiz gelince yeniden gorunuyor', $r && $r['code'] === 200 && durum($MAIL) !== 'offline', (string) durum($MAIL));

echo "\nI) Cikis yapinca hemen Cevrimdisi\n";
$r = istek($BASE . '/logout.php', 'csrf_token=' . $csrf);
check('I) cikis 302', $r && $r['code'] === 302, $r ? (string) $r['code'] : 'null');
check('I) last_seen_at NULL', bcc_fetch_column('SELECT last_seen_at FROM users WHERE email = :e', array('e' => $MAIL)) === null);
check('I) durum Cevrimdisi', durum($MAIL) === 'offline', (string) durum($MAIL));
$r = nabiz($csrf, true);
check('I) cikistan sonra nabiz 401', $r && $r['code'] === 401, $r ? (string) $r['code'] : 'null');
check('I) nabiz durumu geri getirmedi', durum($MAIL) === 'offline');

echo "\nJ) Hesabi kapatilan (is_active=0) kullanici Cevrimdisi\n";
bcc_execute('UPDATE users SET is_active = 0, last_seen_at = NOW(), last_activity_at = NOW() WHERE email = :e', array('e' => $MAIL));
check('J) damgalar taze olsa da Cevrimdisi', durum($MAIL) === 'offline', (string) durum($MAIL));

echo "\nSonuc: $gecti gecti, $kaldi kaldi\n";
exit($kaldi > 0 ? 1 : 0);
