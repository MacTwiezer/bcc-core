<?php
// E-posta gönderici. config/mail.php'deki $MAIL_MODE'a göre üç modda çalışır:
// 'log' (varsayılan, storage/mail/ altına dosya yazar), 'native' (PHP mail())
// veya 'smtp'.
//
// 'smtp' MODU ARTIK PHPMailer KULLANIYOR (aşağıdaki elle yazılmış istemci
// DEĞİL). Gerekçe ve sonuçları:
//
//   * TEK KURUMSAL GÖNDEREN. Önceden bu dosya kendi Gmail hesabından
//     (config/mail.local.php), record_send.php ise Office 365'ten
//     (config/mail_record_send.local.php) gönderiyordu. Aynı üründen iki farklı
//     alan adıyla mail çıkması spam açısından en kötü kombinasyondu: görünen ad
//     kurumsal ("BCC-Core"), adres kişisel bir Gmail — alıcı sunucular bunu
//     "display name spoofing" kalıbı olarak puanlar. Artık ikisi de AYNI dosyayı
//     (mail_record_send.local.php) okuyor, yani aynı kutudan çıkıyor.
//     Dosya adı tarihsel: o config artık "kaydı gönder"e değil, PROJENİN TEK
//     SMTP HESABINA ait. record_send.php'ye bu turda DOKUNULMADI — zaten aynı
//     dosyayı okuduğu için hesap birliği kendiliğinden sağlandı.
//
//   * MULTIPART. Sadece-HTML mailler spam puanını yükseltir; PHPMailer
//     Body + AltBody ile text/plain parçasını da ekliyor. Elle yazılmış
//     istemciye MIME multipart eklemek yeni ve gereksiz bir hata yüzeyiydi
//     (PHPMailer bu projede record_send'de zaten kanıtlanmış durumda).
//
//   * Reply-To (config/mail.php $MAIL_REPLY_TO) desteği bedava geldi.
//
// Elle yazılmış SMTP istemcisi (bcc_smtp_send, dosyanın altında) SİLİNMEDİ:
// bağımlılıksız bir yedek olarak duruyor ve $MAIL_SMTP_* ile yapılandırılmış
// eski bir kurulumda hâlâ devreye girer (bkz. bcc_smtp_config()).

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/mail_template.php';

/**
 * Projenin SMTP hesabını döndürür (host/port/encryption/username/password/
 * from_email/from_name) ya da yapılandırılmamışsa null.
 *
 * Tek kaynak: config/mail_record_send.local.php. Bulunamazsa, GERİYE DÖNÜK
 * UYUMLULUK için config/mail.local.php'deki eski $MAIL_SMTP_* değişkenlerine
 * düşer — böylece bu değişiklik, o dosyayı hâlâ eski biçimde tutan bir
 * kurulumu bozmaz.
 */
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

/**
 * MÜKERRER GÖNDERİM KAPISI — "bu adrese etkinleştirme maili ŞİMDİ atılmalı mı?"
 *
 * Saf fonksiyon (DB/mail/zaman yan etkisi YOK): kararı tek yerde toplar ve
 * gönderim yapmadan test edilebilir kılar. register.php bunu çağırır.
 *
 * Token'ın VERİLİŞ anı ayrı bir kolonda tutulmuyor; son kullanma tarihinden
 * türetiliyor (veriliş = son kullanma - $ttlSeconds). Bu iş için tabloya yeni
 * kolon/migration EKLENMEDİ.
 *
 * @param string|null $expiresAt users.email_verify_expires_at (NULL olabilir)
 * @param int         $cooldown  iki gönderim arasındaki en kısa süre (saniye)
 * @param int         $now       şimdi (unix); testlerde sabitlenebilsin diye parametre
 * @param int         $ttl       token ömrü (saniye) — veriliş anını çıkarmak için
 * @return bool true ise gönder, false ise ATLA (yakın zamanda zaten gönderilmiş)
 */
function bcc_should_send_verification_mail($expiresAt, $cooldown, $now = null, $ttl = 86400)
{
    if ($now === null) {
        $now = time();
    }

    // Hiç token verilmemiş (ya da kolon boş): gönderilecek ilk mail.
    if ($expiresAt === null || $expiresAt === '') {
        return true;
    }

    $expiresTs = strtotime($expiresAt);
    if ($expiresTs === false) {
        // Okunamayan damga: gönderimi ENGELLEMEK yerine izin ver — kullanıcıyı
        // hiç mail alamaz durumda bırakmak, fazladan bir mailden daha kötü.
        return true;
    }

    return ($now - ($expiresTs - $ttl)) >= $cooldown;
}

/**
 * Şablonun footer'ındaki ikonları (cid: ile gömülü) PHPMailer mesajına ekler.
 *
 * YALNIZCA GÖVDEDE GEÇEN cid'ler eklenir: gövdesinde ikon bulunmayan bir mail
 * (ör. record_send.php'nin kendi şablonu) gereksiz 4 ek taşımasın — kullanılmayan
 * bir ek, bazı istemcilerde maili "ataşmanlı" göstererek gereksiz kuşku uyandırır.
 *
 * Dosya yoksa SESSİZCE atlanır: ikon eksikliği yüzünden etkinleştirme maili
 * hiç gitmemesi çok daha kötü bir sonuç olurdu.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $mail
 * @param string                        $bodyHtml
 */
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

        // 5. parametre 'image/png': PHPMailer normalde uzantıdan tahmin eder,
        // açıkça vermek Outlook'un tip tahminine kalmamasını sağlar.
        $mail->addEmbeddedImage($path, $icon['cid'], $icon['file'], 'base64', 'image/png');
    }
}

/**
 * @param string      $toEmail
 * @param string      $subject
 * @param string      $bodyText Düz metin gövde — HER ZAMAN gönderilir
 *                              (multipart'ın text/plain parçası).
 * @param string|null $bodyHtml Verilirse mail multipart HTML olur; verilmezse
 *                              yalnızca düz metin gider (eski davranış).
 * @return bool gönderim (ya da 'log' modunda dosyaya yazma) başarılı mı
 */
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

            // From ADRESİ config'ten gelmek ZORUNDA: Office 365 (ve çoğu
            // sağlayıcı) kimlik doğrulanan kutudan farklı bir From'u reddeder.
            // GÖRÜNEN AD ise uygulamadan geliyor ($MAIL_FROM_NAME) — böylece
            // "BCC İletişim" gibi kurumsal bir isim, record_send'in kendi
            // gönderen adına (o dosya bu turda değişmedi) dokunmadan
            // kullanılabiliyor.
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
                // Sadece-HTML mail spam puanını yükseltir — text/plain parçası
                // her zaman ekleniyor (çağıranın verdiği düz metin, HTML'den
                // strip_tags ile TÜRETİLMİŞ bir yaklaşık DEĞİL).
                $mail->isHTML(true);
                $mail->Body = $bodyHtml;
                $mail->AltBody = $bodyText;
                // Footer ikonlarını cid: ile gövdeye göm — şablon
                // <img src="cid:bcc-icon-*"> basıyor, karşılığı BURADA
                // eklenmezse o ikonlar kırık görünürdü.
                bcc_mail_attach_footer_icons($mail, $bodyHtml);
            } else {
                $mail->isHTML(false);
                $mail->Body = $bodyText;
            }

            $mail->send();

            return true;
        } catch (Throwable $e) {
            // Sessizce yutmak yerine 'log' moduyla aynı yere, insan-okunur bir
            // hata kaydı bırakır — gönderim neden başarısız oldu görülebilsin.
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

    // HTML gövde varsa AYRI bir .html dosyası olarak da yazılır — 'log' modunda
    // tasarımı tarayıcıda açıp gözle kontrol edebilmek için (gerçek gönderim
    // yapmadan). .txt her zaman yazılır, çünkü asıl doğrulanan şey linktir.
    if ($bodyHtml !== null) {
        file_put_contents($dir . '/' . $fileName . '.html', $bodyHtml);
    }

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
