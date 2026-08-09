<?php
// table_fields.php UI yenilemesi — KAPSAM KORUMASI testi.
//
// Bu turun asil riski gorsel degil YAYILMA riskiydi: sayfa .settings-card /
// .settings-table / .settings-btn / .settings-breadcrumb sinifllarini DOKUZ baska
// sayfayla (admin/*, bases, base_tables, form_edit, kanban, slack_settings)
// paylasiyor; ayrica alan tipi grid'i src/partials/field_type_wizard_fields.php'den
// geliyor ve grid.php'nin "+" POPUP'IYLA paylasiliyor. Yeni kurallarin bir gun
// home.css'e/theme.css'e tasinmasi o sayfalari sessizce yeniden tasarlardi.
// Bu betik tam olarak bunu engelliyor.
//
// Calistirma: C:\php73\php.exe scripts\_verify_table_fields_ui.php

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
$tfCss = file_get_contents($root . '/public/assets/table-fields.css');
$tfJs = file_get_contents($root . '/public/assets/table-fields.js');
$page = file_get_contents($root . '/public/table_fields.php');
$homeCss = file_get_contents($root . '/public/assets/home.css');
$themeCss = file_get_contents($root . '/public/assets/theme.css');
$partial = file_get_contents($root . '/src/partials/field_type_wizard_fields.php');
$wizardJs = file_get_contents($root . '/public/assets/field-type-wizard.js');
$shellTop = file_get_contents($root . '/src/partials/home_shell_top.php');

// CSS yorumlarini soy: dosya basligi acikca ".settings-card" gibi sinif adlarini
// ANIYOR; ham metinde aramak yanlis sonuc verirdi (bu projede iki kez yasanan ders).
$tfRules = preg_replace('#/\*.*?\*/#s', '', $tfCss);

// =====================================================================
echo "--- A) Kapsam: her kural .tf-page altinda mi ---\n";
// Her kural bloğunun selector kismini cikar ve .tf-page ile basladigini dogrula.
preg_match_all('/(^|\})\s*([^{}@]+)\{/m', $tfRules, $m);
$unscoped = array();
foreach ($m[2] as $selectorList) {
    foreach (explode(',', $selectorList) as $sel) {
        $sel = trim($sel);
        if ($sel === '' || strpos($sel, '.tf-page') === 0) {
            continue;
        }
        $unscoped[] = $sel;
    }
}
check('A) table-fields.css icindeki TUM selector\'lar .tf-page ile basliyor',
    empty($unscoped), implode(' | ', array_slice($unscoped, 0, 5)));

check('A) sayfa .tf-page sarmalayicisini aciyor ve kapatiyor',
    substr_count($page, '<div class="tf-page">') === 1 && substr_count($page, '</div>') >= 1);

check('A) CSS yalnizca bu sayfaya bagli ($homeExtraCss)',
    strpos($page, "\$homeExtraCss = array('table-fields.css');") !== false);

// =====================================================================
echo "\n--- B) Paylasilan dosyalara SIZMA olmamis ---\n";
foreach (array('tf-page' => $homeCss, 'tf-icon-btn' => $homeCss, 'tf-type-pill' => $homeCss) as $needle => $hay) {
    check("B) home.css '{$needle}' ICERMIYOR", strpos($hay, $needle) === false);
}
check('B) theme.css tf-* sinifi ICERMIYOR', strpos($themeCss, '.tf-') === false);
check('B) paylasilan partial DEGISMEDI (arama kutusu oraya girmemis)',
    strpos($partial, 'tf-type-search') === false && strpos($partial, 'type="search"') === false);
check('B) paylasilan field-type-wizard.js DEGISMEDI (tf-* bilmiyor)',
    strpos($wizardJs, 'tf-') === false);

// theme.css'teki taban tip-karti olculeri KORUNMALI (grid.php popup'i bunlari
// kullaniyor). Degerler /browse ile popup uzerinde de olculdu: radius 6px,
// padding 8px 11.2px, tek sutun.
check('B) theme.css .field-type-option taban stili korunuyor (grid popup)',
    preg_match('/\.field-type-option \{[^}]*border-radius: 6px;[^}]*padding: 0\.5rem 0\.7rem;/s', $themeCss) === 1);
check('B) theme.css .field-type-grid taban minmax(150px) korunuyor (grid popup)',
    strpos($themeCss, 'minmax(150px, 1fr)') !== false);

// =====================================================================
echo "\n--- C) Kabuk degisikligi EKLEMELI (diger 8 sayfa etkilenmiyor) ---\n";
check('C) $homeExtraCss tanimsizsa bos diziye dusuyor',
    strpos($shellTop, "if (!isset(\$homeExtraCss) || !is_array(\$homeExtraCss)) {") !== false);
check('C) kabuk yalnizca dizideki dosyalari basiyor',
    preg_match('/foreach \(\$homeExtraCss as \$bccExtraCssFile\)/', $shellTop) === 1);
// Kabugu paylasan diger sayfalar $homeExtraCss ATAMIYOR olmali (yoksa onlar da
// bu CSS'i cekerdi).
foreach (array('dashboard.php', 'starred.php', 'workspaces.php', 'base_tables.php', 'slack_settings.php', 'team_members.php') as $other) {
    $src = @file_get_contents($root . '/public/' . $other);
    check("C) {$other} \$homeExtraCss ATAMIYOR", $src !== false && strpos($src, 'homeExtraCss') === false);
}

// =====================================================================
echo "\n--- D) Bulunan iki yerlesim kok nedeni duzeltilmis mi ---\n";
// 1) tip grid'i formun max-width:420px'ine sikisiyordu
check('D) #new-field-form max-width kaldirilmis (tip grid\'i tam genislik)',
    preg_match('/\.tf-page #new-field-form \{ max-width: none; \}/', $tfRules) === 1);
// 2) column flex + flex-wrap:wrap -> kap icerikten ~800px uzun oluyordu
check('D) #new-field-form flex-wrap:nowrap (olu alan duzeltmesi)',
    preg_match('/\.tf-page #new-field-form \{ flex-wrap: nowrap; \}/', $tfRules) === 1);
check('D) paylasilan .settings-form kurali DEGISMEDI (yatay formlar bozulmasin)',
    preg_match('/\.settings-form \{[^}]*flex-wrap: wrap;/s', $homeCss) === 1);

// =====================================================================
echo "\n--- E) Arama kutusu ---\n";
check('E) arama kutusu SAYFAYA OZEL js ile ekleniyor',
    strpos($tfJs, 'tf-type-search') !== false && strpos($tfJs, 'new-field-type-step') !== false);
check('E) kutu adim1\'in ICINE ekleniyor (adim2\'de otomatik gizlensin)',
    strpos($tfJs, 'typeStep.insertBefore(wrap, grid)') !== false);
check('E) kisa listede kutu eklenmiyor (esik 8)',
    strpos($tfJs, 'options.length < 8') !== false);
check('E) filtre [hidden] DEGIL ayri sinif kullaniyor (grid display tuzagi)',
    strpos($tfJs, "classList.toggle('tf-hidden'") !== false && strpos($tfJs, '.hidden = !match') === false);

// =====================================================================
echo "\n--- F) Yeni sabit renk eklenmemis (koyu tema) ---\n";
// Gölge rgba'lari disinda hex/rgb renk olmamali: her sey --bcc-* token'i.
preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $tfRules, $hex);
check('F) table-fields.css icinde sabit HEX renk YOK',
    empty($hex[0]), implode(' ', array_unique($hex[0])));
check('F) renkler --bcc-* token\'larindan geliyor',
    substr_count($tfRules, 'var(--bcc-') >= 20, substr_count($tfRules, 'var(--bcc-') . ' kullanim');

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
