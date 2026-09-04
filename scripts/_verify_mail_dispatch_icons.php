<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/app.php';
require __DIR__ . '/../src/mailer.php';
require __DIR__ . '/../vendor/autoload.php';

$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

function code_without_comments($path)
{
    $code = '';
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }

    return $code;
}

$registerCode = code_without_comments(__DIR__ . '/../public/register.php');

echo "--- A) Mukerrer gonderim kapisi ---\n";

$now = 1700000000;
$ttl = 86400;
$cooldown = 120;
$at = function ($secondsAgo) use ($now, $ttl) {
    return date('Y-m-d H:i:s', $now - $secondsAgo + $ttl);
};

$cases = array(

    'hic token yok (NULL) -> GONDER'            => array(null, true),
    'bos damga -> GONDER'                       => array('', true),
    'okunamayan damga -> GONDER (kilitlenme)'   => array('bozuk-tarih', true),
    '0 sn once gonderildi -> ATLA'              => array($at(0), false),
    '1 sn once (cift tiklama) -> ATLA'          => array($at(1), false),
    '119 sn once -> ATLA'                       => array($at(119), false),
    '120 sn once (sinir) -> GONDER'             => array($at(120), true),
    '1 saat once -> GONDER'                     => array($at(3600), true),
);
foreach ($cases as $label => $case) {
    list($expires, $expected) = $case;
    $actual = bcc_should_send_verification_mail($expires, $cooldown, $now, $ttl);
    check('A) ' . $label, $actual === $expected, 'donen: ' . var_export($actual, true));
}

$sendCalls = preg_match_all('/bcc_send_mail\s*\(/', $registerCode);
check('A) register.php TEK bcc_send_mail cagrisi iceriyor', $sendCalls === 1, $sendCalls . ' cagri');
check('A) gonderim $skipVerificationMail kapisiyla sarili',
    strpos($registerCode, 'if(!$skipVerificationMail){bcc_send_mail(') !== false
    || preg_match('/if\s*\(\s*!\s*\$skipVerificationMail\s*\)\s*\{\s*bcc_send_mail\s*\(/', $registerCode) === 1,
    'kapi bulunamadi');
check('A) kapi karari bcc_should_send_verification_mail() ile veriliyor',
    strpos($registerCode, 'bcc_should_send_verification_mail(') !== false);
check('A) atlama dalinda token/son-kullanma GUNCELLENMIYOR (eldeki link gecerli kalir)',
    preg_match('/\$skipVerificationMail\s*=\s*true;\s*bcc_execute\(\s*\'UPDATE users SET full_name = :full_name WHERE id = :id\'/', $registerCode) === 1);
check('A) POST sonrasi yonlendirme var (yenilemede form tekrar POST edilmez)',
    strpos($registerCode, "header('Location: /login.php?registered=1');") !== false
    && preg_match("/header\('Location: \/login\.php\?registered=1'\);\s*exit;/", $registerCode) === 1);
check('A) istemci tarafi cift-submit kilidi duruyor (UX katmani)',
    strpos(file_get_contents(__DIR__ . '/../public/register.php'), 'data-once-submit') !== false);

check('A) kapinin TTL varsayilani register.php token omruyle ayni (86400)',
    strpos($registerCode, "time() + 86400") !== false
    && bcc_should_send_verification_mail(date('Y-m-d H:i:s', $now + 86400), 120, $now) === false,
    'TTL uyusmuyor');

echo "\n--- B) Footer ikonlari ---\n";

$fallbackUrl = 'https://ornek.test/verify_email.php?token=abc';
$html = bcc_mail_html_shell(
    'Hesabınızı etkinleştirin',
    '<p style="margin: 0;">Gövde</p>',
    'Hesabımı Etkinleştir',
    $fallbackUrl,
    'Not',
    'Hesap Doğrulama',
    $fallbackUrl
);
$text = "Merhaba,\n" . bcc_mail_text_footer();

foreach ($GLOBALS['BCC_MAIL_ICONS'] as $key => $icon) {
    $path = bcc_mail_icons_dir() . '/' . $icon['file'];
    $size = is_file($path) ? getimagesize($path) : false;
    check('B) ' . $key . ' ikonu 36x36 gercek PNG',
        $size !== false && $size['mime'] === 'image/png' && $size[0] === 36 && $size[1] === 36,
        is_file($path) ? var_export($size, true) : 'dosya yok: ' . $path);
}

foreach ($GLOBALS['BCC_MAIL_ICONS'] as $key => $icon) {
    $tag = bcc_mail_icon_img($key);

    check('B) ' . $key . ' <img> cid + 14x14 OZNITELIK + inline olcu',
        strpos($tag, 'src="cid:' . $icon['cid'] . '"') !== false
        && strpos($tag, 'width="14" height="14"') !== false
        && strpos($tag, 'width: 14px; height: 14px;') !== false,
        $tag);
    check('B) ' . $key . ' display:block (etiket satiriyla hizali) + kenarliksiz',
        strpos($tag, 'display: block') !== false && strpos($tag, 'border: 0') !== false,
        $tag);
    check('B) ' . $key . ' alt metni var (gorsel engellenirse bile anlam kaybolmaz)',
        preg_match('/alt="[^"]+"/', $tag) === 1, $tag);
    check('B) ' . $key . ' ikonu gonderilen gövdede kullanilıyor',
        substr_count($html, 'cid:' . $icon['cid']) === 1,
        substr_count($html, 'cid:' . $icon['cid']) . ' kez');
}

$rows = array(
    'web'   => '/cid:bcc-icon-web(?:(?!cid:).)*?<a href="https:\/\/bcciletisim\.com\.tr"/s',
    'phone' => '/cid:bcc-icon-phone(?:(?!cid:).)*?<a href="https:\/\/wa\.me\/902162100707"/s',
    'mail'  => '/cid:bcc-icon-mail(?:(?!cid:).)*?<a href="mailto:info@bcciletisim\.com\.tr"/s',
    'map'   => '/cid:bcc-icon-map(?:(?!cid:).)*?>Haritada göster<\/a>/su',
);
foreach ($rows as $key => $pattern) {
    check('B) ' . $key . ' ikonu KENDI kanal hucresinde', preg_match($pattern, $html) === 1);
}

foreach (array('Web Sitesi', 'Telefon / WhatsApp', 'Destek', 'Adres') as $label) {
    check('B) kanal etiketi: ' . $label, strpos($html, '>' . $label . '</div>') !== false);
}

$emoji = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{2190}-\x{21FF}\x{2700}-\x{27BF}]/u';
check('B) HTML gövdede emoji YOK', preg_match($emoji, $html) === 0, 'emoji bulundu');
check('B) duz metin gövdede emoji YOK', preg_match($emoji, $text) === 0, 'emoji bulundu');

$tplLive = preg_replace('#/\*.*?\*/#s', '', file_get_contents(__DIR__ . '/../src/mail_template.php'));
$tplLive = preg_replace('#^\s*//.*$#m', '', $tplLive);
check('B) sablonun CALISAN kodunda emoji YOK (yorumlar haric)',
    preg_match($emoji, $tplLive) === 0, 'emoji bulundu');

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$mail->CharSet = 'UTF-8';
$mail->setFrom('no-reply@ornek.test', 'BCC İletişim');
$mail->addAddress('alici@ornek.test');
$mail->Subject = 'opsflow.bcccrm.com hesabınızı etkinleştirin';
$mail->isHTML(true);
$mail->Body = $html;
$mail->AltBody = $text;
bcc_mail_attach_footer_icons($mail, $html);

$built = false;
try {
    $built = $mail->preSend();
} catch (Throwable $e) {
    check('B) MIME kuruldu', false, $e->getMessage());
}
if ($built) {
    check('B) MIME kuruldu (gonderim YAPILMADI)', true);
    $mime = $mail->getSentMIMEMessage();

    check('B) multipart/related (gomulu gorsel tasiyan yapi)',
        stripos($mime, 'multipart/related') !== false);
    check('B) text/plain parcasi var (sadece-HTML degil)',
        stripos($mime, 'Content-Type: text/plain') !== false);

    foreach ($GLOBALS['BCC_MAIL_ICONS'] as $key => $icon) {
        $count = substr_count($mime, '<' . $icon['cid'] . '>');
        check('B) ' . $key . ' MIME icinde TEK Content-ID olarak gomulu', $count === 1, $count . ' kez');
    }
    check('B) MIME 5 adet image/png parcasi tasiyor (4 ikon + logo)',
        substr_count($mime, 'image/png') === 5,
        substr_count($mime, 'image/png') . ' adet');
    check('B) mail disari HIC HTTP istegi ACMIYOR (logo dahil hepsi gomulu)',
        preg_match('/<img[^>]+src="https?:/i', $html) === 0,
        'beklenen: sifir uzak resim');
}

$plainMail = new PHPMailer\PHPMailer\PHPMailer(true);
$plainMail->setFrom('no-reply@ornek.test', 'BCC');
$plainMail->addAddress('alici@ornek.test');
$plainMail->Subject = 'Ikonsuz';
$plainMail->isHTML(true);
$plainMail->Body = '<p>Ikon yok</p>';
bcc_mail_attach_footer_icons($plainMail, '<p>Ikon yok</p>');
check('B) ikonsuz gövdeye ek EKLENMIYOR', count($plainMail->getAttachments()) === 0,
    count($plainMail->getAttachments()) . ' ek');

echo "\n--- D) Tasarim belirteci ---\n";

$design = array(
    'kart: max-width 600px'          => 'max-width: 600px',
    'kart: akiskan genislik'         => 'width: 100%; max-width: 600px',
    'kart: Outlook hayalet tablosu'  => '<!--[if mso]>',
    'kart: border-radius 12px'       => 'border-radius: 12px',
    'kart: 1px #e2e8f0 kenarlik'     => 'border: 1px solid #e2e8f0',
    'kart: overflow hidden'          => 'overflow: hidden',
    'kart: beyaz zemin'              => 'background-color: #ffffff',
    'font: Inter + system-ui yigini' => "'Inter', system-ui, -apple-system",
    'ust serit: 4px #2563eb'         => 'border-top: 4px solid #2563eb',
    'ust serit: 28px 32px dolgu'     => 'padding: 28px 32px',
    'ust serit: #f8fafc zemin'       => 'background-color: #f8fafc',
    'baslik: 22px'                   => 'font-size: 22px',
    'baslik: #0f172a'                => 'color: #0f172a',
    'CTA: gradient'                  => 'linear-gradient(135deg, #2563eb, #1d4ed8)',
    'CTA: gradient YEDEGI (duz renk)' => 'background-color: #2563eb',
    'CTA: 14px 28px dolgu'           => 'padding: 14px 28px',
    'CTA: radius 8px'                => 'border-radius: 8px',
    'CTA: font-weight 600'           => 'font-weight: 600',
    'CTA: font-size 15px'            => 'font-size: 15px',
    'CTA: box-shadow'                => 'box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25)',
    'CTA: inline-block'              => 'display: inline-block',
    'kutu: word-break (uzun token)'  => 'word-break: break-all',
    'kutu: monospace'                => 'monospace',
    'footer: #f8fafc panel'          => 'background-color: #f8fafc',
    'footer: metin #475569'          => 'color: #475569',
    'footer: ayirici ust cizgi'      => 'border-top: 1px solid #e2e8f0',
    'telif: 11px'                    => 'font-size: 11px',
    'telif: #94a3b8'                 => 'color: #94a3b8',
);
foreach ($design as $label => $needle) {
    check('D) ' . $label, strpos($html, $needle) !== false, $needle);
}

check('D) rozet/pill basildi',
    strpos($html, 'Hesap Doğrulama') !== false && strpos($html, 'border-radius: 999px') !== false);
check('D) rozet zemini bgcolor OZNITELIGIYLE de veriliyor (Outlook)',
    strpos($html, 'bgcolor="#eff6ff"') !== false);
check('D) rozet YOKSA basilmiyor (opsiyonel parametre)',
    strpos(bcc_mail_html_shell('B', '<p>x</p>'), 'border-radius: 999px') === false);

check('D) iletisim izgarasi 2 sutun (4 hucre)',
    substr_count($html, 'width="50%"') === 4,
    substr_count($html, 'width="50%"') . ' hucre');

check('D) CTA zemini <td bgcolor> ile de garantilenmis',
    preg_match('/<td bgcolor="#2563eb"[^>]*linear-gradient/', $html) === 1);

check('D) ham baglanti kopyalama kutusunda (gorunur metin olarak da)',
    strpos($html, 'Buton çalışmazsa bu adresi kopyalayın') !== false
    && substr_count($html, htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8')) === 3
    && preg_match('/>' . preg_quote(htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8'), '/') . '<\/a>/', $html) === 1,
    substr_count($html, htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8')) . ' kez (3 bekleniyor)');
check('D) $fallbackUrl YOKSA kutu basilmiyor',
    strpos(bcc_mail_html_shell('B', '<p>x</p>'), 'Buton çalışmazsa') === false);

check('D) harici <style> / <link> YOK (her kural inline)',
    stripos($html, '<style') === false && stripos($html, '<link') === false);
check('D) tablo tabanli iskelet korundu',
    substr_count($html, '<table role="presentation"') >= 6,
    substr_count($html, '<table role="presentation"') . ' tablo');

$evil = bcc_mail_html_shell('<script>x</script>', '<p>ok</p>', null, null, null, '<b>rozet</b>', 'https://x.test/?a=1&b=2');
check('D) $heading kaciriliyor', strpos($evil, '&lt;script&gt;') !== false && strpos($evil, '<script>x') === false);
check('D) $badgeText kaciriliyor', strpos($evil, '&lt;b&gt;rozet&lt;/b&gt;') !== false);
check('D) $fallbackUrl kaciriliyor', strpos($evil, 'a=1&amp;b=2') !== false);

if (in_array('--db', $argv, true)) {
    echo "\n--- C) Kapinin DB turu ---\n";
    require_once __DIR__ . '/../config/database.php';

    preg_match("/define\('BCC_REGISTER_RESEND_COOLDOWN',\s*(\d+)\)/", $registerCode, $cm);
    check('C) bekleme suresi register.php den okundu', !empty($cm), 'sabit bulunamadi');
    $cooldownProd = !empty($cm) ? (int) $cm[1] : 120;
    echo '        > BCC_REGISTER_RESEND_COOLDOWN = ' . $cooldownProd . " sn\n";

    $testEmail = 'test.register.dedupe@bcc.local';
    $pre = bcc_fetch_one('SELECT id FROM users WHERE email = :email LIMIT 1', array('email' => $testEmail));

    if ($pre) {
        check('C) atilabilir test satiri acilabildi', false,
            $testEmail . ' ZATEN VAR (id=' . $pre['id'] . ') — DOKUNULMADI, test atlandi');
    } else {
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        bcc_execute(
            'INSERT INTO users (email, password_hash, full_name, is_admin, is_active, email_verify_token, email_verify_expires_at)
             VALUES (:email, :hash, :full_name, 0, 0, :token, :expires)',
            array(
                'email' => $testEmail,
                'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'full_name' => 'Dedupe Testi',
                'token' => bin2hex(random_bytes(32)),
                'expires' => $expiresAt,
            )
        );
        $testId = bcc_last_insert_id();

        try {
            $row = bcc_fetch_one(
                'SELECT id, is_active, email_verify_expires_at FROM users WHERE email = :email LIMIT 1',
                array('email' => $testEmail)
            );
            check('C) DB den okunan damga strtotime ile cozulebiliyor',
                $row && strtotime($row['email_verify_expires_at']) !== false,
                $row ? var_export($row['email_verify_expires_at'], true) : 'satir yok');
            check('C) az once kaydolan adrese IKINCI mail ATLANIYOR',
                bcc_should_send_verification_mail($row['email_verify_expires_at'], $cooldownProd) === false);

            bcc_execute(
                'UPDATE users SET email_verify_expires_at = :expires WHERE id = :id',
                array('expires' => date('Y-m-d H:i:s', time() - 3600 + 86400), 'id' => $testId)
            );
            $row2 = bcc_fetch_one(
                'SELECT email_verify_expires_at FROM users WHERE id = :id LIMIT 1',
                array('id' => $testId)
            );
            check('C) 1 saat once gonderilmis token -> yeniden gonderime IZIN',
                bcc_should_send_verification_mail($row2['email_verify_expires_at'], $cooldownProd) === true);
        } catch (Throwable $e) {
            check('C) DB turu hatasiz calisti', false, $e->getMessage());
        }

        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $testId));
        $left = bcc_fetch_one('SELECT id FROM users WHERE id = :id LIMIT 1', array('id' => $testId));
        check('C) test satiri temizlendi (DB de iz birakilmadi)', $left === false || $left === null,
            'kalan: ' . var_export($left, true));
    }
}

$previewPath = __DIR__ . '/../storage/mail/_onizleme_ikonlar.html';
if (!is_dir(dirname($previewPath))) {
    mkdir(dirname($previewPath), 0775, true);
}

$preview = $html;
$preview = str_replace('cid:' . $GLOBALS['BCC_MAIL_LOGO_CID'], '../../public/assets/logo.png', $preview);
foreach ($GLOBALS['BCC_MAIL_ICONS'] as $icon) {
    $preview = str_replace('cid:' . $icon['cid'], '../../public/assets/mail/' . $icon['file'], $preview);
}
file_put_contents($previewPath, $preview);
echo "\nHTML onizleme (cid -> yerel yol): " . realpath($previewPath) . "\n";

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
