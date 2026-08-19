<?php
// opsflow.bcccrm.com — e-posta gönderim ayarları.
//
// $MAIL_MODE:
//   'log'    (varsayılan) — gerçek e-posta GÖNDERİLMEZ, storage/mail/ altına
//            okunabilir bir dosya olarak yazılır. Gerçek bir SMTP sunucusu/
//            kimlik bilgisi gerektirmeyen, yerel geliştirme için güvenli varsayılan.
//   'native' — PHP'nin yerleşik mail() fonksiyonunu kullanır. Yalnızca sunucuda
//            sendmail/SMTP zaten yapılandırılmışsa çalışır — XAMPP'ın varsayılan
//            kurulumunda bu YAPILANDIRILMAMIŞTIR (php.ini [mail function]).
//   'smtp'   — PHPMailer ile gerçek bir SMTP sunucusuna bağlanıp gönderir.
//            SUNUCU/KİMLİK BİLGİLERİ ARTIK BURADA DEĞİL: tek kaynak
//            config/mail_record_send.local.php (dosya adı tarihsel — o config
//            artık "kaydı gönder"e değil, PROJENİN TEK SMTP HESABINA ait, bkz.
//            src/mailer.php bcc_smtp_config()). Böylece doğrulama maili ile
//            "kaydı gönder" maili AYNI kurumsal kutudan çıkıyor; önceden ikisi
//            farklı hesaplardan (Gmail / Office 365) gidiyordu ve bu spam
//            açısından en kötü kombinasyondu.
//
// Bu varsayılanlar YALNIZCA bu makine içindir — gerçek e-posta göndermek isteyen
// bir geliştirici bunları DEĞİŞTİRMEK yerine config/mail.local.php dosyası
// oluşturup (git'e girmez, bkz. .gitignore) $MAIL_* değişkenlerini yeniden
// atayabilir. Örnek:
//
//   $MAIL_MODE = 'smtp';   // SMTP bilgileri mail_record_send.local.php'den
//
// GERİYE DÖNÜK UYUMLULUK: mail_record_send.local.php yoksa, bcc_smtp_config()
// buradaki eski $MAIL_SMTP_HOST/PORT/USER/PASS değişkenlerine düşer.

$MAIL_MODE = 'log';
$MAIL_FROM_EMAIL = 'no-reply@opsflow.bcccrm.com';

// GÖRÜNEN GÖNDEREN ADI. 'smtp' modunda From ADRESİ config/mail_record_send.local.php'den
// gelir (Office 365 kimlik doğrulanan kutudan farklı bir From'u reddeder), ama
// görünen ad buradan okunur. Ürün adı ("OpsFlow") yerine KURUMSAL ad kullanılıyor:
// görünen adın kurumsal, adresin başka bir alan adına ait olması alıcı
// sunucularda "display name spoofing" olarak puanlanıyordu — ikisi de artık
// bcciletisim.com.tr'ye ait.
$MAIL_FROM_NAME = 'BCC İletişim';

// Yanıtlar no-reply kutusuna değil, gerçek bir insana gitsin. 'smtp' modunda
// Reply-To başlığı olarak eklenir (PHPMailer). Boş bırakılırsa eklenmez.
$MAIL_REPLY_TO = 'info@bcciletisim.com.tr';

$bcc_localMailConfigPath = __DIR__ . '/mail.local.php';
if (is_file($bcc_localMailConfigPath)) {
    require $bcc_localMailConfigPath;
}
unset($bcc_localMailConfigPath);
