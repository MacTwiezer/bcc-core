<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

define('BASE_URL', 'http://localhost');
define('OWNER_MAIL', 'wscb.owner@bcc-test.local');
define('VIEWER_MAIL', 'wscb.viewer@bcc-test.local');
define('PASS', 'WsCb!2026');
define('TEAM_A', 'WSCB Alani A');
define('TEAM_B', 'WSCB Alani B');

$results = array();
function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) { echo '         detay: ' . $detail . "\n"; }
}

function http_request($method, $path, $cookie = null, $post = null)
{
    $h = array();
    if ($cookie !== null) { $h[] = 'Cookie: ' . $cookie; }
    $o = array('http' => array('method' => $method, 'ignore_errors' => true));
    if ($method === 'POST') {
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
        $o['http']['content'] = http_build_query($post);
    }
    $o['http']['header'] = implode("\r\n", $h);
    $b = @file_get_contents(BASE_URL . $path, false, stream_context_create($o));
    $st = 0; $nc = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $x) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $x, $m)) { $st = (int) $m[1]; }
            if (stripos($x, 'Set-Cookie:') === 0) { $p = explode(';', substr($x, 11)); $nc = trim($p[0]); }
        }
    }
    return array('body' => (string) $b, 'cookie' => $nc, 'status' => $st);
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $r['body'], $m);
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => PASS, 'csrf_token' => isset($m[1]) ? $m[1] : '',
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function strip_comments($src)
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#^\s*//.*$#m', '', $src);
    return $src;
}

$root = dirname(__DIR__);
$partial = $root . '/src/partials/create_base_modal.php';
$wsLive = strip_comments(file_get_contents($root . '/public/workspaces.php'));
$dashLive = strip_comments(file_get_contents($root . '/public/dashboard.php'));
$homeJsLive = strip_comments(file_get_contents($root . '/public/assets/home.js'));

echo "\n--- A) Modal markup'i ORTAK partial'da ---\n";
check('A) partial dosyasi var', is_file($partial));
check('A) modal iskeleti partial da', is_file($partial)
    && strpos(file_get_contents($partial), 'id="home-create-base-modal"') !== false);
check('A) dashboard.php partial i require ediyor',
    strpos($dashLive, "partials/create_base_modal.php") !== false);
check('A) dashboard.php ARTIK kendi kopyasini tutmuyor',
    strpos($dashLive, 'id="home-create-base-modal"') === false);
check('A) workspaces.php ayni partial i require ediyor',
    strpos($wsLive, "partials/create_base_modal.php") !== false);
check('A) workspaces.php de kendi kopyasini yazmiyor',
    strpos($wsLive, 'id="home-create-base-form"') === false);

echo "\n--- B) workspaces.php tetikleyicileri ---\n";
check('B) "Base olustur" artik <button data-create-base-open>',
    preg_match('#<button[^>]*data-create-base-open[^>]*>.*?Base oluştur#s', $wsLive) === 1);
check('B) "+ Yeni base" de <button data-create-base-open>',
    preg_match('#<button[^>]*data-create-base-open[^>]*>\+ Yeni base</button>#s', $wsLive) === 1);
check('B) ARTIK bases.php ye giden <a> YOK',
    strpos($wsLive, 'href="/bases.php"') === false);
check('B) iki tetikleyici de var (sayim)',
    substr_count($wsLive, 'data-create-base-open') === 2,
    'adet: ' . substr_count($wsLive, 'data-create-base-open'));

echo "\n--- C) home.js coklu tetikleyici destekliyor ---\n";
check('C) tetikleyiciler data-* ile bulunuyor',
    strpos($homeJsLive, "querySelectorAll('[data-create-base-open]')") !== false);
check('C) tek id ye bagli eski secim KALMADI',
    strpos($homeJsLive, "getElementById('home-create-base-btn')") === false);
check('C) her tetikleyiciye dinleyici baglaniyor',
    strpos($homeJsLive, 'createTriggers.forEach') !== false);
check('C) odak ACAN tetikleyiciye donuyor (sabit butona degil)',
    strpos($homeJsLive, 'lastCreateTrigger') !== false);

echo "\n--- D) Geriye donuk uyum: dashboard id'leri KORUNDU ---\n";
$schemaLive = strip_comments(file_get_contents($root . '/src/schema.php'));
check('D) izgara kutucugu id sini koruyor',
    strpos($schemaLive, 'id="home-create-base-btn" data-create-base-open') !== false);
check('D) bos durum butonu da id + data tasiyor',
    preg_match('#home-empty-create-btn" id="home-create-base-btn" data-create-base-open#', $schemaLive) === 1);

$wipe = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name IN (:a, :b)',
        array(':a' => TEAM_A, ':b' => TEAM_B)) as $r) {
        bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id']));
    }
    bcc_execute('DELETE FROM users WHERE email IN (:a, :b)',
        array(':a' => OWNER_MAIL, ':b' => VIEWER_MAIL));
};
$wipe();

register_shutdown_function($wipe);

try {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM_A));
    $tidA = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM_B));
    $tidB = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
        array(':e' => OWNER_MAIL, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => 'WSCB Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
        array(':e' => VIEWER_MAIL, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => 'WSCB Viewer'));
    $viewerId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tidA,':u'=>$ownerId,':r'=>'owner'));
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tidB,':u'=>$ownerId,':r'=>'owner'));
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tidA,':u'=>$viewerId,':r'=>'viewer'));

    $ownerCookie = login(OWNER_MAIL);
    $viewerCookie = login(VIEWER_MAIL);

    echo "\n--- E) CANLI: modal sayfada + ON SECIM ---\n";

    $page = http_request('GET', '/workspaces.php?team_id=' . $tidB, $ownerCookie);
    check('E) sayfa 200', $page['status'] === 200, 'HTTP ' . $page['status']);
    check('E) modal sayfada basiliyor', strpos($page['body'], 'id="home-create-base-modal"') !== false);
    check('E) iki tetikleyici de render edildi',
        substr_count($page['body'], 'data-create-base-open') === 2,
        'adet: ' . substr_count($page['body'], 'data-create-base-open'));
    check('E) SECILI alan (B) on secili geliyor',
        preg_match('#<option\s+value="' . $tidB . '"\s+selected#', $page['body']) === 1);
    check('E) digeri (A) on secili DEGIL',
        preg_match('#<option\s+value="' . $tidA . '"\s+selected#', $page['body']) === 0);
    check('E) her iki alan da listede (liste KILITLENMEDI)',
        strpos($page['body'], TEAM_A) !== false && strpos($page['body'], TEAM_B) !== false);

    echo "\n--- F) CANLI ucdan uca: modal POST u base OLUSTURUYOR ---\n";
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $cm);
    $csrf = isset($cm[1]) ? $cm[1] : '';
    check('F) csrf token okundu', $csrf !== '');

    $before = (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :t', array(':t' => $tidB));
    $res = http_request('POST', '/api/base_create.php', $ownerCookie, array(
        'csrf_token' => $csrf, 'team_id' => $tidB, 'name' => 'WSCB Modal Base', 'description' => 'test',
    ));
    $json = json_decode($res['body'], true);
    check('F) uc nokta 200 + ok', $res['status'] === 200 && !empty($json['ok']),
        'HTTP ' . $res['status'] . ' body: ' . substr($res['body'], 0, 160));
    $after = (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :t', array(':t' => $tidB));
    check('F) base DB de gercekten olustu', $after === $before + 1, "$before -> $after");
    check('F) DOGRU calisma alaninda olustu',
        (int) bcc_fetch_column('SELECT team_id FROM bases WHERE name = :n', array(':n' => 'WSCB Modal Base')) === $tidB);
    check('F) yanit id donuyor (JS base e yonlendiriyor)', !empty($json['id']));

    echo "\n--- G) Yetki: viewer ne tetikleyici ne modal goruyor ---\n";
    $vPage = http_request('GET', '/workspaces.php?team_id=' . $tidA, $viewerCookie);
    check('G) viewer sayfayi gorebiliyor', $vPage['status'] === 200, 'HTTP ' . $vPage['status']);
    check('G) viewer da tetikleyici HIC basilmiyor',
        strpos($vPage['body'], 'data-create-base-open') === false);
    check('G) viewer da modal HIC basilmiyor (form/ucnokta adi kaynakta yok)',
        strpos($vPage['body'], 'id="home-create-base-modal"') === false);

    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $vPage['body'], $vcm);
    $vRes = http_request('POST', '/api/base_create.php', $viewerCookie, array(
        'csrf_token' => isset($vcm[1]) ? $vcm[1] : '', 'team_id' => $tidA, 'name' => 'WSCB Olmamali',
    ));
    $vJson = json_decode($vRes['body'], true);
    check('G) viewer in olusturma istegi REDDEDILIYOR (gizleme != yetki)',
        $vRes['status'] !== 200 || empty($vJson['ok']),
        'HTTP ' . $vRes['status'] . ' body: ' . substr($vRes['body'], 0, 160));
    check('G) reddedilen istek base OLUSTURMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE name = :n', array(':n' => 'WSCB Olmamali')) === 0);

    echo "\n--- H) dashboard.php bozulmadi ---\n";
    $dash = http_request('GET', '/dashboard.php', $ownerCookie);
    check('H) dashboard 200', $dash['status'] === 200, 'HTTP ' . $dash['status']);
    check('H) modal orada da basiliyor (partial calisiyor)',
        strpos($dash['body'], 'id="home-create-base-modal"') !== false);
    check('H) eski id li tetikleyici hala var (testler ona bakiyor)',
        strpos($dash['body'], 'id="home-create-base-btn"') !== false);
    check('H) o tetikleyici data-* de tasiyor (home.js onu buluyor)',
        strpos($dash['body'], 'data-create-base-open') !== false);
} catch (Throwable $e) {
    echo "\n[HATA] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $results[] = false;
}

$wipe();
check('Z) test verisi temizlendi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name IN (:a,:b)',
        array(':a' => TEAM_A, ':b' => TEAM_B)) === 0
    && (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE email IN (:a,:b)',
        array(':a' => OWNER_MAIL, ':b' => VIEWER_MAIL)) === 0);

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($pass === $total ? 'GECTI' : 'KALDI') . " ($pass/$total)\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
