<?php
// Genel arama ("Ara..." / Ctrl K) — birlestirilmis davranisin dogrulanmasi.
//
// Neden bu betik var: arama sonuclari sayfanin DOM'undan TOPLANIYOR
// (assets/global-search.js icindeki collector'lar). Bu, sunucu tarafinda
// ikinci bir sorgu gerektirmedigi icin ucuz ama KIRILGAN: bir sayfanin
// markup'i degisip bir sinif adi kaybolursa arama SESSIZCE bos doner.
// Asagidaki C bolumu tam olarak bunu yakalar — her collector'in aradigi
// secici, o sayfanin GERCEK ciktisinda var mi?
//
// Calistirma: C:\php73\php.exe scripts\_verify_global_search.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

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

function render_as($userId, $page, $query = '')
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_render_as_case.php')
        . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg($query);

    return (string) shell_exec($cmd . ' 2>&1');
}

$root = __DIR__ . '/..';
$js = file_get_contents($root . '/public/assets/global-search.js');
$homeJs = file_get_contents($root . '/public/assets/home.js');

// ---------------------------------------------------------------------------
// A) Kisayol ve kapanma mantigi — kaynak seviyesinde
// ---------------------------------------------------------------------------
echo "--- A) Kisayol / kapanma mantigi ---\n";

check('Ctrl+K ve Cmd+K birlikte destekleniyor',
    strpos($js, 'e.ctrlKey || e.metaKey') !== false && strpos($js, "e.key === 'k'") !== false);

// ASIL REGRESYON KORUMASI: eski kusur, kisayolun bir icerik kosulunun
// (#home-base-grid var mi) ICINDE kayitli olmasiydi. Kayit noktasinin
// kosulsuz oldugunu dogrula: keydown dinleyicisinden ONCE gelen metinde
// bir "if (... grid ...)" bloguna girilmemis olmali.
// Suslu parantez derinligi: bir ifadenin kac blok icinde oldugunu verir.
// (Kendi yazdigimiz dosya oldugu icin string/yorum icindeki suslu parantez
// riski yok — asagidaki iki isaret de sade kod satirlari.)
function brace_depth($src, $pos)
{
    $depth = 0;
    for ($i = 0; $i < $pos; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
        }
    }

    return $depth;
}

$shortcutPos = strpos($js, "if ((e.ctrlKey || e.metaKey)");
check('Ctrl+K dinleyicisi kaynakta bulunuyor', $shortcutPos !== false);

// ASIL REGRESYON KORUMASI. Eski kusur "kisayol bir ICERIK kosulunun
// (#home-base-grid var mi) icinde kayitliydi" seklindeydi. Bunu metin arayarak
// yakalamak yaniltici: collector fonksiyonlarinin ICINDE de `if (!grid)` var ve
// bu tamamen dogru. Dogru olcut YAPISAL: kisayolun kayit noktasi, kosulsuz
// kurulum kodunun (`var lastFocused = null;`) TAM OLARAK AYNI blok
// derinliginde olmali. Bir gun biri kaydi bir `if` icine alirsa derinlik artar
// ve bu test duser.
$registrationPos = strpos($js, "document.addEventListener('keydown', function (e) {");
$baselinePos = strpos($js, 'var lastFocused = null;');

check('kisayol kaydi ve kosulsuz kurulum kodu AYNI blok derinliginde '
    . '(kisayol bir icerik kosuluna sarilmamis)',
    $registrationPos !== false && $baselinePos !== false
    && brace_depth($js, $registrationPos) === brace_depth($js, $baselinePos),
    $registrationPos !== false && $baselinePos !== false
        ? 'kayit=' . brace_depth($js, $registrationPos) . ' referans=' . brace_depth($js, $baselinePos)
        : 'isaret bulunamadi');

check('Escape + disari tiklama ortak yardimciyla (bcc_bindDismissable)',
    strpos($js, 'bcc_bindDismissable(details') !== false);

// Backdrop hatasi: .home-search-overlay <details>'in ICINDE oldugu icin
// varsayilan !el.contains(target) olcutu asla saglanmiyordu.
check('isClickOutside override edilmis (backdrop <details> icinde)',
    strpos($js, 'isClickOutside: function') !== false);
check('panel ici tiklama kapatmiyor', strpos($js, 'popover.contains(target)') !== false);
check('tetikleyiciye tiklama cift-toggle yapmiyor', strpos($js, 'trigger.contains(target)') !== false);

check('Escape sonrasi odak geri veriliyor',
    strpos($js, 'lastFocused') !== false && strpos($js, 'lastFocused.focus()') !== false);
check('ok tuslariyla gezinme var', strpos($js, "e.key === 'ArrowDown'") !== false);
check('Enter ile secim var', strpos($js, "e.key === 'Enter'") !== false);

check('home.js artik kendi arama blogunu TASIMIYOR (kopya yok)',
    strpos($homeJs, 'home-search-result') === false && strpos($homeJs, 'resultItems') === false);
check('home.js silme islemi arama listesini ortak kancadan temizliyor',
    strpos($homeJs, 'bcc_searchRemoveItem') !== false);
check('global-search.js o kancayi disariya aciyor',
    strpos($js, 'window.bcc_searchRemoveItem') !== false);

// ---------------------------------------------------------------------------
// B) Markup ve script HER sayfada var mi
// ---------------------------------------------------------------------------
echo "\n--- B) Bilesenin sayfalara dagilimi ---\n";

$team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'Demo Calisma Alani' LIMIT 1");
if ($team === false || $team === null) {
    die("Demo ekibi yok. Once: C:\\php73\\php.exe scripts\\seed_demo_users.php\n");
}
$teamId = (int) $team['id'];

$owner = bcc_fetch_one("SELECT id FROM users WHERE email = 'owner@bcc.local' LIMIT 1");
$ownerId = (int) $owner['id'];

$tableRow = bcc_fetch_one(
    "SELECT tm.id FROM tables_meta tm JOIN bases b ON b.id = tm.base_id
     WHERE b.team_id = :t AND tm.name = 'Musteriler' LIMIT 1",
    array('t' => $teamId)
);
$tableId = (int) $tableRow['id'];

$pages = array(
    'dashboard.php' => '',
    'starred.php' => '',
    'workspaces.php' => 'team_id=' . $teamId,
    'team_members.php' => 'team_id=' . $teamId,
    'grid.php' => 'table_id=' . $tableId,
);

$html = array();
foreach ($pages as $page => $query) {
    $html[$page] = render_as($ownerId, $page, $query);

    check($page . ': arama tetikleyicisi basiliyor', strpos($html[$page], 'id="home-search"') !== false);
    check($page . ': arama girdisi basiliyor', strpos($html[$page], 'id="home-search-input"') !== false);
    check($page . ': backdrop basiliyor', strpos($html[$page], 'home-search-overlay') !== false);
    check($page . ': global-search.js yukleniyor', strpos($html[$page], 'global-search.js') !== false);
    check($page . ': dismissable-panel.js global-search.js\'ten ONCE',
        strpos($html[$page], 'dismissable-panel.js') < strpos($html[$page], 'global-search.js'));
}

// Markup TEK dosyadan gelmeli (kopya yok).
$shellTop = file_get_contents($root . '/src/partials/home_shell_top.php');
$gridSrc = file_get_contents($root . '/public/grid.php');
check('home_shell_top.php arama markup\'ini ORTAK partial\'dan aliyor',
    strpos($shellTop, "require __DIR__ . '/global_search.php'") !== false
    && strpos($shellTop, 'home-search-popover') === false);
check('grid.php AYNI partial\'i kullaniyor (ikinci kopya yok)',
    strpos($gridSrc, "partials/global_search.php") !== false
    && strpos($gridSrc, 'home-search-popover') === false);

// ---------------------------------------------------------------------------
// C) Collector secicileri gercek ciktiyla ESLESIYOR MU (asil kirilganlik)
// ---------------------------------------------------------------------------
echo "\n--- C) Collector secicileri gercek DOM ile eslesiyor mu ---\n";

// Her satir: [sayfa, aciklama, global-search.js'in aradigi secici parcasi,
//             o sayfanin ciktisinda bulunmasi gereken metin]
$selectorChecks = array(
    array('dashboard.php', 'base kart kabi', "getElementById('home-base-grid')", 'id="home-base-grid"'),
    array('dashboard.php', 'base kart sinifi', ".home-base-card", 'home-base-card'),
    array('dashboard.php', 'base adi', ".home-base-name", 'home-base-name'),
    array('dashboard.php', 'base meta', ".home-base-meta", 'home-base-meta'),
    array('dashboard.php', 'olustur kutucugu (ATLANMALI)', "home-base-create", 'home-base-create'),

    array('workspaces.php', 'calisma alani listesi', ".wsx-side", 'wsx-side'),
    array('workspaces.php', 'alan adi', ".wsx-card-name", 'wsx-card-name'),
    array('workspaces.php', 'katilimci izgarasi', "getElementById('wsx-collab-grid')", 'id="wsx-collab-grid"'),
    array('workspaces.php', 'katilimci adi', ".wsx-member-name", 'wsx-member-name'),
    array('workspaces.php', 'katilimci e-postasi', ".wsx-member-mail", 'wsx-member-mail'),
    array('workspaces.php', 'rol hapi', ".sp-role", 'sp-role'),

    array('team_members.php', 'satir kabi', "[data-tm-rows]", 'data-tm-rows'),
    array('team_members.php', 'uye satiri', ".tm-row", 'tm-row'),
    array('team_members.php', 'uye adi', ".ws-collab-name", 'ws-collab-name'),
    array('team_members.php', 'uye e-postasi', ".ws-collab-email", 'ws-collab-email'),
    array('team_members.php', 'rol acilir listesi', ".tm-role-select", 'tm-role-select'),

    array('grid.php', 'tablo elemani', "table.grid", '<table class="grid'),
    array('grid.php', 'kayit satiri', "tr[data-record-id]", 'data-record-id='),
    array('grid.php', 'satir numarasi hucresi (ATLANMALI)', "td:not(.grid-rownum)", 'class="grid-rownum"'),
);

foreach ($selectorChecks as $sc) {
    list($page, $desc, $selectorFragment, $needle) = $sc;

    check("global-search.js '$selectorFragment' seciciyi kullaniyor",
        strpos($js, $selectorFragment) !== false);
    check("$page ciktisinda o secicinin hedefi VAR ($desc)",
        strpos($html[$page], $needle) !== false);
}

// Salt-okunur rol metni (owner olmayan gorunum) de aranabilir olmali.
$viewer = bcc_fetch_one("SELECT id FROM users WHERE email = 'viewer@bcc.local' LIMIT 1");
$viewerHtml = render_as((int) $viewer['id'], 'team_members.php', 'team_id=' . $teamId);
check('global-search.js salt-okunur rol metnini de okuyor (.tm-role-readonly)',
    strpos($js, 'tm-role-readonly') !== false);
check('viewer gorunumunde .tm-role-readonly gercekten basiliyor',
    strpos($viewerHtml, 'tm-role-readonly') !== false);
check('viewer gorunumunde .tm-role-select YOK (RBAC) — collector duz metne dusmeli',
    strpos($viewerHtml, 'tm-role-select') === false);

// ---------------------------------------------------------------------------
// D) Baglam etiketi ve stiller
// ---------------------------------------------------------------------------
echo "\n--- D) Baglam etiketi / stiller ---\n";

$css = file_get_contents($root . '/public/assets/home.css');

check('kapsam etiketi kabi basiliyor', strpos($html['dashboard.php'], 'id="home-search-scope"') !== false);
check('kapsam etiketi stilli', strpos($css, '.home-search-scope') !== false);
check('klavye ile secili satir stilli', strpos($css, '.home-search-result.is-active') !== false);
check('<button> sonuc satirlari sifirlanmis', strpos($css, 'button.home-search-result') !== false);
check('sayfa ici atlama vurgusu stilli', strpos($css, '.bcc-search-flash') !== false);
check('grid ust bari tetikleyicisi stilli', strpos($css, '.gs-topbar-search') !== false);
check('sayfa ici atlama JS tarafinda uygulanmis', strpos($js, "classList.add('bcc-search-flash')") !== false);

// ---------------------------------------------------------------------------
echo "\n";
$failed = count(array_filter($results, function ($r) { return !$r; }));
echo ($failed === 0 ? 'TUM TESTLER GECTI' : $failed . ' TEST KALDI') . ' (' . count($results) . " kontrol)\n";
exit($failed === 0 ? 0 : 1);
