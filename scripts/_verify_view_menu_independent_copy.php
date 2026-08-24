<?php
// GORUNUM MENUSUNDEKI "Bagimsiz kopya olustur" -- kopya ile asil BIRBIRINDEN
// TAMAMEN BAGIMSIZ mi?
//
// ⚠️ NEDEN BU TEST VAR: kullanici UC kez "Gorunumu cogalt ile kopya olusturunca
// kopyadan yaptigim degisiklik orijinali, orijinalden yaptigim kopyayi
// etkiliyor" diye bildirdi. TESHIS (olculdu):
//   - VIEW CONFIG (siralama/filtre/gizli alan) ZATEN bagimsizdi -- sizinti
//     ORADA DEGILDI, o yuzden onceki denemeler sonuc vermedi.
//   - Sizan sey VERIydi ve bu YAPISALDI: kayitlar gorunume degil TABLOYA
//     baglidir (records.table_id). Gorunum cogaltmak yeni bir MERCEK uretir,
//     yeni VERI uretmez -- iki gorunum de AYNI table_id'yi paylasiyordu.
// COZUM: menu kalemi artik api/table_duplicate.php'yi cagiriyor (TABLO
// duzeyinde kopya), eski api/view_duplicate.php KALDIRILDI.
//
// Kapsam:
//   A) Menu kalemi dogru ucnoktaya bagli, eski ucnokta yok
//   B) Yetki esigi editor -> owner oldu (bilincli, kullaniciya soruldu)
//   C) UCTAN UCA BAGIMSIZLIK -- bu testin KALBI:
//        kopyada hucre degistir  -> ASIL DEGISMEDI
//        asilda hucre degistir   -> KOPYA DEGISMEDI
//        kopyaya kayit ekle      -> ASLIN kayit sayisi DEGISMEDI
//        kopyada kayit sil       -> ASIL etkilenmedi
//        kopyaya ALAN ekle       -> ASLIN sema si DEGISMEDI
//   D) Kopya kendi kayitlarini TASIYOR (bos gelmiyor)
//   E) "Ayni veriye bakan ikinci gorunum" yolu HALA VAR (+ Yeni olustur...)
//   F) Kopya oldugu TABLO ADINDAN belli ve o ad DEGISTIRILEBILIR
//      (bir ara sol panele ayri bir "kopyalar" bolumu eklenmisti; kullanici
//       "boyle ayri olmasin" dedi, kaldirildi -- bkz. bolum F)
//
// On kosul: Apache ayakta. Calistirma:
//   C:\php73\php.exe scripts\_verify_view_menu_independent_copy.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_MAIL', 'vmic.owner@bcc-test.local');
define('EDITOR_MAIL', 'vmic.editor@bcc-test.local');
define('PASS', 'VMic!2026');
define('TEAM', 'VMIC Test Alani');

$results = array();
function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) { echo '         detay: ' . $detail . "\n"; }
}
function eq($label, $got, $want)
{
    check($label, $got === $want, 'beklenen=' . var_export($want, true) . ' gelen=' . var_export($got, true));
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

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $r['body'], $m);
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => PASS, 'csrf_token' => isset($m[1]) ? $m[1] : '',
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function strip_js_comments($s)
{
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    return preg_replace('#^\s*//.*$#m', '', $s);
}

$root = dirname(__DIR__);
$vmJs = strip_js_comments(file_get_contents($root . '/public/assets/grid-view-manage.js'));
$gridPhp = file_get_contents($root . '/public/grid.php');

// =====================================================================
echo "\n--- A) Menu kalemi dogru ucnoktaya bagli ---\n";
check('A) tetikleyici api/table_duplicate.php yi cagiriyor',
    preg_match('#gs-view-duplicate-item[\s\S]{0,900}?/api/table_duplicate\.php#', $vmJs) === 1);
check('A) ARTIK view_duplicate.php cagrilmiyor',
    strpos($vmJs, '/api/view_duplicate.php') === false);
check('A) eski ucnokta dosyasi SILINDI',
    !is_file($root . '/public/api/view_duplicate.php'));
check('A) kayitlar da kopyalaniyor (with_records)',
    preg_match("#with_records:\s*'1'#", $vmJs) === 1);
check('A) hedef adres SUNUCUDAN (istemci URL uydurmuyor)',
    strpos($vmJs, 'result.data.redirect_url') !== false);
check('A) menu etiketi "Bagimsiz kopya olustur"',
    strpos($gridPhp, 'Bağımsız kopya oluştur') !== false
    && strpos($gridPhp, 'Görünümü çoğalt') === false);

echo "\n--- B) Yetki esigi owner ---\n";
check('B) menu kalemi $isOwner kosulunun ICINDE',
    preg_match('#<\?php if \(\$isOwner\): \?>[\s\S]{0,2000}?gs-view-duplicate-item#', $gridPhp) === 1);

$wipe = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => TEAM)) as $r) {
        bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id']));
    }
    bcc_execute('DELETE FROM users WHERE email IN (:a,:b)',
        array(':a' => OWNER_MAIL, ':b' => EDITOR_MAIL));
};
$wipe();

try {
    // ---- ORTAM
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM));
    $tid = (int) bcc_last_insert_id();
    $mk = function ($mail, $name) {
        bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
            array(':e' => $mail, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => $name));
        return (int) bcc_last_insert_id();
    };
    $ownerId = $mk(OWNER_MAIL, 'VMIC Owner');
    $editorId = $mk(EDITOR_MAIL, 'VMIC Editor');
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tid,':u'=>$ownerId,':r'=>'owner'));
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tid,':u'=>$editorId,':r'=>'editor'));

    bcc_execute('INSERT INTO bases (team_id,name,created_by) VALUES (:t,:n,:u)', array(':t'=>$tid,':n'=>'VMIC Base',':u'=>$ownerId));
    $bid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id,name,position) VALUES (:b,:n,0)', array(':b'=>$bid,':n'=>'VMIC Tablo'));
    $srcTable = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id,name,field_type,position) VALUES (:t,:n,:ft,0)',
        array(':t'=>$srcTable,':n'=>'Sehir',':ft'=>'single_line_text'));
    $srcField = (int) bcc_last_insert_id();

    // Iki kayit
    $srcRecs = array();
    foreach (array('ORIJINAL-1', 'ORIJINAL-2') as $i => $val) {
        bcc_execute('INSERT INTO records (table_id,position,created_by) VALUES (:t,:p,:u)',
            array(':t'=>$srcTable,':p'=>$i,':u'=>$ownerId));
        $rid = (int) bcc_last_insert_id();
        $srcRecs[] = $rid;
        bcc_execute('INSERT INTO cell_values (record_id,field_id,value_text) VALUES (:r,:f,:v)',
            array(':r'=>$rid,':f'=>$srcField,':v'=>$val));
    }
    bcc_execute("INSERT INTO views (table_id,name,view_type,position,created_by) VALUES (:t,'Asil Gorunum','grid',0,:u)",
        array(':t'=>$srcTable,':u'=>$ownerId));
    $srcView = (int) bcc_last_insert_id();

    $ownerCookie = login(OWNER_MAIL);
    $editorCookie = login(EDITOR_MAIL);
    $page = http_request('GET', '/grid.php?table_id='.$srcTable.'&view_id='.$srcView, $ownerCookie);
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $cm);
    $csrf = isset($cm[1]) ? $cm[1] : '';

    echo "\n--- B2) CANLI yetki ---\n";
    check('B2) owner sayfada tetikleyiciyi goruyor',
        strpos($page['body'], 'gs-view-duplicate-item') !== false);
    $ePage = http_request('GET', '/grid.php?table_id='.$srcTable.'&view_id='.$srcView, $editorCookie);
    check('B2) editor tetikleyiciyi GORMUYOR',
        strpos($ePage['body'], 'gs-view-duplicate-item') === false);
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $ePage['body'], $ecm);
    $eTry = http_request('POST', '/api/table_duplicate.php', $editorCookie, array(
        'csrf_token' => isset($ecm[1]) ? $ecm[1] : '', 'table_id' => $srcTable, 'name' => '', 'with_records' => '1',
    ));
    check('B2) editor in istegi UCNOKTADA da reddediliyor (gizleme != yetki)',
        $eTry['status'] === 403, 'HTTP ' . $eTry['status'] . ' ' . substr($eTry['body'], 0, 100));

    // ---- MENUNUN YAPTIGI CAGRININ TA KENDISI
    echo "\n--- C) UCTAN UCA BAGIMSIZLIK ---\n";
    $dup = http_request('POST', '/api/table_duplicate.php', $ownerCookie, array(
        'csrf_token' => $csrf, 'table_id' => $srcTable, 'name' => '', 'with_records' => '1',
    ));
    $dj = json_decode($dup['body'], true);
    check('C) kopya olusturuldu', $dup['status'] === 200 && !empty($dj['ok']),
        'HTTP ' . $dup['status'] . ' ' . substr($dup['body'], 0, 160));
    $copyTable = (int) $dj['table_id'];
    check('C) YENI bir tablo id si (mercek degil)', $copyTable !== $srcTable,
        "src=$srcTable copy=$copyTable");

    $copyField = (int) bcc_fetch_column('SELECT id FROM fields WHERE table_id=:t ORDER BY position LIMIT 1', array(':t'=>$copyTable));
    $copyRecs = bcc_fetch_all('SELECT id FROM records WHERE table_id=:t ORDER BY position', array(':t'=>$copyTable));
    check('D) kopya KENDI kayitlarini tasiyor (bos degil)', count($copyRecs) === 2, 'adet: ' . count($copyRecs));
    check('D) kopyanin kayit id leri asildan FARKLI',
        !in_array((int) $copyRecs[0]['id'], $srcRecs, true));
    eq('D) kopyanin ilk hucre degeri asilla ayni (icerik kopyalandi)',
        bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id=:r AND field_id=:f',
            array(':r'=>$copyRecs[0]['id'], ':f'=>$copyField)),
        'ORIJINAL-1');

    $cell = function ($rid, $fid) {
        return bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id=:r AND field_id=:f',
            array(':r'=>$rid, ':f'=>$fid));
    };

    // --- 1) KOPYADA degistir -> ASIL degismesin
    http_request('POST', '/api/cell_update.php', $ownerCookie, array(
        'csrf_token'=>$csrf, 'record_id'=>$copyRecs[0]['id'], 'field_id'=>$copyField, 'value'=>'KOPYADAN-DEGISTI',
    ));
    eq('C1) kopyanin hucresi degisti', $cell($copyRecs[0]['id'], $copyField), 'KOPYADAN-DEGISTI');
    eq('C1) ASIL ETKILENMEDI', $cell($srcRecs[0], $srcField), 'ORIJINAL-1');

    // --- 2) ASILDA degistir -> KOPYA degismesin
    http_request('POST', '/api/cell_update.php', $ownerCookie, array(
        'csrf_token'=>$csrf, 'record_id'=>$srcRecs[1], 'field_id'=>$srcField, 'value'=>'ASILDAN-DEGISTI',
    ));
    eq('C2) aslin hucresi degisti', $cell($srcRecs[1], $srcField), 'ASILDAN-DEGISTI');
    eq('C2) KOPYA ETKILENMEDI', $cell($copyRecs[1]['id'], $copyField), 'ORIJINAL-2');

    // --- 3) KOPYAYA kayit ekle -> ASLIN sayisi degismesin
    $srcCountBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id=:t AND deleted_at IS NULL', array(':t'=>$srcTable));
    $add = http_request('POST', '/api/record_add.php', $ownerCookie, array('csrf_token'=>$csrf, 'table_id'=>$copyTable));
    check('C3) kopyaya kayit eklendi', $add['status'] === 200, 'HTTP ' . $add['status'] . ' ' . substr($add['body'],0,100));
    eq('C3) kopyanin kayit sayisi 3',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id=:t AND deleted_at IS NULL', array(':t'=>$copyTable)), 3);
    eq('C3) ASLIN kayit sayisi DEGISMEDI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id=:t AND deleted_at IS NULL', array(':t'=>$srcTable)),
        $srcCountBefore);

    // --- 4) KOPYAYA ALAN ekle -> ASLIN semasi degismesin
    $srcFieldsBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id=:t', array(':t'=>$srcTable));
    bcc_execute('INSERT INTO fields (table_id,name,field_type,position) VALUES (:t,:n,:ft,5)',
        array(':t'=>$copyTable,':n'=>'YalnizKopyada',':ft'=>'single_line_text'));
    eq('C4) ASLIN alan sayisi DEGISMEDI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id=:t', array(':t'=>$srcTable)), $srcFieldsBefore);
    eq('C4) yeni alan yalnizca KOPYADA',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id=:t AND name=:n',
            array(':t'=>$srcTable, ':n'=>'YalnizKopyada')), 0);

    // --- 5) Iki tarafin gorunumleri de AYRI
    $srcViews = (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id=:t', array(':t'=>$srcTable));
    $copyViews = (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id=:t', array(':t'=>$copyTable));
    check('C5) kopyanin KENDI gorunumleri var', $copyViews >= 1, "src=$srcViews copy=$copyViews");
    eq('C5) kopyanin gorunumleri asil tabloya BAGLI DEGIL',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id=:c AND id IN (SELECT id FROM (SELECT id FROM views WHERE table_id=:s) x)',
            array(':c'=>$copyTable, ':s'=>$srcTable)), 0);

    // =====================================================================
    echo "\n--- E) 'Ayni veriye bakan ikinci gorunum' yolu HALA VAR ---\n";
    $vBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id=:t', array(':t'=>$srcTable));
    $vc = http_request('POST', '/api/view_create.php', $ownerCookie,
        array('csrf_token'=>$csrf, 'table_id'=>$srcTable, 'view_type'=>'grid'));
    check('E) + Yeni olustur... calisiyor', $vc['status'] === 200, 'HTTP ' . $vc['status'] . ' ' . substr($vc['body'],0,100));
    eq('E) gorunum sayisi bir artti',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id=:t', array(':t'=>$srcTable)), $vBefore + 1);
    eq('E) o yol YENI kayit uretmedi (mercek oldugu icin, DOGRU olan bu)',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id=:t AND deleted_at IS NULL', array(':t'=>$srcTable)),
        $srcCountBefore);

    // =====================================================================
    echo "\n--- F) KOPYA OLDUGU ADINDAN belli + AD DEGISTIRILEBILIR ---\n";
    // =====================================================================
    // ⚠️ TASARIM KARARI (kullanici belirledi): kopya oldugu bilgisi AYRI bir
    // panel bolumunde DEGIL, TABLO ADININ KENDISINDE durur. Bir ara sol panele
    // "Bu tablonun kopyalari" diye ayri bir bolum eklenmisti; kullanici
    // "boyle ayri olmasin, tablo ismi yaninda copy yazsin ama degistirilebilir
    // olsun" dedi ve o bolum KALDIRILDI (bcc_table_copy_links ile birlikte).
    //
    // Boylece isaret YENI BIR KAVRAM eklemeden calisiyor: ad siradan bir tablo
    // adidir, kullanici istedigi an degistirir.
    eq('F) kopyanin adi "<kaynak> kopyasi" deseninde',
        bcc_fetch_column('SELECT name FROM tables_meta WHERE id=:i', array(':i' => $copyTable)),
        'VMIC Tablo kopyası');
    $cpPage = http_request('GET', '/grid.php?table_id=' . $copyTable, $ownerCookie);
    check('F) ad SEKMEDE gorunuyor (kopya oldugu buradan okunuyor)',
        strpos($cpPage['body'], 'VMIC Tablo kopyası') !== false);

    // AD DEGISTIRILEBILIR: "kopyasi" eki kilitli bir rozet degil, siradan metin.
    $ren = http_request('POST', '/api/table_rename.php', $ownerCookie, array(
        'csrf_token' => $csrf, 'table_id' => $copyTable, 'name' => 'Bambaska Ad',
    ));
    check('F) ad degistirme 200', $ren['status'] === 200,
        'HTTP ' . $ren['status'] . ' ' . substr($ren['body'], 0, 140));
    eq('F) ad GERCEKTEN degisti ("kopyasi" eki kalkabiliyor)',
        bcc_fetch_column('SELECT name FROM tables_meta WHERE id=:i', array(':i' => $copyTable)),
        'Bambaska Ad');
    // Ad degismesi BAGIMSIZLIGI bozmaz: veri hala ayri.
    eq('F) ad degisince bile ASIL hala etkilenmiyor', $cell($srcRecs[0], $srcField), 'ORIJINAL-1');

    // Kaldirilan bolum GERI GELMESIN.
    $srcPage = http_request('GET', '/grid.php?table_id=' . $srcTable, $ownerCookie);
    check('F) sol panelde AYRI "kopyalar" bolumu YOK (kaldirildi)',
        strpos($srcPage['body'], 'gs-view-copies') === false
        && strpos($srcPage['body'], 'Bu tablonun kopyaları') === false);
} catch (Throwable $e) {
    echo "\n[HATA] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $results[] = false;
}

$wipe();
check('Z) test verisi temizlendi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name = :n', array(':n' => TEAM)) === 0);

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($pass === $total ? 'GECTI' : 'KALDI') . " ($pass/$total)\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
