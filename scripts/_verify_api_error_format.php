<?php
// public/api/* uc noktalari HATA durumunda JSON, sayfalar HTML donuyor mu?
//
// src/errors.php'nin kurali: "API'ye HTML, sayfaya JSON donmemeli". Ancak
// src/auth.php'deki require_team_access()/require_role()/require_admin() HEM
// sayfalardan HEM API'den cagriliyor ve kosulsuz bcc_error_page()'e dusuyordu;
// sonuc "Content-Type: application/json" basligi + HTML govde idi.
//
// CALISTIRMA: C:/php73/php.exe scripts/_verify_api_error_format.php
// Apache ayakta olmali. Uygulama VERISINE yazmaz — yalnizca red yollarini
// olcer; yan etkisi viewer@bcc.local ile giris yapmaktir (oturum +
// login_attempts). Hedef hucrenin degeri once okunur ve kapanista geri yazilir,
// boylece yetki kapisi gerilese bile gercek veri bozulmaz.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$BASE = 'http://localhost';

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

// --- basit HTTP istemcisi (cerez destekli) ---
$COOKIE = tempnam(sys_get_temp_dir(), 'bccjar');

function istek($url, $post = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $COOKIE,
        CURLOPT_COOKIEFILE => $COOKIE,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) { return null; }

    $head = substr($raw, 0, $hlen);
    $body = substr($raw, $hlen);
    preg_match('/^content-type:\s*(.+)$/mi', $head, $m);

    return array('code' => $code, 'ctype' => isset($m[1]) ? trim($m[1]) : '', 'body' => $body);
}

function jetonAl($url)
{
    $r = istek($url);
    if (!$r) { return ''; }
    return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
}

// --- sunucu ayakta mi? ---
$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) {
    fwrite(STDERR, "Apache'ye ulasilamadi ($BASE/login.php). XAMPP calisiyor mu?\n");
    exit(2);
}

// --- viewer olarak giris (yazma yetkisi YOK, bu yuzden test guvenli) ---
$demoSifre = null;
foreach (bcc_demo_accounts() as $acc) {
    if ($acc['email'] === 'viewer@bcc.local') { $demoSifre = $acc['password']; }
}
if ($demoSifre === null) {
    fwrite(STDERR, "Bu test demo hesaplarina ihtiyac duyar (config/app.local.php \$BCC_DEMO_PASSWORD).\n");
    exit(2);
}

$tok = jetonAl($BASE . '/login.php');
istek($BASE . '/login.php', 'csrf_token=' . $tok . '&email=viewer@bcc.local&password=' . rawurlencode($demoSifre));

// viewer'in UYESI oldugu bir tablo + o tablonun bir alani/kaydi
$row = bcc_fetch_one(
    "SELECT f.id AS field_id, r.id AS record_id, tm.id AS table_id
     FROM users u
     JOIN team_members m ON m.user_id = u.id
     JOIN bases b ON b.team_id = m.team_id AND b.deleted_at IS NULL
     JOIN tables_meta tm ON tm.base_id = b.id
     JOIN fields f ON f.table_id = tm.id AND f.field_type = 'single_line_text'
     JOIN records r ON r.table_id = tm.id AND r.deleted_at IS NULL
     WHERE u.email = 'viewer@bcc.local' AND m.role = 'viewer'
     LIMIT 1"
);
if (!$row) {
    fwrite(STDERR, "viewer@bcc.local'in viewer oldugu, alanli+kayitli bir tablo bulunamadi.\n");
    exit(2);
}

$tok = jetonAl($BASE . '/grid.php?table_id=' . (int) $row['table_id']);
check('grid sayfasindan CSRF jetonu alindi', strlen($tok) === 64, strlen($tok));

// Hedef hucre GERCEK bir base'e ait (viewer'in uyesi oldugu ilk tablo). Test
// yazmanin REDDEDILMESINI bekliyor, ama tam da o kapi bir gun gerilerse istek
// gercek veriyi ezer. Asagidaki kontrol (D bolumu) bunu yalnizca FARK EDER,
// geri almaz. Bu yuzden mevcut deger simdi okunuyor ve kapanista — testin nasil
// bittiginden bagimsiz olarak — degismisse geri yaziliyor.
$hedefCell = bcc_fetch_one(
    'SELECT id, value_text FROM cell_values WHERE record_id = :r AND field_id = :f LIMIT 1',
    array('r' => (int) $row['record_id'], 'f' => (int) $row['field_id'])
);
register_shutdown_function(function () use ($row, $hedefCell) {
    $simdi = bcc_fetch_one(
        'SELECT id, value_text FROM cell_values WHERE record_id = :r AND field_id = :f LIMIT 1',
        array('r' => (int) $row['record_id'], 'f' => (int) $row['field_id'])
    );
    if ($hedefCell === false || $hedefCell === null) {
        // Test oncesi hic satir yoktu: test bir satir acmissa geri al.
        if ($simdi) {
            bcc_execute('DELETE FROM cell_values WHERE id = :i', array('i' => (int) $simdi['id']));
            fwrite(STDERR, 'UYARI: yazma REDDEDILMEDI, acilan hucre satiri geri alindi.' . PHP_EOL);
        }
        return;
    }
    if ($simdi && $simdi['value_text'] !== $hedefCell['value_text']) {
        bcc_execute('UPDATE cell_values SET value_text = :v WHERE id = :i',
            array('v' => $hedefCell['value_text'], 'i' => (int) $hedefCell['id']));
        fwrite(STDERR, 'UYARI: yazma REDDEDILMEDI, gercek hucre degeri geri yazildi.' . PHP_EOL);
    }
});

$gonderi = 'csrf_token=' . $tok
    . '&field_id=' . (int) $row['field_id']
    . '&record_id=' . (int) $row['record_id']
    . '&value=' . rawurlencode('__test_asla_yazilmamali__');

// ---------------------------------------------------------------------------
echo "\nA) API rol reddi -> JSON olmali (duzeltilen bulgu)\n";
// ---------------------------------------------------------------------------
$r = istek($BASE . '/api/cell_update.php', $gonderi);
check('durum 403', $r['code'] === 403, $r['code']);
check('Content-Type json', strpos($r['ctype'], 'application/json') === 0, $r['ctype']);
check('govde JSON ayrisiyor', json_decode($r['body'], true) !== null, substr($r['body'], 0, 60));
$j = json_decode($r['body'], true);
check('govde ok=false', is_array($j) && isset($j['ok']) && $j['ok'] === false, $r['body']);
check('govde HTML DEGIL', strpos($r['body'], '<!doctype') === false && strpos($r['body'], '<html') === false);

// ---------------------------------------------------------------------------
echo "\nB) API CSRF reddi -> zaten JSON'du, bozulmadi\n";
// ---------------------------------------------------------------------------
$r = istek($BASE . '/api/cell_update.php', 'field_id=1&record_id=1&value=x');
check('durum 403', $r['code'] === 403, $r['code']);
check('govde JSON', json_decode($r['body'], true) !== null, substr($r['body'], 0, 60));

// ---------------------------------------------------------------------------
echo "\nC) SAYFA tarafi HALA HTML (regresyon)\n";
// ---------------------------------------------------------------------------
$r = istek($BASE . '/grid.php?table_id=999999');
check('bulunamayan tablo 404', $r['code'] === 404, $r['code']);
check('Content-Type text/html', strpos($r['ctype'], 'text/html') === 0, $r['ctype']);
check('govde HTML', strpos($r['body'], '<!doctype') !== false || strpos($r['body'], '<html') !== false);

$r = istek($BASE . '/admin/index.php');
check('admin sayfasi viewer icin 403', $r['code'] === 403, $r['code']);
check('admin reddi HTML', strpos($r['ctype'], 'text/html') === 0, $r['ctype']);

// ---------------------------------------------------------------------------
echo "\nD) Hicbir sey yazilmadi\n";
// ---------------------------------------------------------------------------
$kalan = (int) bcc_fetch_column(
    "SELECT COUNT(*) FROM cell_values WHERE value_text = '__test_asla_yazilmamali__'"
);
check('test degeri DB\'ye girmedi', $kalan === 0, $kalan);

@unlink($COOKIE);

echo "\n" . str_repeat('-', 50) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
