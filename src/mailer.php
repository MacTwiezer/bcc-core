<?php
// Basit e-posta gönderici — dış kütüphane YOK (projedeki xlsx_writer.php/csv.php
// ile AYNI ilke). config/mail.php'deki $MAIL_MODE'a göre iki modda çalışır:
// 'log' (varsayılan, storage/mail/ altına dosya yazar) veya 'native' (PHP mail()).
// Gerçek bir SMTP istemcisi (kimlik doğrulamalı, TLS'li) BİLEREK yazılmadı — bu
// ortamda test edilecek gerçek kimlik bilgileri yok, doğrulanamayan bir kod
// yazıp "çalışıyor" demektense, iki modu da tam ve test edilmiş bırakmak
// tercih edildi. İleride gerekirse config/mail.php'ye 'smtp' modu eklenebilir.

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
