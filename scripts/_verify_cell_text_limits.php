<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();
bcc_test_purge_own_audit();

define('PROBE_MAIL', 'celltext.owner@bcc-test.local');
define('PROBE_TEAM', 'ZZ Hucre Metin Siniri');
define('TEXT_MAX', 65535);

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

echo "A) Kolonun gercek siniri ve sql_mode\n";

$kolon = null;
foreach (bcc_fetch_all('SHOW COLUMNS FROM cell_values') as $c) {
    if ($c['Field'] === 'value_text') { $kolon = strtolower($c['Type']); }
}
check('A) value_text hala TEXT (65.535 bayt)', $kolon === 'text', (string) $kolon);
$mode = (string) bcc_fetch_column('SELECT @@SESSION.sql_mode');
check('A) STRICT kapali -> tasma HATA VERMEZ, sessizce keser (bu yuzden kod korumali)',
    stripos($mode, 'STRICT') === false, $mode);

echo "\nB) Zengin metin: hicbir sekil kolonu tasiramiyor\n";

$sekiller = array(
    'duz ascii'      => str_repeat('a', 20000),
    'turkce'         => str_repeat("\xC5\x9F", 20000),
    'emoji'          => str_repeat("\xF0\x9F\x98\x80", 20000),
    'ham ampersand'  => str_repeat('&', 20000),
    'emoji+baglanti' => str_repeat("\xF0\x9F\x98\x80", 16380) . '<a href="https://ornek.example/adres">tikla</a>kuyruk',
);
foreach ($sekiller as $ad => $girdi) {
    $c = (string) bcc_sanitize_rich_text($girdi);
    check('B) "' . $ad . '" ciktisi kolona SIGIYOR', strlen($c) <= TEXT_MAX, strlen($c) . ' bayt');
    check('B) "' . $ad . '" ciktisi gecerli UTF-8', mb_check_encoding($c, 'UTF-8'));
    check('B) "' . $ad . '" ciktisinda kapanmamis <a> YOK',
        substr_count($c, '<a ') === substr_count($c, '</a>'),
        substr_count($c, '<a ') . ' acilis / ' . substr_count($c, '</a>') . ' kapanis');
}

echo "\nC) Normal icerikte DAVRANIS DEGISMEDI (altin ornekler)\n";

$altin = array(
    array('<b>Kalin</b> ve <i>italik</i>',        '<b>Kalin</b> ve <i>italik</i>'),
    array('Satir1<br>Satir2',                     'Satir1<br>Satir2'),
    array('<a href="javascript:alert(1)">kotu</a>', 'kotu'),
    array('<script>alert(1)</script>merhaba',     'merhaba'),
    array('<span style="color:red">x</span>',     'x'),
    array('<span style="font-size:18px">buyuk</span>', '<span style="font-size:18px">buyuk</span>'),
    array('<img src=x onerror=alert(1)>metin',    'metin'),
);
foreach ($altin as $ornek) {
    $sonuc = bcc_sanitize_rich_text($ornek[0]);
    check('C) [' . $ornek[0] . ']', $sonuc === $ornek[1], var_export($sonuc, true));
}
check('C) bos girdi hala null doner', bcc_sanitize_rich_text('   ') === null);

$turkce = bcc_sanitize_rich_text('<b>' . "\xC5\x9F\xC4\x9F\xC4\xB1\xC3\xB6\xC3\xA7\xC3\xBC" . '</b>');
check('C) turkce karakterler korunuyor', $turkce === '<b>' . "\xC5\x9F\xC4\x9F\xC4\xB1\xC3\xB6\xC3\xA7\xC3\xBC" . '</b>', var_export($turkce, true));

echo "\nD) Metin tipleri: sinir asilinca REDDEDILIYOR (sessizce kesilmiyor)\n";

foreach (array('single_line_text', 'url', 'email', 'phone') as $tip) {
    $r = normalize_cell_value($tip, null, str_repeat('a', TEXT_MAX + 10));
    check('D) ' . $tip . ' sinir ustunu reddediyor', $r['ok'] === false, json_encode($r));

    $r2 = normalize_cell_value($tip, null, str_repeat('a', TEXT_MAX));
    check('D) ' . $tip . ' TAM sinirdaki degeri KABUL ediyor', $r2['ok'] === true);
}
$r = normalize_cell_value('single_line_text', null, str_repeat("\xF0\x9F\x98\x80", 20000));
check('D) cok baytli (80.000 bayt) deger de reddediliyor', $r['ok'] === false);

echo "\nE) CANLI: DB'ye yazilan ile geri okunan BIREBIR ayni\n";

$BASE = 'http://localhost';
$COOKIE = tempnam(sys_get_temp_dir(), 'bcctx');

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

$cleanup = function () use ($COOKIE) {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => PROBE_TEAM)) as $x) {
        bcc_execute('DELETE FROM teams WHERE id = :i', array(':i' => $x['id']));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => PROBE_MAIL));
    @unlink($COOKIE);
};
$cleanup();
register_shutdown_function($cleanup);

$sifre = 'p' . bin2hex(random_bytes(10));
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => PROBE_TEAM));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array(':e' => PROBE_MAIL, ':h' => password_hash($sifre, PASSWORD_DEFAULT), ':n' => 'Hucre Metin Owner'));
$userId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)',
    array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t,:n,:u)',
    array(':t' => $teamId, ':n' => 'Metin Siniri Base', ':u' => $userId));
$baseId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b,:n,0)',
    array(':b' => $baseId, ':n' => 'Metinler'));
$tableId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:ft,0)',
    array(':t' => $tableId, ':n' => 'Not', ':ft' => 'long_text'));
$notFieldId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:ft,1)',
    array(':t' => $tableId, ':n' => 'Baslik', ':ft' => 'single_line_text'));
$baslikFieldId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t,0,:u)',
    array(':t' => $tableId, ':u' => $userId));
$recordId = (int) bcc_last_insert_id();

$r = istek($BASE . '/login.php');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
istek($BASE . '/login.php', 'csrf_token=' . $m[1] . '&email=' . rawurlencode(PROBE_MAIL) . '&password=' . rawurlencode($sifre));

$r = istek($BASE . '/grid.php?table_id=' . $tableId);
check('E) oturum acildi, grid sayfasi geldi', $r['code'] === 200, 'HTTP ' . $r['code']);
preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $r['body'], $m2);
$csrf = isset($m2[1]) ? $m2[1] : '';
check('E) CSRF jetonu alindi', $csrf !== '');

$zorlu = str_repeat("\xF0\x9F\x98\x80", 16380) . '<a href="https://ornek.example/adres">tikla</a>kuyruk';
$x = istek($BASE . '/api/cell_update.php', http_build_query(array(
    'csrf_token' => $csrf, 'field_id' => $notFieldId, 'record_id' => $recordId, 'value' => $zorlu,
)));
check('E) zengin metin hucresi kaydedildi', $x['code'] === 200, 'HTTP ' . $x['code'] . ' ' . substr($x['body'], 0, 120));

$saklanan = (string) bcc_fetch_column(
    'SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
    array(':r' => $recordId, ':f' => $notFieldId)
);
$beklenen = (string) bcc_sanitize_rich_text($zorlu);
check('E) DB deki deger sanitize ciktisiyla BIREBIR ayni (kayip YOK)',
    $saklanan === $beklenen,
    'DB ' . strlen($saklanan) . ' bayt / beklenen ' . strlen($beklenen) . ' bayt');
check('E) saklanan deger gecerli UTF-8', mb_check_encoding($saklanan, 'UTF-8'));
check('E) saklanan degerde kapanmamis <a> YOK',
    substr_count($saklanan, '<a ') === substr_count($saklanan, '</a>'),
    substr($saklanan, -40));

$x = istek($BASE . '/api/cell_update.php', http_build_query(array(
    'csrf_token' => $csrf, 'field_id' => $baslikFieldId, 'record_id' => $recordId,
    'value' => str_repeat('a', TEXT_MAX + 100),
)));
check('E) asiri uzun tek satir metin 422 ile REDDEDILDI', $x['code'] === 422, 'HTTP ' . $x['code']);
$kalan = bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
    array(':r' => $recordId, ':f' => $baslikFieldId));
check('E) reddedilen deger DB ye hic yazilmadi', $kalan === false || $kalan === null, var_export($kalan, true));

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
