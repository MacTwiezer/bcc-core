<?php
// HERKESE AÇIK form doldurma sayfası (Grup View-Form).
//
// ⚠️⚠️ BU, PROJEDEKİ TEK KİMLİK DOĞRULAMASIZ SAYFA. require_login() YOK,
// require_team_access() YOK, rol kontrolü YOK — bilerek. Erişim tek bir şeye
// dayanır: URL'deki tahmin edilemez form_token (migrations/015). Bu yüzden:
//   * Tabloya/base'e/ekibe dair HİÇBİR meta veri sızdırılmaz (tablo adı, alan
//     sayısı, diğer görünümler, kayıt sayısı — hiçbiri basılmaz). Ekranda
//     yalnızca tasarımcının AÇIKÇA yazdığı başlık/açıklama ve SEÇTİĞİ alanlar
//     görünür.
//   * Geçersiz/kapalı token'da 404 döner ve AYRIMSIZ aynı metni gösterir —
//     "token yanlış" ile "form kapalı" ayrımı bir saldırgana bilgi verirdi.
//   * Bu sayfa hiçbir mutasyon YAPMAZ; gönderim /api/form_submit.php'ye gider.
//
// Tasarımcı (oturumlu) tarafı AYRI dosyada: form_edit.php.

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/form_security.php';

$token = isset($_GET['t']) ? (string) $_GET['t'] : '';

// Token biçimi önce ucuzca elenir: 32 hex dışında hiçbir şey DB'ye gitmez.
$view = null;
if (preg_match('/^[0-9a-f]{32}$/', $token)) {
    // form_enabled = 1 ŞART (migrations/015'te söz verilen üç okuma noktasından
    // BİRİNCİSİ) — kapalı form hiç render edilmez.
    $view = bcc_fetch_one(
        "SELECT v.id, v.name, v.config, v.form_enabled, v.table_id, t.name AS table_name
         FROM views v
         INNER JOIN tables_meta t ON t.id = v.table_id
         WHERE v.form_token = :token AND v.view_type = 'form' AND v.form_enabled = 1
         LIMIT 1",
        array(':token' => $token)
    );
}

if (!$view) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;

    $formConfig = bcc_form_config_from_view($view);

    $allFields = bcc_fetch_all(
        'SELECT id, name, field_type, options, is_required FROM fields WHERE table_id = :tid ORDER BY position, id',
        array(':tid' => $view['table_id'])
    );
    $fieldsById = array();
    foreach ($allFields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }

    // GÖRÜNECEK ALANLAR = tasarımcının seçtiği sıra ∩ tip filtresi.
    // İki katman birden uygulanır: config elle kurcalanıp salt-okunur ya da
    // attachment/long_text bir alan eklenmiş olsa bile bcc_field_allowed_in_form()
    // onu eler. (form_submit.php AYNI iki katmanı tekrar uygular — istemciye
    // gönderilen listeye güvenilmez.)
    $formFields = array();
    foreach ($formConfig['form_fields'] as $fid) {
        if (isset($fieldsById[$fid]) && bcc_field_allowed_in_form($fieldsById[$fid]['field_type'])) {
            $formFields[] = $fieldsById[$fid];
        }
    }

    $formTitle = $formConfig['form_title'] !== '' ? $formConfig['form_title'] : $view['table_name'];
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php /* Herkese açık bir sayfa: arama motorlarına girmesin. */ ?>
<meta name="robots" content="noindex, nofollow">
<title><?php echo $notFound ? 'Form bulunamadı' : htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('login.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('public-form.css'); ?>">
</head>
<body class="login-page">
<div class="login-card public-form-card">
    <div class="login-card-body">
    <?php if ($notFound): ?>
        <h1 class="login-title">Form bulunamadı</h1>
        <?php /* Kasıtlı olarak AYRIMSIZ: "token yanlış" mı "form kapalı" mı
                 söylenmez — ikisini ayırmak saldırgana geçerli token'ları
                 elemede bilgi verirdi. */ ?>
        <p class="public-form-note">Bu bağlantı geçersiz veya form kapatılmış olabilir.</p>
    <?php else: ?>
        <h1 class="login-title"><?php echo htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8'); ?></h1>

        <?php if ($formConfig['form_description'] !== ''): ?>
            <?php /* nl2br + htmlspecialchars SIRASI önemli: önce kaçır, sonra
                     <br> ekle. Ters sırada kullanıcı HTML'i geçerdi. */ ?>
            <p class="public-form-desc"><?php echo nl2br(htmlspecialchars($formConfig['form_description'], ENT_QUOTES, 'UTF-8')); ?></p>
        <?php endif; ?>

        <?php if (empty($formFields)): ?>
            <p class="public-form-note">Bu form henüz yapılandırılmamış.</p>
        <?php else: ?>
        <div class="public-form-message" id="form-message" hidden></div>

        <form id="public-form" class="public-form" novalidate>
            <input type="hidden" name="t" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

            <?php /* KATMAN 2 — zaman-bazlı HMAC nonce. Durumsuz: sunucuda hiçbir
                     şey saklanmaz, bu yüzden anonim ziyaretçi için oturum
                     AÇILMAZ. Sayfa render zamanını imzalı taşır; form_submit.php
                     hem imzayı hem "3 saniyeden hızlı gönderilmiş mi"yi kontrol
                     eder (bkz. src/form_security.php). */ ?>
            <input type="hidden" name="nonce" value="<?php echo htmlspecialchars(bcc_form_nonce(), ENT_QUOTES, 'UTF-8'); ?>">

            <?php /* KATMAN 1 — honeypot. İnsan GÖRMEZ (CSS + aria-hidden +
                     tabindex=-1 + autocomplete=off), bot doldurur. Dolu gelirse
                     form_submit.php BAŞARILI yanıt döner ama KAYDETMEZ — bot'a
                     "yakalandın" geri bildirimi vermemek kasıtlı. */ ?>
            <div class="public-form-hp" aria-hidden="true">
                <label for="<?php echo BCC_FORM_HONEYPOT_FIELD; ?>">Bu alanı boş bırakın</label>
                <input type="text" id="<?php echo BCC_FORM_HONEYPOT_FIELD; ?>"
                       name="<?php echo BCC_FORM_HONEYPOT_FIELD; ?>"
                       tabindex="-1" autocomplete="off">
            </div>

            <?php foreach ($formFields as $f):
                $fid = (int) $f['id'];
                $inputName = 'f' . $fid;
                $isRequired = ((int) $f['is_required'] === 1);
                $choices = select_choices_from_options($f['options']);
                $fieldOptions = json_decode((string) $f['options'], true);
                $fieldOptions = is_array($fieldOptions) ? $fieldOptions : array();
            ?>
                <div class="public-form-field">
                    <label for="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($isRequired): ?><span class="req-mark">*</span><?php endif; ?>
                    </label>

                    <?php
                    // Girdi tipi eşlemesi — grid.js'in buildInput()'uyla AYNI
                    // mantık, ama burada sunucu tarafında ve YALNIZCA forma
                    // konulabilen tipler için (attachment/long_text zaten elendi).
                    $t = $f['field_type'];
                    if ($t === 'single_select'):
                    ?>
                        <select id="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="">— seçin —</option>
                            <?php foreach ($choices as $c): ?>
                                <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($t === 'multiple_select'): ?>
                        <select id="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>" multiple size="<?php echo min(6, max(3, count($choices))); ?>">
                            <?php foreach ($choices as $c): ?>
                                <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($t === 'checkbox'): ?>
                        <input type="checkbox" id="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>" value="1">
                    <?php elseif ($t === 'rating'):
                        $maxRating = isset($fieldOptions['max_rating']) ? (int) $fieldOptions['max_rating'] : 5;
                    ?>
                        <select id="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="">—</option>
                            <?php for ($i = 1; $i <= $maxRating; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo str_repeat('★', $i); ?></option>
                            <?php endfor; ?>
                        </select>
                    <?php else:
                        // number/currency/percent -> number; date -> date; time -> time;
                        // url/email/phone -> kendi native tipleri (mobil klavye);
                        // geri kalan (single_line_text) -> text.
                        // 'user' buraya HİÇ düşmez — bcc_field_allowed_in_form()
                        // onu KVKK gerekçesiyle eliyor (ekip üyesi listesi anonim
                        // birine sızdırılamaz).
                        $inputType = 'text';
                        if ($t === 'number' || $t === 'currency' || $t === 'percent') {
                            $inputType = 'number';
                        } elseif ($t === 'date') {
                            $inputType = 'date';
                        } elseif ($t === 'time') {
                            $inputType = 'time';
                        } elseif ($t === 'url') {
                            $inputType = 'url';
                        } elseif ($t === 'email') {
                            $inputType = 'email';
                        } elseif ($t === 'phone') {
                            $inputType = 'tel';
                        }
                    ?>
                        <input
                            type="<?php echo $inputType; ?>"
                            id="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>"
                            name="<?php echo htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $inputType === 'number' ? 'step="any"' : ''; ?>
                        >
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="login-btn" id="form-submit-btn">Gönder</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
<?php if (!$notFound && !empty($formFields)): ?>
<script src="<?php echo bcc_asset_url('public-form.js'); ?>" defer></script>
<?php endif; ?>
</body>
</html>
