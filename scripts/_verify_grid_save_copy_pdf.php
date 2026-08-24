<?php
// "Gorunumu kaydet" ARTIK TABLOYU PANOYA DA KOPYALIYOR + "PDF olarak indir".
//
// ⚠️ PREMIS DUZELTMESI: kullanici "Gorunumu kaydet islevsiz" dedi; olculdu ve
// CALISIYORDU (ucnokta ok:true, views.config guncelleniyor). "Islevsiz"
// hissettirmesinin sebebi gorunur bir sonucu olmamasiydi. Bu yuzden kaydetme
// KALDI, kopyalama onun USTUNE eklendi -- ozellik yok edilmedi.
//
// Kapsam:
//   A) Kaydetme KORUNDU: ucnokta cagrisi duruyor ve CANLI olarak calisiyor
//   B) Kopyalama eklendi ve pano mantigi TEK kaynaktan (kopya yok)
//   C) Butun tablo + BASLIK satiri kopyalaniyor (Excel'de kullanilabilir tablo)
//   D) Sag tik -> Yapistir da calisir: GERCEK sistem panosuna iki format yazilir
//   E) Kopyalama basarisiz olursa kaydetme yine BASARILI raporlanir
//   F) copyWholeTable'in dayandigi markup CANLI ciktida GERCEKTEN var
//   G) "PDF olarak indir" PNG'nin HEMEN ALTINDA ve ayri bir kalem
//   H) PDF yeni KUTUPHANE eklemedi, PNG'nin canvas'ini paylasiyor
//   I) Uretilen PDF YAPISAL OLARAK GECERLI (xref ofsetleri bayt bayt dogru)
//
// ⚠️ DOGRULANAMAYAN: panoya yazmanin KENDISI ve PDF'in bir okuyucuda acilmasi
// tarayici gerektirir; bu makinede headless tarayici YOK. Olculen sey
// sozlesme, markup, canli kayit turu ve PDF'in bayt yapisi.
//
// On kosul: Apache + node. Calistirma:
//   C:\php73\php.exe scripts\_verify_grid_save_copy_pdf.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('MAIL', 'gscp.owner@bcc-test.local');
define('PASS', 'GsCp!2026');
define('TEAM', 'GSCP Test Alani');

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

// ⚠️ Yorumlar ayiklanir (bu oturumda dort kez yanlis-pozitif verdi).
function strip_js_comments($src)
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    return preg_replace('#^\s*//.*$#m', '', $src);
}

$root = dirname(__DIR__);
$gridJs = strip_js_comments(file_get_contents($root . '/public/assets/grid.js'));
$copyJs = strip_js_comments(file_get_contents($root . '/public/assets/grid-copy.js'));
$pdfJs = strip_js_comments(file_get_contents($root . '/public/assets/grid-export-pdf.js'));
$pngJs = strip_js_comments(file_get_contents($root . '/public/assets/grid-export-png.js'));
$gridPhp = file_get_contents($root . '/public/grid.php');

// =====================================================================
echo "\n--- A) Kaydetme KORUNDU ---\n";
check('A) ucnokta cagrisi hala yerinde',
    strpos($gridJs, "post('/api/view_save_state.php'") !== false);
check('A) state_query_string hala gonderiliyor',
    strpos($gridJs, 'state_query_string') !== false);
check('A) ucnokta dosyasi duruyor', is_file($root . '/public/api/view_save_state.php'));

// =====================================================================
echo "\n--- B) Kopyalama eklendi, pano mantigi TEK kaynaktan ---\n";
check('B) kaydetme basarisindan SONRA kopyalama cagriliyor',
    strpos($gridJs, 'BCC_GRID_COPY.copyWholeTable()') !== false);
check('B) grid-copy.js yuzeyi disa aciyor',
    strpos($copyJs, 'window.BCC_GRID_COPY = { copyWholeTable: copyWholeTable }') !== false);
// ⚠️ Asil guvence: grid.js KENDI pano mantigini YAZMIYOR.
// ⚠️ KONTROL DARALTILDI: ilk yazimda ciplak 'execCommand' araniyordu ve
// KALDI veriyordu -- grid.js'in zengin metin editoru document.execCommand
// ('bold' vb.) kullaniyor, bu MESRU ve pano ile ilgisiz. Aranan sey PANOYA
// YAZMA: clipboardData / setData / execCommand('copy').
check('B) grid.js kendi pano yazma mantigini YAZMIYOR (kopya yok)',
    strpos($gridJs, 'clipboardData') === false
    && strpos($gridJs, "setData('text/html'") === false
    && strpos($gridJs, "setData('text/plain'") === false
    && strpos($gridJs, "execCommand('copy')") === false);
check('B) copyWholeTable mevcut yardimcilari kullaniyor (buildTsv/buildHtml/writeClipboard)',
    preg_match('#function copyWholeTable\(\)[\s\S]*?buildTsv\(matrix\)#', $copyJs) === 1
    && preg_match('#function copyWholeTable\(\)[\s\S]*?buildHtml\(matrix\)#', $copyJs) === 1
    && preg_match('#function copyWholeTable\(\)[\s\S]*?writeClipboard\(tsv, html\)#', $copyJs) === 1);
check('B) ikinci bir writeClipboard tanimi YOK',
    substr_count($copyJs, 'function writeClipboard') === 1);

// =====================================================================
echo "\n--- C) Butun tablo + BASLIK satiri ---\n";
check('C) veri sutunlari data-col-key ile secilLiyor (satir no / "+" disarida)',
    strpos($copyJs, "thead th[data-col-key]") !== false);
check('C) tum kayit satirlari geziliyor',
    strpos($copyJs, "tbody tr[data-record-id]") !== false);
check('C) TSV ye baslik satiri EKLENIYOR',
    preg_match('#var tsv = headers\.map\(tsvCell\)\.join#', $copyJs) === 1);
check('C) HTML e <thead> EKLENIYOR',
    strpos($copyJs, "'<thead><tr") !== false
    && strpos($copyJs, "replace('<tbody>', thead + '<tbody>')") !== false);
// Ctrl+C (secim) YOLU DEGISMEDI: orada baslik istenmez.
check('C) Ctrl+C secim yolu baslik EKLEMIYOR (degismedi)',
    preg_match('#function copySelection\(\)[\s\S]{0,400}?buildTsv\(matrix\)#', $copyJs) === 1
    && preg_match('#function copySelection\(\)[\s\S]{0,400}?thead#', $copyJs) === 0);

// =====================================================================
echo "\n--- C2) Bicim bilgisi pano HTML'ine satir ici yaziliyor ---\n";
// ⚠️ BU BOLUM "Excel'de kenarlik GORUNUYOR" demiyor -- OLCULEN sonuc bunun
// AKSI: kullanici Excel'e yapistirdi, kenarliklar GELMEDI (Excel buyuk
// olasilikla text/plain dalini aliyor). Buradaki kontroller yalnizca
// bicimin panoya YAZILDIGINI dogrular; hedef programin onu uygulayip
// uygulamadigini bu betik OLCEMEZ (tarayici gerekir).
check('C2) tabloya border ozniteligi + border-collapse',
    strpos($copyJs, 'border="1" style="border-collapse:collapse"') !== false);
check('C2) her <td> ye INLINE kenarlik stili',
    preg_match('#<td data-bcc-raw[\s\S]{0,200}?style="\' \+ CLIP_CELL_STYLE#', $copyJs) === 1);
check('C2) baslik <th> lerine de stil (kalin + zemin)',
    strpos($copyJs, "'<th style=\"' + CLIP_HEAD_STYLE") !== false);
check('C2) renkler SABIT (--bcc-* token hedef programda tanimsiz olurdu)',
    strpos($copyJs, 'CLIP_CELL_STYLE = ') !== false
    && strpos($copyJs, 'var(--bcc') === false);

echo "\n--- C3) Kendi basligimiz grid'e GERI yapistirilinca VERI sanilmaz ---\n";
$pasteJs = strip_js_comments(file_get_contents($root . '/public/assets/grid-paste.js'));
check('C3) baslik satiri data-bcc-head ile isaretleniyor',
    strpos($copyJs, "<tr data-bcc-head=\"1\">") !== false);
check('C3) grid-paste.js o satiri ATLIYOR',
    strpos($pasteJs, "hasAttribute('data-bcc-head')") !== false);
check('C3) atlama YALNIZCA kendi tablomuzda (disaridan gelende davranis degismez)',
    preg_match('#if \(isOurs\)\s*\{[\s\S]{0,200}?data-bcc-head#', $pasteJs) === 1);

// =====================================================================
echo "\n--- D) Sag tik -> Yapistir: GERCEK sistem panosu, iki format ---\n";
check('D) text/plain (Excel/LibreOffice/Not Defteri okur)',
    strpos($copyJs, "setData('text/plain', tsv)") !== false);
check('D) text/html (zengin tablo -- Word/Airtable/LibreOffice)',
    strpos($copyJs, "setData('text/html', html)") !== false);
check('D) sistem panosuna yaziliyor (uygulama ici tampon DEGIL)',
    strpos($copyJs, "execCommand('copy')") !== false);

// =====================================================================
echo "\n--- E) Kopyalama basarisiz olursa kaydetme BASARILI raporlanir ---\n";
check('E) basarili dalda kayit + kopya birlikte bildiriliyor',
    strpos($gridJs, 'Görünüm kaydedildi · tablo panoya kopyalandı') !== false);
check('E) kopya basarisizken kaydin BASARILI oldugu soyleniyor',
    strpos($gridJs, 'Görünüm kaydedildi. (Tablo panoya kopyalanamadı.)') !== false);
check('E) bos tabloda ayri mesaj',
    strpos($gridJs, 'Kopyalanacak satır yok') !== false);
check('E) BCC_GRID_COPY yoksa cokmuyor (guard)',
    strpos($gridJs, 'window.BCC_GRID_COPY' . "\n") !== false
    || strpos($gridJs, 'window.BCC_GRID_COPY') !== false);

// =====================================================================
echo "\n--- G) 'PDF olarak indir' menu kalemi ---\n";
$posPng = strpos($gridPhp, 'gs-view-download-png-item');
$posPdf = strpos($gridPhp, 'gs-view-download-pdf-item');
check('G) PDF kalemi var', $posPdf !== false);
check('G) PNG in HEMEN ALTINDA (istenen sira)',
    $posPng !== false && $posPdf !== false && $posPdf > $posPng,
    "png@$posPng pdf@$posPdf");
// Aradaki mesafe kucuk olmali: baska bir menu kalemi araya girmemis.
$between = ($posPng !== false && $posPdf !== false) ? substr($gridPhp, $posPng, $posPdf - $posPng) : '';
check('G) araya BASKA bir menu kalemi girmemis',
    substr_count($between, 'class="gs-table-tab-menu-item"') <= 1,
    'aradaki kalem sayisi: ' . substr_count($between, 'class="gs-table-tab-menu-item"'));
check('G) "Yazdir" AYRI bir kalem olarak duruyor (PDF onun yerini almadi)',
    strpos($gridPhp, 'gs-view-print-item') !== false);

// =====================================================================
echo "\n--- H) PDF yeni kutuphane eklemedi, canvas'i PAYLASIYOR ---\n";
check('H) grid-export-png.js yakalama yuzeyini disa aciyor',
    strpos($pngJs, 'window.BCC_GRID_EXPORT') !== false
    && strpos($pngJs, 'captureCanvas: captureCanvas') !== false);
check('H) PDF o yuzeyi kullaniyor',
    strpos($pdfJs, 'BCC_GRID_EXPORT') !== false
    && strpos($pdfJs, "captureCanvas('PDF')") !== false);
check('H) PDF KENDI html2canvas sarmalayicisini yazmiyor',
    strpos($pdfJs, 'loadHtml2Canvas') === false
    && strpos($pdfJs, 'createElement(\'script\')') === false);
check('H) vendor klasorune YENI kutuphane eklenmedi (yalnizca html2canvas)',
    count(array_diff(scandir($root . '/public/assets/vendor'), array('.', '..'))) === 1,
    implode(',', array_diff(scandir($root . '/public/assets/vendor'), array('.', '..'))));
// Yukleme SIRASI: PDF, PNG'den SONRA (yuzey orada kuruluyor; ikisi de defer).
$sPng = strpos($gridPhp, 'grid-export-png.js');
$sPdf = strpos($gridPhp, 'grid-export-pdf.js');
check('H) grid-export-pdf.js, grid-export-png.js ten SONRA yukleniyor',
    $sPng !== false && $sPdf !== false && $sPdf > $sPng, "png@$sPng pdf@$sPdf");
check('H) grid-copy.js grid.js ten SONRA yukleniyor (BCC_GRID_COPY hazir olsun)',
    strpos($gridPhp, 'grid-copy.js') > strpos($gridPhp, "bcc_asset_url('grid.js')"));

// =====================================================================
echo "\n--- I) Uretilen PDF YAPISAL OLARAK GECERLI ---\n";
// buildPdf DOSYADAN cikarilip node'da calistirilir (ikinci bir kopya YAZILMAZ).
$nodeScript = <<<'JS'
const fs=require('fs');
// argv[0]=node, argv[1]=BU betik, argv[2]=incelenecek dosya. Ilk yazimda
// argv[1] kullanilmisti (node -e ile calisirken dogruydu, dosyadan
// calisirken betigin KENDI yolunu okuyordu) ve buildPdf hic bulunamiyordu.
const src=fs.readFileSync(process.argv[2],'utf8');
const m=src.match(/function buildPdf\(jpegBytes, pxW, pxH\) \{[\s\S]*?\n        \}/);
if(!m){ console.log('FAIL buildPdf-bulunamadi'); process.exit(0); }
global.Blob = class { constructor(chunks){ this.chunks=chunks; } };
const buildPdf = eval('(' + m[0].replace(/^function buildPdf/,'function') + ')');
const jpeg = new Uint8Array([0xFF,0xD8,0xFF,0xE0,0x00,0x10,0x4A,0x46,0x49,0x46,0x00,0xFF,0xD9]);
const blob = buildPdf(jpeg, 800, 600);
const buf = Buffer.concat(blob.chunks.map(c=>Buffer.from(c)));
const s = buf.toString('latin1');
const out = [];
out.push('header=' + (s.startsWith('%PDF-') ? 'OK':'FAIL'));
out.push('eof=' + (s.trimEnd().endsWith('%%EOF') ? 'OK':'FAIL'));
const sxi = s.lastIndexOf('startxref');
const startxref = parseInt(s.slice(sxi+9).trim(),10);
out.push('startxref=' + (s.substr(startxref,4)==='xref' ? 'OK':'FAIL'));
const offs = [...s.slice(startxref).matchAll(/^(\d{10}) 00000 n /gm)].map(x=>parseInt(x[1],10));
out.push('objcount=' + offs.length);
let allOk = offs.length===5;
offs.forEach((o,i)=>{ if(s.substr(o,7)!==((i+1)+' 0 obj')) allOk=false; });
out.push('offsets=' + (allOk?'OK':'FAIL'));
out.push('dct=' + (s.includes('/DCTDecode')?'OK':'FAIL'));
// Sayfa boyutu 96dpi -> 72pt donusumu: 800px -> 600pt, 600px -> 450pt
out.push('mediabox=' + (s.includes('/MediaBox [0 0 600 450]')?'OK':'FAIL'));
console.log(out.join(' '));
JS;
file_put_contents(sys_get_temp_dir() . '/bcc_pdfcheck.js', $nodeScript);
$nodeOut = trim(shell_exec('node ' . escapeshellarg(sys_get_temp_dir() . '/bcc_pdfcheck.js')
    . ' ' . escapeshellarg($root . '/public/assets/grid-export-pdf.js') . ' 2>&1'));
@unlink(sys_get_temp_dir() . '/bcc_pdfcheck.js');
echo "         node: $nodeOut\n";
check('I) PDF basligi (%PDF-)', strpos($nodeOut, 'header=OK') !== false);
check('I) dosya %%EOF ile bitiyor', strpos($nodeOut, 'eof=OK') !== false);
check('I) startxref gercekten xref tablosunu gosteriyor', strpos($nodeOut, 'startxref=OK') !== false);
check('I) bes nesne kayitli', strpos($nodeOut, 'objcount=5') !== false);
check('I) TUM xref ofsetleri BAYT BAYT dogru', strpos($nodeOut, 'offsets=OK') !== false);
check('I) JPEG /DCTDecode ile gomulu', strpos($nodeOut, 'dct=OK') !== false);
check('I) sayfa boyutu 96dpi->72pt donusumuyle dogru', strpos($nodeOut, 'mediabox=OK') !== false);

// =====================================================================
// F) CANLI: markup ve kaydetme
// =====================================================================
$wipe = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => TEAM)) as $r) {
        bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id']));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => MAIL));
};
$wipe();

try {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM));
    $tid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
        array(':e' => MAIL, ':h' => password_hash(PASS, PASSWORD_DEFAULT), ':n' => 'GSCP Owner'));
    $uid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id,user_id,role) VALUES (:t,:u,:r)', array(':t'=>$tid,':u'=>$uid,':r'=>'owner'));
    bcc_execute('INSERT INTO bases (team_id,name,created_by) VALUES (:t,:n,:u)', array(':t'=>$tid,':n'=>'GSCP Base',':u'=>$uid));
    $bid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id,name,position) VALUES (:b,:n,0)', array(':b'=>$bid,':n'=>'GSCP Tablo'));
    $tblId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id,name,field_type,position) VALUES (:t,:n,:ft,0)',
        array(':t'=>$tblId,':n'=>'Sehir',':ft'=>'single_line_text'));
    $f1 = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id,name,field_type,position) VALUES (:t,:n,:ft,1)',
        array(':t'=>$tblId,':n'=>'Butce',':ft'=>'number'));
    bcc_execute('INSERT INTO records (table_id,position,created_by) VALUES (:t,0,:u)', array(':t'=>$tblId,':u'=>$uid));
    $r1 = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO cell_values (record_id,field_id,value_text) VALUES (:r,:f,:v)',
        array(':r'=>$r1,':f'=>$f1,':v'=>'Istanbul'));
    bcc_execute("INSERT INTO views (table_id,name,view_type,position,created_by) VALUES (:t,'GSCP Gorunum','grid',0,:u)",
        array(':t'=>$tblId,':u'=>$uid));
    $vid = (int) bcc_last_insert_id();

    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $r['body'], $m);
    $r = http_request('POST', '/login.php', $c, array('email'=>MAIL,'password'=>PASS,'csrf_token'=>$m[1]));
    $c = $r['cookie'] ? $r['cookie'] : $c;

    echo "\n--- F) CANLI: copyWholeTable'in dayandigi markup ---\n";
    $page = http_request('GET', '/grid.php?table_id=' . $tblId . '&view_id=' . $vid, $c);
    check('F) grid.php 200', $page['status'] === 200, 'HTTP ' . $page['status']);
    check('F) veri sutunu basliklari data-col-key tasiyor',
        preg_match('#<th data-col-key="f' . $f1 . '"#', $page['body']) === 1);
    check('F) baslik metni .grid-th-label icinde',
        strpos($page['body'], '<span class="grid-th-label">Sehir</span>') !== false);
    check('F) kayit satiri data-record-id tasiyor',
        strpos($page['body'], 'data-record-id="' . $r1 . '"') !== false);
    check('F) veri hucreleri td.grid-cell + data-value + data-field-type',
        preg_match('#<td[^>]*class="[^"]*grid-cell[^"]*"[^>]*data-value=#', $page['body']) === 1
        || preg_match('#<td[^>]*data-value=[^>]*class="[^"]*grid-cell#', $page['body']) === 1);
    check('F) satir no sutunu data-col-key TASIMIYOR (kopyaya girmemeli)',
        preg_match('#<th class="grid-rownum"[^>]*data-col-key#', $page['body']) === 0);
    check('F) "Görünümü kaydet" butonu sayfada',
        strpos($page['body'], 'gs-view-save-state-btn') !== false);
    check('F) "PDF olarak indir" butonu sayfada',
        strpos($page['body'], 'gs-view-download-pdf-item') !== false);
    check('F) grid-export-pdf.js sayfada yukleniyor',
        strpos($page['body'], 'grid-export-pdf.js') !== false);

    echo "\n--- A2) CANLI: kaydetme GERCEKTEN calisiyor (premis) ---\n";
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $cm);
    $before = bcc_fetch_column('SELECT config FROM views WHERE id = :v', array(':v'=>$vid));
    $save = http_request('POST', '/api/view_save_state.php', $c, array(
        'csrf_token' => isset($cm[1]) ? $cm[1] : '',
        'view_id' => $vid,
        'state_query_string' => 'table_id=' . $tblId . '&view_id=' . $vid . '&sort_field_1=' . $f1 . '&sort_dir_1=asc',
    ));
    $after = bcc_fetch_column('SELECT config FROM views WHERE id = :v', array(':v'=>$vid));
    check('A2) ucnokta 200 + ok', $save['status'] === 200 && strpos($save['body'], '"ok":true') !== false,
        'HTTP ' . $save['status'] . ' ' . substr($save['body'], 0, 120));
    check('A2) views.config GERCEKTEN degisti (kaydetme yok edilmedi)',
        $before !== $after && strpos((string) $after, 'sort_field_1') !== false,
        'once=' . var_export($before, true) . ' sonra=' . var_export($after, true));

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
