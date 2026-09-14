<?php

/*
 * Profil fotografinin uygulamanin her yerinde gorunmesi — 2026-09-14.
 *
 * Iki iddia dogrulaniyor:
 *  1) Kisinin KENDI avatari (ust cubuk + hesap sayfasi) HTML'nin icine gomulu
 *     geliyor: ayri bir istek yok, yeni sekmede once bos daire gorunmez.
 *     Esigin (48KB) ustundeki dosya gomulmez, adresle gelir.
 *  2) Baska kullanicilarin avatarlari fotografa bagli: grid "Olusturan" ve
 *     kullanici hucreleri, ekip uyeleri, calisma alani katilimcilari, paylasim
 *     penceresi, bildirimler, yorumlar, cop kutusu, admin paneli, hucre
 *     guncelleme yaniti. Ve hepsinde EKIP IZOLASYONU: bakan kisinin goremedigi
 *     birinin fotografi hicbir yere basilmiyor.
 *
 * Gercek HTTP (Apache, localhost). Kendi kullanici/ekip/base/tablo/kayit/
 * yorum/denetim verisini kurar ve sonunda HEPSINI siler; sayilarin koşu
 * oncesiyle ayni oldugunu kendisi dogrular. Test ekibinin Slack webhook'u
 * olmadigi icin hicbir mesaj gonderilmez, webhooklar susturulmaz.
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
        return array('code' => 0, 'body' => '');
    }
    return array('code' => $code, 'body' => substr($raw, $hlen));
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

/* json_encode eğik çizgileri kaçırıyor ("\/api\/..."); HTML'de de &amp; var.
   Karşılaştırmalar tek bir biçim üzerinden yapılsın. */
function duz($metin)
{
    return str_replace(array('\\/', '&amp;'), array('/', '&'), $metin);
}

function adres_var($govde, $userId)
{
    return strpos(duz($govde), '/api/avatar.php?user_id=' . (int) $userId . '&v=') !== false;
}

function png_parca($tip, $veri)
{
    return pack('N', strlen($veri)) . $tip . $veri . pack('N', crc32($tip . $veri));
}

function png_yap($w, $h, $gurultu = false)
{
    $ham = '';
    for ($y = 0; $y < $h; $y++) {
        $ham .= "\x00";
        for ($x = 0; $x < $w; $x++) {
            $ham .= $gurultu ? random_bytes(3) : chr(($x * 9) & 255) . chr(($y * 7) & 255) . "\x80";
        }
    }
    return "\x89PNG\r\n\x1A\n" . png_parca('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        . png_parca('IDAT', gzcompress($ham)) . png_parca('IEND', '');
}

$r = istek(tempnam(sys_get_temp_dir(), 'bccave'), $BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$avDir = bcc_avatar_storage_dir();
$dirOnceVardi = is_dir($avDir);
$sayim = function () use ($avDir) {
    return array(
        'users' => (int) bcc_fetch_column('SELECT COUNT(*) FROM users'),
        'teams' => (int) bcc_fetch_column('SELECT COUNT(*) FROM teams'),
        'bases' => (int) bcc_fetch_column('SELECT COUNT(*) FROM bases'),
        'records' => (int) bcc_fetch_column('SELECT COUNT(*) FROM records'),
        'comments' => (int) bcc_fetch_column('SELECT COUNT(*) FROM comments'),
        'avatar_dosyasi' => is_dir($avDir) ? count(glob($avDir . '/*')) : 0,
    );
};
$once = $sayim();

$SON = bin2hex(random_bytes(4));
$SIFRE = 'Heryer!' . $SON;
$K = array();
foreach (array('A' => 'Ayse Bakan', 'B' => 'Burak Olusturan', 'C' => 'Cem Disarida', 'D' => 'Deniz Fotografsiz', 'M' => 'Mert Admin') as $k => $ad) {
    $email = 'aveverywhere.' . strtolower($k) . ".$SON@bcc-test.local";
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, :a, 1)',
        array('e' => $email, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $ad, 'a' => $k === 'M' ? 1 : 0)
    );
    $K[$k] = array('id' => (int) bcc_last_insert_id(), 'email' => $email, 'ad' => $ad, 'jar' => tempnam(sys_get_temp_dir(), 'bccave'));
}

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "AVEVERY-1-$SON"));
$T1 = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "AVEVERY-2-$SON"));
$T2 = (int) bcc_last_insert_id();

foreach (array(array($T1, 'A', 'owner'), array($T1, 'B', 'editor'), array($T1, 'D', 'viewer'), array($T2, 'C', 'owner')) as $uy) {
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $uy[0], 'u' => $K[$uy[1]]['id'], 'r' => $uy[2]));
}

$bases = array();
$cleanup = function () use (&$K, &$bases, $T1, $T2, $avDir, $dirOnceVardi) {
    foreach ($bases as $bid) {
        bcc_execute('DELETE FROM bases WHERE id = :id', array('id' => $bid));
    }
    foreach ($K as $k) {
        @unlink(bcc_avatar_path($k['id']));
        bcc_execute('DELETE FROM comments WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM audit_log WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $k['email']));
        bcc_execute('DELETE FROM team_members WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $k['id']));
        @unlink($k['jar']);
    }
    bcc_execute('DELETE FROM audit_log WHERE team_id IN (' . (int) $T1 . ',' . (int) $T2 . ')');
    bcc_execute('DELETE FROM teams WHERE id IN (' . (int) $T1 . ',' . (int) $T2 . ')');
    if (!$dirOnceVardi && is_dir($avDir) && count(glob($avDir . '/*')) === 0) {
        @rmdir($avDir);
    }
};
register_shutdown_function($cleanup);

if (!is_dir($avDir)) {
    mkdir($avDir, 0755, true);
}
$kucuk = png_yap(64, 64);
foreach (array('A', 'B', 'C', 'M') as $k) {
    file_put_contents(bcc_avatar_path($K[$k]['id']), $kucuk);
}
check('kucuk test avatari gomme esiginin altinda', strlen($kucuk) < BCC_AVATAR_INLINE_MAX_BYTES, (string) strlen($kucuk));

bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array('t' => $T1, 'n' => "AvBase $SON", 'u' => $K['B']['id']));
$bases[] = $B1 = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO bases (team_id, name, created_by, deleted_at, deleted_by) VALUES (:t, :n, :u, NOW(), :d)',
    array('t' => $T1, 'n' => "AvCop $SON", 'u' => $K['B']['id'], 'd' => $K['B']['id']));
$bases[] = (int) bcc_last_insert_id();

bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array('b' => $B1, 'n' => 'Avatar Tablo'));
$TB = (int) bcc_last_insert_id();
$alan = function ($ad, $tip, $pos) use ($TB) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)',
        array('t' => $TB, 'n' => $ad, 'ft' => $tip, 'p' => $pos));
    return (int) bcc_last_insert_id();
};
$alan('Ad', 'single_line_text', 0);
$F_SORUMLU = $alan('Sorumlu', 'user', 1);
$alan('Olusturan', 'created_by', 2);

bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)', array('t' => $TB, 'u' => $K['B']['id']));
$R1 = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, 1, :u)', array('t' => $TB, 'u' => $K['C']['id']));
$R2 = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r, :f, :v)', array('r' => $R1, 'f' => $F_SORUMLU, 'v' => $K['B']['id']));
bcc_execute('UPDATE records SET slack_notified_at = NOW(), updated_at = updated_at WHERE table_id = :t', array('t' => $TB));

bcc_execute('INSERT INTO comments (record_id, user_id, body) VALUES (:r, :u, :b)', array('r' => $R1, 'u' => $K['B']['id'], 'b' => 'Burak yorumu'));
bcc_execute("INSERT INTO audit_log (team_id, user_id, action, entity_type, entity_id, details) VALUES (:t, :u, 'record.create', 'record', :e, '{}')",
    array('t' => $T1, 'u' => $K['B']['id'], 'e' => $R1));

foreach (array('A', 'M') as $k) {
    $tok = jeton($K[$k]['jar'], '/login.php');
    $r = istek($K[$k]['jar'], $BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode($K[$k]['email']) . '&password=' . rawurlencode($SIFRE));
    check("$k giris yapti (302)", $r['code'] === 302, $r['code']);
}
$A = $K['A'];
$idA = $A['id']; $idB = $K['B']['id']; $idC = $K['C']['id']; $idD = $K['D']['id'];

echo "\n1) KENDI avatarim HTML'nin icinde gomulu — yeni sekmede once bos daire yok\n";

$r = istek($A['jar'], $BASE . '/dashboard.php');
$dugme = preg_match('/<button[^>]*data-avatar-self[^>]*>(.*?)<\/button>/s', $r['body'], $m) ? $m[1] : '';
check('ust cubuk dugmesi bulundu', $dugme !== '');
check('resim data: URI olarak gomulu', strpos($dugme, 'src="data:image/png;base64,') !== false, substr($dugme, 0, 80));
check('decoding="sync" (resim metinle ayni karede)', strpos($dugme, 'decoding="sync"') !== false);
check('dugmede ayri istek atacak /api/avatar.php adresi YOK', strpos($dugme, '/api/avatar.php') === false);
check('dugmede bas harf YOK', strpos(strip_tags($dugme), 'A') === false, strip_tags($dugme));
if (preg_match('/src="data:image\/png;base64,([^"]+)"/', $dugme, $mm)) {
    check('gomulu baytlar diskteki dosyayla BIREBIR ayni', base64_decode($mm[1]) === $kucuk);
}

$r = istek($A['jar'], $BASE . '/account.php');
check('hesap sayfasi: buyuk yuz + ust cubuk = 2 gomulu resim', substr_count($r['body'], 'src="data:image/png;base64,') === 2, (string) substr_count($r['body'], 'src="data:image/png;base64,'));

$buyuk = png_yap(160, 160, true);
file_put_contents(bcc_avatar_path($idA), $buyuk);
$r = istek($A['jar'], $BASE . '/dashboard.php');
$dugme = preg_match('/<button[^>]*data-avatar-self[^>]*>(.*?)<\/button>/s', $r['body'], $m) ? $m[1] : '';
check('esik ustu (' . round(strlen($buyuk) / 1024) . 'KB) dosya GOMULMUYOR', strpos($dugme, 'data:image') === false);
check('... onun yerine surumlu adres kullaniliyor', adres_var($dugme, $idA));
file_put_contents(bcc_avatar_path($idA), $kucuk);

echo "\n2) Grid: \"Olusturan\" ve kullanici hucreleri\n";

$r = istek($A['jar'], $BASE . '/grid.php?table_id=' . $TB);
check('grid acildi (200)', $r['code'] === 200, $r['code']);
$govde = $r['body'];

$satir = function ($recId) use ($govde) {
    return preg_match('/<tr[^>]*data-record-id="' . (int) $recId . '"[^>]*>(.*?)<\/tr>/s', $govde, $m) ? $m[1] : '';
};
$s1 = $satir($R1);
$s2 = $satir($R2);
check('R1 satiri bulundu', $s1 !== '');
check('R1 "Olusturan" + "Sorumlu" = B\'nin fotografi (2 hucre)', substr_count(duz($s1), '/api/avatar.php?user_id=' . $idB . '&v=') === 2, (string) substr_count(duz($s1), '/api/avatar.php?user_id=' . $idB . '&v='));
check('R2 satiri bulundu', $s2 !== '');
check('R2 "Olusturan" C (baska ekip): fotograf YOK, bas harf "C" var',
    !adres_var($s2, $idC) && (bool) preg_match('/cell-user-avatar"[^>]*>C<\/span>/', $s2));
check('grid sayfasinin HICBIR yerinde C\'nin fotograf adresi yok', !adres_var($govde, $idC));
check('hucre resimleri gecikmeli yukleniyor (loading="lazy")', strpos($s1, 'loading="lazy"') !== false);

echo "\n3) Paylasim penceresi ve katilimci onizlemesi (grid sayfasinda)\n";

check('paylasim verisinde B\'nin avatar adresi var', (bool) preg_match('/"id":' . $idB . ',[^}]*"avatar":"\/api\/avatar\.php\?user_id=' . $idB . '&v=/', duz($govde)));
check('paylasim verisinde fotografsiz D icin avatar null', (bool) preg_match('/"id":' . $idD . ',[^}]*"avatar":null/', duz($govde)));
check('katilimci onizlemesi (collab-popover) B\'nin resmini basiyor',
    (bool) preg_match('/collab-popover-avatar"[^>]*><img class="bcc-avatar-img" src="\/api\/avatar\.php\?user_id=' . $idB . '&v=/', duz($govde)));

echo "\n4) Ekip uyeleri ve calisma alani katilimcilari\n";

$r = istek($A['jar'], $BASE . '/team_members.php?team_id=' . $T1);
check('team_members.php 200', $r['code'] === 200, $r['code']);
check('B\'nin resmi var', adres_var($r['body'], $idB));
check('fotografsiz D bas harfle ("D")', (bool) preg_match('/<div class="ws-collab-avatar">D<\/div>/', $r['body']));

$r = istek($A['jar'], $BASE . '/workspaces.php?team_id=' . $T1);
check('workspaces.php 200', $r['code'] === 200, $r['code']);
check('katilimci kartinda B\'nin resmi', (bool) preg_match('/<span class="sp-avatar"><img class="bcc-avatar-img" src="\/api\/avatar\.php\?user_id=' . $idB . '&v=/', duz($r['body'])));

echo "\n5) Bildirimler\n";

$r = istek($A['jar'], $BASE . '/dashboard.php');
check('bildirim avatari B\'nin resmi', (bool) preg_match('/home-notif-avatar"><img class="bcc-avatar-img" src="\/api\/avatar\.php\?user_id=' . $idB . '&v=/', duz($r['body'])));

echo "\n6) JSON uclari: yorumlar, cop kutusu, hucre guncelleme\n";

$r = istek($A['jar'], $BASE . '/api/comment_list.php?record_id=' . $R1);
$j = json_decode($r['body'], true);
$yorum = isset($j['comments'][0]) ? $j['comments'][0] : array();
check('yorum listesi: B\'nin yorumunda author_avatar = B adresi', isset($yorum['author_avatar']) && adres_var($yorum['author_avatar'], $idB), $r['body']);

$csrf = jeton($A['jar'], '/grid.php?table_id=' . $TB);
$r = istek($A['jar'], $BASE . '/api/comment_add.php', 'csrf_token=' . $csrf . '&record_id=' . $R1 . '&body=' . rawurlencode('Ayse yorumu'));
$j = json_decode($r['body'], true);
check('yeni yorum yanitinda author_avatar = kendi adresim', isset($j['comment']['author_avatar']) && adres_var($j['comment']['author_avatar'], $idA), substr($r['body'], 0, 200));

$r = istek($A['jar'], $BASE . '/api/trash_list.php');
$j = json_decode($r['body'], true);
$bulundu = null;
foreach ((isset($j['items']) ? $j['items'] : array()) as $it) {
    if (strpos((string) $it['message'], "AvCop $SON") !== false) { $bulundu = $it; }
}
check('cop kutusu: B\'nin sildigi base listede', $bulundu !== null, $r['body']);
check('... actor_avatar = B adresi', $bulundu !== null && isset($bulundu['actor_avatar']) && adres_var($bulundu['actor_avatar'], $idB));

$r = istek($A['jar'], $BASE . '/api/cell_update.php', 'csrf_token=' . $csrf . '&record_id=' . $R2 . '&field_id=' . $F_SORUMLU . '&value=' . $idB);
$j = json_decode($r['body'], true);
check('kullanici alanina B atandi -> display_avatar = B adresi', !empty($j['ok']) && isset($j['display_avatar']) && adres_var($j['display_avatar'], $idB), substr($r['body'], 0, 200));

$r = istek($A['jar'], $BASE . '/api/cell_update.php', 'csrf_token=' . $csrf . '&record_id=' . $R2 . '&field_id=' . $F_SORUMLU . '&value=' . $idD);
$j = json_decode($r['body'], true);
check('fotografsiz D atandi -> display_avatar null', !empty($j['ok']) && array_key_exists('display_avatar', $j) && $j['display_avatar'] === null, substr($r['body'], 0, 200));

echo "\n7) Admin paneli (platform admini herkesi gorebilir)\n";

$r = istek($K['M']['jar'], $BASE . '/admin/index.php');
check('admin/index.php 200', $r['code'] === 200, $r['code']);
check('admin listesinde B\'nin resmi', (bool) preg_match('/admin-avatar"><img class="bcc-avatar-img" src="\/api\/avatar\.php\?user_id=' . $idB . '&v=/', duz($r['body'])));
check('admin C\'yi de goruyor (ekip siniri yok)', (bool) preg_match('/admin-avatar"><img class="bcc-avatar-img" src="\/api\/avatar\.php\?user_id=' . $idC . '&v=/', duz($r['body'])));

echo "\n8) Tarayici resimleri gercekten alabiliyor mu (adres = yetki)\n";

$r = istek($A['jar'], $BASE . '/grid.php?table_id=' . $TB);
preg_match('/\/api\/avatar\.php\?user_id=' . $idB . '&v=[0-9-]+/', duz($r['body']), $mb);
$rb = istek($A['jar'], $BASE . (isset($mb[0]) ? $mb[0] : '/api/avatar.php?user_id=0'));
check('A, grid\'de basilan B adresinden resmi alabiliyor (200)', $rb['code'] === 200, $rb['code']);
$rc = istek($A['jar'], $BASE . '/api/avatar.php?user_id=' . $idC . '&v=1');
check('A, C\'nin fotografini dogrudan isteyince 404', $rc['code'] === 404, $rc['code']);

$cleanup();

echo "\n9) Kirlilik\n";
$sonra = $sayim();
foreach ($once as $ad => $deger) {
    check("$ad sayisi ayni ($deger)", $sonra[$ad] === $deger, $deger . ' -> ' . $sonra[$ad]);
}
check('klasor onceden yoksa geride birakilmadi', $dirOnceVardi || !is_dir($avDir));

echo "\nSONUC: $gecti gecti, $kaldi kaldi\n";
exit($kaldi === 0 ? 0 : 1);
