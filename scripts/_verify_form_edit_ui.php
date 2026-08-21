<?php
// form_edit.php (Form Olusturucu) UI cilasi.
//
// Kapsam:
//   A) Sol panel: $starredBases bu sayfada da doluyor (EKSIKTI), kabuk da
//      artik varsayilana dusuyor
//   B) Yerlesim: kap genisligi ortak olcuye cekildi ve ortalaniyor
//   C) Girdi araliklari: etiket tek satir, bosluk tek yerden (satir ici
//      style="margin-top" kalintisi yok)
//   D) Sag panel: alan listesi kart icinde kayiyor, zorunluluk yildizi
//      adin YANINDA ve renkli
//   E) Regresyon: form ALAN ADLARI degismedi (sunucu tarafi ayni), kaydetme
//      calisiyor, salt-okunur dal duruyor, dar ekran override'lari yerinde
//   F) Gercek base (15) dokunulmamis olmali
//
// OLCUM NOTU: A/B/C/D'nin gorsel sonuclari TARAYICIDA olculdu (/browse,
// 1440x900): sol panel yildizli sayisi 0 -> 1; sayfa 1128 -> 1080px; "Form
// basligi" girdisi 704 -> 480px; zorunluluk yildizi ad ile arasinda ~150px
// bosluk birakip kartin sag kenarindayken artik 10px yaninda ve --bcc-danger
// renginde (rgb(198,40,40)); 28 alanli listede kart 1074px'e tasarken artik
// kendi icinde kayiyor. Burasi o kararlarin KODDA durdugunu bekler.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_form_edit_ui.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'feui.owner@bcc-test.local');
define('VIEWER_EMAIL', 'feui.viewer2@bcc-test.local');
define('TEST_PASS', 'FeUi!2026');
define('REAL_BASE_ID', 15);

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

function http_request($method, $path, $cookie = null, $postFields = null)
{
    $headers = array();
    if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }
    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $status = 0; $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
            if (stripos($h, 'Set-Cookie:') === 0) { $p = explode(';', substr($h, 11)); $newCookie = trim($p[0]); }
        }
    }

    return array('body' => (string) $body, 'cookie' => $newCookie, 'status' => $status);
}

function extract_csrf_field($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf_field($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

// CSS yorumlarini soyar: bu projede testler aciklama YORUMLARINA takilip
// birden fazla kez yanlis "GECTI" verdi.
function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

function rule_body($css, $selector)
{
    $q = preg_quote($selector, '#');
    if (preg_match('#(?:^|[};])\s*' . $q . '\s*\{([^}]*)\}#s', $css, $m)) { return $m[1]; }
    return null;
}

foreach (array(OWNER_EMAIL, VIEWER_EMAIL) as $mail) {
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $mail));
}

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => OWNER_EMAIL)
    ), 'id');
    foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
    foreach (array(OWNER_EMAIL, VIEWER_EMAIL) as $mail) {
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $mail));
    }
};

$realBefore = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

try {
    $assetsDir = __DIR__ . '/../public/assets';
    $feCss = css_rules(file_get_contents($assetsDir . '/form-edit.css'));
    $spCss = css_rules(file_get_contents($assetsDir . '/settings-page.css'));
    $formEditPhp = file_get_contents(__DIR__ . '/../public/form_edit.php');
    $shellPhp = file_get_contents(__DIR__ . '/../src/partials/home_shell_top.php');

    // =====================================================================
    // ORTAM: owner + viewer + base + tablo + alanlar + form gorunumu
    // =====================================================================
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'FeUi Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => VIEWER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'FeUi Viewer'));
    $viewerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $viewerId, ':r' => 'viewer'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'FormEdit UI Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();
    // Sol paneldeki "Yildizlilar" listesi test edilebilsin diye base yildizlaniyor.
    bcc_execute('INSERT INTO user_starred_bases (user_id, base_id) VALUES (:u, :b)',
        array(':u' => $ownerId, ':b' => $baseId));

    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Basvurular'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position, is_required) VALUES (:t, :n, :ft, 0, 1)',
        array(':t' => $tableId, ':n' => 'Ad Soyad', ':ft' => 'single_line_text'));
    $fieldA = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position, is_required) VALUES (:t, :n, :ft, 1, 0)',
        array(':t' => $tableId, ':n' => 'E-posta', ':ft' => 'email'));
    $fieldB = (int) bcc_last_insert_id();

    bcc_execute("INSERT INTO views (table_id, name, view_type, position, created_by, config, form_token, form_enabled)
                 VALUES (:t, 'Basvuru Formu', 'form', 0, :u, :c, :tok, 1)",
        array(':t' => $tableId, ':u' => $ownerId,
              ':c' => json_encode(array('form_fields' => array(), 'form_title' => '', 'form_description' => '', 'form_success_message' => '', 'form_slack_notify' => 0)),
              ':tok' => bin2hex(random_bytes(16))));
    $viewId = (int) bcc_last_insert_id();
    $feUrl = '/form_edit.php?table_id=' . $tableId . '&view_id=' . $viewId;

    $cookie = login(OWNER_EMAIL);
    check('Ortam) Giris yapildi', $cookie !== null);
    $page = http_request('GET', $feUrl, $cookie);
    check('Ortam) form_edit.php 200', $page['status'] === 200, 'HTTP ' . $page['status']);
    $html = $page['body'];

    // =====================================================================
    // A) SOL PANEL
    // =====================================================================
    echo "\n--- A) Sol panel gezinmesi ---\n";
    // Bulunan gercek bug: bu sayfa $starredBases'i HIC ayarlamiyordu; kabuk
    // tanimsiz degiskenle foreach calistiriyor, yildizli base listesi
    // YALNIZCA bu sayfada bos kaliyordu (display_errors kapali, uyari yok).
    check('A) form_edit.php $starredBases i dolduruyor',
        strpos($formEditPhp, '$starredBases = bcc_fetch_all(') !== false);
    check('A) kabuk $starredBases i de varsayilana dusuruyor (diger ikisi gibi)',
        preg_match('#if \(!isset\(\$starredBases\) \|\| !is_array\(\$starredBases\)\) \{\s*\$starredBases = array\(\);#s', $shellPhp) === 1);
    // CANLI: yildizli base sol panelde GERCEKTEN cikiyor mu.
    check('A) yildizli base sol panelde GORUNUYOR',
        strpos($html, 'home-starred-item') !== false
        && strpos($html, 'FormEdit UI Test') !== false);
    check('A) kardes sayfayla AYNI cikti (table_fields.php)',
        strpos(http_request('GET', '/table_fields.php?table_id=' . $tableId, $cookie)['body'], 'home-starred-item') !== false);
    check('A) uc ana gezinme baglantisi duruyor',
        strpos($html, 'href="/dashboard.php"') !== false
        && strpos($html, 'href="/starred.php"') !== false
        && strpos($html, 'href="/workspaces.php"') !== false);
    // Daraltma dugmesi ORTAK kabukta; bu sayfada da basiliyor olmali.
    check('A) daralt/genislet dugmesi sayfada var',
        strpos($html, 'id="home-sidebar-toggle"') !== false);
    check('A) sayfa kendi sidebar CSS/JS ini YAZMIYOR (ortak kabuk)',
        strpos($formEditPhp, 'home-sidebar') === false);

    // =====================================================================
    // B) YERLESIM GENISLIGI VE ORTALAMA
    // =====================================================================
    echo "\n--- B) Kap genisligi ve ortalama ---\n";
    $fePageRule = rule_body($feCss, '.sp-page.fe-page');
    check('B) .fe-page kap genisligi 1080px (ortak olcu)',
        $fePageRule !== null && strpos($fePageRule, 'max-width: 1080px;') !== false, (string) $fePageRule);
    check('B) eski 1240px istisnasi KALKTI',
        strpos($feCss, '1240px') === false);
    // Ortalama ORTAK .sp-page kuralindan geliyor — burada kopyasi olmamali.
    $spPageRule = rule_body($spCss, '.sp-page');
    check('B) ortalama ortak kuraldan (margin: 0 auto)',
        $spPageRule !== null && strpos($spPageRule, 'margin: 0 auto;') !== false, (string) $spPageRule);
    check('B) iki sutunlu izgara duruyor',
        preg_match('#\.fe-grid \{[^}]*grid-template-columns: minmax\(0, 1fr\) minmax\(0, 340px\);#s', $feCss) === 1);
    check('B) dar ekranda tek sutuna dusuyor',
        preg_match('#@media \(max-width: 1000px\)[^}]*\{\s*\.sp-page \.fe-grid \{ grid-template-columns: minmax\(0, 1fr\); \}#s', $feCss) === 1);

    // =====================================================================
    // C) GIRDI ARALIKLARI
    // =====================================================================
    echo "\n--- C) Girdi araliklari ---\n";
    // Ortak .settings-field flex-column: etiket metni ile .sp-muted aciklamasi
    // AYRI satirlara dusuyordu ("Aciklama" / "— formun ustunde gorunur").
    check('C) etiket blok duzende (aciklama ayni satirda)',
        preg_match('#\.sp-page\.fe-page \.settings-field \{[^}]*display: block;#s', $feCss) === 1);
    check('C) girdiye ust bosluk verildi (flex gap in yerine)',
        preg_match('#\.sp-page\.fe-page \.settings-field > input,[^{]*\{[^}]*margin-top: 0\.3rem;#s', $feCss) === 1);
    check('C) alanlar arasi bosluk TEK yerden suruluyor',
        preg_match('#\.settings-field \+ \.settings-field \{[^}]*margin-top:#s', $feCss) === 1);
    // Markup'taki satir ici margin kalintilari kaldirildi.
    check('C) form_edit.php te style="margin-top:0.9rem" KALMADI',
        strpos($formEditPhp, 'margin-top:0.9rem') === false);
    check('C) tek satirlik metin girdilerine ust sinir kondu',
        preg_match('#\.fe-section \.settings-field > input\[type="text"\] \{[^}]*max-width: 480px;#s', $feCss) === 1);
    // Kapsam BILEREK .fe-page: ayni `.settings-field` sinifi dokuz sayfada daha
    // kullaniliyor (admin/*, bases, base_tables, kanban, slack_settings...).
    // .settings-field'a DOKUNAN HER seci ci .sp-page.fe-page ile baslamali,
    // yoksa bu dosya o sayfalarin etiket duzenini de sessizce degistirir.
    $unscopedFieldSelectors = array();
    if (preg_match_all('#([^{}]*\.settings-field[^{}]*)\{#', $feCss, $mm)) {
        foreach ($mm[1] as $selectorList) {
            foreach (explode(',', $selectorList) as $sel) {
                $sel = trim($sel);
                if ($sel !== '' && strpos($sel, '.settings-field') !== false
                    && strpos($sel, '.sp-page.fe-page') !== 0) {
                    $unscopedFieldSelectors[] = $sel;
                }
            }
        }
    }
    check('C) etiket duzeni kurallari .fe-page ile KAPSANMIS (diger sayfalar etkilenmiyor)',
        count($unscopedFieldSelectors) === 0,
        'kapsamsiz: ' . implode(' | ', $unscopedFieldSelectors));

    // =====================================================================
    // D) SAG PANEL — ALAN LISTESI
    // =====================================================================
    echo "\n--- D) Sag panel alan listesi ---\n";
    check('D) kart flex sutun + viewport ust siniri',
        preg_match('#\.fe-col-right > \.settings-card \{[^}]*display: flex;[^}]*flex-direction: column;[^}]*max-height: calc\(100vh - 2rem\);#s', $feCss) === 1);
    check('D) liste kalan alani aliyor ve kendi icinde kayiyor',
        preg_match('#\.fe-col-right > \.settings-card > \.fe-field-list \{[^}]*flex: 1 1 auto;[^}]*min-height: 0;[^}]*overflow-y: auto;#s', $feCss) === 1);
    // Bulunan gercek bug (kendi kurallarim arasinda): `... > *  { flex: none }`
    // (0,0,3,0) tek basina `.sp-page .fe-field-list` i (0,0,2,0) YENIYORDU;
    // liste buyuyemiyor, kart viewport'un 174px disina tasiyordu.
    check('D) liste kurali `> *` kuralindan DAHA OZGUL (ozgulluk regresyonu)',
        strpos($feCss, '.sp-page .fe-col-right > .settings-card > .fe-field-list') !== false
        && strpos($feCss, '.sp-page .fe-col-right > .settings-card > *') !== false);
    check('D) dar ekranda ic kaydirma KAPANIYOR (cift kaydirma tuzagi)',
        preg_match('#@media \(max-width: 1000px\)[\s\S]*?\.fe-col-right > \.settings-card \{ display: block; max-height: none; \}#s', $feCss) === 1);
    // ⚠️ .req-mark style.css'te tanimli, bu sayfa style.css'i YUKLEMIYOR —
    // yildiz TAMAMEN stilsiz cikiyordu (ayni tuzak .field-badge'de yasanmisti).
    check('D) zorunluluk yildizi bu sayfada tanimli (style.css yuklenmiyor)',
        preg_match('#\.fe-field-inner \.req-mark \{[^}]*color: var\(--bcc-danger\);#s', $feCss) === 1);
    check('D) yildiz adin YANINDA (kart kenarina itilmiyor)',
        preg_match('#\.fe-field-inner \.req-mark \{[^}]*margin-right: auto;#s', $feCss) === 1
        && preg_match('#\.fe-field-name \{[^}]*flex: 0 1 auto;#s', $feCss) === 1);
    check('D) sayfa style.css i GERCEKTEN yuklemiyor (varsayimin dogrulanmasi)',
        strpos($html, 'assets/style.css') === false);
    check('D) zorunlu alan icin yildiz render ediliyor',
        strpos($html, 'req-mark') !== false);

    // =====================================================================
    // E) REGRESYON — SUNUCU TARAFI DEGISMEDI
    // =====================================================================
    echo "\n--- E) Regresyon: kaydetme ve salt-okunur dal ---\n";
    foreach (array('form_enabled', 'form_title', 'form_description', 'form_success_message', 'form_slack_notify', 'form_fields[]') as $name) {
        check("E) '{$name}' girdi adi DEGISMEDI", strpos($html, 'name="' . $name . '"') !== false);
    }
    $csrf = extract_csrf_field($html);
    $save = http_request('POST', '/form_edit.php', $cookie, array(
        'csrf_token' => $csrf, 'action' => 'save_form', 'table_id' => $tableId, 'view_id' => $viewId,
        'form_enabled' => '1', 'form_title' => 'Basvuru', 'form_description' => 'Aciklama',
        'form_success_message' => 'Tesekkurler', 'form_fields' => array($fieldA, $fieldB),
    ));
    check('E) kaydetme 200', $save['status'] === 200, 'HTTP ' . $save['status']);
    check('E) basari mesaji gosteriliyor', strpos($save['body'], 'Form ayarları kaydedildi.') !== false);
    $savedView = bcc_find_view($viewId, $tableId);
    $savedCfg = bcc_form_config_from_view($savedView);
    check('E) secilen alanlar DB ye yazildi',
        $savedCfg['form_fields'] === array($fieldA, $fieldB),
        json_encode($savedCfg['form_fields']));
    check('E) baslik DB ye yazildi', $savedCfg['form_title'] === 'Basvuru');

    $viewerCookie = login(VIEWER_EMAIL);
    $roPage = http_request('GET', $feUrl, $viewerCookie);
    check('E) salt-okunur dal 200', $roPage['status'] === 200, 'HTTP ' . $roPage['status']);
    check('E) salt-okunur dalda duzenleme formu YOK',
        strpos($roPage['body'], 'class="fe-grid"') === false);
    // ⚠️ TERSINE CEVRILDI: paylasim karti IKI daldan da (editor + salt-okunur)
    // KALDIRILDI. Test silinmedi, kaldirmanin kalici oldugunu dogruluyor.
    check('E) salt-okunur dalda paylasim karti YOK (kaldirildi)',
        strpos($roPage['body'], 'share-popover-form') === false);
    check('E) salt-okunur dalda da sol panel yildizlilari calisiyor (kabuk ortak)',
        strpos($roPage['body'], 'home-starred-list') !== false);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

echo "\n--- F) Gercek base (id " . REAL_BASE_ID . ") dokunulmadi mi ---\n";
$realAfter = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);
foreach ($realBefore as $k => $before) {
    check("Base " . REAL_BASE_ID . " {$k} sayisi degismedi ({$before})", $realAfter[$k] === $before,
        "once={$before} sonra={$realAfter[$k]}");
}

$passed = count(array_filter($results));
$total = count($results);
echo "\n==== SONUC: {$passed}/{$total} ====\n";
exit($passed === $total ? 0 : 1);
