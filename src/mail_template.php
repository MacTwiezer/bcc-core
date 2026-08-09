<?php
// BCC-Core — ORTAK HTML e-posta şablonu.
//
// Neden tablo tabanlı layout ve inline CSS: e-posta istemcileri (özellikle
// Outlook, Word render motorunu kullanıyor) flexbox/grid/harici <style>
// desteklemez ya da kırpar. Bu yüzden burada bilerek 2005 tarzı HTML var —
// <table> iskeleti, her kurala inline `style`, genişlik piksel cinsinden.
// Uygulamanın kendi CSS'iyle (theme.css) HİÇBİR bağlantısı yok, olamaz da;
// renkler oradan ELLE kopyalandı (--bcc-accent-strong #1a56db,
// --bcc-accent #2d7ff9) çünkü mailde CSS değişkeni çalışmaz.
//
// LOGO bir <img src="https://..."> ile UZAKTAN çekiliyor. Gömülü/base64 (CID
// olmayan data: URI) görselleri Gmail dahil birçok istemci engeller. Adres
// bilerek ŞİRKETİN KENDİ alan adı: gönderen alan adıyla aynı kaynaktan gelen
// görsel istemcilerde en yüksek güveni görür ve depoya yeni dosya eklemeyi
// gerektirmez (proje logosu zaten orada yayında, birebir aynı dosya).
// localhost adresleri BURAYA ASLA GİRMEZ — alıcı onlara erişemez.
//
// KULLANIM: şu an TEK tüketicisi register.php (e-posta doğrulama). record_send.php
// hâlâ KENDİ bcc_build_send_email_html()'ini kullanıyor — bu turda bilerek
// dokunulmadı. İki şablonu birleştirmek ayrı bir iş.

$GLOBALS['BCC_MAIL_LOGO_URL'] = 'https://bcciletisim.com.tr/assets/images/logo.png';
$GLOBALS['BCC_MAIL_SITE_URL'] = 'https://bcciletisim.com.tr';
$GLOBALS['BCC_MAIL_CONTACT_EMAIL'] = 'info@bcciletisim.com.tr';
// İki numara da AYNI WhatsApp hattına gidiyor — istenen davranış bu.
$GLOBALS['BCC_MAIL_WHATSAPP_URL'] = 'https://wa.me/902162100707';
$GLOBALS['BCC_MAIL_PHONE_1'] = '0(216) 210 07 07';
$GLOBALS['BCC_MAIL_PHONE_2'] = '0(850) 260 0 999';
$GLOBALS['BCC_MAIL_MAPS_URL'] = 'https://www.google.com/maps/place/bcc+%C4%B0leti%C5%9Fim+Hizmetleri+A.%C5%9E./@40.9764305,29.1006436,17z/data=!3m1!4b1!4m6!3m5!1s0x14cac77177a2ac63:0xb734a2c61af0af6e!8m2!3d40.9764265!4d29.1032185!16s%2Fg%2F11n7z48_0s';

/**
 * Bir e-posta gövdesini kurumsal kabuğa (logo + içerik + CTA + iletişim
 * footer'ı) sarar.
 *
 * @param string      $heading   Büyük başlık (düz metin, kaçırılır)
 * @param string      $introHtml Başlığın altındaki paragraf(lar) — GÜVENLİ HTML
 *                               beklenir; değişken içerik çağıran tarafından
 *                               htmlspecialchars'lanmalıdır.
 * @param string|null $ctaText   Buton metni (null ise buton basılmaz)
 * @param string|null $ctaUrl    Buton hedefi
 * @param string|null $noteHtml  Butonun altındaki küçük not (ör. düz bağlantı)
 */
function bcc_mail_html_shell($heading, $introHtml, $ctaText = null, $ctaUrl = null, $noteHtml = null)
{
    $logo = htmlspecialchars($GLOBALS['BCC_MAIL_LOGO_URL'], ENT_QUOTES, 'UTF-8');
    $site = htmlspecialchars($GLOBALS['BCC_MAIL_SITE_URL'], ENT_QUOTES, 'UTF-8');
    $mailTo = htmlspecialchars($GLOBALS['BCC_MAIL_CONTACT_EMAIL'], ENT_QUOTES, 'UTF-8');
    $wa = htmlspecialchars($GLOBALS['BCC_MAIL_WHATSAPP_URL'], ENT_QUOTES, 'UTF-8');
    $phone1 = htmlspecialchars($GLOBALS['BCC_MAIL_PHONE_1'], ENT_QUOTES, 'UTF-8');
    $phone2 = htmlspecialchars($GLOBALS['BCC_MAIL_PHONE_2'], ENT_QUOTES, 'UTF-8');
    $maps = htmlspecialchars($GLOBALS['BCC_MAIL_MAPS_URL'], ENT_QUOTES, 'UTF-8');
    $headingSafe = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    $ctaHtml = '';
    if ($ctaText !== null && $ctaUrl !== null) {
        $ctaUrlSafe = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
        $ctaTextSafe = htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8');
        // Buton bir <a>: <button> mailde çalışmaz. Yuvarlak köşe Outlook'ta
        // düşer, sorun değil — dolgu rengi ve metin her istemcide görünür.
        $ctaHtml = <<<HTML
                        <tr>
                            <td align="center" style="padding: 28px 0 8px;">
                                <a href="{$ctaUrlSafe}" style="display: inline-block; padding: 14px 32px; background-color: #1a56db; color: #ffffff; font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; text-decoration: none; border-radius: 6px;">{$ctaTextSafe}</a>
                            </td>
                        </tr>
HTML;
    }

    $noteRow = '';
    if ($noteHtml !== null) {
        $noteRow = <<<HTML
                        <tr>
                            <td style="padding: 8px 0 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 20px; color: #6b6b70;">{$noteHtml}</td>
                        </tr>
HTML;
    }

    return <<<HTML
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$headingSafe}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f5f7;">
    <tr>
        <td align="center" style="padding: 24px 12px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 600px; max-width: 100%; background-color: #ffffff; border: 1px solid #e6e6e9; border-radius: 10px;">

                <tr>
                    <td align="center" style="padding: 28px 32px 4px;">
                        <a href="{$site}"><img src="{$logo}" width="94" height="44" alt="bcc İletişim" style="display: block; border: 0; outline: none; text-decoration: none;"></a>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 12px 32px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding: 0 0 12px; font-family: Arial, Helvetica, sans-serif; font-size: 22px; line-height: 30px; font-weight: bold; color: #1d1d1f;">{$headingSafe}</td>
                            </tr>
                            <tr>
                                <td style="font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 24px; color: #3c3c40;">{$introHtml}</td>
                            </tr>
{$ctaHtml}
{$noteRow}
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 0 32px;"><div style="height: 1px; background-color: #e6e6e9; font-size: 0; line-height: 0;">&nbsp;</div></td>
                </tr>

                <tr>
                    <td style="padding: 20px 32px 28px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 22px; color: #56565c;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding: 0 0 6px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: bold; color: #1d1d1f;">bcc İletişim Hizmetleri A.Ş.</td>
                            </tr>
                            <tr>
                                <td style="padding: 0 0 4px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 22px; color: #56565c;">
                                    <a href="{$site}" style="color: #2d7ff9; text-decoration: none;">bcciletisim.com.tr</a>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 0 4px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 22px; color: #56565c;">
                                    <a href="{$wa}" style="color: #2d7ff9; text-decoration: none;">{$phone1}</a>
                                    &nbsp;&middot;&nbsp;
                                    <a href="{$wa}" style="color: #2d7ff9; text-decoration: none;">{$phone2}</a>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 0 4px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 22px; color: #56565c;">
                                    <a href="mailto:{$mailTo}" style="color: #2d7ff9; text-decoration: none;">{$mailTo}</a>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 0 0 12px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 22px; color: #56565c;">
                                    <a href="{$maps}" style="color: #2d7ff9; text-decoration: none;">Haritada göster</a>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; line-height: 18px; color: #93939a;">&copy; {$year} bcc İletişim Hizmetleri A.Ş. Bu e-posta BCC-Core hesap işlemleri için gönderilmiştir.</td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>
</body>
</html>
HTML;
}

/**
 * HTML gövdenin düz metin karşılığını üretir (multipart'ın text/plain parçası).
 * Çağıran ELLE de verebilir; bu yardımcı yalnızca "hiç vermezse" devreye girer.
 * Sadece-HTML mailler spam puanını yükseltiyor — bu yüzden metin parçası
 * opsiyonel DEĞİL, her zaman gönderiliyor (bkz. src/mailer.php).
 */
function bcc_mail_text_footer()
{
    return "\n\n--\n"
        . "bcc İletişim Hizmetleri A.Ş.\n"
        . $GLOBALS['BCC_MAIL_SITE_URL'] . "\n"
        . $GLOBALS['BCC_MAIL_PHONE_1'] . ' · ' . $GLOBALS['BCC_MAIL_PHONE_2'] . ' (WhatsApp: ' . $GLOBALS['BCC_MAIL_WHATSAPP_URL'] . ")\n"
        . $GLOBALS['BCC_MAIL_CONTACT_EMAIL'] . "\n"
        . 'Adres/harita: ' . $GLOBALS['BCC_MAIL_MAPS_URL'] . "\n";
}
