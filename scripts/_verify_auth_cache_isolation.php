<?php
// Oturumdaki kullanici DEGISTIGINDE yetki onbelleklerinin bayat kalmadigini
// dogrular.
//
// BULUNAN GERCEK TUZAK: current_user_team_roles() tek bir "static $cache"
// kullaniyordu ve bu harita current_user(true) ile kullanici degistirildiginde
// SIFIRLANMIYORDU. attempt_login() tam da bunu yapar: $_SESSION['user_id']'i
// yazip current_user(true) cagirir.
//
// Olculdu: A kullanicisinin ekipleri okunduktan sonra B'ye gecilince fonksiyon
// HALA A'nin ekiplerini donduruyordu. Bu harita current_user_team_ids() ->
// require_team_access() zincirini ve bildirim kapsamini besledigi icin bir
// YETKI kaynagidir; bayat kalmasi, bir kullanicinin baska bir kullanicinin
// ekip kumesiyle degerlendirilmesi demektir.
//
// Duzeltme: onbellek kullanici kimligine gore anahtarlandi (src/auth.php).
//
// Calistirma: C:\php73\php.exe scripts\_verify_auth_cache_isolation.php

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

// Iki AYRI ekip, iki AYRI kullanici — kesisim YOK.
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
// ⚠️ is_admin = 0 SART: platform admini HER ekipte sanal owner sayilir
// (current_user_team_roles), o zaman bu test hicbir sey olcemezdi.
$uidA = $mk(A_MAIL, 'AuthCache A', $teamA);
$uidB = $mk(B_MAIL, 'AuthCache B', $teamB);
check('A) iki atilir kullanici ve iki ayri ekip kuruldu', $uidA > 0 && $uidB > 0 && $teamA !== $teamB);

// ---------------------------------------------------------------------------
echo "\nB) Kullanici A olarak oku\n";
// ---------------------------------------------------------------------------
$_SESSION['user_id'] = $uidA;
current_user(true);
$rolesA = current_user_team_roles();
$idsA = current_user_team_ids();
check('B) A kendi ekibini goruyor', in_array($teamA, $idsA, true), implode(',', $idsA));
check('B) A, B nin ekibini GORMUYOR', !in_array($teamB, $idsA, true), implode(',', $idsA));
check('B) rol haritasi owner diyor',
    isset($rolesA[$teamA]) && $rolesA[$teamA] === 'owner',
    json_encode($rolesA));

// ---------------------------------------------------------------------------
echo "\nC) AYNI surecte kullanici B ye gec (attempt_login'in yaptigi sey)\n";
// ---------------------------------------------------------------------------
$_SESSION['user_id'] = $uidB;
current_user(true);
$u = current_user();
check('C) current_user() gercekten B yi dondurdu',
    $u !== null && $u['email'] === B_MAIL, $u ? $u['email'] : 'null');

$idsB = current_user_team_ids();
// ASIL KONTROL: bayat onbellek burada A'nin ekibini dondururdu.
check('C) ⭐ B, A nin ekibini GORMUYOR (onbellek bayat degil)',
    !in_array($teamA, $idsB, true), implode(',', $idsB));
check('C) B kendi ekibini goruyor', in_array($teamB, $idsB, true), implode(',', $idsB));

$rolesB = current_user_team_roles();
check('C) rol haritasi B nin ekibiyle sinirli',
    array_keys($rolesB) === array($teamB), json_encode($rolesB));

// ---------------------------------------------------------------------------
echo "\nD) Geri A ya donunce yine dogru\n";
// ---------------------------------------------------------------------------
$_SESSION['user_id'] = $uidA;
current_user(true);
$idsA2 = current_user_team_ids();
check('D) A tekrar kendi ekibini goruyor', in_array($teamA, $idsA2, true), implode(',', $idsA2));
check('D) A, B nin ekibini yine GORMUYOR', !in_array($teamB, $idsA2, true), implode(',', $idsA2));

// ---------------------------------------------------------------------------
echo "\nE) Onbellek gercekten calisiyor (duzeltme onu bozmadi)\n";
// ---------------------------------------------------------------------------
// Ayni kullanici icin ikinci cagri yeni sorgu ACMAMALI; dogrudan olcemedigimiz
// icin en azindan AYNI sonucu dondurmesini dogruluyoruz (kimlige gore
// anahtarlama sonucu degistirmemeli).
$tekrar = current_user_team_roles();
check('E) ayni kullanici icin sonuc kararli', $tekrar === current_user_team_roles());

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
