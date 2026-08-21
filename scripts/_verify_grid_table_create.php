<?php
// Grid'den TABLO OLUSTURMA — aynı sayfada modal, ayrı sayfaya yönlendirme YOK.
//
// Modali IKI tetikleyici acar:
//   1. Tablo sekmeleri cubugundaki "+"  (.gs-table-tab-add)
//   2. "+ Yeni olustur" menusundeki "Bos tablo olustur"
// Ikisi de [data-create-table-btn] tasir ve AYNI modali, AYNI ucnoktayi
// (api/table_create.php) kullanir — ikinci bir akis YOK.
//
// Kapsam:
//   A) Owner: modal + IKI tetikleyici basiliyor, "+" href yedegini koruyor
//   B) Editor: NE tetikleyici NE modal (tablo olusturmak owner isi)
//   C) Ucnokta kapilari: GET/CSRF/yetki
//   D) Dogrulama: bos ad, 150 karakter, AYNI base'te ayni ad
//   E) Kapsamli benzersizlik: BASKA base'te ayni ad SERBEST
//   F) Basari: tablo olusuyor, redirect_url SUNUCUDAN, mevcut tablodan
//      HICBIR SEY kopyalanmiyor (klonlama degil)
//   G) JS tum tetikleyicileri bagliyor ve gezinmeyi durduruyor
//
// ⚠️ GERCEK VERIYE DOKUNMAZ: kendi kullanicilarini/base'ini yaratir, siler.
//
// On kosul: Apache ayakta. Calistirma:
//   C:\php73\php.exe scripts\_verify_grid_table_create.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'tblcreate.owner@bcc-test.local');
define('EDITOR_EMAIL', 'tblcreate.editor@bcc-test.local');
define('TEST_PASS', 'TblCreate!2026');

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

function eq($label, $actual, $expected)
{
    check($label, $actual === $expected,
        'beklenen ' . var_export($expected, true) . ', gelen ' . var_export($actual, true));
}

function http_request($method, $path, $cookie = null, $postFields = null)
{
    $headers = array();
    if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }
    $options = array('http' => array('method' => $method, 'ignore_errors' => true));
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

function extract_csrf($html)
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

$cleanup = function () {
    foreach (array(OWNER_EMAIL, EDITOR_EMAIL) as $mail) {
        $baseIds = array_column(bcc_fetch_all(
            'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
            array(':e' => $mail)
        ), 'id');
        foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $mail));
    }
};
$cleanup();

try {
    $team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$team) { echo "HATA: TY ekibi yok.\n"; exit(1); }
    $teamId = (int) $team['id'];

    $mkUser = function ($email, $role) use ($teamId) {
        bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
            array(':e' => $email, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'TblCreate ' . $role));
        $uid = (int) bcc_last_insert_id();
        bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
            array(':t' => $teamId, ':u' => $uid, ':r' => $role));
        return $uid;
    };

    $ownerId = $mkUser(OWNER_EMAIL, 'owner');
    $editorId = $mkUser(EDITOR_EMAIL, 'editor');

    // Iki base: kapsamli benzersizlik (E) icin gerekli.
    $mkBase = function ($name) use ($teamId, $ownerId) {
        bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
            array(':t' => $teamId, ':n' => $name, ':u' => $ownerId));
        return (int) bcc_last_insert_id();
    };
    $baseA = $mkBase('TblCreate Base A');
    $baseB = $mkBase('TblCreate Base B');

    // Grid acilabilmesi icin baslangic tablosu (bir alanla).
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseA, ':n' => 'Musteriler'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, :n, :ft, NULL, 0)',
        array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));

    $ownerCookie = login(OWNER_EMAIL);
    $editorCookie = login(EDITOR_EMAIL);

    // =====================================================================
    echo "\n--- A) Owner: modal + iki tetikleyici ---\n";
    // =====================================================================
    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $ownerCookie);
    check('A) grid acildi', $g['status'] === 200, 'HTTP ' . $g['status']);
    $html = $g['body'];

    check('A) modal sayfada basili', strpos($html, 'id="gs-create-table-modal"') !== false);
    eq('A) IKI tetikleyici var (sekme "+" ve menu secenegi)',
        substr_count($html, 'data-create-table-btn'), 2);
    check('A) sekme "+" tetikleyicisi bagli',
        preg_match('#class="gs-table-tab-add"[^>]*data-create-table-btn#', $html) === 1
        || preg_match('#data-create-table-btn[^>]*class="gs-table-tab-add"#', $html) === 1);
    check('A) menudeki "Bos tablo olustur" tetikleyicisi bagli',
        strpos($html, 'id="gs-create-table-btn"') !== false);
    // ⚠️ JS'siz yedek: "+" gercek bir href tasimali, yoksa JS yuklenmediginde
    // tiklama HICBIR SEY yapmazdi.
    check('A) "+" href yedegini KORUYOR (JS yoksa eski sayfaya gider)',
        preg_match('#<a href="/base_tables\.php\?base_id=\d+"[^>]*data-create-table-btn#', $html) === 1);

    // =====================================================================
    echo "\n--- B) Editor: tetikleyici de modal da YOK ---\n";
    // =====================================================================
    $ge = http_request('GET', '/grid.php?table_id=' . $tableId, $editorCookie);
    check('B) editor grid acabiliyor', $ge['status'] === 200, 'HTTP ' . $ge['status']);
    check('B) editor tetikleyiciyi GORMUYOR',
        strpos($ge['body'], 'data-create-table-btn') === false);
    check('B) editor modali GORMUYOR',
        strpos($ge['body'], 'id="gs-create-table-modal"') === false);

    // =====================================================================
    echo "\n--- C) Ucnokta kapilari ---\n";
    // =====================================================================
    $csrf = extract_csrf($html);
    check('C) CSRF token bulundu', $csrf !== null);

    $r = http_request('GET', '/api/table_create.php', $ownerCookie);
    eq('C) GET reddediliyor (405)', $r['status'], 405);

    $r = http_request('POST', '/api/table_create.php', $ownerCookie,
        array('base_id' => $baseA, 'name' => 'CSRFsiz'));
    eq('C) CSRF token YOKKEN 403', $r['status'], 403);

    $ecsrf = extract_csrf($ge['body']);
    $r = http_request('POST', '/api/table_create.php', $editorCookie,
        array('base_id' => $baseA, 'name' => 'EditorTablo', 'csrf_token' => $ecsrf));
    eq('C) editor 403 aliyor (owner isi)', $r['status'], 403);
    eq('C) reddedilen istek tablo OLUSTURMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
            array(':b' => $baseA, ':n' => 'EditorTablo')), 0);

    // =====================================================================
    echo "\n--- D) Dogrulama ---\n";
    // =====================================================================
    $r = http_request('POST', '/api/table_create.php', $ownerCookie,
        array('base_id' => $baseA, 'name' => '   ', 'csrf_token' => $csrf));
    eq('D) bos ad 422', $r['status'], 422);

    $r = http_request('POST', '/api/table_create.php', $ownerCookie,
        array('base_id' => $baseA, 'name' => str_repeat('x', 151), 'csrf_token' => $csrf));
    eq('D) 151 karakter 422', $r['status'], 422);

    $r = http_request('POST', '/api/table_create.php', $ownerCookie,
        array('base_id' => $baseA, 'name' => 'Musteriler', 'csrf_token' => $csrf));
    eq('D) AYNI base te ayni ad 422', $r['status'], 422);
    eq('D) mukerrer ad tablo OLUSTURMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
            array(':b' => $baseA, ':n' => 'Musteriler')), 1);

    // =====================================================================
    echo "\n--- E) Kapsamli benzersizlik: BASKA base te ayni ad serbest ---\n";
    // =====================================================================
    $r = http_request('POST', '/api/table_create.php', $ownerCookie,
        array('base_id' => $baseB, 'name' => 'Musteriler', 'csrf_token' => $csrf));
    $d = json_decode($r['body'], true);
    check('E) baska base te ayni ad KABUL', !empty($d['ok']), $r['body']);
    eq('E) ikinci base te tablo olustu',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
            array(':b' => $baseB, ':n' => 'Musteriler')), 1);

    // =====================================================================
    echo "\n--- F) Basari + KLONLAMA YOK ---\n";
    // =====================================================================
    $r = http_request('POST', '/api/table_create.php', $ownerCookie,
        array('base_id' => $baseA, 'name' => 'Siparisler', 'description' => 'Deneme', 'csrf_token' => $csrf));
    $d = json_decode($r['body'], true);
    eq('F) basarili istek 200', $r['status'], 200);
    check('F) ok=true ve table_id dondu', !empty($d['ok']) && !empty($d['table_id']), $r['body']);
    $newId = (int) $d['table_id'];
    eq('F) redirect_url SUNUCUDAN geliyor',
        isset($d['redirect_url']) ? $d['redirect_url'] : null, '/grid.php?table_id=' . $newId);

    eq('F) tablo gercekten olustu',
        bcc_fetch_column('SELECT name FROM tables_meta WHERE id = :i', array(':i' => $newId)), 'Siparisler');
    // ⚠️ "Yeni olustur" MEVCUT TABLOYU KLONLAMAMALI — bu, kullanicinin daha
    // once bildirdigi karisikligin ta kendisiydi. Yeni tabloda kaynak
    // tablonun alanlari/kayitlari OLMAMALI.
    eq('F) yeni tabloda KAYIT yok (klonlanmadi)',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :i', array(':i' => $newId)), 0);
    $srcFields = (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :i', array(':i' => $tableId));
    $newFields = (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :i', array(':i' => $newId));
    check('F) kaynak tablonun alanlari KOPYALANMADI',
        $newFields === 0 || $newFields !== $srcFields,
        'kaynak=' . $srcFields . ' yeni=' . $newFields);

    // Yeni tablonun grid'i acilabilmeli (varsayilan gorunum tembel olusur).
    $ng = http_request('GET', '/grid.php?table_id=' . $newId, $ownerCookie);
    eq('F) yeni tablonun gridi acilabiliyor', $ng['status'], 200);

    // =====================================================================
    echo "\n--- G) JS tum tetikleyicileri bagliyor ---\n";
    // =====================================================================
    $js = file_get_contents(__DIR__ . '/../public/assets/grid-view-manage.js');
    check('G) TEK id yerine tum [data-create-table-btn] seciliyor',
        preg_match('#querySelectorAll\(\s*[\'"]\[data-create-table-btn\][\'"]\s*\)#', $js) === 1);
    check('G) eski tek-id secimi KALMADI',
        strpos($js, "getElementById('gs-create-table-btn')") === false);
    // ⚠️ "+" gercek bir <a href> — preventDefault olmazsa modal acilir acilmaz
    // sayfa base_tables.php'ye giderdi.
    // ⚠️ Pencere BAYT cinsinden: aradaki aciklama yorumu UTF-8 Turkce ve cok
    // baytli karakterler tasiyor, dar bir pencere kodu DOGRU olsa bile
    // eslesmezdi (ilk denemede oyle oldu).
    check('G) varsayilan gezinme durduruluyor (preventDefault)',
        preg_match('#createTableTriggers\.forEach[\s\S]{0,900}e\.preventDefault\(\)#', $js) === 1);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo ($pass === $total ? "SONUC: GECTI ($pass/$total)" : "SONUC: $pass/$total") . "\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
