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
/* 2026-10-12: tek satir metin / url / e-posta / telefon siniri uzun metinle ayni. */
define('KISA_MAX', BCC_RICH_TEXT_MAX_BYTES);

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
check('A) value_text MEDIUMTEXT (uzun metin 65.535 bayti asabiliyor)', $kolon === 'mediumtext', (string) $kolon);
$paket = (int) bcc_fetch_column('SELECT @@GLOBAL.max_allowed_packet');
check('A) uzun metin siniri sunucunun max_allowed_packet degerinin ALTINDA',
    BCC_RICH_TEXT_MAX_BYTES + 4096 < $paket, BCC_RICH_TEXT_MAX_BYTES . ' bayt / paket ' . $paket);
$mode = (string) bcc_fetch_column('SELECT @@SESSION.sql_mode');
check('A) STRICT kapali -> tasma HATA VERMEZ, sessizce keser (bu yuzden kod korumali)',
    stripos($mode, 'STRICT') === false, $mode);

echo "\nB) Zengin metin: hicbir sekil kolonu tasiramiyor\n";

$sekiller = array(
    'duz ascii'      => str_repeat('a', 1200000),
    'turkce'         => str_repeat("\xC5\x9F", 600000),
    'emoji'          => str_repeat("\xF0\x9F\x98\x80", 300000),
    'ham ampersand'  => str_repeat('&', 300000),
    'emoji+baglanti' => str_repeat("\xF0\x9F\x98\x80", 224990) . '<a href="https://ornek.example/adres">tikla</a>kuyruk',
    'ham girdi siniri ustu' => str_repeat('a ', 2100000),
);
foreach ($sekiller as $ad => $girdi) {
    $kirpildi = null;
    $c = (string) bcc_sanitize_rich_text($girdi, $kirpildi);
    check('B) "' . $ad . '" ciktisi sinira SIGIYOR', strlen($c) <= BCC_RICH_TEXT_MAX_BYTES, strlen($c) . ' bayt');
    check('B) "' . $ad . '" kirpildigi BILDIRILIYOR', $kirpildi === true);
    $r = normalize_cell_value('long_text', null, $girdi);
    check('B) "' . $ad . '" hucreye yazilirken REDDEDILIYOR (sessizce kesilmiyor)',
        $r['ok'] === false && strpos($r['error'], '900.000') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));
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
    $r = normalize_cell_value($tip, null, str_repeat('a', KISA_MAX + 10));
    check('D) ' . $tip . ' sinir ustunu reddediyor', $r['ok'] === false, substr(json_encode($r), 0, 200));

    $r2 = normalize_cell_value($tip, null, str_repeat('a', KISA_MAX));
    check('D) ' . $tip . ' TAM sinirdaki degeri KABUL ediyor', $r2['ok'] === true && strlen((string) $r2['value']) === KISA_MAX);

    $r3 = normalize_cell_value($tip, null, str_repeat('a', TEXT_MAX + 10));
    check('D) ' . $tip . ' ESKI siniri (65.535) asan degeri artik KABUL ediyor',
        $r3['ok'] === true && strlen((string) $r3['value']) === TEXT_MAX + 10);
}
$r = normalize_cell_value('single_line_text', null, str_repeat("\xF0\x9F\x98\x80", 20000));
check('D) cok baytli (80.000 bayt) deger KABUL ediliyor, bozulmadan',
    $r['ok'] === true && $r['value'] === str_repeat("\xF0\x9F\x98\x80", 20000));
$r = normalize_cell_value('single_line_text', null, str_repeat("\xF0\x9F\x98\x80", 250000));
check('D) cok baytli sinir ustu (1.000.000 bayt) deger reddediliyor (bayt sayiliyor, karakter degil)', $r['ok'] === false);

echo "\nH) Sayi tipleri: kolona sigmayan sayi REDDEDILIYOR (sessizce kirpilmiyor)\n";

foreach (array('number', 'currency') as $tip) {
    $r = normalize_cell_value($tip, null, '99999999999999');
    check('H) ' . $tip . ' 14 haneli sayiyi KABUL ediyor', $r['ok'] === true && $r['value'] === 99999999999999.0);
    $r = normalize_cell_value($tip, null, '-99999999999999.5');
    check('H) ' . $tip . ' negatif 14 haneli sayiyi KABUL ediyor', $r['ok'] === true);
    $r = normalize_cell_value($tip, null, '100000000000000');
    check('H) ' . $tip . ' 15 haneli sayiyi reddediyor', $r['ok'] === false && strpos($r['error'], '14 hane') !== false, json_encode($r));
    $r = normalize_cell_value($tip, null, '-123456789012345678901234');
    check('H) ' . $tip . ' cok buyuk negatif sayiyi reddediyor', $r['ok'] === false);
    $r = normalize_cell_value($tip, null, '1e999');
    check('H) ' . $tip . ' sonsuza tasan degeri reddediyor', $r['ok'] === false);
    $r = normalize_cell_value($tip, null, '1234.5');
    check('H) ' . $tip . ' siradan sayi degismedi', $r['ok'] === true && $r['value'] === 1234.5);
}
$r = normalize_cell_value('percent', null, '50');
check('H) percent siradan deger degismedi (50 -> 0.5)', $r['ok'] === true && $r['value'] === 0.5);
$r = normalize_cell_value('percent', null, '10000000000000000');
check('H) percent kolona sigmayan degeri reddediyor', $r['ok'] === false);

echo "\nF) Buyuk liste: 7.000 / 10.000 satir KAYIPSIZ ve hizli\n";

function liste_satirlari($n)
{
    $satirlar = array();
    for ($i = 1; $i <= $n; $i++) {
        $satirlar[] = "\xC5\x9Ei\xC5\x9Fli Ma\xC4\x9Fazas\xC4\xB1 - \xC3\xBCr\xC3\xBCn kodu 123456 & a\xC3\xA7\xC4\xB1klama sat\xC4\xB1r\xC4\xB1 " . $i;
    }
    return $satirlar;
}

function kacir($metin)
{
    return htmlspecialchars($metin, ENT_QUOTES, 'UTF-8');
}

foreach (array(7000, 10000) as $adet) {
    $kacirilmis = array_map('kacir', liste_satirlari($adet));
    $beklenen = implode('<br>', $kacirilmis);

    foreach (array(
        '<br>'  => implode('<br>', $kacirilmis),
        '<div>' => '<div>' . implode('</div><div>', $kacirilmis) . '</div>',
        '<p>'   => '<p>' . implode('</p><p>', $kacirilmis) . '</p>',
    ) as $bicim => $girdi) {
        $t = microtime(true);
        $r = normalize_cell_value('long_text', null, $girdi);
        $sure = microtime(true) - $t;
        check("F) $adet satir ($bicim) KABUL ediliyor", $r['ok'] === true, $r['ok'] ? '' : $r['error']);
        check("F) $adet satir ($bicim) birebir saklaniyor (" . strlen($beklenen) . ' bayt, ilk ve son satir dahil)',
            $r['ok'] && $r['value'] === $beklenen);
        check("F) $adet satir ($bicim) 2 saniyenin altinda", $sure < 2.0, round($sure, 2) . ' sn');
    }
}

$r = normalize_cell_value('long_text', null, str_repeat('a', 20001));
check('F) eski sinirin (20.000 karakter) hemen ustu artik TAM saklaniyor', $r['ok'] && strlen((string) $r['value']) === 20001);
$r = normalize_cell_value('long_text', null, str_repeat("\xC5\x9F", 40000));
check('F) eski bayt sinirinin (60.000) ustu artik TAM saklaniyor', $r['ok'] && strlen((string) $r['value']) === 80000);
$r = normalize_cell_value('long_text', null, str_repeat('a', BCC_RICH_TEXT_MAX_BYTES));
check('F) TAM sinirdaki deger KABUL ediliyor', $r['ok'] === true && strlen((string) $r['value']) === BCC_RICH_TEXT_MAX_BYTES);
$r = normalize_cell_value('long_text', null, str_repeat('a', BCC_RICH_TEXT_MAX_BYTES + 1));
check('F) sinirin 1 bayt ustu REDDEDILIYOR', $r['ok'] === false);

$eskiIfade = '#<br>(?:\s*</(?:strong|b|em|i|a|span)>)*\s*$#';
$parcalar = array('<br>', '</b>', '</strong>', '</em>', '</i>', '</a>', '</span>', ' ', "\n", "\t", "\r", "\x0B", "\x0C", 'x', '<', '>', '/', 'br', '</div>', '<b>', '</u>', "\xC5\x9F", '');
mt_srand(20261008);
$fark = 0;
$ornek = '';
for ($i = 0; $i < 20000; $i++) {
    $m = '';
    $uz = mt_rand(0, 7);
    for ($j = 0; $j < $uz; $j++) { $m .= $parcalar[mt_rand(0, count($parcalar) - 1)]; }
    if (bcc_rich_text_ends_with_break($m) !== (bool) preg_match($eskiIfade, $m)) { $fark++; $ornek = $m; }
}
check('F) satir sonu denetimi eski duzenli ifadeyle AYNI sonucu veriyor (20.000 rastgele kuyruk)',
    $fark === 0, $fark . ' fark, ornek: ' . json_encode($ornek));

echo "\nG) Excel disa aktarimi: hucre 32.767 karakteri asmiyor\n";

require_once __DIR__ . '/../src/xlsx_writer.php';
$x = bcc_xlsx_escape(str_repeat("\xC5\x9F", 50000));
check('G) 50.000 karakterlik hucre 32.767 karaktere iniyor', mb_strlen($x, 'UTF-8') === 32767, (string) mb_strlen($x, 'UTF-8'));
check('G) kisaltilan hucre uc nokta ile bitiyor ve gecerli UTF-8', substr($x, -3) === "\xE2\x80\xA6" && mb_check_encoding($x, 'UTF-8'));
$x = bcc_xlsx_escape(str_repeat("\xC5\x9F", 32767));
check('G) TAM sinirdaki hucreye DOKUNULMUYOR', $x === str_repeat("\xC5\x9F", 32767));
check('G) kisa hucreye DOKUNULMUYOR', bcc_xlsx_escape('Merhaba & <d>') === 'Merhaba &amp; &lt;d&gt;');

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

check('E) eski kolonu tasiran deger (65.615 bayt) artik KISALTILMADAN saklaniyor',
    substr($saklanan, -6) === 'kuyruk' && strlen($saklanan) > TEXT_MAX, strlen($saklanan) . ' bayt');

$liste = '<div>' . implode('</div><div>', array_map('kacir', liste_satirlari(10000))) . '</div>';
$t = microtime(true);
$x = istek($BASE . '/api/cell_update.php', http_build_query(array(
    'csrf_token' => $csrf, 'field_id' => $notFieldId, 'record_id' => $recordId, 'value' => $liste,
)));
$sure = microtime(true) - $t;
check('E) 10.000 satirlik liste HTTP ile kaydedildi', $x['code'] === 200, 'HTTP ' . $x['code'] . ' ' . substr($x['body'], 0, 160));
check('E) kayit 5 saniyenin altinda', $sure < 5.0, round($sure, 2) . ' sn');
$saklanan = (string) bcc_fetch_column(
    'SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
    array(':r' => $recordId, ':f' => $notFieldId)
);
$r = normalize_cell_value('long_text', null, $liste);
check('E) DB deki liste BIREBIR ayni (' . strlen($saklanan) . ' bayt, 10.000 satir)',
    $saklanan === $r['value'] && substr_count($saklanan, '<br>') === 9999,
    'DB ' . strlen($saklanan) . ' / beklenen ' . strlen((string) $r['value']));
check('E) listenin SON satiri yerinde', substr($saklanan, -6) === ' 10000', substr($saklanan, -30));

$t = microtime(true);
$g = istek($BASE . '/grid.php?table_id=' . $tableId);
$sure = microtime(true) - $t;
check('E) grid sayfasi bu hucreyle ACILIYOR', $g['code'] === 200 && strpos($g['body'], ' 10000') !== false,
    'HTTP ' . $g['code'] . ', ' . strlen($g['body']) . ' bayt');
check('E) grid sayfasi 5 saniyenin altinda', $sure < 5.0, round($sure, 2) . ' sn');
echo '       (grid sayfasi ' . round(strlen($g['body']) / 1048576, 2) . " MB)\n";

$x = istek($BASE . '/api/cell_update.php', http_build_query(array(
    'csrf_token' => $csrf, 'field_id' => $notFieldId, 'record_id' => $recordId,
    'value' => str_repeat('a', BCC_RICH_TEXT_MAX_BYTES + 100),
)));
check('E) sinir ustu uzun metin 422 ile REDDEDILDI', $x['code'] === 422, 'HTTP ' . $x['code'] . ' ' . substr($x['body'], 0, 160));
check('E) ret mesaji siniri soyluyor', strpos($x['body'], '900.000') !== false, substr($x['body'], 0, 200));
$sonra = (string) bcc_fetch_column(
    'SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
    array(':r' => $recordId, ':f' => $notFieldId)
);
check('E) reddedilen deger eski icerigi BOZMADI', $sonra === $saklanan);

$bx = istek($BASE . '/api/cells_bulk_update.php', http_build_query(array(
    'csrf_token' => $csrf, 'table_id' => $tableId,
    'payload' => json_encode(array('updates' => array(), 'creates' => array(
        array(array('f' => $notFieldId, 'v' => str_repeat('b', 600000))),
        array(array('f' => $notFieldId, 'v' => str_repeat('c', 600000))),
        array(array('f' => $notFieldId, 'v' => str_repeat('d', 600000))),
    ))),
)));
check('E) toplu yapistirma: 3 x 600.000 baytlik hucre (tek pakete sigmaz) KAYDEDILDI', $bx['code'] === 200,
    'HTTP ' . $bx['code'] . ' ' . substr($bx['body'], 0, 160));
$boylar = bcc_fetch_all(
    'SELECT LENGTH(cv.value_text) AS b FROM cell_values cv JOIN records r ON r.id = cv.record_id
     WHERE r.table_id = :t AND cv.field_id = :f AND r.id <> :r',
    array(':t' => $tableId, ':f' => $notFieldId, ':r' => $recordId)
);
check('E) toplu yapistirmanin 3 hucresi de TAM (600.000 bayt)',
    count($boylar) === 3 && (int) $boylar[0]['b'] === 600000 && (int) $boylar[1]['b'] === 600000 && (int) $boylar[2]['b'] === 600000,
    json_encode($boylar));

$x = istek($BASE . '/api/cell_update.php', http_build_query(array(
    'csrf_token' => $csrf, 'field_id' => $baslikFieldId, 'record_id' => $recordId,
    'value' => str_repeat('a', KISA_MAX + 100),
)));
check('E) asiri uzun tek satir metin 422 ile REDDEDILDI', $x['code'] === 422, 'HTTP ' . $x['code']);
$kalan = bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
    array(':r' => $recordId, ':f' => $baslikFieldId));
check('E) reddedilen deger DB ye hic yazilmadi', $kalan === false || $kalan === null, var_export($kalan, true));

$uzunBaslik = str_repeat('Tek satir metin 0123456789 ', 20000) . 'kuyruk';
$x = istek($BASE . '/api/cell_update.php', http_build_query(array(
    'csrf_token' => $csrf, 'field_id' => $baslikFieldId, 'record_id' => $recordId,
    'value' => $uzunBaslik,
)));
check('E) tek satir metin: ' . strlen($uzunBaslik) . ' baytlik deger (eski sinirin 8 kati) KAYDEDILDI', $x['code'] === 200,
    'HTTP ' . $x['code'] . ' ' . substr($x['body'], 0, 160));
$saklananBaslik = bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
    array(':r' => $recordId, ':f' => $baslikFieldId));
check('E) tek satir metin veritabanindan BIREBIR geri okunuyor', $saklananBaslik === $uzunBaslik,
    strlen((string) $saklananBaslik) . ' bayt');
$g2 = istek($BASE . '/grid.php?table_id=' . $tableId);
check('E) grid sayfasi o uzun tek satir hucreyle ACILIYOR', $g2['code'] === 200 && strpos($g2['body'], 'kuyruk') !== false,
    'HTTP ' . $g2['code'] . ', ' . strlen($g2['body']) . ' bayt');

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
