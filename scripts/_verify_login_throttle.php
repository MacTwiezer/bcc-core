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
function olc($email, $sifre)
{
    $t = array();
    for ($i = 0; $i < 5; $i++) {
        $b = microtime(true);
        attempt_login($email, $sifre);
        $t[] = (microtime(true) - $b) * 1000;
        // Olcumun kendisi esigi doldurmasin.
        bcc_execute('DELETE FROM login_attempts WHERE ip = :ip', array('ip' => bcc_client_ip_binary()));
    }
    sort($t);
    return $t[2]; // ortanca
}
$tVar = olc($VAR, $YANLIS);
$tYok = olc($YOK, $YANLIS);
printf("  var olan: %.1f ms   olmayan: %.1f ms   fark: %.1f ms\n", $tVar, $tYok, abs($tVar - $tYok));
check("fark < 10 ms (kullanici sayimi kapali)", abs($tVar - $tYok) < 10.0, sprintf('%.1f ms', abs($tVar - $tYok)));

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
