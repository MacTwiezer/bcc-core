<?php

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/mail_template.php';

// SMTP zaman asimi (saniye). PHPMailer'in VARSAYILANI 300 ve bu deger bir web
// istegi icin anlamsiz: gonderim SENKRON, yani kullanici kayit olurken ya da
// parola sifirlama isterken sunucu yanit vermezse ISTEK O KADAR BEKLER.
// Pratikte PHP once oldurur (php.ini max_execution_time = 30) ve kullanici 30
// saniye bekledikten sonra hata sayfasi gorur - ustelik hesap ZATEN
// olusturulmus olabilir, cunku mail gonderimi DB yazmasindan sonra geliyor.
//
// 15 sn bilincli secildi: Office 365'e TLS ile gonderim pratikte 1-5 sn
// suruyor, 15 rahat bir tavan; PHP'nin 30 sn sinirinin ALTINDA kaldigi icin
// PHPMailer kendi istisnasini firlatabiliyor ve uygulama duzgun bir hata
// donduruyor (PHP surecin ortasinda oldurmuyor).
//
// AYNI GEREKCE src/slack.php'de de var: orada CONNECTTIMEOUT 3 / TIMEOUT 5
// secilmis, cunku o cagri da kayit olusturmanin ICINDE senkron calisiyor.
// Mail tarafinda bu disiplin atlanmisti.
define('BCC_SMTP_TIMEOUT', 15);

// ⚠️ IKI AYRI SINIR GEREKIYOR - YALNIZCA $mail->Timeout YETMEZ.
//
// Olculdu (yerel, sessiz dinleyen bir soketle): sunucu TCP baglantisini kabul
// edip SMTP karsilamasini (220) HIC gondermezse, $mail->Timeout = 3 iken bile
// gonderim TAM 300,0 SANIYE asili kaldi - yani Timelimit varsayilani kadar.
// Sebep PHPMailer'in kaynaginda:
//   * PHPMailer::$Timeout  -> yalnizca BAGLANMA ve stream_set_timeout icin
//     (SMTP.php:1348, PHPMailer.php:2340/2405)
//   * SMTP::$Timelimit     -> veriyi BEKLEYEN asil cagri
//     (SMTP.php:1360, stream_select($..., $this->Timelimit)), varsayilan 300
// ve Timelimit PHPMailer uzerinden HIC ACILMAMIS; yalnizca SMTP nesnesinde var.
// Ikisi birlikte ayarlanince AYNI deneme 3.0 sn'de kesildi (300 -> 3).
//
// getSMTPInstance() SMTP nesnesini tembel olusturur ve saklar, yani send()
// oncesi verilen bu deger korunur.
function bcc_apply_smtp_timeout($mail)
{
    $mail->Timeout = BCC_SMTP_TIMEOUT;
    $mail->getSMTPInstance()->Timelimit = BCC_SMTP_TIMEOUT;
}

function bcc_smtp_config()
{
    $path = __DIR__ . '/../config/mail_record_send.local.php';

    if (is_file($path)) {
        $config = require $path;
        if (is_array($config) && !empty($config['password']) && $config['password'] !== 'BURAYA_SIFRE') {
            return $config;
        }
    }

    global $MAIL_SMTP_HOST, $MAIL_SMTP_PORT, $MAIL_SMTP_USER, $MAIL_SMTP_PASS, $MAIL_FROM_EMAIL, $MAIL_FROM_NAME;
    if (!empty($MAIL_SMTP_HOST) && !empty($MAIL_SMTP_USER)) {
        return array(
            'host' => $MAIL_SMTP_HOST,
            'port' => $MAIL_SMTP_PORT,
            'encryption' => 'tls',
            'username' => $MAIL_SMTP_USER,
            'password' => $MAIL_SMTP_PASS,
            'from_email' => $MAIL_FROM_EMAIL,
            'from_name' => $MAIL_FROM_NAME,
            'legacy' => true,
        );
    }

    return null;
}

function bcc_mail_storage_dir()
{
    $dir = __DIR__ . '/../storage/mail';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function bcc_should_send_verification_mail($expiresAt, $cooldown, $now = null, $ttl = 86400)
{
    if ($now === null) {
        $now = time();
    }

    if ($expiresAt === null || $expiresAt === '') {
        return true;
    }

    $expiresTs = strtotime($expiresAt);
    if ($expiresTs === false) {

        return true;
    }

    return ($now - ($expiresTs - $ttl)) >= $cooldown;
}

function bcc_mail_attach_footer_icons($mail, $bodyHtml)
{
    if (empty($GLOBALS['BCC_MAIL_ICONS'])) {
        return;
    }

    $dir = bcc_mail_icons_dir();

    foreach ($GLOBALS['BCC_MAIL_ICONS'] as $icon) {
        if (strpos($bodyHtml, 'cid:' . $icon['cid']) === false) {
            continue;
        }

        $path = $dir . '/' . $icon['file'];
        if (!is_file($path)) {
            continue;
        }

        $mail->addEmbeddedImage($path, $icon['cid'], $icon['file'], 'base64', 'image/png');
    }
}

function bcc_send_mail($toEmail, $subject, $bodyText, $bodyHtml = null)
{
    global $MAIL_MODE, $MAIL_FROM_EMAIL, $MAIL_FROM_NAME, $MAIL_REPLY_TO;

    if ($MAIL_MODE === 'smtp') {
        $config = bcc_smtp_config();

        if ($config === null) {
            $dir = bcc_mail_storage_dir();
            $fileName = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '_SMTP_CONFIG_MISSING.txt';
            file_put_contents($dir . '/' . $fileName, "Kime: {$toEmail}\nKonu: {$subject}\n\nSMTP yapilandirmasi bulunamadi (config/mail_record_send.local.php).\n");

            return false;
        }

        require_once __DIR__ . '/../vendor/autoload.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = isset($config['encryption']) ? $config['encryption'] : 'tls';
            $mail->CharSet = 'UTF-8';
            bcc_apply_smtp_timeout($mail);

            $fromName = ($MAIL_FROM_NAME !== null && $MAIL_FROM_NAME !== '')
                ? $MAIL_FROM_NAME
                : (isset($config['from_name']) ? $config['from_name'] : '');
            $mail->setFrom($config['from_email'], $fromName);
            $mail->addAddress($toEmail);

            if (!empty($MAIL_REPLY_TO)) {
                $mail->addReplyTo($MAIL_REPLY_TO);
            }

            $mail->Subject = $subject;

            if ($bodyHtml !== null) {

                $mail->isHTML(true);
                $mail->Body = $bodyHtml;
                $mail->AltBody = $bodyText;

                bcc_mail_attach_footer_icons($mail, $bodyHtml);
            } else {
                $mail->isHTML(false);
                $mail->Body = $bodyText;
            }

            $mail->send();

            return true;
        } catch (Throwable $e) {

            $dir = bcc_mail_storage_dir();
            $fileName = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '_SMTP_ERROR.txt';
            file_put_contents(
                $dir . '/' . $fileName,
                "Kime: {$toEmail}\nKonu: {$subject}\n\n--- PHPMailer hatasi ---\n" . $mail->ErrorInfo . "\n" . $e->getMessage() . "\n"
            );

            return false;
        }
    }

    if ($MAIL_MODE === 'native') {
        $headers = 'From: ' . $MAIL_FROM_NAME . ' <' . $MAIL_FROM_EMAIL . ">\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return @mail($toEmail, $encodedSubject, $bodyText, $headers);
    }

    $dir = bcc_mail_storage_dir();
    $fileName = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.txt';
    $content = "Kime: {$toEmail}\n"
        . "Kimden: {$MAIL_FROM_NAME} <{$MAIL_FROM_EMAIL}>\n"
        . "Konu: {$subject}\n"
        . 'Tarih: ' . date('Y-m-d H:i:s') . "\n\n"
        . $bodyText . "\n";

    if ($bodyHtml !== null) {
        file_put_contents($dir . '/' . $fileName . '.html', $bodyHtml);
    }

    return file_put_contents($dir . '/' . $fileName, $content) !== false;
}

