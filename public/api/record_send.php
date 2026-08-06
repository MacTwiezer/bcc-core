<?php
// AJAX uçnoktası: "Kaydı gönder" modalının (grid-row-detail.js) Gönder butonu
// çağırır. record_delete.php/comment_add.php ile AYNI desen: api_bootstrap +
// api_require_post/login/csrf, team_id record_id üzerinden bcc_find_record()
// ile DB'den türetilir (istekten değil, KVKK), require_role().
//
// İki güvenlik kuralı (yalnızca burada, frontend'deki gizleme/ön-doğrulama
// UX'tir, son söz burada):
//   1. Rol: yalnızca editor/owner gönderebilir (require_role — projedeki
//      TEK rol-kontrol mekanizması, ikinci bir yetki deseni İCAT EDİLMEDİ).
//   2. Alıcı: yalnızca @bcciletisim.com.tr, geçerli e-posta formatı, en
//      fazla 15 — ilk ihlalde hangi adres/kural olduğu Türkçe belirtilir.
//
// Alan önizlemesi (mail gövdesindeki kayıt değerleri) SUNUCUDA YENİDEN
// ÇIKARILMAZ — istemci zaten ekranda gösterdiği önizlemeyi (grid-row-detail.js
// collectFieldPreviewData(), yazdırdaki fieldPrintText()'i çağırıyor)
// preview_fields JSON'u olarak gönderir, burada yalnızca escape'lenip
// biçimlendirilir (bkz. bcc_build_send_email_html()).
//
// DB YAZMASI YOK (DDL/INSERT/UPDATE yok, kapsam dışı bırakıldı).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

define('BCC_SEND_RECIPIENT_DOMAIN', '@bcciletisim.com.tr');
define('BCC_SEND_MAX_RECIPIENTS', 15);

function bcc_send_recipient_error($email)
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '"' . $email . '" geçerli bir e-posta adresi değil.';
    }
    if (strtolower(substr($email, -strlen(BCC_SEND_RECIPIENT_DOMAIN))) !== BCC_SEND_RECIPIENT_DOMAIN) {
        return '"' . $email . '" adresine gönderilemez — yalnızca ' . BCC_SEND_RECIPIENT_DOMAIN . ' adreslerine gönderim yapılabilir.';
    }

    return null;
}

// "Tablo düzenini kullan" AÇIK: tek satırlık bir tablo (başlık satırı =
// etiketler, veri satırı = değerler) — Airtable'daki grid görünümünün
// minimal karşılığı. KAPALI: alt alta liste (etiket kalın, değer altında).
// İkisi de preview_fields'ı (istemcinin ÇIKARDIĞI, burada yeniden
// üretilmeyen veri) yalnızca escape'leyip biçimlendirir.
function bcc_build_send_email_html($message, $previewFields, $useGridLayout)
{
    $html = '<p style="font-family:Arial,sans-serif;font-size:14px;white-space:pre-wrap;">'
        . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'))
        . '</p>';

    if (empty($previewFields)) {
        return $html;
    }

    if ($useGridLayout) {
        $html .= '<table style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-top:12px;">';
        $html .= '<tr>';
        foreach ($previewFields as $f) {
            $html .= '<th style="text-align:left;padding:6px 10px;border:1px solid #ddd;background:#f5f5f5;">'
                . htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr><tr>';
        foreach ($previewFields as $f) {
            $html .= '<td style="padding:6px 10px;border:1px solid #ddd;white-space:pre-wrap;">'
                . nl2br(htmlspecialchars($f['value'], ENT_QUOTES, 'UTF-8')) . '</td>';
        }
        $html .= '</tr></table>';
    } else {
        $html .= '<table style="font-family:Arial,sans-serif;font-size:13px;margin-top:12px;">';
        foreach ($previewFields as $f) {
            $html .= '<tr>'
                . '<td style="padding:4px 12px 4px 0;font-weight:bold;vertical-align:top;white-space:nowrap;">'
                . htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:4px 0;white-space:pre-wrap;">'
                . nl2br(htmlspecialchars($f['value'], ENT_QUOTES, 'UTF-8')) . '</td>'
                . '</tr>';
        }
        $html .= '</table>';
    }

    return $html;
}

$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
$rawRecipients = isset($_POST['recipients']) ? (string) $_POST['recipients'] : '';
$subject = isset($_POST['subject']) ? trim((string) $_POST['subject']) : '';
$message = isset($_POST['message']) ? (string) $_POST['message'] : '';
$useGridLayout = isset($_POST['use_grid_layout']) && $_POST['use_grid_layout'] === '1';
$sendCopyToSelf = isset($_POST['send_copy_to_self']) && $_POST['send_copy_to_self'] === '1';

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

// 1) ROL KONTROLÜ — yalnızca editor/owner gönderebilir. commenter/viewer
// require_role() içinde 403 ile reddedilir (mail HİÇ gönderilmez).
require_role($record['team_id'], 'editor');

// Adım 3c'nin tamamlayıcısı: silinmiş (çöp kutusundaki) bir kayıt
// gönderilemez — cell_update.php/attachment_upload.php ile AYNI desen.
// bcc_find_record()'a DOKUNULMADI (record_soft_delete.php deleted_at'i
// "zaten silinmiş" 422'si için OKUYABİLMEK zorunda), burada YEREL kontrol.
$recordStatus = bcc_fetch_one('SELECT deleted_at FROM records WHERE id = :id LIMIT 1', array(':id' => $recordId));
if (!$recordStatus || $recordStatus['deleted_at'] !== null) {
    json_fail(404, 'Kayıt bulunamadı (silinmiş).');
}

// preview_fields: istemcinin çıkardığı {label, value} listesi — biçim
// dışında GÜVENMİYORUZ, her ihtimale karşı şekli doğrulanıp string'e
// zorlanıyor (htmlspecialchars zaten aşağıda XSS'e karşı koruyor).
$previewFieldsRaw = isset($_POST['preview_fields']) ? (string) $_POST['preview_fields'] : '[]';
$previewFieldsDecoded = json_decode($previewFieldsRaw, true);
$previewFields = array();
if (is_array($previewFieldsDecoded)) {
    foreach ($previewFieldsDecoded as $f) {
        if (is_array($f) && isset($f['label']) && isset($f['value'])) {
            $previewFields[] = array('label' => (string) $f['label'], 'value' => (string) $f['value']);
        }
    }
}

if ($subject === '') {
    json_fail(422, 'Konu boş olamaz.');
}
// Başlık enjeksiyonuna karşı: konu satır sonu/satır başı İÇEREMEZ.
$subject = str_replace(array("\r", "\n"), ' ', $subject);

$user = current_user();

// 2) ALICI DOĞRULAMA — format + @bcciletisim.com.tr + en fazla 15.
// "Bir kopyasını bana gönder": oturumdaki kullanıcının adresi OTURUMDAN
// alınır (istekten değil) — ama listeye eklendikten SONRA aynı domain
// kontrolünden geçer, istisna YOK (ör. @bcc.local test hesapları reddedilir).
$recipients = array_values(array_filter(
    array_map('trim', explode(',', $rawRecipients)),
    function ($e) { return $e !== ''; }
));

if ($sendCopyToSelf && $user['email'] !== '') {
    $recipients[] = $user['email'];
}
$recipients = array_values(array_unique($recipients));

if (empty($recipients)) {
    json_fail(422, 'En az bir alıcı e-posta adresi girin.');
}
if (count($recipients) > BCC_SEND_MAX_RECIPIENTS) {
    json_fail(422, 'En fazla ' . BCC_SEND_MAX_RECIPIENTS . ' alıcı ekleyebilirsiniz.');
}
foreach ($recipients as $email) {
    $error = bcc_send_recipient_error($email);
    if ($error !== null) {
        json_fail(422, $error);
    }
}

$configPath = __DIR__ . '/../../config/mail_record_send.local.php';
if (!is_file($configPath)) {
    json_fail(500, 'Mail yapılandırması bulunamadı.');
}
$mailConfig = require $configPath;
if (!is_array($mailConfig) || !isset($mailConfig['password']) || $mailConfig['password'] === 'BURAYA_SIFRE') {
    json_fail(500, 'Mail yapılandırması eksik (şifre girilmemiş).');
}

require __DIR__ . '/../../vendor/autoload.php';

$bodyHtml = bcc_build_send_email_html($message, $previewFields, $useGridLayout);

$mail = new PHPMailer\PHPMailer\PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = $mailConfig['host'];
    $mail->Port = $mailConfig['port'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->SMTPSecure = $mailConfig['encryption'];
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
    foreach ($recipients as $email) {
        $mail->addAddress($email);
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $bodyHtml;
    $mail->AltBody = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $bodyHtml)));

    $mail->send();
} catch (\PHPMailer\PHPMailer\Exception $e) {
    json_fail(502, 'Mail gönderilemedi: ' . $mail->ErrorInfo);
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
