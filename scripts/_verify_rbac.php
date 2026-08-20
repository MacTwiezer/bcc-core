<?php
// RBAC (rol tabanli erisim denetimi) — UCTAN UCA dogrulama.
//
// Iki katmani AYRI AYRI ve BIRLIKTE test eder:
//   1. GORUNURLUK: gercek sayfa, o rolun oturumuyla render edilir; yetkisiz
//      kontrolun HTML'de HIC OLMADIGI dogrulanir (CSS ile gizleme SAYILMAZ).
//   2. ZORLAMA: ayni role, arayuzde hic gormedigi aksiyonu ELLE POST eder;
//      403 dondugu VE veritabaninda hicbir sey degismedigi dogrulanir.
//
// Ikinci katman asil olandir: "gizleme != yetkilendirme". Bu betigin
// yakaladigi gercek acik (duzeltmeden once canli olarak uretildi):
//   viewer -> team_members.php POST assign -> 200 + "Atama kaydedildi"
//             + team_members satiri OLUSTU.
//
// Fikstur: scripts/seed_demo_users.php'nin olusturdugu demo hesaplari
// (owner/editor/commenter/viewer @bcc.local). Betik KENDI gecici kurban
// uyeligini olusturur ve her durumda temizler; baska veriye DOKUNMAZ.
//
// Calistirma: C:\php73\php.exe scripts\_verify_rbac.php

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

// Yetkisiz POST denemesi — GERCEK sayfa/uc nokta dosyasini calistirir.
function post_as($userId, $page, $query, $post)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_post_as_case.php')
        . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg($page)
        // base64: Windows escapeshellarg() ham JSON'daki cift tirnaklari
        // bozuyor (bkz. _post_as_case.php basligi).
        . ' ' . escapeshellarg($query) . ' ' . escapeshellarg(base64_encode(json_encode($post)));

    $out = (string) shell_exec($cmd . ' 2>&1');
    $status = preg_match('/HTTP_STATUS=(\d+)/', $out, $m) ? (int) $m[1] : 0;

    return array('status' => $status, 'body' => $out);
}

// ---------------------------------------------------------------------------
// Fikstur
// ---------------------------------------------------------------------------
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

// "Kurban": ekibe HENUZ uye olmayan bir hesap — yetkisiz ekleme denemelerinin
// hedefi. Sadece bir team_members satiri soz konusu, kullanici hesabina
// DOKUNULMAZ; her cikista temizlenir.
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
    // Test bir sekilde uyelik olusturduysa (yani bir acik varsa) burada temizlenir.
    bcc_execute(
        'DELETE FROM team_members WHERE team_id = :t AND user_id = :u',
        array('t' => $teamId, 'u' => $victimId)
    );
});

// ---------------------------------------------------------------------------
// A) Yetenek haritasi — saf fonksiyonlar
// ---------------------------------------------------------------------------
echo "--- A) Yetenek haritasi (src/auth.php) ---\n";

$matrix = array(
    // rol => array(bases, members, schema, records, comment)
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

// ---------------------------------------------------------------------------
// B) team_members.php — GORUNURLUK
// ---------------------------------------------------------------------------
echo "\n--- B) team_members.php gorunurluk ---\n";

$tmMarkers = array(
    'tm-assign-form' => 'uye ekleme formu',
    'tm-role-select' => 'rol degistirme acilir listesi',
    'tm-bulk-remove-form' => 'toplu cikarma formu',
    'data-tm-row-check' => 'satir secim kutusu',
    'data-tm-select-all' => 'tumunu sec kutusu',
);

// creator@bcc.local KALDIRILDI (rolu zaten 'owner'di, owner@bcc.local ile ayni
// seyi test ediyordu). Yerine commenter@bcc.local: GERCEKTEN farkli bir seviye,
// ve bu yeteneklerin HICBIRINE sahip olmamali.
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

// ---------------------------------------------------------------------------
// C) team_members.php — ZORLAMA (asil kapi)
// ---------------------------------------------------------------------------
echo "\n--- C) team_members.php yetkisiz POST reddi ---\n";

foreach (array('editor@bcc.local', 'viewer@bcc.local') as $email) {
    // 1) Uye EKLEME denemesi
    $r = post_as($uid[$email], 'team_members.php', 'team_id=' . $teamId, array(
        'action' => 'assign', 'user_id' => $victimId, 'role' => 'viewer',
    ));
    check($email . ': POST assign -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    check($email . ': uyelik OLUSMADI (DB degismedi)',
        no_row(bcc_fetch_one('SELECT id FROM team_members WHERE team_id=:t AND user_id=:u',
            array('t' => $teamId, 'u' => $victimId))));

    // 2) Rol YUKSELTME denemesi (kendini owner yapmak)
    $r = post_as($uid[$email], 'team_members.php', 'team_id=' . $teamId, array(
        'action' => 'assign', 'user_id' => $uid[$email], 'role' => 'owner',
    ));
    check($email . ': POST kendini owner yapma -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    $selfRole = bcc_fetch_one('SELECT role FROM team_members WHERE team_id=:t AND user_id=:u',
        array('t' => $teamId, 'u' => $uid[$email]));
    $expectedRole = ($email === 'editor@bcc.local') ? 'editor' : 'viewer';
    check($email . ': kendi rolu degismedi (' . $expectedRole . ')',
        $selfRole && $selfRole['role'] === $expectedRole, $selfRole ? $selfRole['role'] : 'yok');

    // 3) Uye CIKARMA denemesi (owner'i ekipten atmak)
    $r = post_as($uid[$email], 'team_members.php', 'team_id=' . $teamId, array(
        'action' => 'remove', 'user_id' => $uid['owner@bcc.local'],
    ));
    check($email . ': POST owner cikarma -> 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    check($email . ': owner hala ekipte',
        !no_row(bcc_fetch_one('SELECT id FROM team_members WHERE team_id=:t AND user_id=:u',
            array('t' => $teamId, 'u' => $uid['owner@bcc.local']))));

    // 4) TOPLU cikarma denemesi
    //
    // Uye sayisi ONCE olculur, SONRA karsilastirilir. Onceki iki hali de
    // kirilgandi: once sabit "=== 4" yaziyordu (listeye besinci hesap
    // eklenince kirildi), sonra count(bcc_demo_accounts())'tan turetiliyordu
    // (bu sefer demo listesinden bir hesap CIKARILINCA kirildi, cunku ekipte
    // listede olmayan eski uyeler kalabiliyor). Testin dogruladigi sey
    // "KIMSE CIKARILAMADI"dir — ekibin kac kisilik oldugu HIC ONEMLI DEGIL.
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

// Owner GERCEKTEN yapabiliyor mu (kapi fazla kapanmadi mi)?
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

// ---------------------------------------------------------------------------
// D) grid.php — Paylas popup + sema/kayit kontrolleri
// ---------------------------------------------------------------------------
echo "\n--- D) grid.php gorunurluk ---\n";

$tableRow = bcc_fetch_one(
    "SELECT tm.id FROM tables_meta tm JOIN bases b ON b.id = tm.base_id
     WHERE b.team_id = :t AND tm.name = 'Musteriler' LIMIT 1",
    array('t' => $teamId)
);
$tableId = (int) $tableRow['id'];

foreach (array('owner@bcc.local' => 'owner', 'editor@bcc.local' => 'editor', 'viewer@bcc.local' => 'viewer') as $email => $role) {
    $html = render_as($uid[$email], 'grid.php', 'table_id=' . $tableId);

    // Paylas popup'indaki KATILIMCI EKLEME yolu — yalnizca owner.
    //
    // ESKIDEN: popup'in icinde team_members.php'ye tam sayfa POST eden bir
    // <form class="collab-popover-assign"> vardi. O form KALDIRILDI; ekleme
    // artik sayfadan cikmadan "Paylas" MODALINDA yapiliyor
    // (src/partials/share_modal.php). Bu yuzden kontrol iki yeni ize bakiyor:
    //   1. .collab-popover-add-btn  — popup'taki "Katilimci ekle" tetikleyicisi
    //      (sunucu yetkisiz kullaniciya HIC basmiyor),
    //   2. BCC_SHARE_MODAL.can_manage — modalin davet kutusunu/rol
    //      <select>'lerini acan sunucu bayragi.
    // Ikisi de owner'da VAR, editor/viewer'da YOK olmali.
    $isOwner = ($role === 'owner');
    check($email . ': "Katilimci ekle" tetikleyicisi ' . ($isOwner ? 'var' : 'YOK'),
        (strpos($html, 'collab-popover-add-btn') !== false) === $isOwner);
    check($email . ': modal can_manage = ' . var_export($isOwner, true),
        (strpos($html, '"can_manage":true') !== false) === $isOwner);
    // Eski form gercekten kalkmis olmali (hicbir rolde basilmamali).
    check($email . ': eski collab-popover-assign formu KALMADI',
        strpos($html, 'collab-popover-assign') === false);

    // Kayit duzenleme — editor+.
    check($email . ': BCC_CAN_EDIT = ' . var_export(bcc_can_edit_records($role), true),
        strpos($html, 'var BCC_CAN_EDIT = ' . (bcc_can_edit_records($role) ? 'true' : 'false') . ';') !== false);

    // Yorum — commenter+.
    check($email . ': BCC_CAN_COMMENT = ' . var_export(bcc_can_comment($role), true),
        strpos($html, 'var BCC_CAN_COMMENT = ' . (bcc_can_comment($role) ? 'true' : 'false') . ';') !== false);

    // Herkes veriyi OKUR.
    check($email . ': veri goruluyor', strpos($html, 'Acme') !== false);
}

// ---------------------------------------------------------------------------
// E) SEMA: editor alan/tablo ekleyemez (OpsFlow: Editor sema degistiremez)
// ---------------------------------------------------------------------------
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
$html = render_as($uid['editor@bcc.local'], 'base_tables.php', 'base_id=' . (int) $baseRow['id']);
check('editor: base_tables.php tablo olusturma formu YOK', strpos($html, '<th>İşlemler</th>') === false);

$html = render_as($uid['owner@bcc.local'], 'table_fields.php', 'table_id=' . $tableId);
check('owner: table_fields.php "Islemler" kolonu VAR', strpos($html, '<th>İşlemler</th>') !== false);

// Kayit duzenleme editor'de ACIK kalmali (asil ayrim bu).
$recCountBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t AND deleted_at IS NULL', array('t' => $tableId));
$r = post_as($uid['editor@bcc.local'], 'api/record_add.php', '', array('table_id' => $tableId));
check('editor: api/record_add.php KABUL edildi (kayit duzenleme acik)', $r['status'] === 200,
    'HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 120));
$recCountAfter = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t AND deleted_at IS NULL', array('t' => $tableId));
check('editor: kayit gercekten eklendi', $recCountAfter === $recCountBefore + 1);

// Eklenen test kaydini temizle.
if ($recCountAfter > $recCountBefore) {
    $newRec = bcc_fetch_one('SELECT id FROM records WHERE table_id = :t ORDER BY id DESC LIMIT 1', array('t' => $tableId));
    bcc_execute('DELETE FROM cell_values WHERE record_id = :r', array('r' => $newRec['id']));
    bcc_execute('DELETE FROM records WHERE id = :r', array('r' => $newRec['id']));
}

// Viewer kayit da ekleyemez.
$r = post_as($uid['viewer@bcc.local'], 'api/record_add.php', '', array('table_id' => $tableId));
check('viewer: api/record_add.php reddedildi', $r['status'] === 403 || strpos($r['body'], 'yetkiniz') !== false,
    'HTTP ' . $r['status']);
check('viewer: kayit sayisi degismedi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t AND deleted_at IS NULL', array('t' => $tableId)) === $recCountBefore);

// ---------------------------------------------------------------------------
// F) workspaces.php butonlari
// ---------------------------------------------------------------------------
echo "\n--- F) workspaces.php buton gorunurlugu ---\n";

// creator@bcc.local KALDIRILDI (rolu zaten 'owner'di, owner@bcc.local ile ayni
// seyi test ediyordu). Yerine commenter@bcc.local: GERCEKTEN farkli bir seviye,
// ve bu yeteneklerin HICBIRINE sahip olmamali.
foreach (array('owner@bcc.local' => true, 'commenter@bcc.local' => false,
               'editor@bcc.local' => false, 'viewer@bcc.local' => false) as $email => $shouldSee) {
    $html = render_as($uid[$email], 'workspaces.php', 'team_id=' . $teamId);

    check($email . ': "Katılımcıları yönet" ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'Katılımcıları yönet') !== false) === $shouldSee);
    check($email . ': "Base oluştur" ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'Base oluştur') !== false) === $shouldSee);
    check($email . ': "Ayarlar" ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, '>Ayarlar<') !== false || strpos($html, "\n                                    Ayarlar") !== false) === $shouldSee);
    check($email . ': satir ici "yonet" kisayolu ' . ($shouldSee ? 'var' : 'YOK'),
        (strpos($html, 'wsx-member-manage') !== false) === $shouldSee);

    // Katilimci listesi HERKESE gorunur (OpsFlow'da da oyle).
    check($email . ': katilimci listesi goruluyor', strpos($html, 'wsx-collab-grid') !== false);

    if (!$shouldSee) {
        check($email . ': rol etiketi gosteriliyor', strpos($html, 'wsx-role-note') !== false);
    }
}

// ---------------------------------------------------------------------------
// G) dashboard.php + bases.php (onceki turdan — regresyon korumasi)
// ---------------------------------------------------------------------------
echo "\n--- G) dashboard.php / bases.php (regresyon) ---\n";

// creator@bcc.local KALDIRILDI (rolu zaten 'owner'di, owner@bcc.local ile ayni
// seyi test ediyordu). Yerine commenter@bcc.local: GERCEKTEN farkli bir seviye,
// ve bu yeteneklerin HICBIRINE sahip olmamali.
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

// ---------------------------------------------------------------------------
// H) Kapsam korumasi: elle yazilmis rol kontrolu KALMAMALI
// ---------------------------------------------------------------------------
echo "\n--- H) Tek kaynak korumasi ---\n";

// Aranan sey: CAGIRANIN KENDI rolunu ($role) elle bir esikle karsilastiran kod.
// $targetMember['role'] === 'owner' gibi BASKA birinin rolune bakan satirlar
// kapsam disidir — onlar yetki esigi degil veri kontroludur (ör. "son owner'i
// ekipten cikarma" korumasi). Desen bu yuzden yalnizca duz $role degiskenini
// hedefler, dizi erisimlerini ($x['role']) DEGIL.
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
check('team_members.php yetkisiz POST\'ta 403 + die',
    strpos($tmSrc, 'http_response_code(403)') !== false);

// ---------------------------------------------------------------------------
echo "\n";
$failed = count(array_filter($results, function ($r) { return !$r; }));
echo ($failed === 0 ? 'TUM TESTLER GECTI' : $failed . ' TEST KALDI') . ' (' . count($results) . " kontrol)\n";
exit($failed === 0 ? 0 : 1);
