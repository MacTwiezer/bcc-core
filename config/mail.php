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
//
// Bu varsayılanlar YALNIZCA bu makine içindir — gerçek e-posta göndermek isteyen
// bir geliştirici bunları DEĞİŞTİRMEK yerine config/mail.local.php dosyası
// oluşturup (git'e girmez, bkz. .gitignore) $MAIL_* değişkenlerini yeniden
// atayabilir.

$MAIL_MODE = 'log';
$MAIL_FROM_EMAIL = 'no-reply@bcc-core.local';
$MAIL_FROM_NAME = 'BCC-Core';

$bcc_localMailConfigPath = __DIR__ . '/mail.local.php';
if (is_file($bcc_localMailConfigPath)) {
    require $bcc_localMailConfigPath;
}
unset($bcc_localMailConfigPath);
