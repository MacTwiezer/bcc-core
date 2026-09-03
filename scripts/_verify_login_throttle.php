<?php
// Giris deneme siniri regresyon testi (migrations/024_login_attempts.sql +
// src/auth.php BCC_LOGIN_* / bcc_login_retry_after).
//
// CALISTIRMA:  C:/php73/php.exe scripts/_verify_login_throttle.php
//
// KENDI IZINI TEMIZLER: test, CLI'in sabit IP yer tutucusu (0.0.0.0, bkz.
// bcc_client_ip_binary) altinda calisir ve bastan/sondan o IP'ye ait satirlari
// siler. Tarayicidan gelen gercek denemeler (127.0.0.1 / ::1) ETKILENMEZ.

require __DIR__ . '/../src/bootstrap.php';

// Bu betik denetim satiri uretiyor; test kullanicisi silinince o satirlar
// audit_log'da OKSUZ kaliyordu. Kapanista yalnizca bu kosunun urettigi ve
// aktoru artik var olmayan satirlar temizlenir.
require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) {
        $gecti++;
        echo "  [OK]   $ad\n";
    } else {
        $kaldi++;
        echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n";
    }
}

function temizle()
{
    bcc_execute('DELETE FROM login_attempts WHERE ip = :ip', array('ip' => bcc_client_ip_binary()));
}

// Test, GERCEK bir demo hesabina (viewer@bcc.local) bilerek ust uste yanlis
// sifreyle giriyor ve esigi doldurup kilitliyor. temizle() bunu her bolum
// sonunda siliyor ama yalnizca normal akista: betik ortada olurse hesap bu
// makinenin IP'sinden 15 dakika kilitli kalirdi. Kapanisa da baglandi.
register_shutdown_function('temizle');

$VAR   = 'viewer@bcc.local';                  // gercek demo hesabi
$YOK    = 'yok-' . bin2hex(random_bytes(4)) . '@bcc.local';
$YANLIS = 'kesinlikle-yanlis-sifre-123';

echo "PHP " . PHP_VERSION . " — giris deneme siniri testi\n";
echo "Esikler: hesap=" . BCC_LOGIN_MAX_PER_ACCOUNT
   . " ip=" . BCC_LOGIN_MAX_PER_IP
   . " pencere=" . BCC_LOGIN_WINDOW_MINUTES . " dk\n\n";

temizle();

// ---------------------------------------------------------------------------
echo "A) (ip + e-posta) kurali — esik " . BCC_LOGIN_MAX_PER_ACCOUNT . "\n";
// ---------------------------------------------------------------------------
for ($i = 1; $i < BCC_LOGIN_MAX_PER_ACCOUNT; $i++) {
    $out = attempt_login($VAR, $YANLIS);
    check("deneme $i -> invalid (henuz serbest)", $out === 'invalid', $out);
}
$out = attempt_login($VAR, $YANLIS);
check("deneme " . BCC_LOGIN_MAX_PER_ACCOUNT . " -> invalid (esige DEGDI)", $out === 'invalid', $out);

$out = attempt_login($VAR, $YANLIS);
check("deneme " . (BCC_LOGIN_MAX_PER_ACCOUNT + 1) . " -> throttled", $out === 'throttled', $out);

$kalan = bcc_login_retry_after($VAR);
check("kalan sure 0 < s <= pencere", $kalan > 0 && $kalan <= BCC_LOGIN_WINDOW_MINUTES * 60, $kalan . ' sn');

// ---------------------------------------------------------------------------
echo "\nB) Kilitliyken DOGRU sifre de reddedilir (fren gercekten onde)\n";
// ---------------------------------------------------------------------------
$out = attempt_login($VAR, bcc_demo_password_for_test());
check("dogru sifre -> throttled", $out === 'throttled', $out);

// ---------------------------------------------------------------------------
echo "\nC) Kilit HESABA degil (ip+e-posta) CIFTINE ait\n";
// ---------------------------------------------------------------------------
// Ayni IP, FARKLI e-posta: hesap kurali onu kilitlememeli (ip kurali esigi 20,
// su ana kadarki hata sayisi 6 — yani hala serbest).
$out = attempt_login($YOK, $YANLIS);
check("farkli e-posta hala deneyebiliyor", $out === 'invalid', $out);

// ---------------------------------------------------------------------------
echo "\nD) Zaman sabitligi kilitten ONCE korunuyor mu (yan kanal)\n";
// ---------------------------------------------------------------------------
temizle();

// YONTEM DEGISTI. Onceki hal yalnizca duvar saati olcumune bagliydi ve makine
// yuk altindayken duzenli olarak yanlis KALDI veriyordu (olculdu: dort ardisik
// kosunun ikisi kaldi, farklar 25.4 ve 41.1 ms). Sorun uygulamada degil TESTTE
// idi: ~100 ms'lik bir bcrypt'in sabitligini Windows'ta, yuk altinda, duvar
// saatiyle kanitlamak guvenilir degil. Ustelik sure ZATEN dolayli bir gosterge.
//
// ASIL DEGISMEZ YAPISAL: attempt_login() (src/auth.php) kullanici bulunamasa da
// GERCEK bir bcrypt hash'ine karsi password_verify() cagirmali. Eski bug
// "!$row || !password_verify(...)" kisa devresiydi; $row yokken bcrypt HIC
// calismiyordu (canli olcum: ~6 ms'ye karsi ~141 ms). Asagidaki uc kontrol tam
// olarak bunu BELIRLEYICI olcuyor, gurultuye bagli degiller.
$authCode = '';
foreach (token_get_all(file_get_contents(__DIR__ . '/../src/auth.php')) as $tok) {
    if (is_array($tok) && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)) { continue; }
    $authCode .= is_array($tok) ? $tok[1] : $tok;
}
$fnPos = strpos($authCode, 'function attempt_login');
// Bosluk dizileri tek boslugua indirgenir: kontroller kodun BICIMLENDIRMESINE
// degil YAPISINA baksin (token_get_all yorumlari cikarir ama girintiyi birakir).
$govde = $fnPos === false ? '' : preg_replace('/\s+/', ' ', substr($authCode, $fnPos, 2000));

$posVerify = strpos($govde, 'password_verify(');
$posRowGate = strpos($govde, 'if (!$row');
check('D) password_verify() $row kapisindan ONCE cagriliyor (kisa devre yok)',
    $posVerify !== false && $posRowGate !== false && $posVerify < $posRowGate,
    'verify@' . var_export($posVerify, true) . '  kapi@' . var_export($posRowGate, true));

// Kullanici YOKKEN kullanilan yedek hash gercek bir bcrypt hash'i mi?
$yedek = '';
if (preg_match('/\$hashToCheck\s*=\s*\$row\s*\?[^:]*:\s*\x27([^\x27]+)\x27/', $govde, $hm)) { $yedek = $hm[1]; }
$bilgi = $yedek !== '' ? password_get_info($yedek) : array('algoName' => 'bulunamadi');
check('D) kullanici YOKKEN gercek bir bcrypt hash dogrulaniyor (bos/sahte degil)',
    isset($bilgi['algoName']) && $bilgi['algoName'] === 'bcrypt',
    isset($bilgi['algoName']) ? $bilgi['algoName'] : 'bulunamadi');

// Yedek hash'in MALIYETI uygulamanin uretttigiyle ayni olmali; daha ucuz bir
// maliyet (or. cost=4) sizintiyi sessizce geri getirirdi.
$varsayilan = password_get_info(password_hash('x', PASSWORD_DEFAULT));
$mYedek = isset($bilgi['options']['cost']) ? (int) $bilgi['options']['cost'] : -1;
$mVar = isset($varsayilan['options']['cost']) ? (int) $varsayilan['options']['cost'] : -2;
check('D) yedek hash maliyeti uygulamanin varsayilaniyla AYNI (ucuz hash sizdirir)',
    $mYedek === $mVar, 'yedek=' . $mYedek . ' varsayilan=' . $mVar);

// Kaba EMNIYET olcumu: yapisal kontroller bir gun yanilirsa diye. Esik bilerek
// cok gevsek ve KAT cinsinden — duzeltme oncesi fark 206 KAT idi, bu esik onu
// rahat yakalar ama makine yukunden etkilenmez.
function olc_tek($email, $sifre)
{
    $b = microtime(true);
    attempt_login($email, $sifre);
    $ms = (microtime(true) - $b) * 1000;
    bcc_execute('DELETE FROM login_attempts WHERE ip = :ip', array('ip' => bcc_client_ip_binary()));
    return $ms;
}
olc_tek($VAR, $YANLIS);
olc_tek($YOK, $YANLIS);
$olcVar = array();
$olcYok = array();
for ($i = 0; $i < 5; $i++) {
    $olcVar[] = olc_tek($VAR, $YANLIS);
    $olcYok[] = olc_tek($YOK, $YANLIS);
}
$tVar = min($olcVar);
$tYok = min($olcYok);
$kat = ($tVar > 0 && $tYok > 0) ? (max($tVar, $tYok) / min($tVar, $tYok)) : 999.0;
printf("  var olan: %.1f ms   olmayan: %.1f ms   oran: %.2fx  (emniyet esigi 3x)\n", $tVar, $tYok, $kat);
check('D) sureler ayni buyukluk mertebesinde (emniyet olcumu)', $kat < 3.0, sprintf('%.2fx', $kat));

// ---------------------------------------------------------------------------
echo "\nE) Basarili giris hata gecmisini siler\n";
// ---------------------------------------------------------------------------
temizle();
attempt_login($VAR, $YANLIS);
attempt_login($VAR, $YANLIS);
$once = (int) bcc_fetch_column(
    'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND email = :email',
    array('ip' => bcc_client_ip_binary(), 'email' => $VAR)
);
check("iki hata kaydedildi", $once === 2, $once);

$out = attempt_login($VAR, bcc_demo_password_for_test());
check("dogru sifre -> ok", $out === 'ok', $out);

$sonra = (int) bcc_fetch_column(
    'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND email = :email',
    array('ip' => bcc_client_ip_binary(), 'email' => $VAR)
);
check("basarili giristen sonra 0 hata", $sonra === 0, $sonra);

// ---------------------------------------------------------------------------
echo "\nF) CSRF jetonu giriste yenileniyor\n";
// ---------------------------------------------------------------------------
$_SESSION = array();
$eskiJeton = csrf_token();
check("giris oncesi jeton uretildi (64 hex)", strlen($eskiJeton) === 64);
attempt_login($VAR, bcc_demo_password_for_test());
$yeniJeton = csrf_token();
check("giristen sonra jeton DEGISTI", $eskiJeton !== $yeniJeton,
    substr($eskiJeton, 0, 8) . ' vs ' . substr($yeniJeton, 0, 8));

// ---------------------------------------------------------------------------
echo "\nG) 'cevrimici' kosulu tek kaynaktan (bcc_online_where_sql)\n";
// ---------------------------------------------------------------------------
check("bcc_online_where_sql() tanimli", function_exists('bcc_online_where_sql'));
$sayi = bcc_online_user_count();
$liste = bcc_online_users();
check("sayi == liste uzunlugu", $sayi === count($liste), $sayi . ' vs ' . count($liste));

temizle();

echo "\n" . str_repeat('-', 50) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);

// ---------------------------------------------------------------------------
// Demo sifresi config/app.local.php'den gelir ($BCC_DEMO_PASSWORD). Depoda
// LITERAL tutulmaz (bkz. config/app.php guvenlik notu) — bu test de onu
// yazmaz, bcc_demo_accounts()'tan okur.
// ---------------------------------------------------------------------------
function bcc_demo_password_for_test()
{
    foreach (bcc_demo_accounts() as $acc) {
        if ($acc['email'] === 'viewer@bcc.local') {
            return $acc['password'];
        }
    }
    fwrite(STDERR, "\nBu test demo hesaplarina ihtiyac duyar: config/app.local.php'ye\n"
        . "\$BCC_DEMO_PASSWORD tanimlayin.\n");
    exit(2);
}
