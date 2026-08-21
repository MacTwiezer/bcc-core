<?php
// Home (dashboard.php) — base kartlarinin CALISMA ALANINA gore gruplanmasi.
//
// Neden: platform yoneticisi TUM ekipleri gormeye baslayinca Home'da onlarca
// base tek duz listede karisiyordu; hangisinin hangi alana ait oldugu hicbir
// yerden okunamiyordu (kartlardaki "Calisma alani" hucresi de BOS basiliyordu).
//
// Kapsam:
//   A) Birden cok calisma alani: gruplaniyor, her grubun basliginda ALAN ADI
//   B) Rol rozeti grup BASLIGINDA, kartlarda DEGIL (kart adini kirpiyordu)
//   C) Bir base yalnizca KENDI alaninin grubunda
//   D) Tek calisma alani: gruplama YOK (gereksiz baslik eklenmiyor)
//   E) Liste gorunumunun "Calisma alani" hucresi artik DOLU
//   F) Silme yetkisi rozet gizlenince ETKILENMIYOR (gorsel tercih != yetki)
//   G) home.js coklu izgarayi destekliyor (getElementById -> querySelectorAll)
//
// ⚠️ GERCEK HESAPLARA DOKUNULMAZ: kendi admin/normal kullanicisini ve
// ekiplerini yaratir, sonunda siler.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_home_workspace_groups.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/audit.php';

define('BASE_URL', 'http://localhost');
define('SOLO_EMAIL', 'hwg.solo@bcc-test.local');
define('MULTI_EMAIL', 'hwg.multi@bcc-test.local');
define('TEST_PASS', 'HwGroup!2026');
define('TEAM_PREFIX', 'HWG Test ');

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

// Bir grup basliginin govdesini dondurur (yoksa null).
function ws_head_for($html, $teamName)
{
    $esc = preg_quote(htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8'), '#');
    if (preg_match('#<div class="home-section-head home-ws-head">(.*?)</div>\s*</div>#s', $html)) {
        // Basliklari tek tek gez: her biri </div> ile kapanir.
    }
    if (preg_match_all('#<div class="home-section-head home-ws-head">(.*?)<div class="home-base-grid#s', $html, $ms)) {
        foreach ($ms[1] as $blk) {
            if (preg_match('#<h2 class="home-section-title">' . $esc . '</h2>#', $blk)) {
                return $blk;
            }
        }
    }
    return null;
}

$wipe = function () {
    $rows = bcc_fetch_all('SELECT id FROM teams WHERE name LIKE :p', array(':p' => TEAM_PREFIX . '%'));
    foreach ($rows as $r) { bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id'])); }
    foreach (array(SOLO_EMAIL, MULTI_EMAIL) as $mail) {
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $mail));
    }
};
$wipe();

try {
    // ORTAM: iki ekip. MULTI ikisinin de uyesi, SOLO yalnizca birinin.
    // ⚠️ Ikisi de is_admin=0: bu test GRUPLAMAYI olcuyor, admin kapsamini degil
    // (o _verify_team_create.php'de). Boylece gruplama admin'e bagli olmadan
    // dogrulanir.
    bcc_execute("INSERT INTO teams (name) VALUES (:n)", array(':n' => TEAM_PREFIX . 'Alfa'));
    $teamAlfa = (int) bcc_last_insert_id();
    bcc_execute("INSERT INTO teams (name) VALUES (:n)", array(':n' => TEAM_PREFIX . 'Beta'));
    $teamBeta = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => MULTI_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'HWG Multi'));
    $multiId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => SOLO_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'HWG Solo'));
    $soloId = (int) bcc_last_insert_id();

    // MULTI: Alfa'da owner, Beta'da editor -> rol rozetleri FARKLI olmali.
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamAlfa, ':u' => $multiId, ':r' => 'owner'));
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamBeta, ':u' => $multiId, ':r' => 'editor'));
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamAlfa, ':u' => $soloId, ':r' => 'owner'));

    // Base adlari BILEREK UZUN: kirpilma (ellipsis) davranisi gozlemlensin.
    $baseAlfa = 'HWG Cok Uzun Base Adi Alfa';
    $baseBeta = 'HWG Cok Uzun Base Adi Beta';
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamAlfa, ':n' => $baseAlfa, ':u' => $multiId));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamBeta, ':n' => $baseBeta, ':u' => $multiId));

    $multiCookie = login(MULTI_EMAIL);
    $soloCookie = login(SOLO_EMAIL);

    // =====================================================================
    // A) Coklu alan -> gruplama + alan adlari
    // =====================================================================
    echo "\n--- A) Gruplama ve alan adlari ---\n";
    $page = http_request('GET', '/dashboard.php', $multiCookie);
    check('A) dashboard.php 200', $page['status'] === 200, 'HTTP ' . $page['status']);
    check('A) grup basliklari basiliyor',
        substr_count($page['body'], 'home-section-head home-ws-head') === 2,
        'adet: ' . substr_count($page['body'], 'home-section-head home-ws-head'));

    $headAlfa = ws_head_for($page['body'], TEAM_PREFIX . 'Alfa');
    $headBeta = ws_head_for($page['body'], TEAM_PREFIX . 'Beta');
    check('A) Alfa alaninin ADI baslikta', $headAlfa !== null);
    check('A) Beta alaninin ADI baslikta', $headBeta !== null);
    check('A) tepedeki genel "Base\'leriniz" basligi BASILMIYOR (gruplu modda fazlalik)',
        strpos($page['body'], "Base'leriniz") === false);
    check('A) her grubun kendi base sayaci var',
        $headAlfa !== null && strpos($headAlfa, '1 base') !== false);

    // =====================================================================
    // B) Rol rozeti BASLIKTA, kartta DEGIL
    // =====================================================================
    echo "\n--- B) Rol rozeti yeri ---\n";
    check('B) Alfa basliginda owner rozeti', $headAlfa !== null && strpos($headAlfa, 'home-base-role--owner') !== false);
    check('B) Beta basliginda editor rozeti', $headBeta !== null && strpos($headBeta, 'home-base-role--editor') !== false);
    // Kartlarin ICINDE rozet kalmamali: toplam rozet sayisi = grup sayisi.
    check('B) rozet YALNIZCA basliklarda (kartlarda tekrarlanmiyor)',
        substr_count($page['body'], 'home-base-role home-base-role--') === 2,
        'toplam rozet: ' . substr_count($page['body'], 'home-base-role home-base-role--'));

    // =====================================================================
    // C) Kartlar dogru gruba giriyor
    // =====================================================================
    echo "\n--- C) Kart-grup eslesmesi ---\n";
    // Sayfayi grup basliklarindan bol; her parcada YALNIZCA kendi base'i olmali.
    $parts = preg_split('#<div class="home-section-head home-ws-head">#', $page['body']);
    $alfaPart = null; $betaPart = null;
    foreach ($parts as $p) {
        if (strpos($p, htmlspecialchars(TEAM_PREFIX . 'Alfa', ENT_QUOTES, 'UTF-8') . '</h2>') !== false) { $alfaPart = $p; }
        if (strpos($p, htmlspecialchars(TEAM_PREFIX . 'Beta', ENT_QUOTES, 'UTF-8') . '</h2>') !== false) { $betaPart = $p; }
    }
    check('C) Alfa bolumu ayristirilabildi', $alfaPart !== null);
    check('C) Beta bolumu ayristirilabildi', $betaPart !== null);
    check('C) Alfa bolumunde Alfa base i VAR',
        $alfaPart !== null && strpos($alfaPart, htmlspecialchars($baseAlfa, ENT_QUOTES, 'UTF-8')) !== false);
    check('C) Alfa bolumunde Beta base i YOK (sizinti yok)',
        $alfaPart !== null && strpos($alfaPart, htmlspecialchars($baseBeta, ENT_QUOTES, 'UTF-8')) === false);

    // =====================================================================
    // D) Tek alan -> gruplama YOK
    // =====================================================================
    echo "\n--- D) Tek calisma alani ---\n";
    $soloPage = http_request('GET', '/dashboard.php', $soloCookie);
    check('D) solo kullanici dashboard 200', $soloPage['status'] === 200, 'HTTP ' . $soloPage['status']);
    check('D) tek alanda grup basligi BASILMIYOR',
        strpos($soloPage['body'], 'home-ws-head') === false);
    check('D) tek alanda genel "Base\'leriniz" basligi DURUYOR',
        strpos($soloPage['body'], "Base'leriniz") !== false);
    check('D) tek alanda kart rozeti yine kartta',
        strpos($soloPage['body'], 'home-base-role home-base-role--owner') !== false);

    // =====================================================================
    // E) Liste gorunumunun "Calisma alani" hucresi DOLU
    // =====================================================================
    echo "\n--- E) Calisma alani hucresi ---\n";
    // ⚠️ ESKIDEN BOS BASILIYORDU: <div class="home-base-workspace"></div>
    check('E) bos calisma alani hucresi KALMADI',
        strpos($page['body'], '<div class="home-base-workspace"></div>') === false);
    check('E) hucre GERCEK alan adini tasiyor',
        strpos($page['body'], '<div class="home-base-workspace">' . htmlspecialchars(TEAM_PREFIX . 'Alfa', ENT_QUOTES, 'UTF-8') . '</div>') !== false);
    check('E) solo sayfada da hucre dolu',
        strpos($soloPage['body'], '<div class="home-base-workspace"></div>') === false);

    // =====================================================================
    // F) Rozet gizlenmesi YETKIYI etkilemiyor
    // =====================================================================
    echo "\n--- F) Yetki, gorsel tercihten bagimsiz ---\n";
    // MULTI Alfa'da owner -> o karttaki "Sil" gorunmeli; Beta'da editor -> gorunmemeli.
    // Silme tetikleyicisi kartin ⋯ menusunde; base_id ile eslestirip sayiyoruz.
    $alfaBaseId = (int) bcc_fetch_column('SELECT id FROM bases WHERE name = :n', array(':n' => $baseAlfa));
    $betaBaseId = (int) bcc_fetch_column('SELECT id FROM bases WHERE name = :n', array(':n' => $baseBeta));
    $alfaCard = '';
    if (preg_match('#<a class="home-base-card[^"]*"[^>]*data-base-id="' . $alfaBaseId . '".*?</a>#s', $page['body'], $m)) { $alfaCard = $m[0]; }
    $betaCard = '';
    if (preg_match('#<a class="home-base-card[^"]*"[^>]*data-base-id="' . $betaBaseId . '".*?</a>#s', $page['body'], $m)) { $betaCard = $m[0]; }
    check('F) Alfa karti bulundu', $alfaCard !== '');
    check('F) Beta karti bulundu', $betaCard !== '');
    check('F) owner alanindaki kartta silme tetikleyicisi VAR (rozet gizli olsa da)',
        $alfaCard !== '' && stripos($alfaCard, 'delete') !== false);
    check('F) editor alanindaki kartta silme tetikleyicisi YOK',
        $betaCard !== '' && stripos($betaCard, 'delete') === false);

    // =====================================================================
    // G) home.js coklu izgara
    // =====================================================================
    echo "\n--- G) home.js coklu izgara ---\n";
    $js = file_get_contents(__DIR__ . '/../public/assets/home.js');
    check('G) gorunum degistirici TUM izgaralari geziyor',
        preg_match('#querySelectorAll\(\s*[\'"]\.home-base-grid[\'"]\s*\)#', $js) === 1);
    check('G) tek id ye bagli eski secim KALMADI',
        strpos($js, "getElementById('home-base-grid')") === false);
    check('G) sayfada gercekten birden cok izgara var',
        substr_count($page['body'], 'class="home-base-grid') >= 2,
        'adet: ' . substr_count($page['body'], 'class="home-base-grid'));

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
