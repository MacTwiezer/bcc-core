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
 * FOOTER İKONLARI — cid: (Content-ID) ile GÖMÜLÜ.
 *
 * Üç seçenek vardı, ikisi elendi:
 *   * data: URI  — Gmail ve Outlook <img src="data:..."> görsellerini
 *     ENGELLER (bu dosyanın en üstündeki logo notu da aynı şeyi söylüyor).
 *     "Gömülü" istense de ekranda kırık ikon çıkardı.
 *   * Üçüncü taraf CDN — her maile şirket dışı bir bağımlılık ve iz sürücü
 *     ekler, lisans/erişilebilirlik garantisi bizde değil.
 *   * cid: (SEÇİLEN) — görsel mailin KENDİ gövdesinde taşınır; Gmail,
 *     Outlook (Word motoru dahil), Apple Mail ve Thunderbird hepsinde
 *     render edilir, dış istek yapılmaz, "görselleri göster" uyarısı çıkmaz.
 *
 * Dosyalar repoda (public/assets/mail/) ve gönderim anında
 * bcc_mail_attach_footer_icons() ile eklenir — CID'ler İKİ TARAFTA DA bu
 * diziden okunur, elle yazılmış bir string eşleşmesi YOK.
 *
 * 36x36 üretilip 18x18 gösteriliyor: retina/HiDPI istemcilerde net kalsın.
 */
$GLOBALS['BCC_MAIL_ICONS'] = array(
    'web'   => array('cid' => 'bcc-icon-web',   'file' => 'icon-web.png',   'alt' => 'Web'),
    'phone' => array('cid' => 'bcc-icon-phone', 'file' => 'icon-phone.png', 'alt' => 'Telefon'),
    'mail'  => array('cid' => 'bcc-icon-mail',  'file' => 'icon-mail.png',  'alt' => 'E-posta'),
    'map'   => array('cid' => 'bcc-icon-map',   'file' => 'icon-map.png',   'alt' => 'Adres'),
);

/**
 * İkon dosyalarının bulunduğu dizin (tek kaynak — şablon ve gönderici aynı
 * yolu buradan okur).
 */
function bcc_mail_icons_dir()
{
    return __DIR__ . '/../public/assets/mail';
}

/**
 * TASARIM BELİRTEÇLERİ — tek kaynak.
 *
 * Mailde CSS değişkeni (var(--x)) ÇALIŞMAZ; bu sabitler PHP tarafında
 * çözülüp her kurala inline gömülüyor. Renkler Tailwind slate/blue
 * ölçeğiyle aynı hizada seçildi (#0f172a … #f8fafc), böylece uygulamanın
 * arayüzüyle aynı görsel dili konuşuyor.
 */
define('BCC_MAIL_FONT', "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif");
define('BCC_MAIL_C_INK', '#0f172a');        // başlık
define('BCC_MAIL_C_BODY', '#334155');       // gövde metni
define('BCC_MAIL_C_MUTED', '#475569');      // footer metni
define('BCC_MAIL_C_FAINT', '#94a3b8');      // etiket / telif
define('BCC_MAIL_C_ACCENT', '#2563eb');
define('BCC_MAIL_C_ACCENT_DARK', '#1d4ed8');
define('BCC_MAIL_C_LINE', '#e2e8f0');
define('BCC_MAIL_C_PANEL', '#f8fafc');
define('BCC_MAIL_C_PAGE', '#f1f5f9');

/**
 * Footer ızgarasındaki 16x16 ikonun <img> etiketi.
 *
 * width/height ÖZNİTELİK olarak da veriliyor (yalnızca style değil): Outlook
 * inline CSS'in bir kısmını kırpar ama HTML özniteliklerine her zaman uyar —
 * öznitelik olmadan ikon doğal 36px'ine büyüyüp satırı bozardı.
 * vertical-align: middle + margin-right: 6px, metinle aynı optik hizada
 * durması için.
 *
 * 36x36 üretilip 16x16 gösteriliyor (2.25x): retina/HiDPI istemcilerde net kalır.
 */
function bcc_mail_icon_img($key)
{
    if (!isset($GLOBALS['BCC_MAIL_ICONS'][$key])) {
        return '';
    }
    $icon = $GLOBALS['BCC_MAIL_ICONS'][$key];
    $cid = htmlspecialchars($icon['cid'], ENT_QUOTES, 'UTF-8');
    $alt = htmlspecialchars($icon['alt'], ENT_QUOTES, 'UTF-8');

    return '<img src="cid:' . $cid . '" width="16" height="16" alt="' . $alt . '"'
        . ' style="width: 16px; height: 16px; vertical-align: middle; margin-right: 6px; border: 0;">';
}

/**
 * Footer'daki TEK bir iletişim kanalı (ızgaranın bir hücresi):
 * ikon + üstte küçük etiket + altta değer.
 *
 * Ikon ve metin AYRI <td>'lerde: tek satırda yan yana <img>+metin, uzun değer
 * kaydığında ikonun altına sarardı. İki hücreli mini tablo, metin kaç satıra
 * çıkarsa çıksın ikonu sabit tutar (Outlook dahil).
 *
 * @param string $iconKey $GLOBALS['BCC_MAIL_ICONS'] anahtarı
 * @param string $label   Kanal adı (düz metin, kaçırılır)
 * @param string $valueHtml Değer — GÜVENLİ HTML (çağıran kaçırır)
 */
function bcc_mail_contact_cell($iconKey, $label, $valueHtml)
{
    $icon = bcc_mail_icon_img($iconKey);
    $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $font = BCC_MAIL_FONT;
    $faint = BCC_MAIL_C_FAINT;
    $muted = BCC_MAIL_C_MUTED;

    return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                <td width="22" valign="top" style="width: 22px; padding-top: 1px;">{$icon}</td>
                                                <td valign="top" style="font-family: {$font}; font-size: 13px; line-height: 20px; color: {$muted};">
                                                    <div style="font-family: {$font}; font-size: 10px; line-height: 14px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase; color: {$faint}; padding-bottom: 3px;">{$labelSafe}</div>
                                                    {$valueHtml}
                                                </td>
                                            </tr>
                                        </table>
HTML;
}

/**
 * Bir e-posta gövdesini kurumsal kabuğa (üst şerit + kart + CTA + iletişim
 * ızgarası) sarar.
 *
 * DÜZEN: 600px'lik tek bir "kart" — üstte marka şeridi (accent border +
 * açık gri zemin + logo), ortada içerik, altta 2 sütunlu iletişim paneli.
 * Tamamı <table> ve inline CSS: Outlook Word motorunu kullandığı için
 * flexbox/grid/harici <style> ya desteklenmez ya kırpılır.
 *
 * OUTLOOK'TA DÜŞEN KURALLAR ve karşılıkları (hepsi bilerek, kırık değil sade
 * görünmesi için):
 *   * border-radius   -> köşeler dik çıkar; kart/kutu yine de doğru renkte.
 *   * linear-gradient -> butonda background-color (#2563eb) YEDEĞİ var,
 *                        ayrıca <td bgcolor> ile ikinci kez garantiye alındı.
 *   * box-shadow      -> gölge yok, buton dolgusu ve rengi yerinde.
 *   * Inter/system-ui -> font yığınının sonundaki Arial'a düşer.
 * Bu yüzden hiçbir görsel bilgi YALNIZCA bu kurallara emanet edilmedi.
 *
 * @param string      $heading     Büyük başlık (düz metin, kaçırılır)
 * @param string      $introHtml   Başlığın altındaki paragraf(lar) — GÜVENLİ HTML
 *                                 beklenir; değişken içerik çağıran tarafından
 *                                 htmlspecialchars'lanmalıdır.
 * @param string|null $ctaText     Buton metni (null ise buton basılmaz)
 * @param string|null $ctaUrl      Buton hedefi
 * @param string|null $noteHtml    CTA altındaki küçük not
 * @param string|null $badgeText   Başlığın üstündeki rozet/pill (null ise basılmaz)
 * @param string|null $fallbackUrl Verilirse ham bağlantı, kopyalanabilir bir
 *                                 kutu içinde gösterilir (buton çalışmayan
 *                                 istemciler için)
 */
function bcc_mail_html_shell($heading, $introHtml, $ctaText = null, $ctaUrl = null, $noteHtml = null, $badgeText = null, $fallbackUrl = null)
{
    $logo = htmlspecialchars($GLOBALS['BCC_MAIL_LOGO_URL'], ENT_QUOTES, 'UTF-8');
    $site = htmlspecialchars($GLOBALS['BCC_MAIL_SITE_URL'], ENT_QUOTES, 'UTF-8');
    $siteLabel = htmlspecialchars(preg_replace('#^https?://#', '', $GLOBALS['BCC_MAIL_SITE_URL']), ENT_QUOTES, 'UTF-8');
    $mailTo = htmlspecialchars($GLOBALS['BCC_MAIL_CONTACT_EMAIL'], ENT_QUOTES, 'UTF-8');
    $wa = htmlspecialchars($GLOBALS['BCC_MAIL_WHATSAPP_URL'], ENT_QUOTES, 'UTF-8');
    $phone1 = htmlspecialchars($GLOBALS['BCC_MAIL_PHONE_1'], ENT_QUOTES, 'UTF-8');
    $phone2 = htmlspecialchars($GLOBALS['BCC_MAIL_PHONE_2'], ENT_QUOTES, 'UTF-8');
    $maps = htmlspecialchars($GLOBALS['BCC_MAIL_MAPS_URL'], ENT_QUOTES, 'UTF-8');
    $headingSafe = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    $font = BCC_MAIL_FONT;
    $ink = BCC_MAIL_C_INK;
    $body = BCC_MAIL_C_BODY;
    $muted = BCC_MAIL_C_MUTED;
    $faint = BCC_MAIL_C_FAINT;
    $accent = BCC_MAIL_C_ACCENT;
    $accentDark = BCC_MAIL_C_ACCENT_DARK;
    $line = BCC_MAIL_C_LINE;
    $panel = BCC_MAIL_C_PANEL;
    $page = BCC_MAIL_C_PAGE;

    // --- Rozet (pill) ------------------------------------------------------
    // <span> DEĞİL mini tablo: Outlook, satır içi bir span'in padding'ini
    // yok sayar ve rozet zemini metne yapışırdı. bgcolor ÖZNİTELİĞİ, inline
    // background kırpılsa bile zemini garanti eder.
    $badgeHtml = '';
    if ($badgeText !== null && $badgeText !== '') {
        $badgeSafe = htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8');
        $badgeHtml = <<<HTML
                            <tr>
                                <td style="padding: 0 0 14px;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td bgcolor="#eff6ff" style="padding: 6px 13px; background-color: #eff6ff; border-radius: 999px; font-family: {$font}; font-size: 11px; line-height: 14px; font-weight: 700; letter-spacing: 0.7px; text-transform: uppercase; color: {$accentDark};">{$badgeSafe}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
HTML;
    }

    // --- CTA butonu --------------------------------------------------------
    // <button> mailde çalışmaz; buton bir <a>. Gradyan Outlook'ta düşer, bu
    // yüzden ÖNCE background-color (düz #2563eb) yazılıyor, gradyan onun
    // ÜSTÜNE geliyor: destekleyen istemci gradyanı, desteklemeyen düz rengi
    // gösterir. Sarmalayan <td bgcolor> ise <a>'nın arka planını tamamen
    // kırpan istemciler için üçüncü katman.
    $ctaHtml = '';
    if ($ctaText !== null && $ctaUrl !== null) {
        $ctaUrlSafe = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
        $ctaTextSafe = htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8');
        $ctaHtml = <<<HTML
                            <tr>
                                <td style="padding: 26px 0 6px;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td bgcolor="{$accent}" style="background-color: {$accent}; background-image: linear-gradient(135deg, {$accent}, {$accentDark}); border-radius: 8px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                                                <a href="{$ctaUrlSafe}" style="display: inline-block; padding: 14px 28px; font-family: {$font}; font-size: 15px; font-weight: 600; line-height: 20px; color: #ffffff; text-decoration: none; border-radius: 8px;">{$ctaTextSafe}</a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
HTML;
    }

    // --- Ham bağlantı kutusu ----------------------------------------------
    // Buton bazı istemcilerde düz metne çevriliyor; adresin kendisi de
    // görünür olmalı. word-break: uzun token satırı yatay taşırmasın.
    $fallbackHtml = '';
    if ($fallbackUrl !== null && $fallbackUrl !== '') {
        $fallbackSafe = htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8');
        $fallbackHtml = <<<HTML
                            <tr>
                                <td style="padding: 18px 0 0;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td bgcolor="{$panel}" style="padding: 14px 16px; background-color: {$panel}; border: 1px solid {$line}; border-radius: 8px;">
                                                <div style="font-family: {$font}; font-size: 10px; line-height: 14px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase; color: {$faint}; padding-bottom: 6px;">Buton çalışmazsa bu adresi kopyalayın</div>
                                                <a href="{$fallbackSafe}" style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 12px; line-height: 19px; color: {$accentDark}; text-decoration: none; word-break: break-all;">{$fallbackSafe}</a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
HTML;
    }

    // Not satiri #94a3b8 DEGIL #64748b: beyaz zeminde #94a3b8 ~2.8:1 kontrast
    // veriyor (WCAG AA icin 4.5:1 gerekiyor) ve "kaydi siz yapmadiysaniz"
    // uyarisi okunmasi ONEMLI bir metin. #94a3b8 yalnizca telif satirinda
    // kaldi — orasi gercekten ikincil.
    $noteRow = '';
    if ($noteHtml !== null) {
        $noteRow = <<<HTML
                            <tr>
                                <td style="padding: 18px 0 0; font-family: {$font}; font-size: 13px; line-height: 21px; color: #64748b;">{$noteHtml}</td>
                            </tr>
HTML;
    }

    // --- İletişim ızgarası (2 sütun x 2 satır) -----------------------------
    // Kanallar gruplanmış: Web / Telefon / Destek / Adres. CSS grid mailde
    // yok — ızgara <td width="50%"> ile kuruluyor.
    $cellWeb = bcc_mail_contact_cell('web', 'Web Sitesi',
        '<a href="' . $site . '" style="font-family: ' . $font . '; font-size: 13px; line-height: 20px; color: ' . $accent . '; text-decoration: none;">' . $siteLabel . '</a>');
    $cellPhone = bcc_mail_contact_cell('phone', 'Telefon / WhatsApp',
        '<a href="' . $wa . '" style="font-family: ' . $font . '; font-size: 13px; line-height: 20px; color: ' . $accent . '; text-decoration: none;">' . $phone1 . '</a><br>'
        . '<a href="' . $wa . '" style="font-family: ' . $font . '; font-size: 13px; line-height: 20px; color: ' . $accent . '; text-decoration: none;">' . $phone2 . '</a>');
    $cellMail = bcc_mail_contact_cell('mail', 'Destek',
        '<a href="mailto:' . $mailTo . '" style="font-family: ' . $font . '; font-size: 13px; line-height: 20px; color: ' . $accent . '; text-decoration: none;">' . $mailTo . '</a>');
    $cellMap = bcc_mail_contact_cell('map', 'Adres',
        '<a href="' . $maps . '" style="font-family: ' . $font . '; font-size: 13px; line-height: 20px; color: ' . $accent . '; text-decoration: none;">Haritada göster</a>');

    return <<<HTML
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{$headingSafe}</title>
</head>
<body style="margin: 0; padding: 0; background-color: {$page}; -webkit-font-smoothing: antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: {$page};">
    <tr>
        <!-- Kart boslugu (30px) PADDING olarak veriliyor: Outlook <table>
             uzerindeki margin'i yok sayar, padding'e her zaman uyar. -->
        <td align="center" style="padding: 30px 12px;">

            <!--[if mso]>
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td>
            <![endif]-->
            <!-- AKISKAN GENISLIK: kart width="600" DEGIL width="100%" +
                 max-width 600px. Sabit 600px'te dar ekranlarda mail YATAY
                 KAYIYORDU (400px pencerede 624px icerik). Outlook max-width'i
                 yok saydigi icin yukaridaki [if mso] "hayalet tablo" orada
                 sabit 600px'i geri veriyor — iki dunyada da dogru. -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid {$line}; border-radius: 12px; overflow: hidden; font-family: {$font};">

                <tr>
                    <td align="center" bgcolor="{$panel}" style="padding: 28px 32px; background-color: {$panel}; border-top: 4px solid {$accent};">
                        <a href="{$site}"><img src="{$logo}" width="94" height="44" alt="bcc İletişim" style="display: block; border: 0; outline: none; text-decoration: none;"></a>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 32px; border-top: 1px solid {$line};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
{$badgeHtml}
                            <tr>
                                <td style="padding: 0 0 12px; font-family: {$font}; font-size: 22px; line-height: 30px; font-weight: 700; letter-spacing: -0.2px; color: {$ink};">{$headingSafe}</td>
                            </tr>
                            <tr>
                                <td style="font-family: {$font}; font-size: 15px; line-height: 24px; color: {$body};">{$introHtml}</td>
                            </tr>
{$ctaHtml}
{$fallbackHtml}
{$noteRow}
                        </table>
                    </td>
                </tr>

                <tr>
                    <td bgcolor="{$panel}" style="padding: 24px 32px 20px; background-color: {$panel}; border-top: 1px solid {$line};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding: 0 0 16px; font-family: {$font}; font-size: 14px; line-height: 20px; font-weight: 700; color: {$ink};">bcc İletişim Hizmetleri A.Ş.</td>
                            </tr>
                            <tr>
                                <td>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td width="50%" valign="top" style="width: 50%; padding: 0 10px 16px 0;">
                                        {$cellWeb}
                                            </td>
                                            <td width="50%" valign="top" style="width: 50%; padding: 0 0 16px 10px;">
                                        {$cellPhone}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td width="50%" valign="top" style="width: 50%; padding: 0 10px 0 0;">
                                        {$cellMail}
                                            </td>
                                            <td width="50%" valign="top" style="width: 50%; padding: 0 0 0 10px;">
                                        {$cellMap}
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td bgcolor="{$panel}" style="padding: 14px 32px 20px; background-color: {$panel}; border-top: 1px solid {$line}; font-family: {$font}; font-size: 11px; line-height: 17px; color: {$faint};">&copy; {$year} bcc İletişim Hizmetleri A.Ş. Bu e-posta BCC-Core hesap işlemleri için gönderilmiştir.</td>
                </tr>

            </table>
            <!--[if mso]>
            </td></tr></table>
            <![endif]-->

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
    // Kanallar HTML ızgarasıyla AYNI sırada ve aynı etiketlerle gruplanıyor
    // (Web / Telefon / Destek / Adres) — iki parça yan yana okunduğunda
    // birbirinin karşılığı olduğu görünsün.
    return "\n\n--\n"
        . "bcc İletişim Hizmetleri A.Ş.\n"
        . 'Web sitesi: ' . $GLOBALS['BCC_MAIL_SITE_URL'] . "\n"
        . 'Telefon / WhatsApp: ' . $GLOBALS['BCC_MAIL_PHONE_1'] . ' · ' . $GLOBALS['BCC_MAIL_PHONE_2']
        . ' (' . $GLOBALS['BCC_MAIL_WHATSAPP_URL'] . ")\n"
        . 'Destek: ' . $GLOBALS['BCC_MAIL_CONTACT_EMAIL'] . "\n"
        . 'Adres: ' . $GLOBALS['BCC_MAIL_MAPS_URL'] . "\n";
}
