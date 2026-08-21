<?php
// Calisma alani (= ekip) olusturma: modal akisi + "olusan alan gorunmuyor" bugu.
//
// Kapsam:
//   A) BUG: bcc_create_team() olusturani 'owner' UYE yapiyor
//      (eskiden yalnizca "INSERT INTO teams" vardi; workspaces.php listeyi
//      team_members uzerinden kurdugu icin yeni alan GORUNMUYORDU ve
//      require_team_access() admin muafiyeti tanimadigi icin ekibe HIC KIMSE
//      erisemiyordu)
//   B) api/team_create.php: yetki (admin degilse 403), dogrulama (422),
//      basari (redirect_url SUNUCUDAN)
//   C) CANLI: olusturduktan sonra workspaces.php'de GERCEKTEN gorunuyor
//   D) Modal iki sayfada da basiliyor ve tetikleyiciler bagli
//   E) Klasik yol (admin/create_team.php POST) da AYNI fonksiyonu kullaniyor,
//      yani uyelik orada da olusuyor (iki kod yolu ayrismiyor)
//   F) Islem butunlugu: ekip + uyelik TEK transaction
//
// ⚠️ GERCEK HESAPLARA DOKUNULMAZ: test kendi admin/normal kullanicisini
// yaratir ve sonunda siler (bkz. $cleanup). is_admin=1 ile GERCEK hesaplari
// secen bir sorgu YOK.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_team_create.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';
// ⚠️ audit.php DA GEREKLI: bcc_create_team() log_audit() cagiriyor
// (bcc_create_base() gibi). Kardes testler yalnizca duz SQL kullandigi icin
// bu dosyayi yuklemiyorlar; burada asil fonksiyon CALISTIRILDIGI icin sart.
// bootstrap.php'nin tamami YUKLENMIYOR: o session_start() yapiyor ve CLI'da
// gereksiz yan etkileri var.
require __DIR__ . '/../src/audit.php';

define('BASE_URL', 'http://localhost');
define('ADMIN_EMAIL', 'tcreate.admin@bcc-test.local');
define('PLAIN_EMAIL', 'tcreate.plain@bcc-test.local');
define('TEST_PASS', 'TCreate!2026');
define('TEAM_PREFIX', 'TCreate Test ');

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

function http_request($method, $path, $cookie = null, $postFields = null)
{
    $headers = array();
    if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true, 'follow_location' => 0));
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }
    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $status = 0; $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
            if (stripos($h, 'Set-Cookie:') === 0) { $p = explode(';', substr($h, 11)); $newCookie = trim($p[0]); }
        }
    }

    return array('body' => (string) $body, 'cookie' => $newCookie, 'status' => $status);
}

function extract_csrf_field($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf_field($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

// --- Temizlik: once eski kalintilari sil ---------------------------------
$wipe = function () {
    // Ekipler ONCE: team_members FK'si ON DELETE CASCADE, yani ekip silinince
    // uyelik de gider. Ters sirada silmek uyeligi birakirdi.
    $rows = bcc_fetch_all("SELECT id FROM teams WHERE name LIKE :p", array(':p' => TEAM_PREFIX . '%'));
    foreach ($rows as $r) { bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id'])); }
    foreach (array(ADMIN_EMAIL, PLAIN_EMAIL) as $mail) {
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $mail));
    }
};
$wipe();

try {
    // =====================================================================
    // ORTAM: bir admin, bir normal kullanici (ikisi de BU TESTE AIT)
    // =====================================================================
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 1, 1)',
        array(':e' => ADMIN_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'TCreate Admin'));
    $adminId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => PLAIN_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'TCreate Plain'));
    $plainId = (int) bcc_last_insert_id();

    $adminCookie = login(ADMIN_EMAIL);
    $plainCookie = login(PLAIN_EMAIL);

    // =====================================================================
    // A) ASIL BUG — olusturan 'owner' UYE oluyor mu
    // =====================================================================
    echo "\n--- A) bcc_create_team() uyelik olusturuyor ---\n";
    $nameA = TEAM_PREFIX . 'A';
    $res = bcc_create_team($nameA, $adminId);
    check('A) bcc_create_team ok dondu', !empty($res['ok']), json_encode($res));
    $teamA = (int) $res['id'];
    check('A) teams satiri olustu',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :i', array(':i' => $teamA)) === 1);

    // ⚠️ ADMIN UYE YAPILMAZ. Kisa gecmis: once hic uyelik yoktu (ekip
    // gorunmuyor + erisilemiyordu), sonra olusturan 'owner' uye yapildi, sonra
    // bu yapay uyelikten vazgecilip admin kapsami genisletildi. Bu kontrol o
    // son karari kilitler — "gorunmuyor" bugunun cozumu artik G) bolumunde.
    check('A) olusturan team_members e EKLENMIYOR (admin yapay uye YAPILMAZ)',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $teamA)) === 0);
    check('A) yeni ekip HIC uyesi olmadan olusuyor (katilimci listesi bos)',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t AND user_id = :u',
            array(':t' => $teamA, ':u' => $adminId)) === 0);

    // Ayni ad ikinci kez kabul edilmemeli
    $dup = bcc_create_team($nameA, $adminId);
    check('A) ayni ad ikinci kez reddediliyor', empty($dup['ok']), json_encode($dup));
    check('A) reddedilen denemede EKIP OLUSMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => $nameA)) === 1);

    // Dogrulama sinirlari
    $empty = bcc_create_team('   ', $adminId);
    check('A) bos ad reddediliyor', empty($empty['ok']));
    $long = bcc_create_team(str_repeat('x', 151), $adminId);
    check('A) 151 karakter reddediliyor', empty($long['ok']));
    check('A) reddedilen uzun ad DB ye YAZILMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => str_repeat('x', 151))) === 0);

    // =====================================================================
    // B) api/team_create.php
    // =====================================================================
    echo "\n--- B) api/team_create.php ---\n";
    $wsPage = http_request('GET', '/workspaces.php', $adminCookie);
    $csrf = extract_csrf_field($wsPage['body']);
    check('B) workspaces.php CSRF token basiyor', $csrf !== null);

    // Admin OLMAYAN reddedilmeli
    $plainPage = http_request('GET', '/workspaces.php', $plainCookie);
    $plainCsrf = extract_csrf_field($plainPage['body']);
    $r = http_request('POST', '/api/team_create.php', $plainCookie,
        array('name' => TEAM_PREFIX . 'HACK', 'csrf_token' => $plainCsrf));
    check('B) admin OLMAYAN 403 aliyor', $r['status'] === 403, 'HTTP ' . $r['status'] . ' ' . $r['body']);
    check('B) reddedilen istek ekip OLUSTURMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => TEAM_PREFIX . 'HACK')) === 0);

    // CSRF'siz reddedilmeli
    $r = http_request('POST', '/api/team_create.php', $adminCookie, array('name' => TEAM_PREFIX . 'NOCSRF'));
    check('B) CSRF token YOKKEN 403', $r['status'] === 403, 'HTTP ' . $r['status']);
    check('B) CSRF reddinde ekip OLUSMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => TEAM_PREFIX . 'NOCSRF')) === 0);

    // GET reddedilmeli
    $r = http_request('GET', '/api/team_create.php', $adminCookie);
    check('B) GET 405 aliyor', $r['status'] === 405, 'HTTP ' . $r['status']);

    // Bos ad -> 422 (500 DEGIL)
    $r = http_request('POST', '/api/team_create.php', $adminCookie,
        array('name' => '  ', 'csrf_token' => $csrf));
    check('B) bos ad 422 (500 degil)', $r['status'] === 422, 'HTTP ' . $r['status']);

    // Basari
    $nameB = TEAM_PREFIX . 'B';
    $r = http_request('POST', '/api/team_create.php', $adminCookie,
        array('name' => $nameB, 'csrf_token' => $csrf));
    $data = json_decode($r['body'], true);
    check('B) basarili istek 200', $r['status'] === 200, 'HTTP ' . $r['status'] . ' ' . $r['body']);
    check('B) ok=true ve team_id dondu', !empty($data['ok']) && !empty($data['team_id']), $r['body']);
    $teamB = isset($data['team_id']) ? (int) $data['team_id'] : 0;
    check('B) redirect_url SUNUCUDAN geliyor ve team_id tasiyor',
        isset($data['redirect_url']) && $data['redirect_url'] === '/workspaces.php?team_id=' . $teamB,
        isset($data['redirect_url']) ? $data['redirect_url'] : 'YOK');
    check('B) API yolu da uyelik YARATMIYOR (iki yol ayni davraniyor)',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $teamB)) === 0);

    // =====================================================================
    // C) CANLI: gercekten gorunuyor mu (kullanicinin bildirdigi semptom)
    // =====================================================================
    echo "\n--- C) workspaces.php'de GORUNUYOR mu ---\n";
    $after = http_request('GET', '/workspaces.php', $adminCookie);
    check('C) workspaces.php 200', $after['status'] === 200, 'HTTP ' . $after['status']);
    check('C) yeni calisma alani LISTEDE gorunuyor (asil semptom)',
        strpos($after['body'], htmlspecialchars($nameB, ENT_QUOTES, 'UTF-8')) !== false);
    check('C) A adimindaki alan da gorunuyor',
        strpos($after['body'], htmlspecialchars($nameA, ENT_QUOTES, 'UTF-8')) !== false);

    // Secili gelme: redirect_url'in gonderdigi adres 200 donmeli (403 DEGIL —
    // uyelik olustugu icin require_team_access gecmeli).
    $sel = http_request('GET', '/workspaces.php?team_id=' . $teamB, $adminCookie);
    check('C) redirect_url hedefi 200 (require_team_access GECIYOR)',
        $sel['status'] === 200, 'HTTP ' . $sel['status']);

    // =====================================================================
    // D) Modal iki sayfada da basiliyor
    // =====================================================================
    echo "\n--- D) Modal + tetikleyiciler ---\n";
    check('D) workspaces.php modali basiyor', strpos($after['body'], 'id="create-team-modal"') !== false);
    check('D) workspaces.php tetikleyicisi bagli', strpos($after['body'], 'data-create-team-btn') !== false);
    check('D) workspaces.php ortak JS yukluyor', strpos($after['body'], 'create-team-modal.js') !== false);

    $adminIdx = http_request('GET', '/admin/index.php', $adminCookie);
    check('D) admin/index.php 200', $adminIdx['status'] === 200, 'HTTP ' . $adminIdx['status']);
    check('D) admin/index.php modali basiyor', strpos($adminIdx['body'], 'id="create-team-modal"') !== false);
    check('D) admin/index.php tetikleyicisi bagli', strpos($adminIdx['body'], 'data-create-team-btn') !== false);
    check('D) admin/index.php ortak JS yukluyor', strpos($adminIdx['body'], 'create-team-modal.js') !== false);

    // JS'siz yedek: tetikleyici GERCEK bir href tasimali
    check('D) tetikleyici href yedegini koruyor (JS yoksa sayfaya gider)',
        preg_match('#<a href="/admin/create_team\.php"[^>]*data-create-team-btn#', $after['body']) === 1);

    // Admin OLMAYAN modali GORMEMELI
    $plainAfter = http_request('GET', '/workspaces.php', $plainCookie);
    check('D) admin OLMAYAN modali gormuyor',
        strpos($plainAfter['body'], 'id="create-team-modal"') === false);

    // =====================================================================
    // E) Klasik yol da AYNI fonksiyonu kullaniyor
    // =====================================================================
    echo "\n--- E) admin/create_team.php klasik POST ---\n";
    $ctPage = http_request('GET', '/admin/create_team.php', $adminCookie);
    $ctCsrf = extract_csrf_field($ctPage['body']);
    $nameE = TEAM_PREFIX . 'E';
    $r = http_request('POST', '/admin/create_team.php', $adminCookie,
        array('name' => $nameE, 'csrf_token' => $ctCsrf));
    check('E) klasik POST 200', $r['status'] === 200, 'HTTP ' . $r['status']);
    $teamE = (int) bcc_fetch_column('SELECT id FROM teams WHERE name = :n', array(':n' => $nameE));
    check('E) klasik yol ekibi olusturdu', $teamE > 0);
    // Iki kod yolu AYRISMAMALI: klasik POST da ayni fonksiyonu cagirdigi icin
    // o da uyelik yaratmaz.
    check('E) klasik yol da uyelik YARATMIYOR (kod yollari AYRISMIYOR)',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array(':t' => $teamE)) === 0);
    check('E) klasik yolla olusan alan da workspaces.php de gorunuyor',
        strpos(http_request('GET', '/workspaces.php', $adminCookie)['body'],
            htmlspecialchars($nameE, ENT_QUOTES, 'UTF-8')) !== false);

    // =====================================================================
    // F) Kod yapisi: tek kaynak + transaction
    // =====================================================================
    echo "\n--- F) Tek kaynak / islem butunlugu ---\n";
    $schemaSrc = file_get_contents(__DIR__ . '/../src/schema.php');
    $ctSrc = file_get_contents(__DIR__ . '/../public/admin/create_team.php');
    $apiSrc = file_get_contents(__DIR__ . '/../public/api/team_create.php');

    check('F) create_team.php artik kendi INSERT ini YAPMIYOR',
        strpos($ctSrc, 'INSERT INTO teams') === false);
    check('F) create_team.php ortak fonksiyonu cagiriyor',
        strpos($ctSrc, 'bcc_create_team(') !== false);
    check('F) api/team_create.php ortak fonksiyonu cagiriyor',
        strpos($apiSrc, 'bcc_create_team(') !== false);

    if (preg_match('#function bcc_create_team\(.*?\n\}#s', $schemaSrc, $fn)) {
        $body = $fn[0];
        check('F) bcc_create_team team_members e YAZMIYOR (yapay uyelik yok)',
            strpos($body, 'INSERT INTO team_members') === false);
        check('F) olusturan yine de denetim izine yaziliyor',
            strpos($body, 'created_by_user_id') !== false);
    } else {
        check('F) bcc_create_team govdesi okunabildi', false, 'regex eslesmedi');
    }

    // Listeleme sorgusu BES sayfada kopyalanmisti; tek kaynaga alindi ki admin
    // kapsami gibi bir kural degisince ayrismasinlar.
    foreach (array('dashboard.php', 'starred.php', 'workspaces.php', 'bases.php') as $page) {
        $src = file_get_contents(__DIR__ . '/../public/' . $page);
        check("F) {$page} ERISIM KAPSAMI fonksiyonunu kullaniyor",
            strpos($src, 'bcc_teams_for_current_user()') !== false
            && strpos($src, 'FROM team_members m') === false);
    }
    // ⚠️ account.php AYRI: kisinin KENDI hesabi, erisim kapsami degil GERCEK
    // uyelikler gosterilmeli (yoksa admin her ekibin uyesiymis gibi gorunur ve
    // kullanim sayaclari tum sistemi toplar).
    //
    // ⚠️ YORUMLAR SOYULUYOR: account.php'deki aciklama "bcc_teams_for_current_user()
    // DEGIL" diye o adi ANIYOR; duz bir strpos kararin GEREKCESINE takilip
    // yanlis KALDI verirdi (bu projede daha once bircok kez yasandi).
    $accSrc = file_get_contents(__DIR__ . '/../public/account.php');
    $accCode = '';
    foreach (token_get_all($accSrc) as $tok) {
        if (is_array($tok) && in_array($tok[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        $accCode .= is_array($tok) ? $tok[1] : $tok;
    }
    check('F) account.php GERCEK UYELIK fonksiyonunu kullaniyor',
        strpos($accCode, 'bcc_team_memberships_for_current_user()') !== false
        && strpos($accCode, '$teams = bcc_teams_for_current_user()') === false);

    // =====================================================================
    // G) ADMIN KAPSAMI — "admin tum ekipleri gorur" ve izolasyon BOZULMADI
    // =====================================================================
    echo "\n--- G) Admin kapsami / izolasyon ---\n";

    // Admin, UYESI OLMADIGI ekipleri goruyor mu (bu bolumun ana iddiasi).
    $gPage = http_request('GET', '/workspaces.php', $adminCookie);
    check('G) admin, uyesi OLMADIGI ekibi listede goruyor',
        strpos($gPage['body'], htmlspecialchars($nameA, ENT_QUOTES, 'UTF-8')) !== false);

    // Admin tarafindan HIC olusturulmamis, baskasinin ekibini de gormeli:
    // testin kendi ekiplerinden bagimsiz, sistemdeki toplam sayiyla karsilastir.
    $totalTeams = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams');
    $listedTeams = substr_count($gPage['body'], 'wsx-team-item');
    check('G) admin listesi TUM ekipleri kapsiyor',
        $listedTeams === 0 || $listedTeams === $totalTeams,
        'listelenen: ' . $listedTeams . ' toplam: ' . $totalTeams);

    // require_role() kapisi admin icin aciliyor mu (uyelik YOKKEN).
    $tm = http_request('GET', '/team_members.php?team_id=' . $teamA, $adminCookie);
    check('G) admin, uye OLMADIGI ekibin katilimci sayfasina girebiliyor',
        $tm['status'] === 200, 'HTTP ' . $tm['status']);

    // ⚠️ EN KRITIK KONTROL: admin kapsami genisledi diye NORMAL kullanicinin
    // KVKK izolasyonu gevsememeli. Uyesi olmadigi ekipte hala reddedilmeli.
    $tmPlain = http_request('GET', '/team_members.php?team_id=' . $teamA, $plainCookie);
    check('G) NORMAL kullanici uyesi OLMADIGI ekipte hala REDDEDILIYOR (izolasyon)',
        $tmPlain['status'] === 403, 'HTTP ' . $tmPlain['status']);
    $plainWs = http_request('GET', '/workspaces.php', $plainCookie);
    check('G) NORMAL kullanici baskasinin ekibini listede GORMUYOR',
        strpos($plainWs['body'], htmlspecialchars($nameA, ENT_QUOTES, 'UTF-8')) === false);

    // Kullanicinin ikinci itirazi: admin katilimci listesinde uye gibi
    // GORUNMEMELI (sanal rol, team_members satiri degil).
    //
    // ⚠️ TUM SAYFADA ARAMAK YANLIS OLURDU: kabugun ust bari zaten GIRIS YAPAN
    // kullanicinin e-postasini basiyor, yani duz bir strpos her zaman eslesir
    // ve kontrol hicbir sey olcmez. Yalnizca katilimci TABLOSUNUN govdesine
    // (<tbody data-tm-rows>) bakiliyor.
    $rowsHtml = '';
    if (preg_match('#<tbody data-tm-rows>(.*?)</tbody>#s', $tm['body'], $tb)) {
        $rowsHtml = $tb[1];
    }
    check('G) katilimci tablosu govdesi okunabildi', $rowsHtml !== '');
    check('G) admin katilimci listesinde uye olarak GORUNMUYOR',
        strpos($rowsHtml, htmlspecialchars(ADMIN_EMAIL, ENT_QUOTES, 'UTF-8')) === false);

    // Kod yapisi: admin kurali TEK kaynakta, sayfalara serpilmemis.
    $authSrc = file_get_contents(__DIR__ . '/../src/auth.php');
    check('G) admin kapsami current_user_team_ids() icinde',
        preg_match('#function current_user_team_ids.*?is_platform_admin\(\)#s', $authSrc) === 1);
    check('G) admin rolu current_user_role_in_team() icinde',
        preg_match('#function current_user_role_in_team.*?is_platform_admin\(\)#s', $authSrc) === 1);

    $wipe();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $wipe();
    $results[] = false;
}

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo ($pass === $total ? "SONUC: GECTI ($pass/$total)" : "SONUC: $pass/$total") . "\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
