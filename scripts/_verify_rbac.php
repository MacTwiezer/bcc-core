<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();
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

function no_row($row)
{
    return $row === false || $row === null;
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

$team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'Demo Calisma Alani' LIMIT 1");
if (no_row($team)) {
    die("Demo ekibi yok. Once: C:\\php73\\php.exe scripts\\seed_demo_users.php\n");
}
$teamId = (int) $team['id'];

$uid = array();
foreach (bcc_demo_accounts() as $acc) {
    $u = bcc_fetch_one('SELECT id FROM users WHERE email = :e LIMIT 1', array('e' => $acc['email']));
    if (no_row($u)) {
        die('Demo hesabi eksik: ' . $acc['email'] . " — once seed_demo_users.php calistirin.\n");
    }
    $uid[$acc['email']] = (int) $u['id'];
}

$victim = bcc_fetch_one(
    "SELECT id FROM users WHERE is_active = 1
       AND id NOT IN (SELECT user_id FROM team_members WHERE team_id = :t)
     ORDER BY id LIMIT 1",
    array('t' => $teamId)
);
if (no_row($victim)) {
    die("Testin ekleyebilecegi (ekipte olmayan) aktif kullanici bulunamadi.\n");
}
$victimId = (int) $victim['id'];

register_shutdown_function(function () use ($teamId, $victimId) {
    bcc_execute(
        'DELETE FROM team_members WHERE team_id = :t AND user_id = :u',
        array('t' => $teamId, 'u' => $victimId)
    );
});

echo "--- A) Yetenek haritasi (src/auth.php) ---\n";

$matrix = array(

    'owner' => array(true, true, true, true, true),
    'editor' => array(false, false, false, true, true),
    'commenter' => array(false, false, false, false, true),
    'viewer' => array(false, false, false, false, false),
);

foreach ($matrix as $role => $exp) {
    check("$role: base yonetimi = " . var_export($exp[0], true), bcc_can_manage_bases($role) === $exp[0]);
    check("$role: uye yonetimi = " . var_export($exp[1], true), bcc_can_manage_members($role) === $exp[1]);
    check("$role: sema yonetimi = " . var_export($exp[2], true), bcc_can_manage_schema($role) === $exp[2]);
    check("$role: kayit duzenleme = " . var_export($exp[3], true), bcc_can_edit_records($role) === $exp[3]);
    check("$role: yorum = " . var_export($exp[4], true), bcc_can_comment($role) === $exp[4]);
}

check('bilinmeyen rol hicbir yetenege sahip degil',
    !bcc_can_manage_bases('uydurma_rol') && !bcc_can_manage_members('uydurma_rol')
    && !bcc_can_manage_schema('uydurma_rol') && !bcc_can_edit_records('uydurma_rol') && !bcc_can_comment('uydurma_rol'));
check('null rol hicbir yetenege sahip degil',
    !bcc_can_manage_bases(null) && !bcc_can_manage_members(null)
    && !bcc_can_manage_schema(null) && !bcc_can_edit_records(null) && !bcc_can_comment(null));

echo "\n--- B) team_members.php gorunurluk ---\n";

$tmMarkers = array(
    'tm-assign-form' => 'uye ekleme formu',
    'tm-role-select' => 'rol degistirme acilir listesi',
    'tm-bulk-remove-form' => 'toplu cikarma formu',
    'data-tm-row-check' => 'satir secim kutusu',
    'data-tm-select-all' => 'tumunu sec kutusu',
);

foreach (array('owner@bcc.local' => true, 'commenter@bcc.local' => false,
               'editor@bcc.local' => false, 'viewer@bcc.local' => false) as $email => $shouldSee) {
    $html = render_as($uid[$email], 'team_members.php', 'team_id=' . $teamId);

    check($email . ': sayfa aciliyor (katilimci listesi herkese acik)',
        strpos($html, 'Üyeler (') !== false, substr($html, 0, 200));

    foreach ($tmMarkers as $marker => $desc) {
        check($email . ": $desc " . ($shouldSee ? 'var' : 'HTML\'de HIC YOK'),
            (strpos($html, $marker) !== false) === $shouldSee);
    }

    if (!$shouldSee) {
        check($email . ': salt-okunur aciklamasi gosteriliyor',
            strpos($html, 'tm-readonly-note') !== false);
    }
}

foreach (array('editor@bcc.local', 'viewer@bcc.local') as $email) {
    $r = post_as($uid[$email], 'team_members.php', 'team_id=' . $teamId, array(
        'action' => 'assign', 'user_id' => $victimId, 'role' => 'viewer',
    ));
    check($email . ': POST assign -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    check($email . ': uyelik OLUSMADI (DB degismedi)',
        no_row(bcc_fetch_one('SELECT id FROM team_members WHERE team_id=:t AND user_id=:u',
            array('t' => $teamId, 'u' => $victimId))));

    $r = post_as($uid[$email], 'team_members.php', 'team_id=' . $teamId, array(
        'action' => 'assign', 'user_id' => $uid[$email], 'role' => 'owner',
    ));
    check($email . ': POST kendini owner yapma -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    $selfRole = bcc_fetch_one('SELECT role FROM team_members WHERE team_id=:t AND user_id=:u',
        array('t' => $teamId, 'u' => $uid[$email]));
    $expectedRole = ($email === 'editor@bcc.local') ? 'editor' : 'viewer';
    check($email . ': kendi rolu degismedi (' . $expectedRole . ')',
        $selfRole && $selfRole['role'] === $expectedRole, $selfRole ? $selfRole['role'] : 'yok');

    $r = post_as($uid[$email], 'team_members.php', 'team_id=' . $teamId, array(
        'action' => 'remove', 'user_id' => $uid['owner@bcc.local'],
    ));
    check($email . ': POST owner cikarma -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    check($email . ': owner hala ekipte',
        !no_row(bcc_fetch_one('SELECT id FROM team_members WHERE team_id=:t AND user_id=:u',
            array('t' => $teamId, 'u' => $uid['owner@bcc.local']))));

    $membersBefore = (int) bcc_fetch_column(
        'SELECT COUNT(*) FROM team_members WHERE team_id = :t', array('t' => $teamId));

    $r = post_as($uid[$email], 'team_members.php', 'team_id=' . $teamId, array(
        'action' => 'remove_bulk', 'user_ids' => array($uid['owner@bcc.local'], $uid['commenter@bcc.local']),
    ));
    check($email . ': POST remove_bulk -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);

    $membersAfter = (int) bcc_fetch_column(
        'SELECT COUNT(*) FROM team_members WHERE team_id = :t', array('t' => $teamId));
    check($email . ': toplu cikarma sonrasi uye sayisi DEGISMEDI (' . $membersBefore . ')',
        $membersAfter === $membersBefore, 'once=' . $membersBefore . ' sonra=' . $membersAfter);
}

$r = post_as($uid['owner@bcc.local'], 'team_members.php', 'team_id=' . $teamId, array(
    'action' => 'assign', 'user_id' => $victimId, 'role' => 'viewer',
));
check('owner: POST assign -> 200 (yetki fazla kisilmadi)', $r['status'] === 200, 'HTTP ' . $r['status']);
check('owner: uyelik GERCEKTEN olustu',
    !no_row(bcc_fetch_one('SELECT id FROM team_members WHERE team_id=:t AND user_id=:u',
        array('t' => $teamId, 'u' => $victimId))));

$r = post_as($uid['owner@bcc.local'], 'team_members.php', 'team_id=' . $teamId, array(
    'action' => 'remove', 'user_id' => $victimId,
));
check('owner: POST remove -> 200', $r['status'] === 200, 'HTTP ' . $r['status']);
check('owner: uyelik GERCEKTEN silindi',
    no_row(bcc_fetch_one('SELECT id FROM team_members WHERE team_id=:t AND user_id=:u',
        array('t' => $teamId, 'u' => $victimId))));

echo "\n--- D) grid.php gorunurluk ---\n";

$tableRow = bcc_fetch_one(
    "SELECT tm.id FROM tables_meta tm JOIN bases b ON b.id = tm.base_id
     WHERE b.team_id = :t AND tm.name = 'Musteriler' LIMIT 1",
    array('t' => $teamId)
);
if ($tableRow === false || $tableRow === null) {
    die("Demo 'Musteriler' tablosu yok. Once: C:\php73\php.exe scripts\seed_demo_users.php
");
}
$tableId = (int) $tableRow['id'];

foreach (array('owner@bcc.local' => 'owner', 'editor@bcc.local' => 'editor', 'viewer@bcc.local' => 'viewer') as $email => $role) {
    $html = render_as($uid[$email], 'grid.php', 'table_id=' . $tableId);

    $isOwner = ($role === 'owner');
    check($email . ': "Katilimci ekle" tetikleyicisi ' . ($isOwner ? 'var' : 'YOK'),
        (strpos($html, 'collab-popover-add-btn') !== false) === $isOwner);
    check($email . ': modal can_manage = ' . var_export($isOwner, true),
        (strpos($html, '"can_manage":true') !== false) === $isOwner);

    check($email . ': eski collab-popover-assign formu KALMADI',
        strpos($html, 'collab-popover-assign') === false);

    check($email . ': BCC_CAN_EDIT = ' . var_export(bcc_can_edit_records($role), true),
        strpos($html, 'var BCC_CAN_EDIT = ' . (bcc_can_edit_records($role) ? 'true' : 'false') . ';') !== false);

    check($email . ': BCC_CAN_COMMENT = ' . var_export(bcc_can_comment($role), true),
        strpos($html, 'var BCC_CAN_COMMENT = ' . (bcc_can_comment($role) ? 'true' : 'false') . ';') !== false);

    check($email . ': veri goruluyor', strpos($html, 'Acme') !== false);
}

echo "\n--- E) Sema kilidi (editor kayit duzenler, sema DEGISTIREMEZ) ---\n";

$fieldCountBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :t', array('t' => $tableId));

$r = post_as($uid['editor@bcc.local'], 'api/field_create.php', '', array(
    'table_id' => $tableId, 'name' => 'RBAC_TEST_ALAN', 'field_type' => 'single_line_text',
));
check('editor: api/field_create.php reddedildi', $r['status'] === 403 || strpos($r['body'], 'yetkiniz') !== false,
    'HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 120));
check('editor: alan OLUSMADI',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :t', array('t' => $tableId)) === $fieldCountBefore);

$html = render_as($uid['editor@bcc.local'], 'table_fields.php', 'table_id=' . $tableId);
check('editor: table_fields.php "Islemler" kolonu YOK', strpos($html, '<th>İşlemler</th>') === false);

$baseRow = bcc_fetch_one("SELECT id FROM bases WHERE team_id = :t AND name = 'Demo CRM' LIMIT 1", array('t' => $teamId));
if ($baseRow === false || $baseRow === null) {
    die("Demo 'Demo CRM' base'i yok. Once: C:\php73\php.exe scripts\seed_demo_users.php
");
}
$html = render_as($uid['editor@bcc.local'], 'base_tables.php', 'base_id=' . (int) $baseRow['id']);
check('editor: base_tables.php tablo olusturma formu YOK', strpos($html, '<th>İşlemler</th>') === false);

$html = render_as($uid['owner@bcc.local'], 'table_fields.php', 'table_id=' . $tableId);
check('owner: table_fields.php "Islemler" kolonu VAR', strpos($html, '<th>İşlemler</th>') !== false);

$recCountBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t AND deleted_at IS NULL', array('t' => $tableId));
$r = post_as($uid['editor@bcc.local'], 'api/record_add.php', '', array('table_id' => $tableId));
check('editor: api/record_add.php KABUL edildi (kayit duzenleme acik)', $r['status'] === 200,
    'HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 120));
$recCountAfter = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t AND deleted_at IS NULL', array('t' => $tableId));
check('editor: kayit gercekten eklendi', $recCountAfter === $recCountBefore + 1);

if ($recCountAfter > $recCountBefore) {
    $newRec = bcc_fetch_one('SELECT id FROM records WHERE table_id = :t ORDER BY id DESC LIMIT 1', array('t' => $tableId));
    bcc_execute('DELETE FROM cell_values WHERE record_id = :r', array('r' => $newRec['id']));
    bcc_execute('DELETE FROM records WHERE id = :r', array('r' => $newRec['id']));
}

$r = post_as($uid['viewer@bcc.local'], 'api/record_add.php', '', array('table_id' => $tableId));
check('viewer: api/record_add.php reddedildi', $r['status'] === 403 || strpos($r['body'], 'yetkiniz') !== false,
    'HTTP ' . $r['status']);
check('viewer: kayit sayisi degismedi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t AND deleted_at IS NULL', array('t' => $tableId)) === $recCountBefore);

echo "\n--- F) workspaces.php buton gorunurlugu ---\n";

foreach (array('owner@bcc.local' => true, 'commenter@bcc.local' => false,
               'editor@bcc.local' => false, 'viewer@bcc.local' => false) as $email => $shouldSee) {
    $html = render_as($uid[$email], 'workspaces.php', 'team_id=' . $teamId);

    check($email . ': "Katılımcıları yönet" ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'Katılımcıları yönet') !== false) === $shouldSee);
    check($email . ': "Base oluştur" ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'Base oluştur') !== false) === $shouldSee);

    check($email . ': olu "Ayarlar" dugmesi ARTIK YOK',
        strpos($html, '>Ayarlar<') === false);
    check($email . ': satir ici "yonet" kisayolu ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'wsx-member-manage') !== false) === $shouldSee);

    check($email . ': katilimci listesi goruluyor', strpos($html, 'wsx-collab-grid') !== false);

    if (!$shouldSee) {
        check($email . ': rol etiketi gosteriliyor', strpos($html, 'wsx-role-note') !== false);
    }
}

echo "\n--- G) dashboard.php / bases.php (regresyon) ---\n";

foreach (array('owner@bcc.local' => true, 'commenter@bcc.local' => false,
               'editor@bcc.local' => false, 'viewer@bcc.local' => false) as $email => $shouldSee) {
    $html = render_as($uid[$email], 'dashboard.php');
    check($email . ': dashboard "+ Yeni Base Oluştur" ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'home-create-base-btn') !== false) === $shouldSee);
    check($email . ': dashboard "Sil" ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'data-base-delete') !== false) === $shouldSee);

    $html = render_as($uid[$email], 'bases.php');
    check($email . ': bases.php olusturma formu ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'Base Oluştur') !== false) === $shouldSee);
}

echo "\n--- H) Tek kaynak korumasi ---\n";

$offenders = array();
foreach (glob(__DIR__ . '/../public/*.php') as $f) {
    $src = file_get_contents($f);
    if (preg_match('/(?<![\'\]])\$role\s*===\s*\'/', $src) || preg_match('/in_array\(\$role,\s*array\(\'/', $src)) {
        $offenders[] = basename($f);
    }
}
check('public/*.php icinde elle yazilmis rol esigi yok', empty($offenders), implode(', ', $offenders));

$authSrc = file_get_contents(__DIR__ . '/../src/auth.php');
foreach (array('bcc_can_manage_bases', 'bcc_can_manage_members', 'bcc_can_manage_schema',
               'bcc_can_edit_records', 'bcc_can_comment') as $fn) {
    check("src/auth.php: $fn() TEK KEZ tanimli", substr_count($authSrc, 'function ' . $fn . '(') === 1);
}

$tmSrc = file_get_contents(__DIR__ . '/../public/team_members.php');
check('team_members.php POST kapisi bcc_can_manage_members() kullaniyor',
    strpos($tmSrc, 'bcc_can_manage_members(') !== false);

check('team_members.php yetkisiz POST ta 403 donduruyor (ortak hata sayfasi)',
    preg_match('/bcc_error_page\(.*403\)/', $tmSrc) === 1);

echo "\n";
$failed = count(array_filter($results, function ($r) { return !$r; }));
echo ($failed === 0 ? 'TUM TESTLER GECTI' : $failed . ' TEST KALDI') . ' (' . count($results) . " kontrol)\n";
exit($failed === 0 ? 0 : 1);
