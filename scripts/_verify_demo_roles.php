<?php
// Demo hesaplarinin rol sinirlarini UCTAN UCA dogrular.
//
// Yontem: her demo kullanicisi icin GERCEK sayfalar (dashboard.php, grid.php,
// workspaces.php, bases.php) ayri bir PHP alt surecinde, o kullanicinin
// oturumuyla render edilir (bkz. _render_as_case.php) ve uretilen HTML'de
// role gore GORUNMESI/GORUNMEMESI gereken isaretler aranir. Sayfalarin kendi
// kodu calisir — yetki mantiginin bir kopyasi test edilmez.
//
// Ayrica: giris (attempt_login) her hesap icin gercekten denenir, cunku
// "sifre calisiyor mu" bu betigin asil sorusudur.
//
// Calistirma: C:\php73\php.exe scripts\_verify_demo_roles.php

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
        . ' ' . escapeshellarg((string) $userId)
        . ' ' . escapeshellarg($page)
        . ' ' . escapeshellarg($query);

    return (string) shell_exec($cmd . ' 2>&1');
}

$accounts = bcc_demo_accounts();

// ---------------------------------------------------------------------------
// A) Hesaplar ve kimlik bilgileri
// ---------------------------------------------------------------------------
echo "--- A) Hesaplar, sifreler, roller ---\n";

$teamRow = bcc_fetch_one("SELECT id FROM teams WHERE name = 'Demo Calisma Alani' LIMIT 1");
check('demo ekibi var', $teamRow !== false && $teamRow !== null);
$teamId = $teamRow ? (int) $teamRow['id'] : 0;

$userIdByEmail = array();

foreach ($accounts as $acc) {
    $u = bcc_fetch_one(
        'SELECT id, password_hash, is_active FROM users WHERE email = :e LIMIT 1',
        array('e' => $acc['email'])
    );

    $exists = ($u !== false && $u !== null);
    check($acc['email'] . ' hesabi var', $exists);

    if (!$exists) {
        continue;
    }

    $userIdByEmail[$acc['email']] = (int) $u['id'];

    check($acc['email'] . ' aktif (giris yapabilir)', (int) $u['is_active'] === 1);
    check($acc['email'] . ' sifresi "' . $acc['password'] . '" ile dogrulaniyor',
        password_verify($acc['password'], $u['password_hash']));

    $m = bcc_fetch_one(
        'SELECT role FROM team_members WHERE team_id = :t AND user_id = :u LIMIT 1',
        array('t' => $teamId, 'u' => $u['id'])
    );
    check($acc['email'] . ' rolu = ' . $acc['role'],
        $m && $m['role'] === $acc['role'], $m ? $m['role'] : 'uyelik yok');
}

// attempt_login() GERCEKTEN calistirilir (login.php'nin cagirdigi fonksiyon).
// Oturum yan etkisi olmasin diye alt surecte.
echo "\n--- B) attempt_login() gercek giris denemesi ---\n";

foreach ($accounts as $acc) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_login_case.php')
        . ' ' . escapeshellarg($acc['email']) . ' ' . escapeshellarg($acc['password']);
    $out = trim((string) shell_exec($cmd . ' 2>&1'));
    check($acc['email'] . ' -> attempt_login = ok', $out === 'ok', $out);
}

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_login_case.php')
    . ' ' . escapeshellarg('owner@bcc.local') . ' ' . escapeshellarg('yanlis-sifre');
check('yanlis sifre reddediliyor', trim((string) shell_exec($cmd . ' 2>&1')) === 'invalid');

// ---------------------------------------------------------------------------
// C) dashboard.php — base olusturma/silme yalnizca owner
// ---------------------------------------------------------------------------
echo "\n--- C) dashboard.php yetki sinirlari ---\n";

$expectCreate = array(
    'owner@bcc.local' => true,
    'creator@bcc.local' => true,   // rolu 'owner' (OpsFlow Creator eslemesi)
    'editor@bcc.local' => false,
    'viewer@bcc.local' => false,
);

foreach ($expectCreate as $email => $shouldSee) {
    if (!isset($userIdByEmail[$email])) {
        continue;
    }

    $html = render_as($userIdByEmail[$email], 'dashboard.php');
    $hasTile = strpos($html, 'home-create-base-btn') !== false;
    $hasModal = strpos($html, 'home-create-base-modal') !== false;
    $hasDelete = strpos($html, 'data-base-delete') !== false;

    check($email . ': "+ Yeni Base Olustur" ' . ($shouldSee ? 'GORUNUR' : 'YOK'), $hasTile === $shouldSee);
    check($email . ': olusturma modali ' . ($shouldSee ? 'basilir' : 'HTML\'de HIC YOK'), $hasModal === $shouldSee);
    check($email . ': "Sil" ogesi ' . ($shouldSee ? 'var' : 'YOK'), $hasDelete === $shouldSee);

    // Herkes base'leri GORUR (OpsFlow: "Access all bases ... at your assigned
    // permission level" bes rolde de acik).
    check($email . ': demo base\'leri goruyor (erisim rolden bagimsiz)',
        strpos($html, 'Demo CRM') !== false);

    // Rol rozeti kartta dogru yaziyor mu?
    $role = ($email === 'editor@bcc.local') ? 'editor' : (($email === 'viewer@bcc.local') ? 'viewer' : 'owner');
    check($email . ': kart rozeti home-base-role--' . $role,
        strpos($html, 'home-base-role--' . $role) !== false);
}

// ---------------------------------------------------------------------------
// D) grid.php — duzenleme yalnizca editor+
// ---------------------------------------------------------------------------
echo "\n--- D) grid.php yetki sinirlari ---\n";

$tableRow = bcc_fetch_one(
    "SELECT tm.id FROM tables_meta tm
     JOIN bases b ON b.id = tm.base_id
     WHERE b.team_id = :t AND tm.name = 'Musteriler' LIMIT 1",
    array('t' => $teamId)
);
$tableId = $tableRow ? (int) $tableRow['id'] : 0;
check('demo tablosu var', $tableId > 0);

if ($tableId > 0) {
    $expectEdit = array(
        'owner@bcc.local' => true,
        'creator@bcc.local' => true,
        'editor@bcc.local' => true,
        'viewer@bcc.local' => false,
    );

    foreach ($expectEdit as $email => $shouldEdit) {
        if (!isset($userIdByEmail[$email])) {
            continue;
        }

        $html = render_as($userIdByEmail[$email], 'grid.php', 'table_id=' . $tableId);

        check($email . ': grid.php acildi (403 yok)', strpos($html, 'yetkiniz') === false, substr($html, 0, 160));
        check($email . ': demo verisi goruluyor', strpos($html, 'Acme') !== false);
        check($email . ': BCC_CAN_EDIT = ' . ($shouldEdit ? 'true' : 'false'),
            strpos($html, 'var BCC_CAN_EDIT = ' . ($shouldEdit ? 'true' : 'false') . ';') !== false);
    }

    // viewer'da yorum da kapali (commenter+ gerekir), editor'de acik.
    $viewerHtml = render_as($userIdByEmail['viewer@bcc.local'], 'grid.php', 'table_id=' . $tableId);
    check('viewer: BCC_CAN_COMMENT = false', strpos($viewerHtml, 'var BCC_CAN_COMMENT = false;') !== false);

    $editorHtml = render_as($userIdByEmail['editor@bcc.local'], 'grid.php', 'table_id=' . $tableId);
    check('editor: BCC_CAN_COMMENT = true', strpos($editorHtml, 'var BCC_CAN_COMMENT = true;') !== false);
}

// ---------------------------------------------------------------------------
// E) workspaces.php + bases.php
// ---------------------------------------------------------------------------
echo "\n--- E) workspaces.php / bases.php ---\n";

foreach (array('owner@bcc.local', 'editor@bcc.local', 'viewer@bcc.local') as $email) {
    if (!isset($userIdByEmail[$email])) {
        continue;
    }

    $ws = render_as($userIdByEmail[$email], 'workspaces.php');
    check($email . ': workspaces.php acildi', strpos($ws, 'Demo Calisma Alani') !== false);

    // Rol hapi herkeste kendi rolunu gostermeli.
    $role = ($email === 'editor@bcc.local') ? 'editor' : (($email === 'viewer@bcc.local') ? 'viewer' : 'owner');
    check($email . ': workspaces rol hapi sp-role--' . $role,
        strpos($ws, 'sp-role--' . $role) !== false);

    $bp = render_as($userIdByEmail[$email], 'bases.php');
    $hasForm = strpos($bp, 'Base Oluştur') !== false;
    $shouldHaveForm = ($email === 'owner@bcc.local');
    check($email . ': bases.php olusturma formu ' . ($shouldHaveForm ? 'var' : 'YOK'), $hasForm === $shouldHaveForm);

    if (!$shouldHaveForm) {
        check($email . ': bases.php "Owner rolu gerekir" uyarisi var',
            strpos($bp, 'Owner rolü gerekir') !== false);
    }
}

// ---------------------------------------------------------------------------
// F) login.php demo bloğu bayraga bagli
// ---------------------------------------------------------------------------
echo "\n--- F) Demo blogu bayrak kontrolu ---\n";

$loginSrc = file_get_contents(__DIR__ . '/../public/login.php');
check('login.php demo blogunu bcc_demo_login_enabled() ile sariyor',
    substr_count($loginSrc, 'bcc_demo_login_enabled()') >= 2);
// Sifre literali ARTIK HICBIR izlenen dosyada olmamali (guvenlik denetimi):
// deger yalnizca git'e girmeyen config/app.local.php'de yasiyor. Kontrol,
// literali BU DOSYAYA da gommemek icin yapilandirmadan okunan gercek
// degeri ariyor - yoksa test dosyasinin kendisi sizinti kaynagi olurdu.
$demoPass = bcc_demo_password();
check('login.php sabit sifreyi KENDI ICINDE tasimiyor (tek kaynak: demo_accounts.php)',
    $demoPass === null || strpos($loginSrc, $demoPass) === false);
check('config/app.php varsayilani KAPALI',
    strpos(file_get_contents(__DIR__ . '/../config/app.php'), '$BCC_DEMO_LOGIN = false;') !== false);

$seedSrc = file_get_contents(__DIR__ . '/seed_demo_users.php');
check('seed betigi de bcc_demo_accounts() kullaniyor (kopya liste yok)',
    strpos($seedSrc, 'bcc_demo_accounts()') !== false
    && ($demoPass === null || strpos($seedSrc, $demoPass) === false));

// ---------------------------------------------------------------------------
echo "\n";
$failed = count(array_filter($results, function ($r) { return !$r; }));
echo ($failed === 0 ? 'TUM TESTLER GECTI' : $failed . ' TEST KALDI') . ' (' . count($results) . " kontrol)\n";
exit($failed === 0 ? 0 : 1);
