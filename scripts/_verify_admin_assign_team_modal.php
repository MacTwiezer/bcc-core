<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

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

function istek($jar, $url, $post = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) { return array('code' => 0, 'head' => '', 'body' => ''); }
    return array('code' => $code, 'head' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen));
}

$r = istek(tempnam(sys_get_temp_dir(), 'bccat'), $BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$sayim = function () {
    $o = array();
    foreach (array('users', 'teams', 'team_members', 'bases', 'records') as $t) {
        $o[$t] = (int) bcc_fetch_column('SELECT COUNT(*) FROM ' . $t);
    }
    return $o;
};
$once = $sayim();

$SON = bin2hex(random_bytes(4));
$SIFRE = 'Atama!' . $SON;
$K = array();
foreach (array('admin' => array('Atama Admin', 1, 1), 'uye' => array('Atama Uye', 0, 1), 'pasif' => array('Atama Pasif', 0, 0), 'normal' => array('Atama Normal', 0, 1)) as $k => $v) {
    $email = "atama.$k.$SON@bcc-test.local";
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,:a,:ac)',
        array('e' => $email, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $v[0], 'a' => $v[1], 'ac' => $v[2]));
    $K[$k] = array('id' => (int) bcc_last_insert_id(), 'email' => $email, 'ad' => $v[0], 'jar' => tempnam(sys_get_temp_dir(), 'bccat'));
}
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "ATAMA-$SON"));
$T = (int) bcc_last_insert_id();

$cleanup = function () use (&$K, $T) {
    bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $T));
    bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $T));
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $T));
    foreach ($K as $k) {
        bcc_execute('DELETE FROM audit_log WHERE user_id = :u', array('u' => $k['id']));
        bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $k['email']));
        bcc_execute('DELETE FROM team_members WHERE user_id = :u', array('u' => $k['id']));
        bcc_execute('DELETE FROM users WHERE id = :u', array('u' => $k['id']));
        @unlink($k['jar']);
    }
};
register_shutdown_function($cleanup);

$giris = function ($k) use (&$K, $BASE, $SIFRE) {
    $lp = istek($K[$k]['jar'], $BASE . '/login.php');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $lp['body'], $m);
    return istek($K[$k]['jar'], $BASE . '/login.php', 'csrf_token=' . (isset($m[1]) ? $m[1] : '') . '&email=' . rawurlencode($K[$k]['email']) . '&password=' . rawurlencode($SIFRE));
};
$jeton = function ($k, $sayfa) use (&$K, $BASE) {
    $p = istek($K[$k]['jar'], $BASE . $sayfa);
    return preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $p['body'], $m) ? $m[1] : '';
};
$ata = function ($k, $csrf, $userId, $teamId, $role) use (&$K, $BASE) {
    $r = istek($K[$k]['jar'], $BASE . '/api/admin_team_member_assign.php',
        'csrf_token=' . $csrf . '&user_id=' . (int) $userId . '&team_id=' . (int) $teamId . '&role=' . rawurlencode($role));
    $r['json'] = json_decode($r['body'], true);
    return $r;
};

echo "A) Admin paneli: sayfa degil pencere\n";
check('admin giris (302)', $giris('admin')['code'] === 302);
$p = istek($K['admin']['jar'], $BASE . '/admin/index.php');
check('admin/index.php 200', $p['code'] === 200, $p['code']);
check('pencere basiliyor (id="assign-team-modal", hidden)', strpos($p['body'], 'id="assign-team-modal" hidden') !== false);
check('tetikleyici data-assign-team-btn', strpos($p['body'], 'data-assign-team-btn') !== false);
check('assign-team-modal.js yukleniyor', strpos($p['body'], 'assign-team-modal.js') !== false);
check('form yeni admin ucuna gidiyor', strpos($p['body'], 'action="/api/admin_team_member_assign.php"') !== false);
check('kullanici ve ekip listelerinin yaninda arama kutusu', substr_count($p['body'], 'data-assign-filter=') === 2);
$uyeSecenek = '<option value="' . $K['uye']['id'] . '">';
$pasifSecenek = '<option value="' . $K['pasif']['id'] . '">';
check('aktif kullanici listede', strpos($p['body'], $uyeSecenek) !== false);
check('PASIF kullanici listede degil (atanamaz)', strpos($p['body'], $pasifSecenek) === false);
check('ekip listede', strpos($p['body'], '<option value="' . $T . '">ATAMA-' . $SON . '</option>') !== false);
check('rol varsayilani viewer', preg_match('#<option value="viewer" selected>#', $p['body']) === 1);

$eski = istek($K['admin']['jar'], $BASE . '/admin/assign_team.php');
check('eski sayfa adresi admin paneline yonlendiriyor ve pencereyi aciyor (302 -> ?ekibe_ata=1)',
    $eski['code'] === 302 && stripos($eski['head'], 'Location: /admin/index.php?ekibe_ata=1') !== false, $eski['code']);

echo "\nB) Sunucu ucu: yetki ve dogrulama\n";
$csrf = $jeton('admin', '/admin/index.php');
$get = istek($K['admin']['jar'], $BASE . '/api/admin_team_member_assign.php');
check('GET 405', $get['code'] === 405, $get['code']);
$noCsrf = $ata('admin', 'yanlis', $K['uye']['id'], $T, 'editor');
check('CSRF yok -> 403', $noCsrf['code'] === 403, $noCsrf['code']);
check('giris yapmamis -> 401', istek(tempnam(sys_get_temp_dir(), 'bccat'), $BASE . '/api/admin_team_member_assign.php', 'user_id=1&team_id=1&role=viewer')['code'] === 401);

check('normal kullanici giris', $giris('normal')['code'] === 302);
$nCsrf = $jeton('normal', '/dashboard.php');
$n = $ata('normal', $nCsrf, $K['uye']['id'], $T, 'owner');
check('platform yoneticisi olmayan -> 403', $n['code'] === 403, $n['code'] . ' ' . $n['body']);
check('403 sonrasi uyelik YOK', (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array('t' => $T)) === 0);

$e = $ata('admin', $csrf, 0, $T, 'viewer');
check('kullanici secilmemis -> 422', $e['code'] === 422, $e['code']);
$e = $ata('admin', $csrf, $K['uye']['id'], 99999999, 'viewer');
check('olmayan ekip -> 404', $e['code'] === 404, $e['code']);
$e = $ata('admin', $csrf, $K['uye']['id'], $T, 'superadmin');
check('gecersiz rol -> 422', $e['code'] === 422, $e['code']);
$e = $ata('admin', $csrf, $K['pasif']['id'], $T, 'viewer');
check('pasif kullanici -> 422', $e['code'] === 422, $e['code'] . ' ' . $e['body']);

echo "\nC) Atama ve rol degisikligi\n";
$a = $ata('admin', $csrf, $K['uye']['id'], $T, 'editor');
check('atama 200, created=true', $a['code'] === 200 && $a['json']['ok'] === true && $a['json']['created'] === true, $a['code'] . ' ' . $a['body']);
check('DB: uye editor', bcc_fetch_column('SELECT role FROM team_members WHERE team_id = :t AND user_id = :u', array('t' => $T, 'u' => $K['uye']['id'])) === 'editor');
$a = $ata('admin', $csrf, $K['uye']['id'], $T, 'owner');
check('ayni kisiye tekrar -> rol degisti, created=false', $a['code'] === 200 && $a['json']['created'] === false, $a['body']);
check('DB: uye owner, tek satir',
    bcc_fetch_column('SELECT role FROM team_members WHERE team_id = :t AND user_id = :u', array('t' => $T, 'u' => $K['uye']['id'])) === 'owner'
    && (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array('t' => $T)) === 1);
check('denetim kaydi: assign + role_change',
    (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE team_id = :t AND action IN ('team_member.assign','team_member.role_change')", array('t' => $T)) === 2);
$p2 = istek($K['admin']['jar'], $BASE . '/admin/index.php');
check('admin panelinde ekip kartinda uye gorunuyor', strpos($p2['body'], htmlspecialchars($K['uye']['email'], ENT_QUOTES, 'UTF-8')) !== false);

echo "\nD) Paylasim penceresinin ucu DOKUNULMADI\n";
$src = (string) file_get_contents(__DIR__ . '/../public/api/team_member_assign.php');
check('api/team_member_assign.php hala ekip Owner yetkisiyle calisiyor (bcc_can_manage_members)',
    strpos($src, 'bcc_can_manage_members($myRole)') !== false && strpos($src, 'bcc_share_modal_payload') !== false);

$cleanup();
$cleanup = function () {};
check('Kirlilik: sayaclar oncekiyle ayni', $sayim() === $once, json_encode(array_diff_assoc($sayim(), $once)));

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
