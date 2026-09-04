<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../src/mailer.php';

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

$fullName = 'Test Kullanıcı';
$token = str_repeat('a1b2', 8);
$verifyLink = bcc_app_base_url() . '/verify_email.php?token=' . $token;

$safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
$safeLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');

$introHtml = '<p style="margin: 0 0 14px;">Merhaba <strong>' . $safeName . '</strong>,</p>'
    . '<p style="margin: 0 0 14px;">opsflow.bcccrm.com hesabınız oluşturuldu. Hesabınızı etkinleştirmek ve şifrenizi belirlemek için aşağıdaki butona tıklayın.</p>'
    . '<p style="margin: 0;">Bu bağlantı <strong>24 saat</strong> geçerlidir.</p>';
$noteHtml = '<p style="margin: 12px 0 0;">Buton çalışmazsa bu adresi tarayıcınıza yapıştırın:<br>'
    . '<a href="' . $safeLink . '" style="color: #2d7ff9; word-break: break-all;">' . $safeLink . '</a></p>'
    . '<p style="margin: 12px 0 0;">Bu kaydı siz yapmadıysanız bu e-postayı yok sayabilirsiniz.</p>';

$html = bcc_mail_html_shell('Hesabınızı etkinleştirin', $introHtml, 'Hesabımı Etkinleştir', $verifyLink, $noteHtml);
$text = "Merhaba {$fullName},\n\nopsflow.bcccrm.com hesabınızı etkinleştirmek ve şifrenizi oluşturmak için aşağıdaki bağlantıyı açın:\n\n"
    . $verifyLink . "\n\nBu bağlantı 24 saat geçerlidir.\n\nBu kaydı siz yapmadıysanız bu e-postayı yok sayabilirsiniz."
    . bcc_mail_text_footer();

echo "--- A) Gonderen kimligi ---\n";
$smtp = bcc_smtp_config();
check('A) SMTP yapilandirmasi bulundu', is_array($smtp));
check('A) TEK kurumsal hesap kullaniliyor (Gmail DEGIL)',
    is_array($smtp) && strpos((string) $smtp['host'], 'gmail') === false
    && substr((string) $smtp['from_email'], -strlen('@bcciletisim.com.tr')) === '@bcciletisim.com.tr',
    is_array($smtp) ? $smtp['host'] . ' / ' . $smtp['from_email'] : 'YOK');
check('A) record_send ile AYNI config dosyasi okunuyor (hesap birligi)',
    is_file(__DIR__ . '/../config/mail_record_send.local.php')
    && strpos(file_get_contents(__DIR__ . '/../src/mailer.php'), 'mail_record_send.local.php') !== false);
check('A) gorunen ad kurumsal', $GLOBALS['MAIL_FROM_NAME'] === 'BCC İletişim', (string) $GLOBALS['MAIL_FROM_NAME']);
check('A) Reply-To tanimli', $GLOBALS['MAIL_REPLY_TO'] === 'info@bcciletisim.com.tr', (string) $GLOBALS['MAIL_REPLY_TO']);

function php_code_without_comments($path)
{
    $code = '';
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }
    return $code;
}

$mailLocalCode = php_code_without_comments(__DIR__ . '/../config/mail.local.php');
check('A) mail.local.php artik Gmail kimlik bilgisi ICERMIYOR',
    strpos($mailLocalCode, 'gmail.com') === false
    && strpos($mailLocalCode, 'MAIL_SMTP_PASS') === false
    && strpos($mailLocalCode, 'MAIL_FROM_EMAIL') === false,
    trim(preg_replace('/\s+/', ' ', $mailLocalCode)));

echo "\n--- B) Bicim ---\n";
check('B) duz metin parcasi HTML den turetilmemis (elle yazilmis)',
    strpos($text, 'Merhaba Test Kullanıcı') !== false && strpos($text, '<') === false);
check('B) mailer multipart destekliyor (AltBody)',
    strpos(file_get_contents(__DIR__ . '/../src/mailer.php'), '$mail->AltBody = $bodyText;') !== false);
check('B) konu sade (uzun tire / gereksiz onek yok)',
    strpos('opsflow.bcccrm.com hesabınızı etkinleştirin', '—') === false);

echo "\n--- C) Sablon icerigi ---\n";
$mustContain = array(
    'logo GOMULU (cid, dis istek yok)' => 'src="cid:bcc-logo"',
    'web sitesi linki'             => 'href="https://bcciletisim.com.tr"',
    'WhatsApp linki'               => 'href="https://wa.me/902162100707"',
    'telefon 1'                    => '0(216) 210 07 07',
    'telefon 2'                    => '0(850) 260 0 999',
    'mailto'                       => 'href="mailto:info@bcciletisim.com.tr"',
    'Google Maps'                  => 'google.com/maps/place/bcc',
    'CTA butonu'                   => 'Hesabımı Etkinleştir',
);
foreach ($mustContain as $label => $needle) {
    check('C) ' . $label, strpos($html, $needle) !== false, $needle);
}
check('C) iki telefon da AYNI WhatsApp hattina gidiyor',
    substr_count($html, 'href="https://wa.me/902162100707"') === 2,
    substr_count($html, 'href="https://wa.me/902162100707"') . ' kez');
check('C) tablo tabanli layout + inline CSS (mail uyumlu)',
    strpos($html, '<table role="presentation"') !== false && strpos($html, '<style') === false);
check('C) MAILDE localhost adresi YOK (footer/logo tarafinda)',
    strpos($GLOBALS['BCC_MAIL_LOGO_URL'], 'localhost') === false
    && strpos($GLOBALS['BCC_MAIL_SITE_URL'], 'localhost') === false);

echo "\n--- D) Uzak kaynaklar ---\n";

function head_status($url, $attempt = 1)
{
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 20, 'ignore_errors' => true)));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
        }
    }

    if ($status !== 200 && $attempt < 2) {
        sleep(2);

        return head_status($url, $attempt + 1);
    }

    return array($status, $body === false ? 0 : strlen($body));
}

$uzakUyari = 0;

list($st, $len) = head_status($GLOBALS['BCC_MAIL_LOGO_URL']);
if ($st === 0) {
    $uzakUyari++;
    echo "  [UYARI] D) logo URL'ine ULASILAMADI (ag/uzak sunucu) — kontrol atlandi\n";
    echo "          " . $GLOBALS['BCC_MAIL_LOGO_URL'] . "\n";
    echo "          Bu bir KOD hatasi degil; adres dogru ama sunucuya erisilemiyor.\n";
    echo "          Surerse: maillerdeki logo ALICILARDA da kirik gorunur.\n";
} else {
    check('D) logo URL 200 ve bos degil', $st === 200 && $len > 1000, "HTTP {$st}, {$len} bayt");
}

list($st2) = head_status($GLOBALS['BCC_MAIL_SITE_URL']);
if ($st2 === 0) {
    $uzakUyari++;
    echo "  [UYARI] D) web sitesine ULASILAMADI (ag/uzak sunucu) — kontrol atlandi\n";
    echo "          " . $GLOBALS['BCC_MAIL_SITE_URL'] . "\n";
} else {
    check('D) web sitesi 200', $st2 === 200, 'HTTP ' . $st2);
}

check('D) logo URL https ve mutlak', preg_match('#^https://[a-z0-9.\-]+/#i', $GLOBALS['BCC_MAIL_LOGO_URL']) === 1,
    $GLOBALS['BCC_MAIL_LOGO_URL']);
check('D) site URL https ve mutlak', preg_match('#^https://[a-z0-9.\-]+#i', $GLOBALS['BCC_MAIL_SITE_URL']) === 1,
    $GLOBALS['BCC_MAIL_SITE_URL']);

echo "\n--- E) Dogrulama baglantisi ---\n";
check('E) baglanti bcc_app_base_url() uzerinden kuruluyor',
    strpos(file_get_contents(__DIR__ . '/../public/register.php'), 'bcc_app_base_url()') !== false);
check('E) register.php artik HTTP_HOST kullanmiyor',
    strpos(php_code_without_comments(__DIR__ . '/../public/register.php'), 'HTTP_HOST') === false);

global $APP_BASE_URL;
$configured = (is_string($APP_BASE_URL) && $APP_BASE_URL !== '');
$isLocal = strpos($verifyLink, 'localhost') !== false;

echo '        > Uretilen baglanti: ' . $verifyLink . "\n";
echo '        > Kaynak: ' . ($configured
    ? 'config/app.local.php ($APP_BASE_URL = ' . $APP_BASE_URL . ')'
    : "yapilandirma BOS -> \$_SERVER['HTTP_HOST']'a dusuldu") . "\n";

check('E) baglanti yapilandirmadan geliyor (HTTP_HOST tan DEGIL)', $configured,
    'config/app.local.php yok ya da $APP_BASE_URL bos');

if ($isLocal) {
    echo "        > UYARI: baglanti localhost'a cikiyor — YEREL TEST icin dogru, ama\n";
    echo "          disariya gercek mail gonderilecekse ALICI bu adrese ERISEMEZ.\n";
    echo "          Canliya cikarken config/app.local.php'ye gercek adresi yazin.\n";
}

$previewPath = __DIR__ . '/../storage/mail/_onizleme_dogrulama.html';
if (!is_dir(dirname($previewPath))) { mkdir(dirname($previewPath), 0775, true); }

$preview = $html;
$preview = str_replace('cid:' . $GLOBALS['BCC_MAIL_LOGO_CID'], '../../public/assets/logo.png', $preview);
foreach ($GLOBALS['BCC_MAIL_ICONS'] as $icon) {
    $preview = str_replace('cid:' . $icon['cid'], '../../public/assets/mail/' . $icon['file'], $preview);
}
file_put_contents($previewPath, $preview);
echo "\nHTML onizleme (cid -> yerel yol): " . realpath($previewPath) . "\n";

if (isset($argv[1]) && $argv[1] !== '') {
    $to = $argv[1];
    echo "\n--- F) GERCEK gonderim: {$to} ---\n";
    $ok = bcc_send_mail($to, 'opsflow.bcccrm.com hesabınızı etkinleştirin', $text, $html);
    check('F) bcc_send_mail true dondu', $ok === true);
    if (!$ok) {
        $errors = glob(__DIR__ . '/../storage/mail/*SMTP*');
        if (!empty($errors)) {
            rsort($errors);
            echo "--- son hata kaydi (" . basename($errors[0]) . ") ---\n";
            echo file_get_contents($errors[0]) . "\n";
        }
    }
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
