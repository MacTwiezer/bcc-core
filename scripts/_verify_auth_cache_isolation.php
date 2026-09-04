<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

session_start();

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

define('A_MAIL', 'authcache.a@bcc-test.local');
define('B_MAIL', 'authcache.b@bcc-test.local');
define('A_TEAM', 'ZZ AuthCache A');
define('B_TEAM', 'ZZ AuthCache B');

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$cleanup = function () {
    foreach (array(A_TEAM, B_TEAM) as $t) {
        foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => $t)) as $r) {
            bcc_execute('DELETE FROM teams WHERE id = :i', array(':i' => $r['id']));
        }
    }
    bcc_execute('DELETE FROM users WHERE email IN (:a, :b)', array(':a' => A_MAIL, ':b' => B_MAIL));
};
$cleanup();
register_shutdown_function($cleanup);

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => A_TEAM));
$teamA = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => B_TEAM));
$teamB = (int) bcc_last_insert_id();

$mk = function ($mail, $ad, $teamId) {
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => $mail, ':h' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), ':n' => $ad)
    );
    $uid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $uid, ':r' => 'owner'));

    return $uid;
};

$uidA = $mk(A_MAIL, 'AuthCache A', $teamA);
$uidB = $mk(B_MAIL, 'AuthCache B', $teamB);
check('A) iki atilir kullanici ve iki ayri ekip kuruldu', $uidA > 0 && $uidB > 0 && $teamA !== $teamB);

$mkBase = function ($teamId, $ad, $uid) {
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => $ad, ':u' => $uid));
    $bid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO user_starred_bases (user_id, base_id) VALUES (:u, :b)',
        array(':u' => $uid, ':b' => $bid));

    return $bid;
};
$baseA = $mkBase($teamA, 'ZZ AuthCache Base A', $uidA);
$baseB = $mkBase($teamB, 'ZZ AuthCache Base B', $uidB);

echo "\nB) Kullanici A olarak oku\n";

$_SESSION['user_id'] = $uidA;
current_user(true);
$rolesA = current_user_team_roles();
$idsA = current_user_team_ids();
check('B) A kendi ekibini goruyor', in_array($teamA, $idsA, true), implode(',', $idsA));
check('B) A, B nin ekibini GORMUYOR', !in_array($teamB, $idsA, true), implode(',', $idsA));
check('B) rol haritasi owner diyor',
    isset($rolesA[$teamA]) && $rolesA[$teamA] === 'owner',
    json_encode($rolesA));

echo "\nC) AYNI surecte kullanici B ye gec (attempt_login'in yaptigi sey)\n";

$_SESSION['user_id'] = $uidB;
current_user(true);
$u = current_user();
check('C) current_user() gercekten B yi dondurdu',
    $u !== null && $u['email'] === B_MAIL, $u ? $u['email'] : 'null');

$idsB = current_user_team_ids();

check('C) ⭐ B, A nin ekibini GORMUYOR (onbellek bayat degil)',
    !in_array($teamA, $idsB, true), implode(',', $idsB));
check('C) B kendi ekibini goruyor', in_array($teamB, $idsB, true), implode(',', $idsB));

$rolesB = current_user_team_roles();
check('C) rol haritasi B nin ekibiyle sinirli',
    array_keys($rolesB) === array($teamB), json_encode($rolesB));

echo "\nD) Geri A ya donunce yine dogru\n";

$_SESSION['user_id'] = $uidA;
current_user(true);
$idsA2 = current_user_team_ids();
check('D) A tekrar kendi ekibini goruyor', in_array($teamA, $idsA2, true), implode(',', $idsA2));
check('D) A, B nin ekibini yine GORMUYOR', !in_array($teamB, $idsA2, true), implode(',', $idsA2));

echo "\nE) Onbellek gercekten calisiyor (duzeltme onu bozmadi)\n";

$tekrar = current_user_team_roles();
check('E) ayni kullanici icin sonuc kararli', $tekrar === current_user_team_roles());

echo "\nF) AYNI SINIFTAKI ikinci onbellek: yildizli base'ler\n";

$idler = function ($satirlar) {
    $o = array();
    foreach ($satirlar as $s) { $o[] = (int) $s['id']; }
    sort($o);

    return $o;
};

$_SESSION['user_id'] = $uidA;
current_user(true);
$yildizA = $idler(bcc_starred_bases_for_current_user());
check('F) A kendi yildizli base ini goruyor', in_array($baseA, $yildizA, true), implode(',', $yildizA));
check('F) A, B nin base ini GORMUYOR', !in_array($baseB, $yildizA, true), implode(',', $yildizA));

$_SESSION['user_id'] = $uidB;
current_user(true);
$yildizB = $idler(bcc_starred_bases_for_current_user());
check('F) B, A nin base ini GORMUYOR (onbellek bayat degil)',
    !in_array($baseA, $yildizB, true), implode(',', $yildizB));
check('F) B kendi base ini goruyor', in_array($baseB, $yildizB, true), implode(',', $yildizB));

$_SESSION['user_id'] = $uidA;
current_user(true);
check('F) geri A ya donunce yine dogru',
    $idler(bcc_starred_bases_for_current_user()) === $yildizA);

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
