<?php
// Bildirim panelinin ROL SUZGECI — uctan uca dogrulama.
//
// Neyi kanitlar:
//   A) Saf harita: her rol icin bcc_notification_actions_for_role() ne dondurur.
//   B) GERCEK SAYFA: dashboard.php o rolun oturumuyla render edilir ve panelde
//      basilan bildirim METINLERI incelenir — viewer/commenter/editor'da
//      "Slack" ve "ekibe ... uye" cumleleri HIC OLMAMALI (CSS ile gizlemek
//      sayilmaz, HTML'de bulunmamali), owner'da OLMALI.
//   C) ROL EKIP BASINA DEGISIR: gecici bir ekipte viewer hesabi 'owner' yapilir;
//      o ekibin uyelik bildirimini GORMELI, ayni anda Demo ekibinin uyelik
//      bildirimini GORMEMELI. (Tek "team_id IN (...) AND action IN (...)"
//      sorgusu bu testte kalirdi.)
//   D) UC NOKTA: viewer, panelinde HIC gormedigi bir bildirimin id'siyle
//      api/notification_mark_one_read.php'ye POST atar -> 403 beklenir.
//
// Fikstur: seed_demo_users.php'nin hesaplari + bu betigin KENDI olusturdugu
// gecici ekip ve audit_log satirlari. Hepsi cikista silinir; baska veriye
// DOKUNULMAZ.
//
// Calistirma: C:\php73\php.exe scripts\_verify_notification_roles.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

function render_as($userId, $page, $query = '')
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_render_as_case.php')
        . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($query);

    return (string) shell_exec($cmd . ' 2>&1');
}

function post_as($userId, $page, $query, $post)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_post_as_case.php')
        . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg($page)
        . ' ' . escapeshellarg($query) . ' ' . escapeshellarg(base64_encode(json_encode($post)));

    $out = (string) shell_exec($cmd . ' 2>&1');
    $status = preg_match('/HTTP_STATUS=(\d+)/', $out, $m) ? (int) $m[1] : 0;

    return array('status' => $status, 'body' => $out);
}

// Render edilen sayfadan bildirim satirlarinin METNINI cikarir.
function notif_messages($html)
{
    preg_match_all('#<div class="home-notif-message">(.*?)</div>#s', $html, $m);

    return isset($m[1]) ? array_map('trim', $m[1]) : array();
}

function has_any($messages, $needle)
{
    foreach ($messages as $msg) {
        if (mb_stripos($msg, $needle, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// Fikstur
// ---------------------------------------------------------------------------
$team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'Demo Calisma Alani' LIMIT 1");
if ($team === false || $team === null) {
    die("Demo ekibi yok. Once: C:\\php73\\php.exe scripts\\seed_demo_users.php\n");
}
$teamId = (int) $team['id'];

$uid = array();
foreach (bcc_demo_accounts() as $acc) {
    $u = bcc_fetch_one('SELECT id FROM users WHERE email = :e LIMIT 1', array('e' => $acc['email']));
    if ($u === false || $u === null) {
        die('Demo hesabi eksik: ' . $acc['email'] . " — once seed_demo_users.php calistirin.\n");
    }
    // is_admin=1 olan bir hesap HER ekipte sanal 'owner' olur ve testin tum
    // beklentilerini bozar (bkz. current_user_team_roles) — sessizce yanlis
    // sonuc vermek yerine acikca duruyoruz.
    $adm = bcc_fetch_one('SELECT is_admin FROM users WHERE id = :i', array('i' => $u['id']));
    if ((int) $adm['is_admin'] === 1) {
        die('Demo hesabi platform admini: ' . $acc['email'] . " — bu test o hesapla anlamsiz.\n");
    }
    $uid[$acc['role']] = (int) $u['id'];
}

// Panelde MUTLAKA bir uyelik ve bir Slack bildirimi bulunsun diye Demo ekibine
// iki gecici audit_log satiri yazilir (en yeni 30 icine girsinler diye NOW()).
// Cikista ikisi de id ile silinir.
$tempAuditIds = array();

function seed_audit($teamId, $userId, $action)
{
    global $tempAuditIds;

    bcc_execute(
        'INSERT INTO audit_log (team_id, user_id, action, entity_type, entity_id, details)
         VALUES (:t, :u, :a, :e, :i, NULL)',
        array('t' => $teamId, 'u' => $userId, 'a' => $action, 'e' => 'test', 'i' => 0)
    );
    $id = (int) bcc_last_insert_id();
    $tempAuditIds[] = $id;

    return $id;
}

$memberAuditId = seed_audit($teamId, $uid['owner'], 'team_member.assign');
$slackAuditId = seed_audit($teamId, $uid['editor'], 'slack.notify_failed');
$recordAuditId = seed_audit($teamId, $uid['editor'], 'record.create');

// Gecici ekip: viewer hesabi BURADA owner. Ekip basina rol farkini test eder.
$tempTeamName = 'ZZ Bildirim Rol Testi';
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => $tempTeamName));
$tempTeamId = (int) bcc_last_insert_id();
bcc_execute(
    'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
    array('t' => $tempTeamId, 'u' => $uid['viewer'], 'r' => 'owner')
);
$tempTeamMemberAuditId = seed_audit($tempTeamId, $uid['viewer'], 'team_member.assign');

register_shutdown_function(function () use (&$tempAuditIds, $tempTeamId) {
    foreach ($tempAuditIds as $id) {
        bcc_execute('DELETE FROM audit_log WHERE id = :i', array('i' => $id));
    }
    bcc_execute('DELETE FROM user_read_notifications WHERE audit_log_id IN (' . implode(',', array_map('intval', $tempAuditIds ?: array(0))) . ')');
    bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $tempTeamId));
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $tempTeamId));
    echo "\n(temizlik: gecici ekip ve audit satirlari silindi)\n";
});

// ---------------------------------------------------------------------------
// A) Saf harita
// ---------------------------------------------------------------------------
echo "--- A) bcc_notification_actions_for_role() ---\n";

$expected = array(
    'owner' => array('record.create', 'view.rename', 'slack.notify_sent', 'slack.notify_failed', 'team_member.assign', 'team_member.role_change'),
    'editor' => array('record.create', 'view.rename'),
    'commenter' => array('record.create', 'view.rename'),
    'viewer' => array('record.create', 'view.rename'),
);

foreach ($expected as $role => $want) {
    $got = bcc_notification_actions_for_role($role);
    sort($want);
    sort($got);
    check($role . ' -> ' . count($got) . ' tur', $want === $got, 'beklenen: ' . implode(',', $want) . ' | gelen: ' . implode(',', $got));
}

// ---------------------------------------------------------------------------
// B) Gercek sayfa (dashboard.php) — panelde basilan METINLER
// ---------------------------------------------------------------------------
echo "\n--- B) dashboard.php panelinde gorunen bildirimler ---\n";

$seen = array();
foreach (array('owner', 'editor', 'commenter', 'viewer') as $role) {
    $seen[$role] = notif_messages(render_as($uid[$role], 'dashboard.php'));
}

foreach (array('editor', 'commenter', 'viewer') as $role) {
    check($role . ': Slack bildirimi GORMUYOR', !has_any($seen[$role], 'Slack'), implode(' | ', $seen[$role]));
    // Aktore gore aranir: viewer, C bolumundeki gecici ekipte OWNER oldugu
    // icin ORADAKI uyelik bildirimini ("Demo Viewer ekibe...") gormesi
    // DOGRUDUR. Burada test edilen, Demo ekibinin ("Demo Owner ekibe...")
    // bildirimidir.
    check($role . ': uyelik bildirimi GORMUYOR', !has_any($seen[$role], 'Demo Owner ekibe yeni bir'), implode(' | ', $seen[$role]));
    check($role . ': kayit bildirimini GORUYOR', has_any($seen[$role], 'yeni bir kayit ekledi') || has_any($seen[$role], 'yeni bir kayıt ekledi'), implode(' | ', $seen[$role]));
}

check('owner: Slack bildirimini GORUYOR', has_any($seen['owner'], 'Slack'), implode(' | ', $seen['owner']));
check('owner: uyelik bildirimini GORUYOR', has_any($seen['owner'], 'ekibe yeni bir'), implode(' | ', $seen['owner']));

// ---------------------------------------------------------------------------
// C) Rol EKIP BASINA — viewer, gecici ekipte owner
// ---------------------------------------------------------------------------
echo "\n--- C) Ekip basina rol (viewer burada owner) ---\n";

$viewerHtml = render_as($uid['viewer'], 'dashboard.php');
$viewerMsgs = notif_messages($viewerHtml);
$membershipCount = 0;
foreach ($viewerMsgs as $msg) {
    if (mb_stripos($msg, 'ekibe yeni bir', 0, 'UTF-8') !== false) {
        $membershipCount++;
    }
}

// Gecici ekipteki uyelik bildiriminin aktoru viewer'in KENDISI ("Demo Viewer"),
// Demo ekibindeki ise owner ("Demo Owner") — metinden hangisinin geldigi ayirt
// edilebiliyor.
check(
    'viewer: owner OLDUGU ekibin uyelik bildirimini GORUYOR',
    has_any($viewerMsgs, 'Demo Viewer ekibe yeni bir'),
    implode(' | ', $viewerMsgs)
);
check(
    'viewer: viewer OLDUGU ekibin uyelik bildirimini GORMUYOR',
    !has_any($viewerMsgs, 'Demo Owner ekibe yeni bir'),
    implode(' | ', $viewerMsgs)
);
check('viewer: toplam 1 uyelik bildirimi', $membershipCount === 1, 'sayi: ' . $membershipCount);

// ---------------------------------------------------------------------------
// D) Uc nokta zorlamasi
// ---------------------------------------------------------------------------
echo "\n--- D) api/notification_mark_one_read.php ---\n";

$res = post_as($uid['viewer'], 'api/notification_mark_one_read.php', '', array('notification_id' => $memberAuditId));
check('viewer: gormedigi uyelik bildirimini okundu YAPAMIYOR (403)', $res['status'] === 403, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

$res = post_as($uid['viewer'], 'api/notification_mark_one_read.php', '', array('notification_id' => $slackAuditId));
check('viewer: gormedigi Slack bildirimini okundu YAPAMIYOR (403)', $res['status'] === 403, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

$res = post_as($uid['viewer'], 'api/notification_mark_one_read.php', '', array('notification_id' => $recordAuditId));
check('viewer: GORDUGU kayit bildirimini okundu YAPABILIYOR (200)', $res['status'] === 200, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

$res = post_as($uid['owner'], 'api/notification_mark_one_read.php', '', array('notification_id' => $memberAuditId));
check('owner: uyelik bildirimini okundu YAPABILIYOR (200)', $res['status'] === 200, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

// ---------------------------------------------------------------------------
echo "\n";
$pass = count(array_filter($results));
$total = count($results);
echo $pass . '/' . $total . " gecti\n";
exit($pass === $total ? 0 : 1);
