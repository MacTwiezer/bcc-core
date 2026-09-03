<?php
// <script> BLOKLARINA gomulen JSON'un HTML ayristiricisini bozmadigini dogrular.
//
// BULUNAN GERCEK KUSUR: sayfalar kullanici verisini bir <script> blogunun
// icine dogrudan json_encode(..., JSON_UNESCAPED_UNICODE) ciktisiyla
// gomuyordu. Bu XSS'e acik DEGIL — "/" varsayilan olarak kacirildigi icin
// cikti hicbir zaman "</script>" uretemez. Ama "<" kacirilmadigi icin veri
// "<!--<script>" tasiyorsa tarayicinin HTML ayristiricisi "script data double
// escaped" durumuna giriyor ve script etiketinden SONRAKI TUM SAYFAYI yutuyor.
//
// Tarayiciyla olculdu: adi "<!--<script>" olan bir kullanici demo ekibine
// eklendiginde grid.php'nin script blogundan sonraki hicbir sey render
// edilmedi. Ad kayit formundan geliyor, yani bir kullanici kendi adini
// degistirip onu goren HERKESIN sayfasini kirabiliyordu.
//
// Duzeltme: bcc_json_for_script() (src/bootstrap.php) — JSON_HEX_TAG ekler.
//
// Calistirma: C:\php73\php.exe scripts\_verify_json_in_script.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

define('PROBE_EMAIL', 'jsonprobe.viewer@bcc-test.local');
define('PROBE_NAME', '<!--<script>');

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

// ---------------------------------------------------------------------------
echo "A) Yardimci fonksiyonun kendisi\n";
// ---------------------------------------------------------------------------
check('A) bcc_json_for_script() tanimli', function_exists('bcc_json_for_script'));

$kotu = bcc_json_for_script(PROBE_NAME);
check('A) "<" kacirildi (\\u003C)', strpos($kotu, '\u003C') !== false, $kotu);
check('A) ham "<" cikmiyor', strpos($kotu, '<') === false, $kotu);
check('A) ham ">" cikmiyor', strpos($kotu, '>') === false, $kotu);

// JS tarafinda deger AYNI kalmali — kacis yalnizca HTML ayristiricisi icin.
check('A) JSON cozuldugunde deger DEGISMIYOR', json_decode($kotu, true) === PROBE_NAME,
    var_export(json_decode($kotu, true), true));

$turkce = bcc_json_for_script('Şükrü Öz — çalışma');
check('A) Turkce karakterler kacirilmiyor (JSON_UNESCAPED_UNICODE korundu)',
    strpos($turkce, 'Şükrü') !== false, $turkce);

// ---------------------------------------------------------------------------
echo "\nB) Kaynak taramasi: <script> icine DUZ json_encode kalmadi\n";
// ---------------------------------------------------------------------------
// Yorumlari soyarak ara: bu dosyanin ve baskalarinin ACIKLAMA yorumlarinda
// gecen ornek kod yanlis alarm vermesin.
$kalanlar = array();
foreach (array_merge(
    glob(__DIR__ . '/../public/*.php'),
    glob(__DIR__ . '/../public/admin/*.php'),
    glob(__DIR__ . '/../src/partials/*.php')
) as $dosya) {
    $kod = '';
    foreach (token_get_all(file_get_contents($dosya)) as $tok) {
        if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) { continue; }
        $kod .= is_array($tok) ? $tok[1] : $tok;
    }
    if (preg_match('/echo\s+json_encode\s*\(/', $kod)) {
        $kalanlar[] = basename($dosya);
    }
}
check('B) HTML basan hicbir dosyada "echo json_encode(" yok', empty($kalanlar),
    implode(', ', $kalanlar));

// API uc noktalari BU KURALIN DISINDA: ciktilari application/json, HTML degil.
$apiSayisi = 0;
foreach (glob(__DIR__ . '/../public/api/*.php') as $dosya) {
    if (strpos(file_get_contents($dosya), 'json_encode(') !== false) { $apiSayisi++; }
}
check('B) API uc noktalari hala duz json_encode kullaniyor (dogru, HTML degiller)',
    $apiSayisi > 0, 'bulunan: ' . $apiSayisi);

// ---------------------------------------------------------------------------
echo "\nC) CANLI: bozuk adli kullanici sayfayi yutmuyor\n";
// ---------------------------------------------------------------------------
$BASE = 'http://localhost';
$COOKIE = tempnam(sys_get_temp_dir(), 'bccjs');

function istek($url, $post = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $COOKIE,
        CURLOPT_COOKIEFILE => $COOKIE, CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $c, 'body' => (string) $b);
}

$r = istek($BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$demoPass = bcc_demo_password();
if ($demoPass === null) {
    fwrite(STDERR, "Bu test demo hesaplarina ihtiyac duyar (config/app.local.php).\n");
    exit(2);
}

$team = bcc_fetch_column('SELECT id FROM teams WHERE name = :n LIMIT 1',
    array(':n' => 'Demo Calisma Alani'));
if (!$team) {
    fwrite(STDERR, "Demo ekibi yok. Once: scripts\\seed_demo_users.php\n");
    exit(2);
}
$tableId = (int) bcc_fetch_column(
    "SELECT tm.id FROM tables_meta tm JOIN bases b ON b.id = tm.base_id
     WHERE b.team_id = :t AND tm.name = 'Musteriler' LIMIT 1",
    array(':t' => $team)
);
if (!$tableId) {
    fwrite(STDERR, "Demo 'Musteriler' tablosu yok. Once: scripts\\seed_demo_users.php\n");
    exit(2);
}

// Adi BOZUK olan atilir bir uye. Temizlik kurulumdan ONCE kapanisa baglanir.
$cleanup = function () use ($COOKIE) {
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => PROBE_EMAIL));
    @unlink($COOKIE);
};
$cleanup();
register_shutdown_function($cleanup);

bcc_execute(
    'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array(':e' => PROBE_EMAIL, ':h' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
          ':n' => PROBE_NAME)
);
$probeId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
    array(':t' => $team, ':u' => $probeId, ':r' => 'viewer'));
check('C) bozuk adli test uyesi olusturuldu', $probeId > 0);

$r = istek($BASE . '/login.php');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
istek($BASE . '/login.php', 'csrf_token=' . $m[1] . '&email=owner%40bcc.local&password=' . rawurlencode($demoPass));

$g = istek($BASE . '/grid.php?table_id=' . $tableId);
check('C) grid.php 200 donuyor', $g['code'] === 200, 'HTTP ' . $g['code']);
check('C) bozuk ad sayfada HAM haliyle GECMIYOR',
    strpos($g['body'], PROBE_NAME) === false);
check('C) bozuk ad KACIRILMIS haliyle var (veri gercekten sayfaya gitti)',
    strpos($g['body'], '\u003C!--') !== false);
// Asil olcut: script blogundan SONRAKI kapanis etiketleri yerinde mi.
check('C) sayfa </html> ile bitiyor (script sonrasi yutulmadi)',
    strpos($g['body'], '</html>') !== false);
check('C) script SONRASINDAKI modal markup i hala basiliyor',
    strpos($g['body'], 'gs-view-desc-overlay') !== false);

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
