<?php
// Ortak hata sayfası (src/errors.php + src/partials/error_page.php) ve
// viewport meta doğrulaması.
//
// NEDEN: yetki/bulunamadı hataları eskiden `die('Bu tablo bu base'e ait
// değil.')` ile ÇIPLAK METİN basıyordu — üstelik HTTP durum kodu yazılmadığı
// için yanıt 200 OK dönüyordu. Bu betik hem durum kodlarını hem de sayfanın
// gerçekten markalı HTML olduğunu doğrular.
//
// Kendi izole takimini/kullanicisini kurar, dogrular, SONUNDA temizler.
// Calistirma: C:\php73\php.exe scripts\_verify_error_pages.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

// Bu betik denetim satiri uretiyor; test kullanicisi silinince o satirlar
// audit_log'da OKSUZ kaliyordu. Kapanista yalnizca bu kosunun urettigi ve
// aktoru artik var olmayan satirlar temizlenir.
require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

define('BASE_URL', 'http://localhost');
define('TEST_TEAM', 'ZZ Error Page Test');
define('TEST_EMAIL', 'errpage.owner@bcc-test.local');
define('TEST_PASS', 'ErrPage!2026');

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

function req($path, $cookie = null, $post = null, $follow = false)
{
    $h = array();
    if ($cookie !== null) { $h[] = 'Cookie: ' . $cookie; }
    $o = array('http' => array('ignore_errors' => true, 'follow_location' => $follow ? 1 : 0));
    if ($post !== null) {
        $o['http']['method'] = 'POST';
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
        $o['http']['content'] = http_build_query($post);
    }
    $o['http']['header'] = implode("\r\n", $h);
    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($o));

    $status = 0; $ck = null;
    foreach ($http_response_header as $x) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $x, $m)) { $status = (int) $m[1]; }
        if (stripos($x, 'Set-Cookie:') === 0) { $p = explode(';', substr($x, 11)); $ck = trim($p[0]); }
    }
    return array('body' => (string) $body, 'status' => $status, 'cookie' => $ck);
}

$cleanup = function () {
    $ids = array_column(bcc_fetch_all('SELECT id FROM users WHERE email = :e', array(':e' => TEST_EMAIL)), 'id');
    foreach ($ids as $uid) {
        foreach (array_column(bcc_fetch_all('SELECT id FROM bases WHERE created_by = :u', array(':u' => $uid)), 'id') as $bid) {
            bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid));
        }
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));
    bcc_execute('DELETE FROM teams WHERE name = :n', array(':n' => TEST_TEAM));
};

$cleanup();

// Temizlik kapanisa da baglanir: asagidaki try/catch yalnizca ISTISNALARI
// yakaliyor, exit() ya da olumcul hatada calismazdi. Adlar sabit oldugu icin
// sonraki kosu de temizlerdi, ama artik ilk kosunun sonunda temiz kaliyor.
register_shutdown_function($cleanup);

try {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEST_TEAM));
    $teamId = (int) bcc_last_insert_id();
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'ErrPage Owner')
    );
    $userId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));

    // --- Oturum ---
    $r = req('/login.php');
    preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $r['body'], $m);
    $r2 = req('/login.php', $r['cookie'], array(
        'email' => TEST_EMAIL, 'password' => TEST_PASS, 'csrf_token' => $m[1],
    ));
    $cookie = $r2['cookie'] ? $r2['cookie'] : $r['cookie'];
    check('Giris yapildi', $cookie !== null);

    // =====================================================================
    // A) CIPLAK die() KALMADI
    // =====================================================================
    echo "\n=== A) Kaynak taramasi ===\n";
    $root = __DIR__ . '/..';
    $bare = 0;
    foreach (array_merge(glob("$root/public/*.php"), glob("$root/public/admin/*.php"), glob("$root/src/*.php")) as $f) {
        $src = file_get_contents($f);
        // CLI koruma satirlari (PHP_SAPI kontrolu) haric.
        foreach (explode("\n", $src) as $line) {
            if (strpos($line, "die('") === false) { continue; }
            if (strpos($src, 'PHP_SAPI') !== false && strpos($line, 'komut satir') !== false) { continue; }
            $bare++;
        }
    }
    check('A) sayfa tarafinda ciplak die() kalmadi', $bare === 0, 'bulunan: ' . $bare);
    check('A) bcc_error_page() bootstrap tarafindan yukleniyor',
        strpos(file_get_contents("$root/src/bootstrap.php"), "errors.php") !== false);

    // =====================================================================
    // B) DURUM KODU + MARKALI HTML
    // =====================================================================
    echo "\n=== B) Hata sayfalari ===\n";
    $cases = array(
        array('/grid.php?table_id=999999', 404, 'Tablo bulunamadı'),
        array('/team_members.php?team_id=999999', 403, 'Yetkiniz yok'),
        array('/admin/index.php', 403, 'Yetkiniz yok'),
    );
    foreach ($cases as $c) {
        $x = req($c[0], $cookie);
        check("B) {$c[0]} -> HTTP {$c[1]}", $x['status'] === $c[1], 'gelen: ' . $x['status']);
        check("B) {$c[0]} -> markali HTML",
            stripos($x['body'], '<!doctype html>') !== false
            && strpos($x['body'], 'login-card') !== false);
        check("B) {$c[0]} -> basligi \"{$c[2]}\"",
            strpos($x['body'], '<title>' . $c[2]) === 0 || strpos($x['body'], $c[2]) !== false);
        check("B) {$c[0]} -> donus baglantisi var",
            strpos($x['body'], 'Ana sayfaya dön') !== false);
        check("B) {$c[0]} -> viewport meta var",
            strpos($x['body'], 'name="viewport"') !== false);
    }

    // Hata metni KACIRILIYOR mu (XSS): mesaj htmlspecialchars'tan geciyor mu.
    check('B) hata sayfasi mesaji htmlspecialchars ile basiliyor',
        substr_count(file_get_contents("$root/src/partials/error_page.php"), 'htmlspecialchars') >= 3);

    // =====================================================================
    // C) VIEWPORT META — TUM SAYFALAR
    // =====================================================================
    echo "\n=== C) Viewport meta ===\n";
    $missing = array();
    foreach (array_merge(glob("$root/public/*.php"), glob("$root/src/partials/*.php")) as $f) {
        $src = file_get_contents($f);
        if (strpos($src, '<meta charset') === false) { continue; }
        if (strpos($src, 'name="viewport"') === false) { $missing[] = basename($f); }
    }
    check('C) <head> basan her dosyada viewport meta var',
        empty($missing), 'eksik: ' . implode(', ', $missing));

    // Canli: giris ve uygulama sayfalari
    // login.php OTURUMSUZ istenir: oturum acikken 302 ile dashboard a yonlendirir
    // ve govde bos gelir (dogru davranis, ama viewport kontrolu icin ise yaramaz).
    $x = req('/login.php');
    check('C) /login.php (oturumsuz) viewport meta basiliyor',
        strpos($x['body'], 'width=device-width') !== false, 'status: ' . $x['status']);
    foreach (array('/dashboard.php', '/account.php') as $p) {
        $x = req($p, $cookie);
        check("C) {$p} viewport meta basiliyor",
            strpos($x['body'], 'width=device-width') !== false, 'status: ' . $x['status']);
    }
} catch (Throwable $e) {
    echo 'ISTISNA: ' . $e->getMessage() . "\n";
    $results[] = false;
}

$cleanup();

$passed = count(array_filter($results));
$total = count($results);
echo "\n" . str_repeat('=', 60) . "\n";
echo "SONUC: {$passed}/{$total} gecti\n";
echo "Test verisi temizlendi.\n";
exit($passed === $total ? 0 : 1);
