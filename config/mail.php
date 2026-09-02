<?php

$MAIL_FROM_EMAIL = 'no-reply@opsflow.bcccrm.com';

$MAIL_FROM_NAME = 'BCC İletişim';

$MAIL_REPLY_TO = 'info@bcciletisim.com.tr';

$bcc_localMailConfigPath = __DIR__ . '/mail.local.php';
if (is_file($bcc_localMailConfigPath)) {
    require $bcc_localMailConfigPath;
}
unset($bcc_localMailConfigPath);
