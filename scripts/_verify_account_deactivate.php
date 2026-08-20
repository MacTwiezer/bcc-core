<?php
// Hesap silme yasagini, kendi hesabini pasife almayi ve Creator rolunun
// tamamen kaldirildigini UCTAN UCA dogrular.
//
// Kurallar:
//   1. Hicbir kullanici kendi hesabini SILEMEZ (rol bagimsiz, admin dahil).
//   2. Kullanici kendi hesabini PASIFE ALABILIR.
//   3. Pasif kullanici giris yapamaz (mevcut davranis korunuyor).
//   4. Creator diye bir rol YOK; hicbir yuzeyde gorunmez ve atanamaz.
//
// Yontem: uc noktalar GERCEK oturumla, ayri PHP alt sureclerinde calistirilir.
// KENDI kurban kullanicilarini olusturur ve siler.
//
// Calistirma: C:\php73\php.exe scripts\_verify_account_deactivate.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

define('T_PASS', 'DeactivateTest!2026');

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

function call_api($endpoint, $userId, $params)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_post_as_case.php')
        . ' ' . escapeshellarg((string) $userId)
        . ' ' . escapeshellarg('api/' . $endpoint)
        . ' ' . escapeshellarg('')
        . ' ' . escapeshellarg(base64_encode(json_encode($params)));

    $raw = (string) shell_exec($cmd . ' 2>&1');
    $status = 200;
    if (preg_match('/HTTP_STATUS=(\d+)/', $raw, $m)) {
        $status = (int) $m[1];
        $raw = preg_replace('/\s*HTTP_STATUS=\d+\s*/', '', $raw);
    }

    return array('status' => $status, 'body' => trim($raw), 'json' => json_decode(trim($raw), true));
}

echo "=== A) Silme yolu GERCEKTEN kaldirildi mi? ===\n";

check('api/account_delete.php dosyasi YOK',
    !file_exists(__DIR__ . '/../public/api/account_delete.php'));

// Projede kullanici satirini silen BASKA bir yol kalmamali. Yorumlari soyarak
// ara — karari ACIKLAYAN yorumlara takilmasin.
$deleters = array();
foreach (array_merge(
    glob(__DIR__ . '/../public/*.php'),
    glob(__DIR__ . '/../public/api/*.php'),
    glob(__DIR__ . '/../public/admin/*.php'),
    glob(__DIR__ . '/../src/*.php')
) as $file) {
    $code = '';
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok) && in_array($tok[0], array(T_COMMENT, T_DOC_COMMENT), true)) { continue; }
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    if (preg_match('/DELETE\s+FROM\s+users\b/i', $code)) {
        $deleters[] = basename($file);
    }
}
check('uygulama kodunda "DELETE FROM users" YOK', empty($deleters), implode(', ', $deleters));

$accountPage = file_get_contents(__DIR__ . '/../public/account.php');
check('account.php: "Hesabimi Sil" butonu YOK',
    strpos($accountPage, 'account-delete-trigger') === false);
check('account.php: "Hesabimi Pasife Al" butonu VAR',
    strpos($accountPage, 'account-deactivate-trigger') !== false);

$accountJs = file_get_contents(__DIR__ . '/../public/assets/account-page.js');
// DIKKAT (projede DORDUNCU kez ayni ders — bkz. docs/PROJE-DURUM.md
// grid-export.css / mail_verification vakalari ve _verify_note_view_log.php'deki
// beforeunload notu): ham metinde uc nokta adini aramak, o karari ACIKLAYAN
// yoruma takilip yanlis KALDI verir. Aranan sey KULLANIM'dir — post(...) cagrisi.
check('account-page.js: account_delete.php CAGIRMIYOR',
    preg_match('/post\(\s*[\'"]\/api\/account_delete\.php[\'"]/', $accountJs) === 0);
check('account-page.js: account_deactivate.php cagiriyor',
    preg_match('/post\(\s*[\'"]\/api\/account_deactivate\.php[\'"]/', $accountJs) === 1);

echo "\n=== B) Kurban kullanicilar ===\n";

$emails = array(
    'admin'   => 'zz.deact.admin@bcc-test.local',
    'normal'  => 'zz.deact.user@bcc-test.local',
    'keeper'  => 'zz.deact.keeper@bcc-test.local',   // ikinci admin: son-admin kilidini acar
);
foreach ($emails as $e) {
    bcc_execute('DELETE FROM users WHERE email = :e', array('e' => $e));
}

$uid = array();
foreach ($emails as $key => $e) {
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active)
         VALUES (:e, :h, :n, :a, 1)',
        array('e' => $e, 'h' => password_hash(T_PASS, PASSWORD_DEFAULT),
              'n' => 'ZZ ' . $key, 'a' => ($key === 'normal') ? 0 : 1)
    );
    $uid[$key] = (int) bcc_last_insert_id();
}
check('kurban kullanicilar olusturuldu (2 admin + 1 normal)', count($uid) === 3);

register_shutdown_function(function () use ($emails) {
    foreach ($emails as $e) {
        bcc_execute('DELETE FROM users WHERE email = :e', array('e' => $e));
    }
});

echo "\n=== C) TEST 1-2: kendi hesabini SILME denemesi ===\n";

// Uc nokta artik YOK; istek elle gonderilse bile calisacak bir yol olmamali.
foreach (array('admin' => 'TEST 1 (admin)', 'normal' => 'TEST 2 (normal kullanici)') as $key => $label) {
    $r = call_api('account_delete.php', $uid[$key], array('current_password' => T_PASS));
    $stillThere = bcc_fetch_one('SELECT id FROM users WHERE id = :id', array('id' => $uid[$key]));
    check($label . ': hesap SILINMEDI', $stillThere !== false, $r['body']);
    check($label . ': silme uc noktasi cagirilamadi', stripos($r['body'], 'bulunamadi') !== false
        || stripos($r['body'], 'not found') !== false || $r['body'] === '' || $r['status'] >= 400,
        'HTTP ' . $r['status'] . ' / ' . substr($r['body'], 0, 80));
}

echo "\n=== D) TEST 3: kendi hesabini PASIFE ALMA ===\n";

// Yanlis sifre reddedilmeli.
$r = call_api('account_deactivate.php', $uid['normal'], array('current_password' => 'yanlis-sifre'));
$row = bcc_fetch_one('SELECT is_active FROM users WHERE id = :id', array('id' => $uid['normal']));
check('yanlis sifre -> 422 ve hesap AKTIF kaldi',
    $r['status'] === 422 && (int) $row['is_active'] === 1, 'HTTP ' . $r['status'] . ' ' . $r['body']);

// Dogru sifre: pasife alinmali.
$r = call_api('account_deactivate.php', $uid['normal'], array('current_password' => T_PASS));
$row = bcc_fetch_one('SELECT is_active FROM users WHERE id = :id', array('id' => $uid['normal']));
check('TEST 3: normal kullanici kendi hesabini PASIFE ALDI',
    $r['status'] === 200 && (int) $row['is_active'] === 0, 'HTTP ' . $r['status'] . ' ' . $r['body']);

$stillExists = bcc_fetch_one('SELECT id FROM users WHERE id = :id', array('id' => $uid['normal']));
check('pasife alinan hesap SILINMEDI (geri alinabilir)', $stillExists !== false);

$audit = bcc_fetch_one(
    "SELECT id FROM audit_log WHERE action = 'user.self_deactivate' AND entity_id = :id ORDER BY id DESC LIMIT 1",
    array('id' => $uid['normal']));
check('denetim kaydi yazildi (user.self_deactivate)', $audit !== false);

// ADMIN de kendi hesabini pasife alabilmeli (rol bagimsiz kural).
$r = call_api('account_deactivate.php', $uid['admin'], array('current_password' => T_PASS));
$row = bcc_fetch_one('SELECT is_active FROM users WHERE id = :id', array('id' => $uid['admin']));
check('ADMIN de kendi hesabini pasife alabiliyor (rol bagimsiz)',
    $r['status'] === 200 && (int) $row['is_active'] === 0, 'HTTP ' . $r['status'] . ' ' . $r['body']);

echo "\n=== E) Pasif kullanici giris yapamiyor (mevcut davranis) ===\n";

check('attempt_login pasif hesapta "inactive" donuyor',
    attempt_login($emails['normal'], T_PASS) === 'inactive');

echo "\n=== F) Son admin kilidi ===\n";

// Su an: admin pasif, keeper aktif admin. Diger TUM adminleri gecici pasife
// alip keeper'i TEK aktif admin birakiyoruz.
$otherAdmins = bcc_fetch_all('SELECT id FROM users WHERE is_admin = 1 AND is_active = 1 AND id <> :k',
    array('k' => $uid['keeper']));
foreach ($otherAdmins as $a) {
    bcc_execute('UPDATE users SET is_active = 0 WHERE id = :id', array('id' => (int) $a['id']));
}

$r = call_api('account_deactivate.php', $uid['keeper'], array('current_password' => T_PASS));
$row = bcc_fetch_one('SELECT is_active FROM users WHERE id = :id', array('id' => $uid['keeper']));
check('tek aktif admin kendini pasife ALAMIYOR (platform kilitlenmesin)',
    $r['status'] === 422 && (int) $row['is_active'] === 1, 'HTTP ' . $r['status'] . ' ' . $r['body']);

// Gercek adminleri GERI AC — bu betik kullanicinin hesaplarini bozmamali.
foreach ($otherAdmins as $a) {
    bcc_execute('UPDATE users SET is_active = 1 WHERE id = :id', array('id' => (int) $a['id']));
}
$restored = (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1 AND id <> :k',
    array('k' => $uid['keeper']));
check('gercek admin hesaplari GERI ACILDI', $restored === count($otherAdmins), 'geri acilan=' . $restored);

echo "\n=== G) TEST 5-7: Creator rolu ===\n";

check('BCC_ROLE_RANK icinde creator YOK', !isset($GLOBALS['BCC_ROLE_RANK']['creator']));
check('BCC_ROLE_LABELS icinde Creator YOK', !isset($GLOBALS['BCC_ROLE_LABELS']['creator']));

// TEST 5: rol secim listesi (arayuz) — hicbir rankta creator gorunmemeli.
$anyCreator = false;
foreach (array(1, 2, 3, 4) as $rank) {
    if (in_array('creator', bcc_assignable_roles($rank), true)) { $anyCreator = true; }
}
check('TEST 5: rol secim listesinde Creator GORUNMUYOR (hicbir rankta)', !$anyCreator);

// Veritabani ENUM'u
$roleCol = bcc_fetch_one("SHOW COLUMNS FROM team_members LIKE 'role'");
check('DB ENUM icinde creator YOK', stripos($roleCol['Type'], 'creator') === false, $roleCol['Type']);

// TEST 6/7: API uzerinden creator atanmaya calisilirsa reddedilmeli.
$assignable = bcc_assignable_roles($GLOBALS['BCC_ROLE_RANK']['owner']);
$res = bcc_team_member_assign(1, $uid['keeper'], 'creator', $GLOBALS['BCC_ROLE_RANK']['owner'], $assignable);
check('TEST 6/7: creator rolu atanamiyor (whitelist reddi)',
    $res['ok'] === false, json_encode($res, JSON_UNESCAPED_UNICODE));

// Yetenek fonksiyonlarinin hicbiri creator'a acik olmamali.
check('creator hicbir yetenege sahip degil',
    !bcc_can_manage_bases('creator') && !bcc_can_manage_members('creator')
    && !bcc_can_manage_schema('creator') && !bcc_can_edit_records('creator')
    && !bcc_can_comment('creator') && !bcc_is_representative('creator')
    && !bcc_can_view_record_audits('creator'));

// Demo hesap listesi
$demoEmails = array_column(bcc_demo_accounts(), 'email');
$demoLabels = array_column(bcc_demo_accounts(), 'label');
check('demo listesinde creator@bcc.local YOK', !in_array('creator@bcc.local', $demoEmails, true),
    implode(', ', $demoEmails));
check('demo listesinde "Creator" etiketi YOK', !in_array('Creator', $demoLabels, true),
    implode(', ', $demoLabels));
check('demo listesi DORT gercek rolu birebir karsiliyor',
    count($demoEmails) === 4 && array_column(bcc_demo_accounts(), 'role') === array('owner', 'editor', 'commenter', 'viewer'),
    implode(', ', array_column(bcc_demo_accounts(), 'role')));

echo "\n=== H) Kaynak taramasi: aktif kodda Creator ===\n";

// Yorumlari soyup ARA: karari aciklayan yorumlara takilmasin.
$hits = array();
foreach (array_merge(
    glob(__DIR__ . '/../public/*.php'),
    glob(__DIR__ . '/../public/api/*.php'),
    glob(__DIR__ . '/../public/admin/*.php'),
    glob(__DIR__ . '/../src/*.php'),
    glob(__DIR__ . '/../src/partials/*.php')
) as $file) {
    $code = '';
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok) && in_array($tok[0], array(T_COMMENT, T_DOC_COMMENT), true)) { continue; }
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    // created_by / created_time / $creator gibi ALAKASIZ eslesmeleri ele.
    if (preg_match('/[\'"]creator[\'"]|creator@|[\'"]Creator[\'"]/i', $code, $m)) {
        $hits[] = basename($file) . ' (' . $m[0] . ')';
    }
}
check('aktif PHP kodunda "creator" string sabiti YOK', empty($hits), implode(', ', $hits));

$jsHits = array();
foreach (glob(__DIR__ . '/../public/assets/*.js') as $file) {
    if (strpos(basename($file), 'vendor') !== false) { continue; }
    if (preg_match('/creator/i', file_get_contents($file))) {
        $jsHits[] = basename($file);
    }
}
check('frontend JS icinde creator YOK', empty($jsHits), implode(', ', $jsHits));

echo "\n" . str_repeat('-', 62) . "\n";
$passed = count(array_filter($results));
$total = count($results);
echo ($passed === $total ? 'SONUC: ' : 'SONUC: DIKKAT — ') . $passed . '/' . $total . " kontrol gecti\n";
exit($passed === $total ? 0 : 1);
