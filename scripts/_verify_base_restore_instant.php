<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();
bcc_test_purge_own_audit();

define('PROBE_MAIL', 'restore.owner@bcc-test.local');
define('PROBE_TEAM', 'ZZ Geri Yukleme');
define('PROBE_BASE', 'ZZ Geri Yuklenecek Base');

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$BASE = 'http://localhost';
$COOKIE = tempnam(sys_get_temp_dir(), 'bccrst');

function istek($url, $post = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $COOKIE,
        CURLOPT_COOKIEFILE => $COOKIE, CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $c, 'body' => (string) $b);
}

$r = istek($BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$cleanup = function () use ($COOKIE) {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => PROBE_TEAM)) as $x) {
        bcc_execute('DELETE FROM teams WHERE id = :i', array(':i' => $x['id']));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => PROBE_MAIL));
    @unlink($COOKIE);
};
$cleanup();
register_shutdown_function($cleanup);

$sifre = 'p' . bin2hex(random_bytes(10));
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => PROBE_TEAM));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array(':e' => PROBE_MAIL, ':h' => password_hash($sifre, PASSWORD_DEFAULT), ':n' => 'Restore Owner'));
$userId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)',
    array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t,:n,:u)',
    array(':t' => $teamId, ':n' => PROBE_BASE, ':u' => $userId));
$baseId = (int) bcc_last_insert_id();

$r = istek($BASE . '/login.php');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
istek($BASE . '/login.php', 'csrf_token=' . $m[1] . '&email=' . rawurlencode(PROBE_MAIL) . '&password=' . rawurlencode($sifre));

$d = istek($BASE . '/dashboard.php');
preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $d['body'], $cm);
$csrf = isset($cm[1]) ? $cm[1] : '';
check('A) oturum acildi, dashboard geldi', $d['code'] === 200 && $csrf !== '', 'HTTP ' . $d['code']);
check('A) base kart olarak sayfada',
    strpos($d['body'], 'data-base-id="' . $baseId . '"') !== false);

echo "\nB) Silme ANLIK (JSON doner, sayfa yenilenmez)\n";
$x = istek($BASE . '/api/base_delete.php', http_build_query(array('csrf_token' => $csrf, 'base_id' => $baseId)));
$j = json_decode($x['body'], true);
check('B) 200 + ok', $x['code'] === 200 && isset($j['ok']) && $j['ok'] === true, $x['code'] . ' ' . substr($x['body'], 0, 90));
check('B) yanit HTML DEGIL, JSON (yonlendirme yok)', $x['body'] !== '' && $x['body'][0] === '{');
check('B) DB de soft-delete isaretlendi',
    bcc_fetch_column('SELECT deleted_at FROM bases WHERE id = :i', array(':i' => $baseId)) !== null);

echo "\nC) Geri yukleme ANLIK ve KART HTML'i doner\n";
$x = istek($BASE . '/api/base_restore.php', http_build_query(array('csrf_token' => $csrf, 'base_id' => $baseId)));
$j = json_decode($x['body'], true);
check('C) 200 + ok', $x['code'] === 200 && isset($j['ok']) && $j['ok'] === true, $x['code'] . ' ' . substr($x['body'], 0, 90));
check('C) DB de geri yuklendi',
    bcc_fetch_column('SELECT deleted_at FROM bases WHERE id = :i', array(':i' => $baseId)) === null);

check('C) yanit base_id tasiyor', isset($j['base_id']) && (int) $j['base_id'] === $baseId);
check('C) yanit team_id tasiyor', isset($j['team_id']) && (int) $j['team_id'] === $teamId);
check('C) ⭐ yanit KART HTML i tasiyor (sayfa yenilemeye gerek yok)',
    isset($j['card_html']) && $j['card_html'] !== '',
    isset($j['card_html']) ? 'uzunluk ' . strlen($j['card_html']) : 'anahtar yok');

$kart = isset($j['card_html']) ? $j['card_html'] : '';
check('C) kart dogru base id yi tasiyor', strpos($kart, 'data-base-id="' . $baseId . '"') !== false);
check('C) kart dogru team id yi tasiyor (JS gruba yerlestirebilsin)',
    strpos($kart, 'data-team-id="' . $teamId . '"') !== false);
check('C) kart .home-base-card sinifinda (grid ile uyumlu)',
    strpos($kart, 'home-base-card') !== false);
check('C) kartta base adi gecıyor', strpos($kart, htmlspecialchars(PROBE_BASE, ENT_QUOTES, 'UTF-8')) !== false);
check('C) kart TEK kok ogeden olusuyor (JS firstElementChild bekliyor)',
    substr(trim($kart), 0, 3) === '<a ' && substr_count($kart, '<a class="home-base-card') === 1);

echo "\nD) Sunucu kart HTML ini KENDI uretiyor (istemcide kopya yok)\n";
$js = file_get_contents(__DIR__ . '/../public/assets/account-menu.js');
check('D) account-menu.js kart HTML i INSA ETMIYOR',
    strpos($js, 'home-base-card"') === false && strpos($js, "home-base-icon") === false);
check('D) account-menu.js sunucudan geleni kullaniyor', strpos($js, 'card_html') !== false);
check('D) yerlestirme fonksiyonu var', strpos($js, 'insertRestoredCard') !== false);

$api = file_get_contents(__DIR__ . '/../public/api/base_restore.php');
check('D) uc nokta ORTAK render fonksiyonunu cagiriyor',
    strpos($api, 'bcc_render_home_base_card(') !== false);

echo "\nE) Yetki: baskasinin base ini geri yukleyemez\n";
bcc_execute('UPDATE bases SET deleted_at = NOW() WHERE id = :i', array(':i' => $baseId));
bcc_execute('UPDATE team_members SET role = :r WHERE team_id = :t AND user_id = :u',
    array(':r' => 'editor', ':t' => $teamId, ':u' => $userId));
$d2 = istek($BASE . '/dashboard.php');
preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $d2['body'], $cm2);
$x = istek($BASE . '/api/base_restore.php',
    http_build_query(array('csrf_token' => isset($cm2[1]) ? $cm2[1] : $csrf, 'base_id' => $baseId)));
check('E) editor rolu geri yukleyemiyor (403)', $x['code'] === 403, 'HTTP ' . $x['code']);
check('E) base HALA silinmis durumda',
    bcc_fetch_column('SELECT deleted_at FROM bases WHERE id = :i', array(':i' => $baseId)) !== null);

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
