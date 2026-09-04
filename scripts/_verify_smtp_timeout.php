<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

function php_kod($yol)
{
    $out = '';
    foreach (token_get_all(file_get_contents($yol)) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "A) Sabit tanimli ve makul\n";

check('A) BCC_SMTP_TIMEOUT tanimli', defined('BCC_SMTP_TIMEOUT'));
$t = defined('BCC_SMTP_TIMEOUT') ? BCC_SMTP_TIMEOUT : 0;
check('A) pozitif bir sayi', is_int($t) && $t > 0, var_export($t, true));

$ini = '/c/php73/php.ini';
$maxExec = 30;
foreach (@file('C:/php73/php.ini') ?: array() as $satir) {
    if (preg_match('/^\s*max_execution_time\s*=\s*(\d+)/', $satir, $m)) { $maxExec = (int) $m[1]; }
}
check('A) web max_execution_time (' . $maxExec . ' sn) ALTINDA', $t < $maxExec, $t . ' >= ' . $maxExec);
check('A) Slack tarafiyla ayni ruhta (uzun degil, 60 sn alti)', $t <= 60, (string) $t);

echo "\nB) Yardimci IKI ozelligi de ayarliyor (ilk duzeltmenin eksik oldugu yer)\n";

check('B) bcc_apply_smtp_timeout tanimli', function_exists('bcc_apply_smtp_timeout'));

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$varsayilanTimeout = $mail->Timeout;
$varsayilanLimit = $mail->getSMTPInstance()->Timelimit;
bcc_apply_smtp_timeout($mail);

check('B) PHPMailer::$Timeout ayarlandi', $mail->Timeout === $t, var_export($mail->Timeout, true));
check('B) ⭐ SMTP::$Timelimit de ayarlandi (asil sinir)',
    $mail->getSMTPInstance()->Timelimit === $t, var_export($mail->getSMTPInstance()->Timelimit, true));
check('B) ikisi de PHPMailer varsayilanindan KUCUK',
    $t < $varsayilanTimeout && $t < $varsayilanLimit,
    'varsayilanlar: Timeout=' . $varsayilanTimeout . ' Timelimit=' . $varsayilanLimit);

echo "\nC) Gonderim yapan HER yer yardimciyi cagiriyor\n";

$gonderenler = array('src/mailer.php', 'public/api/record_send.php');
foreach ($gonderenler as $d) {
    $kod = php_kod(__DIR__ . '/../' . $d);
    check('C) ' . $d . ' bcc_apply_smtp_timeout cagiriyor',
        strpos($kod, 'bcc_apply_smtp_timeout(') !== false);
    check('C) ' . $d . ' elle Timeout atamiyor (tek kaynak)',
        strpos($kod, '->Timeout =') === false || $d === 'src/mailer.php');
}

$hepsi = array();
foreach (array_merge(glob(__DIR__ . '/../src/*.php'), glob(__DIR__ . '/../public/api/*.php')) as $f) {
    $kod = php_kod($f);
    if (strpos($kod, 'new PHPMailer') !== false) { $hepsi[] = basename($f); }
}
sort($hepsi);
check('C) PHPMailer olusturan yalnizca bilinen 2 dosya var',
    $hepsi === array('mailer.php', 'record_send.php'), implode(', ', $hepsi));

echo "\nD) CANLI OLCUM: sessiz bir sunucuya karsi gercek davranis\n";

$srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$srv) {
    echo "  [ATLANDI] yerel soket kurulamadi ($errstr) — D bolumu calistirilamadi\n";
} else {
    list($h, $p) = explode(':', stream_socket_get_name($srv, false));

    $dene = function ($timeout, $timelimit) use ($h, $p) {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = $h;
        $m->Port = (int) $p;
        $m->SMTPAuth = false;
        $m->SMTPSecure = '';
        $m->SMTPAutoTLS = false;
        $m->Timeout = $timeout;
        $m->getSMTPInstance()->Timelimit = $timelimit;
        $m->setFrom('a@ornek.invalid');
        $m->addAddress('b@ornek.invalid');
        $m->Subject = 'x';
        $m->Body = 'x';
        $bas = microtime(true);
        try { $m->send(); } catch (Throwable $e) {   }

        return microtime(true) - $bas;
    };

    $sure = $dene(2, 2);
    check('D) ⭐ ikisi de 2 sn iken gonderim ~2 sn de kesildi',
        $sure < 8, sprintf('%.1f sn', $sure));

    fclose($srv);
}

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
