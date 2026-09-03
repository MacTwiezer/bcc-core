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
define('OWNER_MAIL', 'wssm.owner@bcc-test.local');
define('VIEWER_MAIL', 'wssm.viewer@bcc-test.local');
define('GUEST_MAIL', 'wssm.guest@bcc-test.local');
define('PASS', 'WsSm!2026');
define('TEAM', 'WSSM Test Alani');

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

$root = dirname(__DIR__);
$wsCode = file_get_contents($root . '/public/workspaces.php');
$wsJs = file_get_contents($root . '/public/assets/workspaces.js');
$smJs = file_get_contents($root . '/public/assets/share-modal.js');

function strip_comments($src)
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#^\s*//.*$#m', '', $src);
    return $src;
}
$wsCodeLive = strip_comments($wsCode);
$wsJsLive = strip_comments($wsJs);

echo "\n--- A) Tetikleyici artik sayfa degistirmiyor ---\n";
check('A) "Katilimcilari yonet" bir <button data-share-modal-open>',
    preg_match('#<button[^>]*data-share-modal-open[^>]*>.*?Katılımcıları yönet.*?</button>#s', $wsCode) === 1);
check('A) o buton ARTIK team_members.php ye <a href> DEGIL',
    preg_match('#<a[^>]+href="/team_members\.php[^"]*"[^>]*>\s*<svg.*?Katılımcıları yönet#s', $wsCode) === 0);

echo "\n--- B) Modal iskeleti + JS + CSS sayfaya geliyor ---\n";
check('B) share_modal.php partial i require ediliyor',
    strpos($wsCode, "partials/share_modal.php") !== false);
check('B) share-modal.js yukleniyor', strpos($wsCode, "share-modal.js") !== false);
check('B) grid-shell.css yukleniyor (.gs-* kurallari orada)',
    strpos($wsCode, "'grid-shell.css'") !== false);

$posShell = strpos($wsCode, 'home_shell_bottom.php');
$posSmJs = strpos($wsCode, "bcc_asset_url('share-modal.js')");
check('B) share-modal.js kabuktan SONRA (bcc_bindDismissable bagimliligi)',
    $posShell !== false && $posSmJs !== false && $posSmJs > $posShell,
    'kabuk@' . var_export($posShell, true) . ' script@' . var_export($posSmJs, true));

echo "\n--- C) Payload TEK kaynaktan ---\n";
check('C) bcc_share_modal_payload cagriliyor',
    strpos($wsCode, 'bcc_share_modal_payload(') !== false);
check('C) SECILI takim ve SECILI rol ile cagriliyor',
    strpos($wsCode, 'bcc_share_modal_payload($selectedTeamId, $selectedRole)') !== false);
check('C) share_modal_payload.php require ediliyor',
    strpos($wsCode, "src/share_modal_payload.php") !== false);

echo "\n--- D) UCUNCU arayuz yazilmamis ---\n";
check('D) workspaces.php kendi katilimci uc noktasini ACMIYOR',
    strpos($wsCodeLive, 'team_member_assign') === false
    && strpos($wsCodeLive, 'team_member_remove') === false);
check('D) workspaces.js kendi davet/rol/cikarma mantigini YAZMIYOR',
    strpos($wsJsLive, 'team_member_assign') === false
    && strpos($wsJsLive, 'team_member_remove') === false);
check('D) katilimci satir sablonu workspaces.js te TEKRARLANMIYOR',
    strpos($wsJsLive, 'assignable_roles') === false);

echo "\n--- E) Bayatlama korumasi ---\n";
check('E) share-modal.js yalnizca GERCEK yazmada isaretliyor (mutated)',
    strpos($smJs, 'mutated = true') !== false);
check('E) kapanista olay yayiyor',
    strpos($smJs, "dispatchEvent(new CustomEvent('bcc:share-modal-changed'))") !== false);
check('E) olay `mutated` kosuluna bagli (bos acilis sayfayi yenilemesin)',
    preg_match('#if\s*\(mutated\)\s*\{\s*document\.dispatchEvent#s', $smJs) === 1);
check('E) workspaces.js olayi dinleyip sayfayi tazeliyor',
    strpos($wsJs, "'bcc:share-modal-changed'") !== false
    && strpos($wsJs, 'window.location.reload()') !== false);

echo "\n--- F) team_members.php SILINMEDI ---\n";
check('F) sayfa dosya olarak duruyor', is_file($root . '/public/team_members.php'));
check('F) modalin "Tum uye ayarlari" baglantisi oraya gidiyor',
    strpos(file_get_contents($root . '/src/partials/share_modal.php'), 'team_members.php') !== false);

$wipe = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => TEAM)) as $r) {
        bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id']));
    }
    bcc_execute('DELETE FROM users WHERE email IN (:a, :b, :c)',
        array(':a' => OWNER_MAIL, ':b' => VIEWER_MAIL, ':c' => GUEST_MAIL));
};
$wipe();

register_shutdown_function($wipe);

try {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM));
    $tid = (int) bcc_last_insert_id();

    $mk = function ($mail, $name) {
        bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
            array(':e' => $mail, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => $name));
        return (int) bcc_last_insert_id();
    };
    $ownerId = $mk(OWNER_MAIL, 'WSSM Owner');
    $viewerId = $mk(VIEWER_MAIL, 'WSSM Viewer');
    $guestId = $mk(GUEST_MAIL, 'WSSM Guest');

    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tid,':u'=>$ownerId,':r'=>'owner'));
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tid,':u'=>$viewerId,':r'=>'viewer'));

    $ownerCookie = login(OWNER_MAIL);
    $viewerCookie = login(VIEWER_MAIL);

    echo "\n--- G) CANLI: modal sayfada + ucdan uca ekle/cikar ---\n";
    $page = http_request('GET', '/workspaces.php?team_id=' . $tid, $ownerCookie);
    check('G) sayfa 200', $page['status'] === 200, 'HTTP ' . $page['status']);
    check('G) modal overlay i sayfada basiliyor',
        strpos($page['body'], 'id="gs-share-overlay"') !== false);
    check('G) tetikleyici buton sayfada', strpos($page['body'], 'data-share-modal-open') !== false);
    check('G) payload sayfaya gomulmus (BCC_SHARE_MODAL)',
        strpos($page['body'], 'BCC_SHARE_MODAL') !== false);
    check('G) payload DOGRU takimin (team_id gomulu)',
        preg_match('/"team_id":\s*' . $tid . '\b/', $page['body']) === 1);
    check('G) owner icin can_manage true',
        preg_match('/"can_manage":\s*true/', $page['body']) === 1);
    check('G) baslikta calisma alani adi geciyor', strpos($page['body'], TEAM) !== false);

    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $cm);
    $csrf = isset($cm[1]) ? $cm[1] : '';
    check('G) csrf token sayfadan okundu', $csrf !== '');

    $before = (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $tid));
    $add = http_request('POST', '/api/team_member_assign.php', $ownerCookie, array(
        'csrf_token' => $csrf, 'team_id' => $tid, 'email' => GUEST_MAIL, 'role' => 'editor',
    ));
    $addJson = json_decode($add['body'], true);
    check('G) EKLE: uc nokta 200 + ok', $add['status'] === 200 && !empty($addJson['ok']),
        'HTTP ' . $add['status'] . ' body: ' . substr($add['body'], 0, 160));
    $afterAdd = (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $tid));
    check('G) EKLE: DB de gercekten yazildi', $afterAdd === $before + 1, "$before -> $afterAdd");
    check('G) EKLE: dogru rolle yazildi',
        bcc_fetch_column('SELECT role FROM team_members WHERE team_id = :t AND user_id = :u',
            array(':t' => $tid, ':u' => $guestId)) === 'editor');
    check('G) EKLE: yanit AYNI payload sozlesmesini donuyor (liste tazelensin)',
        isset($addJson['collaborators']) && isset($addJson['can_manage']));

    $rm = http_request('POST', '/api/team_member_remove.php', $ownerCookie, array(
        'csrf_token' => $csrf, 'team_id' => $tid, 'user_id' => $guestId,
    ));
    $rmJson = json_decode($rm['body'], true);
    check('G) CIKAR: uc nokta 200 + ok', $rm['status'] === 200 && !empty($rmJson['ok']),
        'HTTP ' . $rm['status'] . ' body: ' . substr($rm['body'], 0, 160));
    $afterRm = (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $tid));
    check('G) CIKAR: DB den gercekten silindi', $afterRm === $before, "$before -> $afterRm");

    echo "\n--- H) Yetki: viewer tetikleyiciyi GORMUYOR, ucnokta da reddediyor ---\n";
    $vPage = http_request('GET', '/workspaces.php?team_id=' . $tid, $viewerCookie);
    check('H) viewer sayfayi gorebiliyor', $vPage['status'] === 200, 'HTTP ' . $vPage['status']);
    check('H) viewer da "Katilimcilari yonet" tetikleyicisi HIC basilmiyor',
        strpos($vPage['body'], 'data-share-modal-open') === false);
    check('H) viewer payload inda can_manage false',
        preg_match('/"can_manage":\s*false/', $vPage['body']) === 1);

    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $vPage['body'], $vcm);
    $vAdd = http_request('POST', '/api/team_member_assign.php', $viewerCookie, array(
        'csrf_token' => isset($vcm[1]) ? $vcm[1] : '', 'team_id' => $tid,
        'email' => GUEST_MAIL, 'role' => 'editor',
    ));
    check('H) viewer in EKLEME istegi REDDEDILIYOR (arayuz gizleme != yetki)',
        $vAdd['status'] === 403 || (json_decode($vAdd['body'], true) && empty(json_decode($vAdd['body'], true)['ok'])),
        'HTTP ' . $vAdd['status'] . ' body: ' . substr($vAdd['body'], 0, 160));
    check('H) reddedilen istek DB yi degistirmedi',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $tid)) === $before);
} catch (Throwable $e) {
    echo "\n[HATA] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $results[] = false;
}

$wipe();
check('Z) test verisi temizlendi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => TEAM)) === 0
    && (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE email IN (:a,:b,:c)',
        array(':a' => OWNER_MAIL, ':b' => VIEWER_MAIL, ':c' => GUEST_MAIL)) === 0);

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($pass === $total ? 'GECTI' : 'KALDI') . " ($pass/$total)\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
