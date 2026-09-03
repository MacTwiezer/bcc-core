<?php
// workspaces.php'nin UC LISTESININ ortak yukseklik bandi:
//   sol  = calisma alani secici (.wsx-panel-list)
//   orta = katilimcilar          (.wsx-collab-grid)
//   sag  = son hareketler        (.wsx-act-list)
//
// Istenen davranis: ucu de AYNI banda sahip; icerik banttan uzunsa liste kendi
// icinde kayar, kisaysa kart yine de bant boyu yer kaplar (zemin kisalmaz).
//
// ⚠️ Bu bir CSS davranisi -- headless tarayici bu makinede YOK (/browse kurulu
// degil), bu yuzden "gercekten kayiyor mu" pikselden DOGRULANAMAZ. Olculebilen
// sey kurallarin dogru kurulmus olmasi + sayfanin uc listeyi de GERCEKTEN
// basmasi. Piksel teyidi kullaniciya birakiliyor (raporda yazili).
//
// Kapsam:
//   A) Bant tek bir degiskende, uc secici de onu kullaniyor (kopya deger yok)
//   B) Eski, listeye OZEL max-height kurali kalmadi
//   C) Tasma davranisi: overflow-y auto + kaydirma cubugu bosluğu ayrilmis
//   D) Icerik azken satirlar esnemesin (align-content: start)
//   E) Bos durumda da bant korunuyor (kart cokmuyor)
//   F) Dar ekranda sol liste banttan MUAF (orada yatay seride donuyor)
//   G) CANLI: sayfa uc listeyi de gercekten basiyor (secici bos degil)
//
// On kosul: Apache ayakta. Calistirma:
//   C:\php73\php.exe scripts\_verify_workspaces_list_bands.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

// Bu betik gercek uc noktalardan yaziyor; olusan denetim satirlari test
// kullanicisi silinince audit_log'da OKSUZ kaliyordu. Kapanista yalnizca bu
// kosunun urettigi ve aktoru artik var olmayan satirlar temizlenir.
require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('EMAIL', 'wsband.owner@bcc-test.local');
define('MATE', 'wsband.mate@bcc-test.local');
define('PASS', 'WsBand!2026');
define('TEAM', 'WSBAND Test Alani');

$results = array();
function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) { echo '         detay: ' . $detail . "\n"; }
}

function http_request($method, $path, $cookie = null, $post = null)
{
    $h = array();
    if ($cookie !== null) { $h[] = 'Cookie: ' . $cookie; }
    $o = array('http' => array('method' => $method, 'ignore_errors' => true));
    if ($method === 'POST') {
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
        $o['http']['content'] = http_build_query($post);
    }
    $o['http']['header'] = implode("\r\n", $h);
    $b = @file_get_contents(BASE_URL . $path, false, stream_context_create($o));
    $st = 0; $nc = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $x) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $x, $m)) { $st = (int) $m[1]; }
            if (stripos($x, 'Set-Cookie:') === 0) { $p = explode(';', substr($x, 11)); $nc = trim($p[0]); }
        }
    }
    return array('body' => (string) $b, 'cookie' => $nc, 'status' => $st);
}

$cssRaw = file_get_contents(__DIR__ . '/../public/assets/workspaces.css');

// ⚠️ YORUMLAR AYIKLANIYOR — bu bir konfor degil, GERCEK bir yanlis-pozitif
// duzeltmesi: "artik su kural yok" diyen kontroller, kaldirilan kuralin ADINI
// aciklama yorumunda GECEN bir satir yuzunden duser. Bu tuzaga bu turda uc kez
// dusuldu (wsInviteRoles, .wsx-invite, max-height). Kontroller CANLI CSS'e
// bakmali, aciklama metnine degil.
$css = preg_replace('#/\*.*?\*/#s', '', $cssRaw);

// Bir seciciye ait TUM kural govdelerini dondurur.
//
// ⚠️ NEDEN "TUM": ilk sürümü preg_match ile tek govde donduruyordu ve YANLIS
// kurali yakaladi -- ayni secicinin bir de @media (max-width: 980px) icinde
// override'i var (`flex: 1 1 220px`, dar ekranda yatay serit icin KASITLI) ve o
// dosyada DAHA ONCE geliyor. Taban kuralin dogru oldugu hâlde test KALDI
// veriyordu.
function rule_bodies($css, $selector)
{
    $re = '#(?<![-\w.])' . preg_quote($selector, '#') . '\s*\{([^}]*)\}#';
    if (preg_match_all($re, $css, $m)) { return $m[1]; }
    return array();
}

// Seciciye ait TABAN kural (medya sorgusu icindeki override degil). Taban
// kural, override'da bulunmayan ozellikleri (ornegin position/border-radius)
// tasidigi icin ondan ayirt ediliyor.
function base_rule_body($css, $selector, $marker)
{
    foreach (rule_bodies($css, $selector) as $body) {
        if (strpos($body, $marker) !== false) { return $body; }
    }
    return null;
}

// =====================================================================
echo "\n--- A) Bant TEK degiskende, uc secici de onu kullaniyor ---\n";
check('A) --wsx-list-h tanimli', preg_match('/--wsx-list-h:\s*\d+px/', $css) === 1);

// Uc secici AYNI kuralda gruplanmis mi?
$grouped = preg_match(
    '#\.sp-page\s+\.wsx-panel-list,\s*\.sp-page\s+\.wsx-collab-grid,\s*\.sp-page\s+\.wsx-act-list\s*\{([^}]*)\}#s',
    $css,
    $gm
);
check('A) uc liste TEK ortak kuralda gruplanmis (kopya deger yok)', $grouped === 1);
$band = $grouped === 1 ? $gm[1] : '';
check('A) yukseklik degiskenden okunuyor (sabit px yazilmamis)',
    strpos($band, 'height: var(--wsx-list-h)') !== false
    && preg_match('/height:\s*\d+px/', $band) === 0,
    trim(preg_replace('/\s+/', ' ', $band)));

// =====================================================================
echo "\n--- B) Eski listeye OZEL yukseklik kalmadi ---\n";
$actBodies = rule_bodies($css, '.sp-page .wsx-act-list');
$actBody = $actBodies ? $actBodies[0] : null;
check('B) .wsx-act-list kurali duruyor', $actBody !== null);
check('B) icinde artik max-height YOK (eskiden 420px idi)',
    $actBody !== null && strpos($actBody, 'max-height') === false,
    $actBody === null ? 'kural yok' : trim(preg_replace('/\s+/', ' ', $actBody)));
check('B) dosyada baska bir yerde bandi ezen max-height kalmadi',
    preg_match('/max-height:\s*\d+px/', $css) === 0);

// =====================================================================
echo "\n--- C) Tasma davranisi ---\n";
check('C) overflow-y: auto (banttan uzunsa kaydirma cubugu)',
    strpos($band, 'overflow-y: auto') !== false);
check('C) scrollbar-gutter: stable (cubuk cikinca sutun genisligi oynamasin)',
    strpos($band, 'scrollbar-gutter: stable') !== false);
check('C) scrollbar-width: thin', strpos($band, 'scrollbar-width: thin') !== false);

// =====================================================================
echo "\n--- D) Satirlar ne ESNESIN ne EZILSIN ---\n";
check('D) align-content: start (izgarada satirlar bandi doldurmak icin esnemesin)',
    strpos($band, 'align-content: start') !== false);

// ⚠️ GERCEK BIR HATA YAKALANDI VE BURAYA BAGLANDI: .wsx-panel-list bir flex
// column. Sabit yukseklik verilince cocuklarin varsayilan flex-shrink:1'i
// yuzunden satirlar TASMAK yerine EZILIYORDU -- kartlar birbirine yapisiyor,
// kaydirma cubugu da hic cikmiyordu (tasma yoksa kaydirma da yok).
// align-content BURADA CARE DEGIL: wrap'siz tek satirli flex kabinda o ozellik
// hic uygulanmaz. Care cocuklarda flex: none.
$cardBody = base_rule_body($css, '.sp-page .wsx-panel-list .wsx-card', 'position: relative');
check('D) sol liste satirlari flex kabinda EZILMIYOR (taban kuralda flex: none)',
    $cardBody !== null
    && (strpos($cardBody, 'flex: none') !== false || strpos($cardBody, 'flex-shrink: 0') !== false),
    $cardBody === null ? 'taban kural bulunamadi' : trim(preg_replace('/\s+/', ' ', $cardBody)));
// Dar ekrandaki KASITLI override bozulmasin: 980px altinda kartlar yatayda
// sarip esnemeli (orada liste zaten banttan muaf).
check('D) 980px altindaki yatay serit override i DURUYOR (flex: 1 1 220px)',
    preg_match('#@media\s*\(max-width:\s*980px\)\s*\{.*?\.wsx-panel-list\s+\.wsx-card\s*\{[^}]*flex:\s*1\s+1\s+220px#s', $css) === 1);

// =====================================================================
echo "\n--- E) BOS durumda da bant korunuyor ---\n";
$emptyGrouped = preg_match(
    '#\.sp-page\s+\.wsx-collab-empty,\s*\.sp-page\s+\.wsx-act-empty\s*\{([^}]*)\}#s',
    $css,
    $em
);
check('E) iki bos durum TEK kuralda', $emptyGrouped === 1);
check('E) bos durumda min-height yine bant degiskeni',
    $emptyGrouped === 1 && strpos($em[1], 'min-height: var(--wsx-list-h)') !== false);

// =====================================================================
echo "\n--- F) Dar ekranda sol liste banttan MUAF ---\n";
// 980px kirilimi: sol liste yatay seride donuyor, orada sabit bant anlamsiz.
check('F) 980px altinda .wsx-panel-list height:auto',
    preg_match('#@media\s*\(max-width:\s*980px\)\s*\{.*?\.sp-page\s+\.wsx-panel-list\s*\{[^}]*height:\s*auto#s', $css) === 1);
check('F) ayni yerde overflow da serbest birakilmis',
    preg_match('#@media\s*\(max-width:\s*980px\)\s*\{.*?\.sp-page\s+\.wsx-panel-list\s*\{[^}]*overflow:\s*visible#s', $css) === 1);

// =====================================================================
// G) CANLI: sayfa uc listeyi de gercekten basiyor mu?
// =====================================================================
echo "\n--- G) CANLI: uc liste de sayfada basiliyor ---\n";

$wipe = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => TEAM)) as $r) {
        bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id']));
    }
    bcc_execute('DELETE FROM users WHERE email IN (:a, :b)', array(':a' => EMAIL, ':b' => MATE));
};
$wipe();

try {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM));
    $tid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
        array(':e' => EMAIL, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => 'WSBAND Owner'));
    $uid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
        array(':e' => MATE, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => 'WSBAND Uye'));
    $mid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tid,':u'=>$uid,':r'=>'owner'));
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tid,':u'=>$mid,':r'=>'editor'));

    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $r['body'], $m);
    $r = http_request('POST', '/login.php', $c, array('email'=>EMAIL,'password'=>PASS,'csrf_token'=>$m[1]));
    $c = $r['cookie'] ? $r['cookie'] : $c;

    $p = http_request('GET', '/workspaces.php?team_id=' . $tid, $c);
    $b = $p['body'];

    check('G) sayfa 200', $p['status'] === 200, 'HTTP ' . $p['status']);
    check('G) sol liste kabi basiliyor', strpos($b, 'wsx-panel-list') !== false);
    check('G) orta liste kabi basiliyor', strpos($b, 'wsx-collab-grid') !== false);
    // Bu takimda hic hareket yok -> sag tarafta BOS DURUM basilmali; bant
    // kuralinin bos dalda da is gordugunu boylece gercek ciktida gozluyoruz.
    check('G) sag tarafta liste VEYA bos durum basiliyor',
        strpos($b, 'wsx-act-list') !== false || strpos($b, 'wsx-act-empty') !== false);
    check('G) sayfa workspaces.css yukluyor (bant kurallari geliyor)',
        strpos($b, 'workspaces.css') !== false);
} catch (Throwable $e) {
    echo "\n[HATA] " . $e->getMessage() . "\n";
    $results[] = false;
}

$wipe();
check('Z) test ekibi silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => TEAM)) === 0);

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($pass === $total ? 'GECTI' : 'KALDI') . " ($pass/$total)\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
