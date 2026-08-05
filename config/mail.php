<?php
// BCC-Core — e-posta gönderim ayarları.
//
// $MAIL_MODE:
//   'log'    (varsayılan) — gerçek e-posta GÖNDERİLMEZ, storage/mail/ altına
//            okunabilir bir dosya olarak yazılır. Gerçek bir SMTP sunucusu/
//            kimlik bilgisi gerektirmeyen, yerel geliştirme için güvenli varsayılan.
//   'native' — PHP'nin yerleşik mail() fonksiyonunu kullanır. Yalnızca sunucuda
//            sendmail/SMTP zaten yapılandırılmışsa çalışır — XAMPP'ın varsayılan
//            kurulumunda bu YAPILANDIRILMAMIŞTIR (php.ini [mail function]).
//   'smtp'   — src/mailer.php içindeki elle yazılmış istemciyle (STARTTLS +
//            AUTH LOGIN) gerçek bir SMTP sunucusuna bağlanıp gönderir. Bu modda
//            ayrıca $MAIL_SMTP_HOST, $MAIL_SMTP_PORT, $MAIL_SMTP_USER,
//            $MAIL_SMTP_PASS tanımlanmalıdır (aşağıya bkz.).
//
// Bu varsayılanlar YALNIZCA bu makine içindir — gerçek e-posta göndermek isteyen
// bir geliştirici bunları DEĞİŞTİRMEK yerine config/mail.local.php dosyası
// oluşturup (git'e girmez, bkz. .gitignore) $MAIL_* değişkenlerini yeniden
// atayabilir. Örnek ('smtp' modu, Gmail):
//
//   $MAIL_MODE = 'smtp';
//   $MAIL_FROM_EMAIL = 'adiniz@gmail.com';
//   $MAIL_FROM_NAME = 'BCC-Core';
//   $MAIL_SMTP_HOST = 'smtp.gmail.com';
//   $MAIL_SMTP_PORT = 587;
//   $MAIL_SMTP_USER = 'adiniz@gmail.com';
//   $MAIL_SMTP_PASS = 'xxxx xxxx xxxx xxxx'; // Google Hesabı → Uygulama Şifresi
//            (normal Gmail şifreniz SMTP için ÇALIŞMAZ, 2FA açıkken
//            myaccount.google.com/apppasswords adresinden 16 haneli bir
//            "Uygulama Şifresi" oluşturmanız gerekir)

$MAIL_MODE = 'log';
$MAIL_FROM_EMAIL = 'no-reply@bcc-core.local';
$MAIL_FROM_NAME = 'BCC-Core';

$bcc_localMailConfigPath = __DIR__ . '/mail.local.php';
if (is_file($bcc_localMailConfigPath)) {
    require $bcc_localMailConfigPath;
}
unset($bcc_localMailConfigPath);
