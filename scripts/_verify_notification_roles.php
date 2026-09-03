<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

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

    $adm = bcc_fetch_one('SELECT is_admin FROM users WHERE id = :i', array('i' => $u['id']));
    if ((int) $adm['is_admin'] === 1) {
        die('Demo hesabi platform admini: ' . $acc['email'] . " — bu test o hesapla anlamsiz.\n");
    }
    $uid[$acc['role']] = (int) $u['id'];
}

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

echo "\n--- B) dashboard.php panelinde gorunen bildirimler ---\n";

$seen = array();
foreach (array('owner', 'editor', 'commenter', 'viewer') as $role) {
    $seen[$role] = notif_messages(render_as($uid[$role], 'dashboard.php'));
}

foreach (array('editor', 'commenter', 'viewer') as $role) {
    check($role . ': Slack bildirimi GORMUYOR', !has_any($seen[$role], 'Slack'), implode(' | ', $seen[$role]));

    check($role . ': uyelik bildirimi GORMUYOR', !has_any($seen[$role], 'Demo Owner ekibe yeni bir'), implode(' | ', $seen[$role]));
    check($role . ': kayit bildirimini GORUYOR', has_any($seen[$role], 'yeni bir kayit ekledi') || has_any($seen[$role], 'yeni bir kayıt ekledi'), implode(' | ', $seen[$role]));
}

check('owner: Slack bildirimini GORUYOR', has_any($seen['owner'], 'Slack'), implode(' | ', $seen['owner']));
check('owner: uyelik bildirimini GORUYOR', has_any($seen['owner'], 'ekibe yeni bir'), implode(' | ', $seen['owner']));

echo "\n--- C) Ekip basina rol (viewer burada owner) ---\n";

$viewerHtml = render_as($uid['viewer'], 'dashboard.php');
$viewerMsgs = notif_messages($viewerHtml);
$membershipCount = 0;
foreach ($viewerMsgs as $msg) {
    if (mb_stripos($msg, 'ekibe yeni bir', 0, 'UTF-8') !== false) {
        $membershipCount++;
    }
}

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

echo "\n--- D) api/notification_mark_one_read.php ---\n";

$res = post_as($uid['viewer'], 'api/notification_mark_one_read.php', '', array('notification_id' => $memberAuditId));
check('viewer: gormedigi uyelik bildirimini okundu YAPAMIYOR (403)', $res['status'] === 403, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

$res = post_as($uid['viewer'], 'api/notification_mark_one_read.php', '', array('notification_id' => $slackAuditId));
check('viewer: gormedigi Slack bildirimini okundu YAPAMIYOR (403)', $res['status'] === 403, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

$res = post_as($uid['viewer'], 'api/notification_mark_one_read.php', '', array('notification_id' => $recordAuditId));
check('viewer: GORDUGU kayit bildirimini okundu YAPABILIYOR (200)', $res['status'] === 200, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

$res = post_as($uid['owner'], 'api/notification_mark_one_read.php', '', array('notification_id' => $memberAuditId));
check('owner: uyelik bildirimini okundu YAPABILIYOR (200)', $res['status'] === 200, 'HTTP ' . $res['status'] . ' | ' . trim($res['body']));

echo "\n";
$pass = count(array_filter($results));
$total = count($results);
echo $pass . '/' . $total . " gecti\n";
exit($pass === $total ? 0 : 1);
