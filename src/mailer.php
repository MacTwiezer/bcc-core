<?php
// Basit e-posta gönderici — dış kütüphane YOK (projedeki xlsx_writer.php/csv.php
// ile AYNI ilke). config/mail.php'deki $MAIL_MODE'a göre üç modda çalışır:
// 'log' (varsayılan, storage/mail/ altına dosya yazar), 'native' (PHP mail())
// veya 'smtp' (aşağıdaki elle yazılmış istemci — STARTTLS + AUTH LOGIN).

require_once __DIR__ . '/../config/mail.php';

function bcc_mail_storage_dir()
{
    $dir = __DIR__ . '/../storage/mail';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

/**
 * @return bool gönderim (ya da 'log' modunda dosyaya yazma) başarılı mı
 */
function bcc_send_mail($toEmail, $subject, $bodyText)
{
    global $MAIL_MODE, $MAIL_FROM_EMAIL, $MAIL_FROM_NAME;

    if ($MAIL_MODE === 'smtp') {
        global $MAIL_SMTP_HOST, $MAIL_SMTP_PORT, $MAIL_SMTP_USER, $MAIL_SMTP_PASS;

        $transcript = '';
        $ok = bcc_smtp_send(
            $MAIL_SMTP_HOST,
            $MAIL_SMTP_PORT,
            $MAIL_SMTP_USER,
            $MAIL_SMTP_PASS,
            $MAIL_FROM_EMAIL,
            $MAIL_FROM_NAME,
            $toEmail,
            $subject,
            $bodyText,
            $transcript
        );

        if (!$ok) {
            // Sessizce yutmak yerine 'log' moduyla aynı yere, insan-okunur bir
            // hata kaydı bırakır — gönderim neden başarısız oldu görülebilsin.
            $dir = bcc_mail_storage_dir();
            $fileName = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '_SMTP_ERROR.txt';
            file_put_contents($dir . '/' . $fileName, "Kime: {$toEmail}\nKonu: {$subject}\n\n--- SMTP transcript ---\n{$transcript}");
        }

        return $ok;
    }

    if ($MAIL_MODE === 'native') {
        $headers = 'From: ' . $MAIL_FROM_NAME . ' <' . $MAIL_FROM_EMAIL . ">\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        // Konu satırı UTF-8 (Türkçe karakterler) içerebilir — RFC 2047 ile kodlanır.
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return @mail($toEmail, $encodedSubject, $bodyText, $headers);
    }

    // 'log' (varsayılan): gerçek gönderim yapılmaz — içerik storage/mail/ altına
    // insan-okunur bir .txt dosyası olarak yazılır (yerel geliştirmede e-postanın
    // içeriğini/linkini gözle veya scriptle doğrulamak için).
    $dir = bcc_mail_storage_dir();
    $fileName = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.txt';
    $content = "Kime: {$toEmail}\n"
        . "Kimden: {$MAIL_FROM_NAME} <{$MAIL_FROM_EMAIL}>\n"
        . "Konu: {$subject}\n"
        . 'Tarih: ' . date('Y-m-d H:i:s') . "\n\n"
        . $bodyText . "\n";

    return file_put_contents($dir . '/' . $fileName, $content) !== false;
}

// --- Elle yazılmış SMTP istemcisi (STARTTLS + AUTH LOGIN) ---------------
// Gmail gibi kimlik doğrulamalı/TLS'li sağlayıcılar için. Sadece bu dosya
// içinde kullanılır, dışa açık bir API değildir.

function bcc_smtp_read_line($socket)
{
    $line = fgets($socket, 515);

    return $line === false ? '' : $line;
}

/**
 * Çok satırlı SMTP yanıtlarını (ör. "250-...\r\n250 ...\r\n") okur.
 *
 * @return array{0:int,1:string} [durum kodu, tam yanıt metni]
 */
function bcc_smtp_read_response($socket, &$transcript)
{
    $full = '';
    $code = 0;

    do {
        $line = bcc_smtp_read_line($socket);
        $transcript .= $line;
        $full .= $line;
        $code = (int) substr($line, 0, 3);
        $continues = isset($line[3]) && $line[3] === '-';
    } while ($continues && $line !== '');

    return array($code, $full);
}

function bcc_smtp_command($socket, $command, &$transcript)
{
    $transcript .= $command . "\r\n";
    fwrite($socket, $command . "\r\n");

    return bcc_smtp_read_response($socket, $transcript);
}

/**
 * @param string $transcript referansla doldurulur (hata ayıklama/log için)
 * @return bool
 */
function bcc_smtp_send($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $bodyText, &$transcript)
{
    $transcript = '';

    $socket = @fsockopen($host, (int) $port, $errno, $errstr, 10);
    if ($socket === false) {
        $transcript .= "Bağlantı hatası: [{$errno}] {$errstr}\n";

        return false;
    }
    stream_set_timeout($socket, 10);

    $localHost = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    if ($localHost === '') {
        $localHost = 'localhost';
    }

    list($code) = bcc_smtp_read_response($socket, $transcript); // sunucu karşılama banner'ı
    if ($code !== 220) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, 'EHLO ' . $localHost, $transcript);
    if ($code !== 250) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, 'STARTTLS', $transcript);
    if ($code !== 220) {
        fclose($socket);

        return false;
    }

    if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        $transcript .= "STARTTLS: TLS el sıkışması başarısız\n";
        fclose($socket);

        return false;
    }

    // TLS sonrası EHLO tekrarlanmalı (RFC 3207).
    list($code) = bcc_smtp_command($socket, 'EHLO ' . $localHost, $transcript);
    if ($code !== 250) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, 'AUTH LOGIN', $transcript);
    if ($code !== 334) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, base64_encode($user), $transcript);
    if ($code !== 334) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, base64_encode($pass), $transcript);
    if ($code !== 235) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', $transcript);
    if ($code !== 250) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', $transcript);
    if ($code !== 250 && $code !== 251) {
        fclose($socket);

        return false;
    }

    list($code) = bcc_smtp_command($socket, 'DATA', $transcript);
    if ($code !== 354) {
        fclose($socket);

        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    // Satır başındaki tek "." karakterleri SMTP'de mesaj sonu anlamına gelir
    // (RFC 5321 dot-stuffing) — gövdede varsa kaçırılması gerekir.
    $escapedBody = preg_replace('/^\./m', '..', $bodyText);
    $message = 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n"
        . 'To: <' . $toEmail . ">\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . str_replace("\n", "\r\n", $escapedBody)
        . "\r\n.";

    list($code) = bcc_smtp_command($socket, $message, $transcript);
    if ($code !== 250) {
        fclose($socket);

        return false;
    }

    bcc_smtp_command($socket, 'QUIT', $transcript);
    fclose($socket);

    return true;
}
