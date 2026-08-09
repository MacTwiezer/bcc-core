<?php
// Ayar sayfalari (table_fields.php + base_tables.php) UI yenilemesi —
// KAPSAM KORUMASI testi.
//
// Bu isin asil riski gorsel degil YAYILMA riskiydi: bu sayfalar
// .settings-card / .settings-table / .settings-btn / .settings-breadcrumb
// sinifllarini SEKIZ baska sayfayla paylasiyor (admin/index, admin/create_user,
// admin/create_team, admin/assign_team, bases, form_edit, kanban,
// slack_settings); alan tipi grid'i ise src/partials/field_type_wizard_fields.php'den
// gelip grid.php'nin "+" POPUP'IYLA paylasiliyor. Yeni kurallarin bir gun
// home.css'e/theme.css'e tasinmasi o sayfalari sessizce yeniden tasarlardi.
// Bu betik tam olarak bunu engelliyor.
//
// Calistirma: C:\php73\php.exe scripts\_verify_settings_pages_ui.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

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

$root = __DIR__ . '/..';
$spCss = file_get_contents($root . '/public/assets/settings-page.css');
$tfCss = file_get_contents($root . '/public/assets/table-fields.css');
$acCss = file_get_contents($root . '/public/assets/account.css');
$acPage = file_get_contents($root . '/public/account.php');
$acJs = file_get_contents($root . '/public/assets/account-page.js');
$tfJs = file_get_contents($root . '/public/assets/table-fields.js');
$fieldsPage = file_get_contents($root . '/public/table_fields.php');
$basePage = file_get_contents($root . '/public/base_tables.php');
$homeCss = file_get_contents($root . '/public/assets/home.css');
$themeCss = file_get_contents($root . '/public/assets/theme.css');
$partial = file_get_contents($root . '/src/partials/field_type_wizard_fields.php');
$wizardJs = file_get_contents($root . '/public/assets/field-type-wizard.js');
$shellTop = file_get_contents($root . '/src/partials/home_shell_top.php');

// CSS yorumlarini soy: dosya basliklari acikca ".settings-card" gibi sinif
// adlarini ANIYOR; ham metinde aramak yanlis sonuc verirdi (bu projede iki kez
// yasanan ders).
function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

// PHP yorumlarini soyar. GEREKLI: account.php'nin yorumlari, hangi widget'larin
// BILEREK YAPILMADIGINI ("iki faktorlu dogrulama ... YOK", "API anahtarlari ...
// YOK") ve hangi bug'in duzeltildigini ($user['email_verify_token']) ACIKCA
// ANLATIYOR. Ham metinde aramak, aciklayici yorumu gercek bir kullanim sanip
// yanlis KALDI verir — bu projede UCUNCU kez ayni tuzak (grid-export.css @media
// ve mail.local.php gmail.com vakalari).
function php_code_only($path)
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
    // HTML yorumlari (<!-- ... -->) T_INLINE_HTML icinde kalir, onlar da cikar.
    return preg_replace('/<!--.*?-->/s', '', $code);
}

$spRules = css_rules($spCss);
$tfRules = css_rules($tfCss);
$acRules = css_rules($acCss);

// =====================================================================
echo "--- A) Kapsam: her kural .sp-page altinda mi ---\n";
function unscoped_selectors($rules)
{
    preg_match_all('/(^|\})\s*([^{}@]+)\{/m', $rules, $m);
    $bad = array();
    foreach ($m[2] as $selectorList) {
        foreach (explode(',', $selectorList) as $sel) {
            $sel = trim($sel);
            if ($sel === '' || strpos($sel, '.sp-page') === 0) {
                continue;
            }
            $bad[] = $sel;
        }
    }
    return $bad;
}

foreach (array('settings-page.css' => $spRules, 'table-fields.css' => $tfRules, 'account.css' => $acRules) as $name => $rules) {
    $bad = unscoped_selectors($rules);
    check("A) {$name}: TUM selector'lar .sp-page ile basliyor", empty($bad),
        implode(' | ', array_slice($bad, 0, 5)));
}

check('A) table_fields.php .sp-page sarmalayicisi aciyor',
    substr_count($fieldsPage, '<div class="sp-page">') === 1);
check('A) base_tables.php .sp-page sarmalayicisi aciyor',
    substr_count($basePage, '<div class="sp-page">') === 1);
check('A) table_fields.php ORTAK + sayfaya ozel CSS bagliyor',
    strpos($fieldsPage, "array('settings-page.css', 'table-fields.css')") !== false);
check('A) base_tables.php YALNIZCA ortak CSS bagliyor',
    strpos($basePage, "array('settings-page.css')") !== false
    && strpos($basePage, 'table-fields.css') === false);

// =====================================================================
echo "\n--- B) ORTAK iskelet KOPYALANMAMIS ---\n";
// base_tables.php'nin ihtiyac duydugu her sey settings-page.css'te olmali;
// table-fields.css yalnizca ALAN TIPI kavramina ait olanlari tutmali.
foreach (array('.sp-icon-btn', '.sp-count', '.sp-primary-name', '.sp-move-group', '.settings-card', '.settings-table') as $shared) {
    check("B) '{$shared}' ortak dosyada tanimli", strpos($spRules, $shared) !== false);
    check("B) '{$shared}' table-fields.css'te TEKRARLANMIYOR", strpos($tfRules, $shared) === false);
}
foreach (array('.tf-type-pill', '.tf-required-yes', '.tf-type-search') as $specific) {
    check("B) '{$specific}' YALNIZCA sayfaya ozel dosyada",
        strpos($tfRules, $specific) !== false && strpos($spRules, $specific) === false);
}

// =====================================================================
echo "\n--- C) Paylasilan dosyalara SIZMA olmamis ---\n";
foreach (array('sp-page', 'sp-icon-btn', 'sp-count', 'tf-type-pill') as $needle) {
    check("C) home.css '{$needle}' ICERMIYOR", strpos($homeCss, $needle) === false);
}
check('C) theme.css sp-*/tf-* sinifi ICERMIYOR',
    strpos($themeCss, '.sp-') === false && strpos($themeCss, '.tf-') === false);
check('C) paylasilan partial DEGISMEDI (arama kutusu oraya girmemis)',
    strpos($partial, 'tf-type-search') === false && strpos($partial, 'type="search"') === false);
check('C) paylasilan field-type-wizard.js DEGISMEDI',
    strpos($wizardJs, 'tf-') === false && strpos($wizardJs, 'sp-') === false);
// theme.css'teki taban tip-karti olculeri KORUNMALI (grid.php popup'i bunlari
// kullaniyor); /browse ile popup uzerinde de olculdu.
check('C) theme.css .field-type-option taban stili korunuyor (grid popup)',
    preg_match('/\.field-type-option \{[^}]*border-radius: 6px;[^}]*padding: 0\.5rem 0\.7rem;/s', $themeCss) === 1);
check('C) theme.css .field-type-grid taban minmax(150px) korunuyor (grid popup)',
    strpos($themeCss, 'minmax(150px, 1fr)') !== false);

// =====================================================================
echo "\n--- D) Kabuk degisikligi EKLEMELI ---\n";
check('D) $homeExtraCss tanimsizsa bos diziye dusuyor',
    strpos($shellTop, 'if (!isset($homeExtraCss) || !is_array($homeExtraCss)) {') !== false);
check('D) kabuk yalnizca dizideki dosyalari basiyor',
    preg_match('/foreach \(\$homeExtraCss as \$bccExtraCssFile\)/', $shellTop) === 1);
foreach (array('dashboard.php', 'starred.php', 'workspaces.php', 'slack_settings.php', 'team_members.php', 'bases.php') as $other) {
    $src = @file_get_contents($root . '/public/' . $other);
    check("D) {$other} \$homeExtraCss ATAMIYOR", $src !== false && strpos($src, 'homeExtraCss') === false);
}

// =====================================================================
echo "\n--- E) Bulunan iki yerlesim kok nedeni duzeltilmis mi ---\n";
// 1) tip grid'i formun max-width:420px'ine sikisiyordu (table_fields'a ozgu)
check('E) #new-field-form max-width kaldirilmis (tip grid\'i tam genislik)',
    preg_match('/\.sp-page #new-field-form \{ max-width: none; \}/', $tfRules) === 1);
// 2) column flex + flex-wrap:wrap -> kap icerikten ~800px uzun oluyordu.
//    ORTAK dosyaya tasindi: iki sayfa da ayni stacked form'u kullaniyor.
check('E) settings-form-stacked flex-wrap:nowrap ORTAK dosyada (iki sayfa da yararlaniyor)',
    preg_match('/\.sp-page \.settings-form-stacked \{[^}]*flex-wrap: nowrap;/s', $spRules) === 1);
check('E) paylasilan .settings-form kurali DEGISMEDI (yatay formlar bozulmasin)',
    preg_match('/\.settings-form \{[^}]*flex-wrap: wrap;/s', $homeCss) === 1);

// =====================================================================
echo "\n--- F) Arama kutusu (yalnizca table_fields) ---\n";
check('F) arama kutusu SAYFAYA OZEL js ile ekleniyor',
    strpos($tfJs, 'tf-type-search') !== false && strpos($tfJs, 'new-field-type-step') !== false);
check('F) kutu adim1\'in ICINE ekleniyor (adim2\'de otomatik gizlensin)',
    strpos($tfJs, 'typeStep.insertBefore(wrap, grid)') !== false);
check('F) kisa listede kutu eklenmiyor (esik 8)',
    strpos($tfJs, 'options.length < 8') !== false);
check('F) filtre [hidden] DEGIL ayri sinif kullaniyor (grid display tuzagi)',
    strpos($tfJs, "classList.toggle('tf-hidden'") !== false && strpos($tfJs, '.hidden = !match') === false);
check('F) base_tables.php bu js\'i YUKLEMIYOR', strpos($basePage, 'table-fields.js') === false);

// =====================================================================
echo "\n--- G) Yeni sabit renk eklenmemis (koyu tema) ---\n";
foreach (array('settings-page.css' => $spRules, 'table-fields.css' => $tfRules, 'account.css' => $acRules) as $name => $rules) {
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rules, $hex);
    check("G) {$name} icinde sabit HEX renk YOK", empty($hex[0]), implode(' ', array_unique($hex[0])));
}
check('G) renkler --bcc-* token\'larindan geliyor',
    substr_count($spRules, 'var(--bcc-') >= 20, substr_count($spRules, 'var(--bcc-') . ' kullanim');

// =====================================================================
echo "\n--- H) account.php: uydurma widget YOK, JS sozlesmesi korunuyor ---\n";
// Asagidaki "olmamali" kontrolleri YORUMSUZ kod uzerinden yapilir — bkz.
// php_code_only() basligi.
$acCode = php_code_only($root . '/public/account.php');
check('H) account.php ortak + sayfaya ozel CSS bagliyor',
    strpos($acPage, "array('settings-page.css', 'account.css')") !== false);
check('H) account.php .sp-page sarmalayicisi aciyor',
    substr_count($acPage, '<div class="sp-page ac-page">') === 1);

// UYDURMA WIDGET KORUMASI: bu uygulamada 2FA / oturum kaydi / API anahtari /
// bildirim ayari YOK. Sahte gosterge ya da olu link basilmadigini dogrula.
foreach (array('İki faktör', 'iki faktör', '2FA', 'Aktif oturum', 'API anahtar', 'Bildirim ayar') as $fake) {
    check("H) uydurma widget YOK: '{$fake}'", stripos($acCode, $fake) === false);
}
// Hizli erisim linkleri GERCEKTEN VAR OLAN sayfalara gitmeli.
preg_match_all('/class="ac-link"\s+href="([^"]+)"|href="([^"]+)"\s+class="ac-link"/', $acPage, $lm);
$links = array_values(array_filter(array_merge($lm[1], $lm[2])));
foreach ($links as $href) {
    $file = $root . '/public' . parse_url($href, PHP_URL_PATH);
    check("H) hizli erisim linki gercek bir sayfaya gidiyor: {$href}", is_file($file), $file);
}
check('H) en az uc hizli erisim linki var', count($links) >= 3, count($links) . ' link');

// [hidden] KORUMASI: account-page.js satir ici duzenlemeyi TAMAMEN hidden ile
// yonetiyor; CSS'te display verilen her eleman onu ezebilir. Ilk surumde tum
// formlar acik geliyordu (bu tuzak projede daha once de yasandi).
check('H) [hidden] korumasi var (display kurallari hidden\'i ezmesin)',
    preg_match('/\.sp-page \[hidden\] \{ display: none !important; \}/', $acRules) === 1);
check('H) account-page.js DEGISMEDI (sp-*/ac-* bilmiyor)',
    strpos($acJs, 'ac-') === false && strpos($acJs, 'sp-') === false);
// JS'in bagli oldugu kancalarin hepsi markup'ta durmali.
foreach (array('data-account-field', 'data-account-display', 'data-account-edit-trigger',
               'data-account-edit-form', 'data-account-edit-cancel', 'data-account-value',
               'data-account-input', 'data-account-error', 'account-password-trigger',
               'account-password-form', 'account-delete-trigger', 'account-delete-form') as $hook) {
    check("H) JS kancasi korundu: {$hook}", strpos($acPage, $hook) !== false);
}
// current_user() created_at/email_verify_token DONDURMUYOR -> $user uzerinden
// okumak rozeti HER ZAMAN "dogrulandi" yapardi (bulunan gercek bug).
check('H) dogrulama rozeti $user yerine ACIK sorgudan okunuyor',
    strpos($acCode, "\$user['email_verify_token']") === false
    && strpos($acPage, "SELECT created_at, email_verify_token FROM users") !== false);
// Paylasilan rol hapina dokunulmadi.
check('H) .ws-collab-role (team_members/workspaces ile paylasilan) DEGISTIRILMEDI',
    strpos($acRules, 'ws-collab-role') === false && strpos($acCode, 'ws-collab-role') === false);

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
