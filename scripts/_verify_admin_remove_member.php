<?php
// admin/index.php'nin "remove_from_team" islemi, team_members.php ile AYNI
// korumalari uyguluyor mu? (son owner, kendini cikarma, audit team scope)
//
// CALISTIRMA: C:/php73/php.exe scripts/_verify_admin_remove_member.php
//
// KENDI TEST VERISINI kurar ve sonunda TAMAMEN siler. Gercek ekip/kullanicilara
// DOKUNMAZ.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$SON = bin2hex(random_bytes(4));
$temizlik = array('users' => array(), 'teams' => array());

// Temizlik BURADA baglanir, sonda degil: ekip adi ve e-postalar rastgele ek
// tasiyor (AdminRmTest <hex>), yani betik ortada olurse (fatal, Ctrl+C) kalan
// ekip/kullanicilari SONRAKI kosu de bulamaz. $temizlik REFERANSLA yakalanir,
// boylece kayit listesi buyudukce kapanis da guncel kalir — henuz hicbir sey
// olusturulmamisken calissa bile bos liste uzerinde donup hicbir sey yapmaz.
$cleanup = function () use (&$temizlik) {
    foreach ($temizlik['teams'] as $id) {
        bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $id));
        bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $id));
        bcc_execute('DELETE FROM teams WHERE id = :i', array('i' => $id));
    }
    foreach ($temizlik['users'] as $id) {
        bcc_execute('DELETE FROM users WHERE id = :i', array('i' => $id));
    }
};
register_shutdown_function($cleanup);

function testKullanici($etiket, $son)
{
    global $temizlik;
    $email = "adminrm.$etiket.$son@bcc-test.local";
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array('e' => $email, 'h' => password_hash('x' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'n' => 'AdminRm ' . $etiket)
    );
    $id = (int) bcc_last_insert_id();
    $temizlik['users'][] = $id;
    return $id;
}

function uyeYap($teamId, $userId, $rol)
{
    bcc_execute(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $teamId, 'u' => $userId, 'r' => $rol)
    );
}

function ownerSayisi($teamId)
{
    return (int) bcc_fetch_column(
        "SELECT COUNT(*) FROM team_members WHERE team_id = :t AND role = 'owner'",
        array('t' => $teamId)
    );
}

// --- test ortami ---
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'AdminRmTest ' . $SON));
$teamId = (int) bcc_last_insert_id();
$temizlik['teams'][] = $teamId;

$owner1 = testKullanici('owner1', $SON);
$owner2 = testKullanici('owner2', $SON);
$viewer = testKullanici('viewer', $SON);
uyeYap($teamId, $owner1, 'owner');
uyeYap($teamId, $owner2, 'owner');
uyeYap($teamId, $viewer, 'viewer');

echo "Test ekibi: $teamId (2 owner + 1 viewer)\n\n";

$adminRank = $GLOBALS['BCC_ROLE_RANK']['owner'];

// ---------------------------------------------------------------------------
echo "A) Normal cikarma (viewer)\n";
// ---------------------------------------------------------------------------
$r = bcc_team_member_remove_many($teamId, array($viewer), 0, $adminRank);
check('viewer cikarildi', count($r['removed']) === 1, json_encode($r));
check('owner sayisi degismedi (2)', ownerSayisi($teamId) === 2, ownerSayisi($teamId));

// ---------------------------------------------------------------------------
echo "\nB) Iki owner'dan biri cikarilabilir\n";
// ---------------------------------------------------------------------------
$r = bcc_team_member_remove_many($teamId, array($owner2), 0, $adminRank);
check('owner2 cikarildi', count($r['removed']) === 1, json_encode($r));
check('owner sayisi 1', ownerSayisi($teamId) === 1, ownerSayisi($teamId));

// ---------------------------------------------------------------------------
echo "\nC) SON owner cikarilamaz  <-- eski ciplak DELETE bunu YAPIYORDU\n";
// ---------------------------------------------------------------------------
$r = bcc_team_member_remove_many($teamId, array($owner1), 0, $adminRank);
check('son owner REDDEDILDI', count($r['removed']) === 0 && $r['skipped'] === 1, json_encode($r));
check('ekip hala owner-sahibi', ownerSayisi($teamId) === 1, ownerSayisi($teamId));

// ---------------------------------------------------------------------------
echo "\nD) Kendini cikarma engelli\n";
// ---------------------------------------------------------------------------
$r = bcc_team_member_remove_many($teamId, array($owner1), $owner1, $adminRank);
check('aktor kendini cikaramadi', count($r['removed']) === 0, json_encode($r));

// ---------------------------------------------------------------------------
echo "\nE) audit kaydi TEAM SCOPE tasiyor (bildirimde gorunur)\n";
// ---------------------------------------------------------------------------
$row = bcc_fetch_one(
    "SELECT team_id FROM audit_log WHERE action = 'team_member.remove' AND team_id = :t
     ORDER BY id DESC LIMIT 1",
    array('t' => $teamId)
);
check('audit_log.team_id dolu', $row && (int) $row['team_id'] === $teamId,
    $row ? var_export($row['team_id'], true) : 'kayit yok');

// ---------------------------------------------------------------------------
echo "\nF) Kullaniciya gosterilen mesaj\n";
// ---------------------------------------------------------------------------
$m = bcc_team_member_remove_message(array('removed' => array(), 'skipped' => 1));
check('hic cikarilamayinca hata mesaji var', $m['error'] !== null && $m['success'] === null, json_encode($m));
$m = bcc_team_member_remove_message(array('removed' => array(1), 'skipped' => 0));
check('cikarilinca basari mesaji var', $m['success'] !== null && $m['error'] === null, json_encode($m));

// ---------------------------------------------------------------------------
echo "\nG) assign_team.php yolu: bcc_team_member_assign\n";
// ---------------------------------------------------------------------------
$roller = array_keys($GLOBALS['BCC_ROLE_RANK']);

// yeni uyelik
$r = bcc_team_member_assign($teamId, $viewer, 'editor', $adminRank, $roller);
check('yeni uye eklendi', $r['ok'] === true && $r['created'] === true, json_encode($r));

// rol degisikligi (created=false olmali -> audit action farkli)
$r = bcc_team_member_assign($teamId, $viewer, 'commenter', $adminRank, $roller);
check('rol degisikligi (created=false)', $r['ok'] === true && $r['created'] === false, json_encode($r));

$rol = bcc_fetch_column('SELECT role FROM team_members WHERE team_id = :t AND user_id = :u',
    array('t' => $teamId, 'u' => $viewer));
check('rol DB\'de commenter', $rol === 'commenter', var_export($rol, true));

// gecersiz rol reddedilir
$r = bcc_team_member_assign($teamId, $viewer, 'superadmin', $adminRank, $roller);
check('gecersiz rol REDDEDILDI', $r['ok'] === false, json_encode($r));

// olmayan kullanici reddedilir
$r = bcc_team_member_assign($teamId, 99999999, 'viewer', $adminRank, $roller);
check('olmayan kullanici REDDEDILDI', $r['ok'] === false, json_encode($r));

// audit team scope
$sayi = (int) bcc_fetch_column(
    "SELECT COUNT(*) FROM audit_log WHERE team_id = :t AND action IN ('team_member.assign','team_member.role_change')",
    array('t' => $teamId)
);
check('assign/role_change audit kayitlari team scope tasiyor', $sayi >= 2, $sayi);

// --- temizlik ---
$cleanup();

$kalanEkip = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :i', array('i' => $teamId));
$kalanUser = (int) bcc_fetch_column(
    "SELECT COUNT(*) FROM users WHERE email LIKE :e",
    array('e' => 'adminrm.%.' . $SON . '@bcc-test.local')
);
echo "\nTemizlik: kalan ekip=$kalanEkip  kalan kullanici=$kalanUser (ikisi de 0 olmali)\n";
check('test verisi tamamen silindi', $kalanEkip === 0 && $kalanUser === 0);

echo "\n" . str_repeat('-', 52) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
