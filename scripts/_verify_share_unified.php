<?php
// "Paylas" (katilimci paylasimi) akisinin TUM sayfalarda AYNI satir ici
// bilesende birlesmesi.
//
// Kapsam:
//   A) HICBIR paylas tetikleyicisi sayfadan CIKMIYOR (team_members.php'ye
//      yonlendiren <a>/<form> yok)
//   B) interface.php artik ORTAK bilesende (kendi sorgulari silindi)
//   C) grid.php ile interface.php AYNI modal sozlesmesini basiyor
//   D) CANLI: davet + rol degistirme + cikarma, sayfadan cikmadan calisiyor
//   E) team_members.php KORUNDU (silinmedi) ve hala erisilebilir
//   F) Gercek takim verisi bozulmadi
//
// TARAYICI NOTU: davranis /browse ile de doğrulandı — grid.php ve
// interface.php'de "Paylas" -> modal acilirken URL DEGISMIYOR
// (/grid.php?table_id=... ve /interface.php?base_id=...&table_id=... aynen
// kaliyor); davet/rol/cikarma sonrasi liste ve "N kisinin erisimi var" ozeti
// ANINDA tazeleniyor (14 -> 13 olcuildu).
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_share_unified.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'shareu.owner@bcc-test.local');
define('CAND_EMAIL', 'shareu.cand@bcc-test.local');
define('TEST_PASS', 'ShareU!2026');

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

function extract_csrf_meta($html)
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
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

// PHP/HTML yorumlarini soyar — testler aciklama yorumlarina takilip yanlis
// "GECTI"/"KALDI" vermesin (bu projede birden fazla kez yasandi).
function php_code_only($src)
{
    $src = preg_replace('#<!--.*?-->#s', '', $src);
    $src = preg_replace('#/\*.*?\*/#s', '', $src);

    return preg_replace('#^\s*//.*$#m', '', $src);
}

$teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

// GERCEK uyeler: testin sonunda birebir ayni kalmali (fixture kullanicilari haric).
$realMembersBefore = bcc_fetch_all(
    "SELECT u.email, tm.role FROM team_members tm INNER JOIN users u ON u.id = tm.user_id
     WHERE tm.team_id = :t AND u.email NOT LIKE '%@bcc-test.local' ORDER BY u.email",
    array(':t' => $teamId)
);

foreach (array(OWNER_EMAIL, CAND_EMAIL) as $mail) {
    $uid = bcc_fetch_column('SELECT id FROM users WHERE email = :e', array(':e' => $mail));
    if ($uid) {
        foreach (bcc_fetch_all('SELECT id FROM bases WHERE created_by = :u', array(':u' => $uid)) as $b) {
            bcc_execute('DELETE FROM bases WHERE id = :i', array(':i' => $b['id']));
        }
        bcc_execute('DELETE FROM users WHERE id = :i', array(':i' => $uid));
    }
}

$cleanup = function () {
    foreach (array(OWNER_EMAIL, CAND_EMAIL) as $mail) {
        $uid = bcc_fetch_column('SELECT id FROM users WHERE email = :e', array(':e' => $mail));
        if ($uid) {
            foreach (bcc_fetch_all('SELECT id FROM bases WHERE created_by = :u', array(':u' => $uid)) as $b) {
                bcc_execute('DELETE FROM bases WHERE id = :i', array(':i' => $b['id']));
            }
            bcc_execute('DELETE FROM users WHERE id = :i', array(':i' => $uid));
        }
    }
};

try {
    $gridPhp = file_get_contents(__DIR__ . '/../public/grid.php');
    $ifPhp = file_get_contents(__DIR__ . '/../public/interface.php');
    $ifCode = php_code_only($ifPhp);
    $gridCode = php_code_only($gridPhp);

    // =====================================================================
    // A) YONLENDIRME YOK
    // =====================================================================
    echo "--- A) Paylas tetikleyicileri sayfadan CIKMIYOR ---\n";
    // Bulunan gercek durum: grid.php ZATEN satir iciydi; interface.php ise
    // hem <form action="/team_members.php"> (tam sayfa POST) hem de
    // <a href="/team_members.php"> tasiyordu — /browse ile dogrulandi:
    // "Paylas > N kisinin erisimi var" tiklandiginda adres
    // /team_members.php?team_id=1 oluyordu.
    check('A) interface.php te team_members.php ye giden <a> KALMADI',
        preg_match('#<a[^>]*href="/team_members\.php#', $ifCode) === 0);
    check('A) interface.php te team_members.php ye POST eden <form> KALMADI',
        preg_match('#<form[^>]*action="/team_members\.php#', $ifCode) === 0);
    check('A) eski .collab-popover-assign formu tamamen kalkti',
        strpos($ifCode, 'collab-popover-assign') === false);
    check('A) grid.php te de yonlendiren paylas baglantisi yok',
        preg_match('#<a[^>]*href="/team_members\.php#', $gridCode) === 0);
    // Iki sayfada da katilimci satiri artik <button>.
    foreach (array('grid.php' => $gridCode, 'interface.php' => $ifCode) as $name => $code) {
        check("A) {$name}: katilimci ozeti <button data-share-modal-open>",
            preg_match('#<button[^>]*class="collab-popover-people"[^>]*data-share-modal-open#', $code) === 1);
    }

    // =====================================================================
    // B) INTERFACE.PHP ORTAK BILESENDE
    // =====================================================================
    echo "\n--- B) interface.php ortak bilesene gecti ---\n";
    check('B) ortak payload yardimcisi require ediliyor',
        strpos($ifCode, "require_once __DIR__ . '/../src/share_modal_payload.php'") !== false);
    check('B) payload TEK kaynaktan (bcc_share_modal_payload)',
        strpos($ifCode, 'bcc_share_modal_payload($base[\'team_id\']') !== false);
    check('B) ortak modal partial i basiliyor',
        strpos($ifCode, "require __DIR__ . '/../src/partials/share_modal.php'") !== false);
    check('B) ortak share-modal.js yukleniyor',
        strpos($ifCode, "bcc_asset_url('share-modal.js')") !== false);
    // Kendi katilimci/aday SORGULARI silinmis olmali (ikinci bir hesap yok).
    check('B) kendi katilimci sorgusu SILINDI (ikinci kaynak yok)',
        strpos($ifCode, 'FROM team_members tm') === false, 'interface.php hala kendi sorgusunu yaziyor');
    check('B) eski $shareCandidateUsers/$shareAssignableRoles degiskenleri kalmadi',
        strpos($ifCode, 'shareCandidateUsers') === false
        && strpos($ifCode, 'shareAssignableRoles') === false);
    check('B) yetki kapisi sunucu tarafinda (bcc_can_manage_members)',
        strpos($ifCode, 'bcc_can_manage_members(') !== false);

    // =====================================================================
    // C) IKI SAYFA AYNI SOZLESME
    // =====================================================================
    echo "\n--- C) grid.php ve interface.php AYNI modal sozlesmesi ---\n";
    $ownerId = null;
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'ShareU Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => CAND_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'ShareU Candidate'));
    $candId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'ShareU Demo', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Kayitlar'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));

    $cookie = login(OWNER_EMAIL);
    check('C) Giris yapildi', $cookie !== null);

    $gridHtml = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    $ifHtml = http_request('GET', '/interface.php?base_id=' . $baseId . '&table_id=' . $tableId, $cookie);
    check('C) grid.php 200', $gridHtml['status'] === 200, 'HTTP ' . $gridHtml['status']);
    check('C) interface.php 200', $ifHtml['status'] === 200, 'HTTP ' . $ifHtml['status']);

    // Modalin islemesi icin GEREKEN her parca IKI sayfada da olmali.
    $contract = array(
        'id="gs-share-overlay"'          => 'overlay',
        'data-share-invite'              => 'davet kutusu',
        'data-share-invite-role'         => 'rol secici',
        'data-share-invite-btn'          => 'Davet Et',
        'data-share-suggestions'         => 'kullanici onerileri (arama)',
        'data-share-tab="collaborators"' => 'Katilimcilar sekmesi',
        'data-share-tab="pending"'       => 'Bekleyen davetler sekmesi',
        'data-share-people-label'        => 'canli ozet etiketi',
        'BCC_SHARE_MODAL'                => 'payload',
        'BCC_SHARE_CANDIDATES'           => 'aday listesi',
        'share-modal.js'                 => 'ortak JS',
    );
    foreach ($contract as $needle => $label) {
        check("C) {$label} IKI sayfada da var",
            strpos($gridHtml['body'], $needle) !== false && strpos($ifHtml['body'], $needle) !== false,
            'grid=' . (strpos($gridHtml['body'], $needle) !== false ? 'var' : 'YOK')
            . ' interface=' . (strpos($ifHtml['body'], $needle) !== false ? 'var' : 'YOK'));
    }
    check('C) interface.php te modalin CSS i zaten yuklu (grid-shell.css)',
        strpos($ifHtml['body'], 'grid-shell.css') !== false);
    // "Baglanti" (URL kopyalama) AYRI bir islev — o da tek partial'da birlesik.
    check('C) URL kopyalama kutusu ORTAK partial dan (iki sayfada da)',
        substr_count($gridHtml['body'], 'data-share-url-input') >= 1
        && substr_count($ifHtml['body'], 'data-share-url-input') >= 1);

    // =====================================================================
    // D) CANLI: davet -> rol -> cikarma (hepsi AJAX, yonlendirme yok)
    // =====================================================================
    echo "\n--- D) Canli akis: davet / rol / cikarma ---\n";
    $csrf = extract_csrf_meta($ifHtml['body']);
    check('D) interface.php csrf meta etiketi basiyor', $csrf !== null);

    $isMember = function ($uid) use ($teamId) {
        return bcc_fetch_one('SELECT role FROM team_members WHERE team_id = :t AND user_id = :u',
            array(':t' => $teamId, ':u' => $uid));
    };
    check('D) aday baslangicta uye DEGIL', $isMember($candId) === false || $isMember($candId) === null);

    $inv = http_request('POST', '/api/team_member_assign.php', $cookie, array(
        'csrf_token' => $csrf, 'team_id' => $teamId, 'email' => CAND_EMAIL, 'role' => 'viewer',
    ));
    check('D) davet uc noktasi 200 (JSON, yonlendirme YOK)',
        $inv['status'] === 200 && strpos($inv['body'], '"ok"') !== false, 'HTTP ' . $inv['status'] . ' ' . substr($inv['body'], 0, 120));
    $row = $isMember($candId);
    check('D) davet sonrasi uye ve rolu viewer', $row && $row['role'] === 'viewer', json_encode($row));
    // Yanit modalin TEKRAR cizmek icin ihtiyac duydugu payload'i dondurmeli.
    $invJson = json_decode($inv['body'], true);
    check('D) yanit modalin listesini tazeleyecek payload iceriyor',
        is_array($invJson) && isset($invJson['collaborators']) && isset($invJson['pending']),
        substr($inv['body'], 0, 160));

    $chg = http_request('POST', '/api/team_member_assign.php', $cookie, array(
        'csrf_token' => $csrf, 'team_id' => $teamId, 'email' => CAND_EMAIL, 'role' => 'editor',
    ));
    check('D) rol degistirme 200', $chg['status'] === 200, 'HTTP ' . $chg['status']);
    $row = $isMember($candId);
    check('D) rol editor e guncellendi', $row && $row['role'] === 'editor', json_encode($row));

    $rem = http_request('POST', '/api/team_member_remove.php', $cookie, array(
        'csrf_token' => $csrf, 'team_id' => $teamId, 'user_id' => $candId,
    ));
    check('D) cikarma 200', $rem['status'] === 200, 'HTTP ' . $rem['status']);
    check('D) aday takimdan cikarildi', !$isMember($candId));

    // =====================================================================
    // E) team_members.php KORUNDU
    // =====================================================================
    echo "\n--- E) team_members.php silinmedi / hala gerekli ---\n";
    // ⚠️ 2. gereksinim "yalnizca bu paylas akisinda mi kullaniliyor" diye
    // soruyordu. HAYIR: workspaces.php iki yerden, modalin alt bilgisi bir
    // yerden bagliyor; ustelik modalda OLMAYAN yetenekleri var.
    check('E) dosya duruyor', is_file(__DIR__ . '/../public/team_members.php'));
    $tmPage = http_request('GET', '/team_members.php?team_id=' . $teamId, $cookie);
    check('E) sayfa hala 200 doner', $tmPage['status'] === 200, 'HTTP ' . $tmPage['status']);
    $wsCode = php_code_only(file_get_contents(__DIR__ . '/../public/workspaces.php'));
    check('E) workspaces.php hala oraya bagliyor (iki yerden)',
        substr_count($wsCode, 'href="/team_members.php') === 2,
        'sayim=' . substr_count($wsCode, 'href="/team_members.php'));
    check('E) modal alt bilgisi "Tum uye ayarlari" bagliyor',
        strpos(file_get_contents(__DIR__ . '/../src/partials/share_modal.php'), 'href="/team_members.php') !== false);
    check('E) iki sayfada da alt bilgi bagi render ediliyor',
        strpos($gridHtml['body'], 'gs-share-foot-link') !== false
        && strpos($ifHtml['body'], 'gs-share-foot-link') !== false);
    // Modalda OLMAYAN yetenekler: silinseydi kaybolurdu.
    foreach (array('data-tm-search' => 'uye arama', 'team_members_export_xlsx' => 'Excel indir',
                   'data-tm-sort-created' => '"Eklenme tarihi" siralamasi') as $needle => $label) {
        check("E) modalda OLMAYAN yetenek korundu: {$label}",
            strpos($tmPage['body'], $needle) !== false);
    }

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

echo "\n--- F) Gercek takim verisi bozulmadi ---\n";
$realMembersAfter = bcc_fetch_all(
    "SELECT u.email, tm.role FROM team_members tm INNER JOIN users u ON u.id = tm.user_id
     WHERE tm.team_id = :t AND u.email NOT LIKE '%@bcc-test.local' ORDER BY u.email",
    array(':t' => $teamId)
);
check('F) gercek uyelerin sayisi ve rolleri BIREBIR ayni',
    $realMembersBefore === $realMembersAfter,
    'once=' . count($realMembersBefore) . ' sonra=' . count($realMembersAfter));

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
