<?php

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
$slCss = file_get_contents($root . '/public/assets/slack-settings.css');
$wsCss = file_get_contents($root . '/public/assets/workspaces.css');
$wsPage = file_get_contents($root . '/public/workspaces.php');
$wsJs = file_get_contents($root . '/public/assets/workspaces.js');
$shareJs = file_get_contents($root . '/public/assets/share-popover.js');
$slPage = file_get_contents($root . '/public/slack_settings.php');
$routingJs = file_get_contents($root . '/public/assets/slack-routing.js');
$acPage = file_get_contents($root . '/public/account.php');
$acJs = file_get_contents($root . '/public/assets/account-page.js');
$tfJs = file_get_contents($root . '/public/assets/table-fields.js');
$fieldsPage = file_get_contents($root . '/public/table_fields.php');
$basePage = file_get_contents($root . '/public/base_tables.php');

$homeCss = css_rules(file_get_contents($root . '/public/assets/home.css'));
$themeCss = file_get_contents($root . '/public/assets/theme.css');
$partial = file_get_contents($root . '/src/partials/field_type_wizard_fields.php');
$wizardJs = file_get_contents($root . '/public/assets/field-type-wizard.js');
$shellTop = file_get_contents($root . '/src/partials/home_shell_top.php');

function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

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

    return preg_replace('/<!--.*?-->/s', '', $code);
}

$spRules = css_rules($spCss);
$tfRules = css_rules($tfCss);
$acRules = css_rules($acCss);
$slRules = css_rules($slCss);
$wsRules = css_rules($wsCss);

echo "--- A) Kapsam: her kural .sp-page altinda mi ---\n";

function unscoped_selectors($rules, $allowed = array())
{
    preg_match_all('/(^|\})\s*([^{}@]+)\{/m', $rules, $m);
    $bad = array();
    foreach ($m[2] as $selectorList) {
        foreach (explode(',', $selectorList) as $sel) {
            $sel = trim($sel);
            if ($sel === '' || strpos($sel, '.sp-page') === 0 || in_array($sel, $allowed, true)) {
                continue;
            }
            $bad[] = $sel;
        }
    }
    return $bad;
}

$tfAllowedUnscoped = array('.tf-modal-backdrop', 'body.tf-modal-open');

foreach (array('settings-page.css' => $spRules, 'table-fields.css' => $tfRules, 'account.css' => $acRules, 'slack-settings.css' => $slRules, 'workspaces.css' => $wsRules) as $name => $rules) {
    $bad = unscoped_selectors($rules, $name === 'table-fields.css' ? $tfAllowedUnscoped : array());
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

echo "\n--- B) ORTAK iskelet KOPYALANMAMIS ---\n";

foreach (array('.sp-icon-btn', '.sp-count', '.sp-primary-name', '.sp-move-group', '.settings-card', '.settings-table') as $shared) {
    check("B) '{$shared}' ortak dosyada tanimli", strpos($spRules, $shared) !== false);
    check("B) '{$shared}' table-fields.css'te TEKRARLANMIYOR", strpos($tfRules, $shared) === false);
}
foreach (array('.tf-type-pill', '.tf-col-type', '.tf-type-search') as $specific) {
    check("B) '{$specific}' YALNIZCA sayfaya ozel dosyada",
        strpos($tfRules, $specific) !== false && strpos($spRules, $specific) === false);
}

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

check('C) theme.css .field-type-option taban stili korunuyor (grid popup)',
    preg_match('/\.field-type-option \{[^}]*border-radius: 6px;[^}]*padding: 0\.5rem 0\.7rem;/s', $themeCss) === 1);
check('C) theme.css .field-type-grid taban minmax(150px) korunuyor (grid popup)',
    strpos($themeCss, 'minmax(150px, 1fr)') !== false);

echo "\n--- D) Kabuk degisikligi EKLEMELI ---\n";
check('D) $homeExtraCss tanimsizsa bos diziye dusuyor',
    strpos($shellTop, 'if (!isset($homeExtraCss) || !is_array($homeExtraCss)) {') !== false);
check('D) kabuk yalnizca dizideki dosyalari basiyor',
    preg_match('/foreach \(\$homeExtraCss as \$bccExtraCssFile\)/', $shellTop) === 1);

foreach (array('team_members.php', 'bases.php') as $other) {
    $src = @file_get_contents($root . '/public/' . $other);
    check("D) {$other} \$homeExtraCss ATAMIYOR", $src !== false && strpos($src, 'homeExtraCss') === false);
}

echo "\n--- E) Bulunan iki yerlesim kok nedeni duzeltilmis mi ---\n";

check('E) #new-field-form max-width kaldirilmis (tip grid\'i tam genislik)',
    preg_match('/\.sp-page #new-field-form \{ max-width: none; \}/', $tfRules) === 1);

check('E) settings-form-stacked flex-wrap:nowrap ORTAK dosyada (iki sayfa da yararlaniyor)',
    preg_match('/\.sp-page \.settings-form-stacked \{[^}]*flex-wrap: nowrap;/s', $spRules) === 1);
check('E) paylasilan .settings-form kurali DEGISMEDI (yatay formlar bozulmasin)',
    preg_match('/\.settings-form \{[^}]*flex-wrap: wrap;/s', $homeCss) === 1);

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

echo "\n--- G) Yeni sabit renk eklenmemis (koyu tema) ---\n";
foreach (array('settings-page.css' => $spRules, 'table-fields.css' => $tfRules, 'account.css' => $acRules, 'slack-settings.css' => $slRules, 'workspaces.css' => $wsRules) as $name => $rules) {
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rules, $hex);
    check("G) {$name} icinde sabit HEX renk YOK", empty($hex[0]), implode(' ', array_unique($hex[0])));
}
check('G) renkler --bcc-* token\'larindan geliyor',
    substr_count($spRules, 'var(--bcc-') >= 20, substr_count($spRules, 'var(--bcc-') . ' kullanim');

echo "\n--- H) account.php: uydurma widget YOK, JS sozlesmesi korunuyor ---\n";

$acCode = php_code_only($root . '/public/account.php');
check('H) account.php ortak + sayfaya ozel CSS bagliyor',
    strpos($acPage, "array('settings-page.css', 'account.css')") !== false);
check('H) account.php .sp-page sarmalayicisi aciyor',
    substr_count($acPage, '<div class="sp-page ac-page">') === 1);

foreach (array('İki faktör', 'iki faktör', '2FA', 'Aktif oturum', 'API anahtar', 'Bildirim ayar') as $fake) {
    check("H) uydurma widget YOK: '{$fake}'", stripos($acCode, $fake) === false);
}

preg_match_all('/class="ac-link"\s+href="([^"]+)"|href="([^"]+)"\s+class="ac-link"/', $acPage, $lm);
$links = array_values(array_filter(array_merge($lm[1], $lm[2])));
foreach ($links as $href) {
    $file = $root . '/public' . parse_url($href, PHP_URL_PATH);
    check("H) hizli erisim linki gercek bir sayfaya gidiyor: {$href}", is_file($file), $file);
}
check('H) en az uc hizli erisim linki var', count($links) >= 3, count($links) . ' link');

check('H) [hidden] korumasi var (display kurallari hidden\'i ezmesin)',
    preg_match('/\.sp-page \[hidden\] \{ display: none !important; \}/', $acRules) === 1);
check('H) account-page.js DEGISMEDI (sp-*/ac-* bilmiyor)',
    strpos($acJs, 'ac-') === false && strpos($acJs, 'sp-') === false);

foreach (array('data-account-field', 'data-account-display', 'data-account-edit-trigger',
               'data-account-edit-form', 'data-account-edit-cancel', 'data-account-value',
               'data-account-input', 'data-account-error', 'account-password-trigger',

               'account-password-form', 'account-deactivate-trigger', 'account-deactivate-form') as $hook) {
    check("H) JS kancasi korundu: {$hook}", strpos($acPage, $hook) !== false);
}

check('H) dogrulama rozeti $user yerine ACIK sorgudan okunuyor',
    strpos($acCode, "\$user['email_verify_token']") === false
    && strpos($acPage, "SELECT created_at, email_verify_token FROM users") !== false);

check('H) .ws-collab-role (team_members/workspaces ile paylasilan) DEGISTIRILMEDI',
    strpos($acRules, 'ws-collab-role') === false && strpos($acCode, 'ws-collab-role') === false);

echo "\n--- I) slack_settings.php ---\n";
$slCode = php_code_only($root . '/public/slack_settings.php');

check('I) ortak + sayfaya ozel CSS bagliyor',
    strpos($slPage, "array('settings-page.css', 'slack-settings.css')") !== false);
check('I) .sp-page sarmalayicisi aciyor',
    substr_count($slPage, '<div class="sp-page">') === 1);

foreach (array('.sp-status', '.sp-toggle', '.sp-note', '.sp-code') as $shared) {
    check("I) '{$shared}' ortak dosyada tanimli", strpos($spRules, $shared) !== false);

    check("I) {$shared} slack-settings.css te KENDI tanimi TEKRARLANMIYOR",
        preg_match('/^\s*' . preg_quote($shared, '/') . '[\s,{]/m', $slRules) === 0);
}

check('I) eski .settings-btn-sm butonlari KALMADI',
    strpos($slCode, 'settings-btn-sm') === false);

check('I) kural formu settings-form-stacked KULLANMIYOR (satir ici)',
    strpos($slCode, 'sl-rule-form') !== false
    && preg_match('/class="settings-form settings-form-stacked"[^>]*>\s*<\?php echo csrf_field\(\); \?>\s*<input type="hidden" name="action" value="add_routing_rule"/s', $slPage) === 0);
check('I) kural formu 4 alan + buton olacak sekilde yatay grid',
    preg_match('/\.sp-page \.sl-rule-form \{[^}]*grid-template-columns: repeat\(4, minmax\(0, 1fr\)\) auto;/s', $slRules) === 1);

check('I) slack-routing.js kancalari korundu (#routing-rule-field / -value)',
    strpos($slPage, 'id="routing-rule-field"') !== false && strpos($slPage, 'id="routing-rule-value"') !== false);
check('I) slack-routing.js DEGISMEDI (sp-*/sl-* bilmiyor)',
    strpos($routingJs, 'sp-') === false && strpos($routingJs, 'sl-') === false);

check('I) toggle name="is_active" value="1" sozlesmesini koruyor',
    preg_match('/class="sp-toggle">\s*<input type="checkbox" name="is_active" value="1"/s', $slPage) === 1);

check('I) --bcc-success uc tema blogunda da tanimli',
    substr_count($themeCss, '--bcc-success:') === 3 && substr_count($themeCss, '--bcc-success-soft:') === 3,
    substr_count($themeCss, '--bcc-success:') . ' / ' . substr_count($themeCss, '--bcc-success-soft:'));
foreach (array('--bcc-accent: #2d7ff9', '--bcc-danger: #c62828', '--bcc-danger-soft: #fdecea') as $untouched) {
    check("I) mevcut token DEGISMEDI: {$untouched}", strpos($themeCss, $untouched) !== false);
}

echo "\n--- J) workspaces.php ---\n";
$wsCode = php_code_only($root . '/public/workspaces.php');

check('J) ortak + sayfaya ozel CSS bagliyor',
    strpos($wsPage, "array('settings-page.css', 'workspaces.css', 'grid-shell.css')") !== false);
check('J) grid-shell.css modal ICIN yukleniyor (paylasilan bilesen, kopya CSS yok)',
    strpos($wsPage, 'grid-shell.css') !== false
    && strpos($wsCode, 'partials/share_modal.php') !== false);
check('J) .sp-page sarmalayicisi aciyor',
    substr_count($wsPage, '<div class="sp-page wsx-page">') === 1);

foreach (array('ws-collab-avatar', 'ws-collab-role', 'ws-detail', 'ws-card', 'ws-grid') as $sharedWs) {
    check("J) paylasilan '{$sharedWs}' workspaces.css'te YENIDEN TANIMLANMAMIS",
        strpos($wsRules, '.' . $sharedWs) === false);
    check("J) paylasilan '{$sharedWs}' markup'ta ARTIK KULLANILMIYOR",
        preg_match('/class="[^"]*\b' . preg_quote($sharedWs, '/') . '\b/', $wsCode) === 0);
}

check('J) rol hapi ortak .sp-role kullaniyor', strpos($wsCode, 'sp-role sp-role--') !== false);
check('J) avatar ortak .sp-avatar kullaniyor', strpos($wsCode, 'sp-avatar') !== false);
foreach (array('.sp-role', '.sp-avatar') as $shared) {
    check("J) '{$shared}' ortak dosyada tanimli", strpos($spRules, $shared) !== false);
    check("J) '{$shared}' workspaces.css'te TEKRARLANMIYOR", strpos($wsRules, $shared) === false);
}

check('J) hover kisayolu SAHTE dropdown degil, GERCEK sayfaya link',
    preg_match('/class="wsx-member-manage" href="\/team_members\.php\?team_id=/', $wsPage) === 1);

check('J) sayfada HIC <select> yok (davet kutusu kalkti, satir ici dropdown da yok)',
    substr_count($wsCode, '<select') === 0,
    'adet: ' . substr_count($wsCode, '<select'));
check('J) davet kutusu markupi KALDIRILDI (data-ws-invite* yok)',
    strpos($wsCode, 'data-ws-invite') === false
    && strpos($wsCode, 'wsx-invite') === false);
check('J) $wsInviteRoles degiskeni tamamen kalkti (olu hesap yok)',
    strpos($wsCode, 'wsInviteRoles') === false);
check('J) workspaces.js te davet mantigi KALMADI',
    strpos(file_get_contents($root . '/public/assets/workspaces.js'), 'ws-invite') === false
    && strpos(file_get_contents($root . '/public/assets/workspaces.js'), '/api/team_member_assign.php') === false);
check('J) workspaces.css te olu .wsx-invite* kurallari KALMADI',
    strpos(file_get_contents($root . '/public/assets/workspaces.css'), '.wsx-invite') === false);
check('J) katilimci SATIRLARINDA satir ici rol dropdown\'u YOK',
    preg_match('/wsx-member-badges.*?<select/s', $wsCode) === 0);

check('J) api/team_member_assign.php DURUYOR (Paylas modali + team_members kullaniyor)',
    is_file($root . '/public/api/team_member_assign.php')
    && strpos(file_get_contents($root . '/src/share_modal_payload.php'), 'bcc_assignable_roles') !== false);

check('J) "Katilimcilari yonet" baglantisi duruyor (ekleme icin gercek yol)',
    strpos($wsCode, 'team_members.php') !== false
    && strpos($wsCode, 'Katılımcıları yönet') !== false);

$wsTriggerCount = substr_count($wsPage, 'href="/admin/create_team.php"');
check('J) "Yeni calisma alani" tetikleyicisi var',
    $wsTriggerCount > 0, 'adet: ' . $wsTriggerCount);
check('J) her tetikleyici modal tetikleyicisi (data-create-team-btn) ve href yedegini koruyor',
    substr_count($wsPage, 'data-create-team-btn') === $wsTriggerCount,
    'href=' . $wsTriggerCount . ' data-attr=' . substr_count($wsPage, 'data-create-team-btn'));
check('J) admin/create_team.php GERCEKTEN var', is_file($root . '/public/admin/create_team.php'));

check('J) "Base olustur" OLU DEGIL: modal tetikleyicisi',
    preg_match('#<button[^>]*data-create-base-open[^>]*>.*?Base oluştur#s', $wsPage) === 1);

check('J) tetikleyicinin actigi modal GERCEKTEN var (ortak partial uzerinden)',
    strpos($wsPage, "partials/create_base_modal.php") !== false
    && is_file($root . '/src/partials/create_base_modal.php')
    && strpos(file_get_contents($root . '/src/partials/create_base_modal.php'), 'id="home-create-base-modal"') !== false);

check('J) bases.php duruyor (modalin JS siz yedegi oraya POST ediyor)',
    is_file($root . '/public/bases.php')
    && strpos(file_get_contents($root . '/src/partials/create_base_modal.php'), 'action="/bases.php"') !== false);

check('J) "Ayarlar" dugmesi tamamen KALDIRILDI (olmayan ozellik ima edilmiyor)',
    strpos($wsPage, 'wsx-btn\" disabled') === false);

check('J) katilimci listesi cok sutunlu izgara',
    preg_match('/\.sp-page \.wsx-collab-grid \{[^}]*grid-template-columns: repeat\(auto-fill, minmax\(280px, 1fr\)\);/s', $wsRules) === 1);
check('J) arama kutusu sayfaya ozel js ile, kisa listede eklenmiyor',
    strpos($wsJs, 'wsx-search') !== false && strpos($wsJs, 'members.length < 8') !== false);
check('J) filtre [hidden] DEGIL ayri sinif kullaniyor (grid display tuzagi)',
    strpos($wsJs, "classList.toggle('wsx-hidden'") !== false);

check('J) --bcc-role-owner uc tema blogunda da tanimli',
    substr_count($themeCss, '--bcc-role-owner:') === 3 && substr_count($themeCss, '--bcc-role-owner-soft:') === 3,
    substr_count($themeCss, '--bcc-role-owner:') . ' / ' . substr_count($themeCss, '--bcc-role-owner-soft:'));

check('K) admin satir menusu (⋮) paneli sag hizali kaldi (satirin sag ucunda)',
    preg_match('/\.admin-menu-panel \{[^}]*right: 0;/s', $homeCss) === 1);
check('K) admin "Islemler" toplu menusu SOL hizali acilir (2026-09-15: sag hizaliyken sola tasip kenar cubugunun altina giriyordu)',
    preg_match('/\.admin-bulk-bar \.admin-menu-panel \{[^}]*left: 0;[^}]*right: auto;/s', $homeCss) === 1);
check('K) "Islemler" menusu hala .admin-bulk-bar icinde (secici ona dayaniyor)',
    preg_match('#<div class="admin-bulk-bar">\s*<details class="admin-menu">#', (string) file_get_contents($root . '/public/admin/index.php')) === 1);

$adminJs = (string) file_get_contents($root . '/public/assets/admin.js');
$confirmJs = (string) file_get_contents($root . '/public/assets/confirm-modal.js');
check('L) confirm-modal.js tek dugmeli uyari modu sunuyor (window.bcc_alert)',
    strpos($confirmJs, 'window.bcc_alert = function (options)') !== false
    && strpos($confirmJs, 'cancelBtn.hidden = !!options.alert;') !== false);
check('L) bcc_confirm varsayilaninda Vazgec yine gorunur (alert yoksa hidden=false)',
    strpos($confirmJs, 'cancelBtn.hidden = !!options.alert;') !== false);
check('L) admin: secim yokken "Islemler" menusu ACILMIYOR, uyari cikiyor',
    strpos($adminJs, "document.querySelectorAll('.admin-bulk-bar details.admin-menu > summary')") !== false
    && strpos($adminJs, '!menu.open && isBulkForm(formId) && checkedCountFor(formId) === 0') !== false);
check('L) admin: secim yokken toplu form (ekipten cikar dahil) onay yerine uyari -- capture + stopImmediatePropagation',
    preg_match("/document\.addEventListener\('submit', function \(e\) \{.*?checkedCountFor\(form\.id\) === 0.*?e\.stopImmediatePropagation\(\);.*?warnNoSelection\(\);.*?\}, true\);/s", $adminJs) === 1);
check('L) toplu form tespiti satir kutularinin form= baglantisina dayaniyor (tek satir cikar formlari etkilenmez)',
    strpos($adminJs, "input.admin-row-checkbox[form=\"' + formId + '\"]") !== false);
check('L) uyari metni "Lutfen ... seciniz"', strpos($adminJs, 'Lütfen önce listeden en az bir kullanıcı seçiniz.') !== false);

check('M) "Su an cevrimici" listesi cok sutunlu izgara (alt alta tek sutun degil)',
    preg_match('/\.admin-online-list \{[^}]*display: grid;[^}]*grid-template-columns: repeat\(auto-fill, minmax\(260px, 1fr\)\);/s', $homeCss) === 1);
check('M) cevrimici listesi yukseklik sinirli, tasinca kendi icinde kayar',
    preg_match('/\.admin-online-list \{[^}]*max-height: 13rem;[^}]*overflow-y: auto;/s', $homeCss) === 1);
check('M) uzun ad/e-posta kisaltiliyor (ellipsis + min-width: 0)',
    preg_match('/\.admin-online-info \{[^}]*min-width: 0;/s', $homeCss) === 1
    && preg_match('/\.admin-online-info \.admin-user-email \{[^}]*text-overflow: ellipsis;/s', $homeCss) === 1);
check('M) kisaltilan metnin tamami title ile gorulebiliyor',
    strpos((string) file_get_contents($root . '/public/admin/index.php'), '<div class="admin-user-email" title="<?php echo htmlspecialchars($bccOu[\'email\']') !== false);

$pillRule = preg_match('/\.sp-page \.tf-type-pill \{([^}]*)\}/s', $tfRules, $pm) ? $pm[1] : '';
check('N) alan tipi etiketinde yumusatilmis cerceve YOK (kenarlik/zemin/kose/dolgu kaldirildi, 2026-09-15)',
    $pillRule !== ''
    && strpos($pillRule, 'border') === false
    && strpos($pillRule, 'background') === false
    && strpos($pillRule, 'padding') === false,
    trim(preg_replace('/\s+/', ' ', $pillRule)));
check('N) etiket blok flex (hucrede alan adiyla ayni dikey hizada; inline-flex 1,3px yukarida kaliyordu)',
    strpos($pillRule, 'display: flex;') !== false && strpos($pillRule, 'align-items: center;') !== false);

$tfPhp = (string) file_get_contents($root . '/public/table_fields.php');
check('O) alanlar tablosunda "Zorunlu" sutunu YOK (baslik + hucre, 2026-09-15)',
    strpos($tfPhp, '<th>Zorunlu</th>') === false && strpos($tfPhp, 'tf-required-yes') === false
    && strpos($tfRules, 'tf-required-yes') === false);
check('O) "Zorunlu alan" ayari duzenleme formunda DURUYOR (yalniz tablo sutunu kalkti)',
    strpos($tfPhp, 'name="is_required"') !== false);
check('O) Secenekler: secenek eklenebilen tip -> degerler ya da "—"; eklenemeyen tip -> bos hucre',
    strpos($tfPhp, '$hasChoiceList = is_select_field_type($f[\'field_type\']);') !== false
    && preg_match('#<\?php if \(!\$hasChoiceList\): \?>\s*<td></td>\s*<\?php elseif \(\$choices\): \?>#', $tfPhp) === 1
    && strpos($tfPhp, '<td class="sp-muted">—</td>') !== false);
check('O) sutunlar tablo genisligine dagiliyor; islemler sutunu dugmeler kadar (26/26/kalan/1%)',
    strpos($tfRules, '.tf-fields-table .tf-col-name { width: 26%; }') !== false
    && strpos($tfRules, '.tf-fields-table .tf-col-type { width: 26%; }') !== false
    && strpos($tfRules, '.tf-fields-table .tf-col-actions { width: 1%; }') !== false);
check('O) islemler HUCRESI flex degil (td display:flex satir cizgisini kaydiriyordu); flex ic kapta',
    strpos($tfPhp, '<td class="settings-row-actions">') === false
    && strpos($tfPhp, '<td class="tf-actions-cell"><div class="settings-row-actions">') !== false);

check('P) kalem -> duzenleme sayfanin altinda kart DEGIL, ekranda pencere (2026-09-15)',
    strpos($tfPhp, 'id="tf-edit-modal"') !== false
    && strpos($tfPhp, '<div class="home-modal tf-edit-modal" role="dialog" aria-modal="true"') !== false
    && strpos($tfPhp, '<h2>Alanı Düzenle</h2>') === false);
check('P) secenekler satir satir: ekle / sil butonu + satir basina renk secimi',
    strpos($tfPhp, 'data-tf-choice-add') !== false && strpos($tfPhp, 'data-tf-choice-remove') !== false
    && strpos($tfPhp, 'name="choices[__ROW__]"') !== false && strpos($tfPhp, 'name="colors[__ROW__]"') !== false);
check('P) sunucu choices[N]/colors[N] satirlarini secenek adina gore eslestiriyor (silinen satir renkleri kaydirmaz)',
    strpos($tfPhp, "\$colorByChoice[\$choiceText] = (string) \$colorsPost[\$rowKey];") !== false
    && strpos($tfPhp, 'foreach (parse_select_choices($optionsText) as $choiceIndex => $parsedChoice)') !== false);
check('P) hata olursa pencere taslakla yeniden acik geliyor (girilen deger kaybolmaz)',
    strpos($tfPhp, '$editError = $error;') !== false && strpos($tfPhp, '$editDraft') !== false);
check('P) JS: Esc / arka plan tiklamasi kapatir, Enter yeni secenek satiri acar',
    strpos($tfJs, "e.key === 'Escape'") !== false && strpos($tfJs, 'e.target === modal') !== false
    && strpos($tfJs, "e.key !== 'Enter'") !== false && strpos($tfJs, 'addRow(true);') !== false);
check('P) tip degisince yalniz o tipin ek alanlari gorunur; digerleri disabled (gizli input formu kilitlemez)',
    strpos($tfJs, "box.getAttribute('data-tf-extra') === type") !== false
    && strpos($tfJs, 'input.disabled = !active;') !== false
    && strpos($tfJs, "requiredRow.hidden = type === 'autonumber';") !== false
    && strpos($tfPhp, 'data-tf-extra="currency"') !== false && strpos($tfPhp, 'data-tf-extra="rating"') !== false);
check('P) pencere icinde [hidden] gercekten gizliyor (home-modal-check display kurali ezmesin)',
    strpos($tfRules, '.sp-page .tf-edit-modal [hidden] { display: none; }') !== false);

$btJs = (string) file_get_contents($root . '/public/assets/base-tables.js');
check('Q) base_tables: "Tablo Olustur" dugmesi Tablolar basliginin saginda (2026-09-15)',
    preg_match('#<div class="sp-card-head">\s*<h2>Tablolar <span class="sp-count">.*?</h2>\s*<\?php if \(\$canEdit\): \?>\s*<button type="button" class="settings-btn settings-btn-primary sp-card-head-btn" data-bt-create-open>#s', $basePage) === 1
    && strpos($spRules, '.sp-page .sp-card-head {') !== false
    && preg_match('/\.sp-page \.sp-card-head \{[^}]*justify-content: space-between;/s', $spRules) === 1);
check('Q) alttaki "Yeni Tablo" karti YOK; olusturma formu yalniz pencerede',
    strpos($basePage, '<h2>Yeni Tablo</h2>') === false
    && substr_count($basePage, 'value="create_table"') === 1
    && strpos($basePage, 'id="bt-create-modal"') !== false
    && strpos($basePage, '<form class="home-modal-form" id="bt-create-form" method="post" action="/base_tables.php">') !== false);
check('Q) pencere tablo adi + aciklama istiyor',
    preg_match('#id="bt-create-form".*name="name".*name="description"#s', $basePage) === 1);
check('Q) olusturma hatasi pencerede, taslakla (ust flash degil)',
    strpos($basePage, '$createError = $error;') !== false && strpos($basePage, '$createName') !== false
    && strpos($basePage, "echo \$createOpen ? '' : ' hidden';") !== false);
check('Q) bos durum metni artik "asagidaki form"u gostermiyor',
    strpos($basePage, 'Aşağıdaki formdan') === false);
check('Q) JS: dugme acar; x / Vazgec / Esc / arka plan kapatir; hata ile gelince acik baslar',
    strpos($btJs, "querySelectorAll('[data-bt-create-open]')") !== false
    && strpos($btJs, "querySelectorAll('[data-bt-modal-close]')") !== false
    && strpos($btJs, "e.key === 'Escape' && openModal") !== false
    && strpos($btJs, 'e.target === modal') !== false
    && strpos($btJs, "querySelector('#bt-create-modal:not([hidden]), #bt-edit-modal:not([hidden])')") !== false
    && strpos($basePage, "bcc_asset_url('base-tables.js')") !== false);

check('Q) kalem -> tablonun altinda kart DEGIL, ekranda "Tabloyu Duzenle" penceresi',
    strpos($basePage, '<h2>Tabloyu Düzenle:') === false
    && strpos($basePage, 'id="bt-edit-modal"') !== false
    && strpos($basePage, '<form class="home-modal-form" id="bt-edit-form" method="post" action="/base_tables.php">') !== false
    && strpos($basePage, 'data-bt-edit-open data-table-id=') !== false);
check('Q) kalem sayfayi yenilemeden pencereyi o tablonun ad/aciklamasiyla dolduruyor',
    strpos($btJs, "querySelectorAll('[data-bt-edit-open]')") !== false
    && strpos($btJs, "getAttribute('data-table-name')") !== false
    && strpos($btJs, "getAttribute('data-table-description')") !== false
    && strpos($btJs, "input[name=\"table_id\"]').value = trigger.getAttribute('data-table-id')") !== false);
check('Q) duzenleme hatasi pencerede, taslakla; ?edit= kapaninca adresten siliniyor',
    strpos($basePage, '$editError = $error;') !== false
    && strpos($basePage, '$editId = $editErrorTableId > 0 ? $editErrorTableId') !== false
    && strpos($btJs, "params.delete('edit');") !== false);

check('R) "Islemler" basligi yazisiz (dugmelerden kopuk duruyordu, 2026-09-15); ekran okuyucu icin aria-label',
    strpos($basePage, '<th class="sp-col-actions" aria-label="İşlemler"></th>') !== false
    && strpos($tfPhp, '<th class="tf-col-actions" aria-label="İşlemler"></th>') !== false
    && preg_match('#<th[^>]*>\s*İşlemler\s*</th>#u', $basePage . $tfPhp) === 0);
check('R) base_tables islemler HUCRESI flex degil; flex ic kapta; sutun dugmeler kadar dar',
    strpos($basePage, '<td class="settings-row-actions">') === false
    && strpos($basePage, '<td class="sp-actions-cell"><div class="settings-row-actions">') !== false
    && preg_match('#</form>\s*</div></td>#', $basePage) === 1
    && preg_match('/\.sp-page \.settings-table \.sp-col-actions,\s*\.sp-page \.settings-table \.sp-actions-cell \{[^}]*width: 1%;/s', $spRules) === 1);

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
