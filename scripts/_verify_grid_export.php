<?php
// Grid disa aktarma dogrulamasi: "PDF olarak indir" (window.print() + print CSS)
// ve "PNG olarak indir" (istemci tarafi html2canvas, YEREL dosya).
//
// Odak: (1) menu/varlik, (2) html2canvas'in CDN'siz oldugu, (3) PDF/PNG/Excel
// VERI KAPSAMININ birebir ayni oldugu -- PDF ve PNG DOM'u bastigi icin
// karsilastirma "grid.php'nin HTML'i" ile "view_export_xlsx.php'nin .xlsx'i"
// arasinda yapiliyor: ayni URL state'i, ayni kayitlar, ayni sutunlar.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_grid_export.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/xlsx_reader.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'export.owner@bcc-test.local');
define('TEST_PASS', 'ExportTest!2026');

// Gercek base (id 15) SAYAC KORUMASI -- bu betigin ona dokunmadiginin kaniti.
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

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array('email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf($r['body'])));
    return $r['cookie'] ? $r['cookie'] : $c;
}

// grid.php HTML'inden tbody kayit id'lerini SIRASIYLA cikarir -- PDF/PNG'nin
// bastigi kayit kumesi tam olarak budur.
function grid_record_ids($html)
{
    preg_match_all('/<tr\s[^>]*data-record-id="(\d+)"/', $html, $m);
    return $m[1];
}

// grid.php HTML'inden GORUNUR sutun basliklarini SIRASIYLA cikarir.
// Hedef ALAN sutunlari: data-col-key="f<id>" (sutun genisligi turunda eklendi,
// grid-column-resize.js de ayni kancayi kullaniyor). Onceki surum duz "<th>"
// ariyordu; o oznitelik gelince eslesme sifira dustu ve bu YARDIMCI kirildi --
// urun tarafi degil (ayni turdeki .xlsx ve kayit karsilastirmalari gecmisti).
// Satir no ve "+" sutunlari zaten alan degil, artik acikca disarida kaliyor.
function grid_header_names($html)
{
    if (!preg_match('#<thead>(.*?)</thead>#s', $html, $th)) { return array(); }
    preg_match_all('#<th data-col-key="f\d+">(.*?)</th>#s', $th[1], $m);
    $names = array();
    foreach ($m[1] as $cell) {
        // <span class="field-badge...">, <span class="req-mark">*</span> ve
        // <details> menusu ayiklanir, geriye alan adi kalir.
        $cell = preg_replace('#<details.*?</details>#s', '', $cell);
        $cell = strip_tags($cell);
        $cell = trim(html_entity_decode(str_replace('*', '', $cell), ENT_QUOTES, 'UTF-8'));
        if ($cell !== '') { $names[] = $cell; }
    }
    return $names;
}

function fetch_xlsx_rows($queryString, $cookie)
{
    $r = http_request('GET', '/api/view_export_xlsx.php' . $queryString, $cookie);
    if ($r['status'] !== 200 || $r['body'] === '') { return null; }
    $tmp = sys_get_temp_dir() . '/bcc_export_verify_' . getmypid() . '.xlsx';
    file_put_contents($tmp, $r['body']);
    $rows = bcc_xlsx_read_first_sheet($tmp);
    @unlink($tmp);
    return $rows;
}

bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => OWNER_EMAIL)
    ), 'id');
    foreach ($baseIds as $bid) { bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid)); }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => OWNER_EMAIL));
};

// --- Gercek base (15) ONCE sayaclari ---------------------------------------
$realBefore = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

try {
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'Export Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'Export Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();

    $mkTable = function ($name, $pos) use ($baseId) {
        bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, :p)', array(':b' => $baseId, ':n' => $name, ':p' => $pos));
        return (int) bcc_last_insert_id();
    };
    $mkField = function ($tableId, $name, $type, $pos) {
        bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, :p)',
            array(':t' => $tableId, ':n' => $name, ':ft' => $type, ':p' => $pos));
        return (int) bcc_last_insert_id();
    };
    $mkRecord = function ($tableId, $pos) use ($ownerId) {
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
            array(':t' => $tableId, ':p' => $pos, ':u' => $ownerId));
        return (int) bcc_last_insert_id();
    };
    $setCell = function ($rid, $fid, $val) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
            array(':r' => $rid, ':f' => $fid, ':v' => $val));
    };

    // --- GENIS tablo: 8 alan (landscape esigi >= 6 tetiklenir), 12 kayit -----
    $tWide = $mkTable('Genis Tablo', 0);
    $wideFieldIds = array();
    $wideFieldNames = array('Ad', 'Sehir', 'Departman', 'Unvan', 'Telefon', 'Adres', 'Notlar', 'Durum');
    foreach ($wideFieldNames as $i => $fn) {
        $wideFieldIds[$fn] = $mkField($tWide, $fn, 'single_line_text', $i);
    }

    $sehirler = array('Ankara', 'Istanbul', 'Izmir');
    $wideRecordIds = array();
    for ($i = 0; $i < 12; $i++) {
        $rid = $mkRecord($tWide, $i);
        $wideRecordIds[] = $rid;
        $setCell($rid, $wideFieldIds['Ad'], 'Kisi ' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT));
        $setCell($rid, $wideFieldIds['Sehir'], $sehirler[$i % 3]);
        $setCell($rid, $wideFieldIds['Departman'], 'Dept ' . ($i % 4));
        $setCell($rid, $wideFieldIds['Unvan'], 'Unvan ' . $i);
        $setCell($rid, $wideFieldIds['Telefon'], '0555000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        $setCell($rid, $wideFieldIds['Adres'], 'Adres satiri ' . $i);
        $setCell($rid, $wideFieldIds['Notlar'], 'Not ' . $i);
        $setCell($rid, $wideFieldIds['Durum'], ($i % 2 === 0) ? 'Aktif' : 'Pasif');
    }

    // --- DAR tablo: 3 alan (landscape tetiklenmez) ---------------------------
    $tNarrow = $mkTable('Dar Tablo', 1);
    $nAd = $mkField($tNarrow, 'Ad', 'single_line_text', 0);
    $mkField($tNarrow, 'Kod', 'single_line_text', 1);
    $mkField($tNarrow, 'Aciklama', 'single_line_text', 2);
    for ($i = 0; $i < 3; $i++) { $setCell($mkRecord($tNarrow, $i), $nAd, 'Dar ' . $i); }

    // --- BUYUK tablo: 520 kayit (PNG uyari esigi 500'un ustu) ---------------
    $tBig = $mkTable('Buyuk Tablo', 2);
    $bAd = $mkField($tBig, 'Ad', 'single_line_text', 0);
    bcc_begin_transaction();
    for ($i = 0; $i < 520; $i++) { $setCell($mkRecord($tBig, $i), $bAd, 'Satir ' . $i); }
    bcc_commit();

    $cookie = login(OWNER_EMAIL);
    check('Giris yapildi (owner)', $cookie !== null);

    $assetsDir = __DIR__ . '/../public/assets';

    // =====================================================================
    // A) html2canvas: YEREL, CDN'SIZ
    // =====================================================================
    echo "\n--- A) html2canvas yerel/CDN'siz ---\n";
    $vendorPath = $assetsDir . '/vendor/html2canvas.min.js';
    check('A) vendor/html2canvas.min.js diskte var', is_file($vendorPath));
    $vendorSrc = is_file($vendorPath) ? file_get_contents($vendorPath) : '';
    check('A) MIT + surum 1.4.1 banner',
        strpos($vendorSrc, 'html2canvas 1.4.1') !== false && strpos($vendorSrc, 'MIT License') !== false);
    check('A) sha256 beklenen degerde',
        hash('sha256', $vendorSrc) === 'e87e550794322e574a1fda0c1549a3c70dae5a93d9113417a429016838eab8cb',
        hash('sha256', $vendorSrc));

    // Dosyadaki TUM mutlak URL'ler beyaz listede mi? (SVG namespace + banner)
    preg_match_all('#https?://[a-zA-Z0-9./_-]+#', $vendorSrc, $urlM);
    $allowedUrls = array('http://www.w3.org/2000/svg', 'https://hertzen.com', 'https://html2canvas.hertzen.com');
    $unexpected = array_values(array_diff(array_unique($urlM[0]), $allowedUrls));
    check('A) kutuphanede beklenmeyen URL YOK (agdan hicbir sey cekmiyor)', empty($unexpected), implode(', ', $unexpected));

    foreach (array('unpkg', 'cdnjs', 'jsdelivr', 'cloudflare') as $cdn) {
        check("A) proje kodunda '{$cdn}' CDN referansi YOK",
            strpos(file_get_contents($assetsDir . '/grid-export-png.js'), $cdn) === false
            && strpos(file_get_contents(__DIR__ . '/../public/grid.php'), $cdn) === false);
    }

    // =====================================================================
    // B) CSS/JS kaynak yapisi
    // =====================================================================
    echo "\n--- B) CSS/JS kaynak yapisi ---\n";
    $exportCss = file_get_contents($assetsDir . '/grid-export.css');
    $shellCss = file_get_contents($assetsDir . '/grid-shell.css');
    $pngJs = file_get_contents($assetsDir . '/grid-export-png.js');
    $manageJs = file_get_contents($assetsDir . '/grid-view-manage.js');

    // ORTAK dosya @media'ya HAPSEDILMEMELI -- yoksa html2canvas klonu (screen)
    // kurallari hic gormez ve liste ikinci kez yazilmak zorunda kalir.
    // (Yorumlar soyuluyor: dosya basligi @media print'ten SOZ EDIYOR ama kural
    // olarak kullanmiyor -- ilk surumde bu testi yanlis dusuren seydi.)
    $exportCssRules = preg_replace('#/\*.*?\*/#s', '', $exportCss);
    check('B) grid-export.css @media ile sinirlandirilmamis (PNG de kullanabiliyor)',
        strpos($exportCssRules, '@media') === false);
    check('B) grid-export.css taban "+" SATIRINI (.grid-add-row) gizliyor',
        preg_match('/^\.grid-add-row,$/m', $exportCss) === 1);
    check('B) grid-export.css .grid-wrap kirpmasini aciyor',
        strpos($exportCss, '.grid-wrap') !== false && strpos($exportCss, 'overflow: visible !important') !== false);
    check('B) grid-export.css sticky basligi/sutunu static yapiyor',
        preg_match('/table\.grid thead th,\s*table\.grid \.grid-rownum,\s*\.grid-frozen-cell \{\s*position: static;/', $exportCss) === 1);
    check('B) grid-export.css sekme seridi + "Alanlari yonet" gizliyor',
        strpos($exportCss, '.gs-table-tabs,') !== false && strpos($exportCss, '.gs-fields-link,') !== false);

    check('B) grid-shell.css @page kenar bosluklari var', strpos($shellCss, '@page') !== false);
    check('B) grid-shell.css thead her sayfada tekrarliyor',
        preg_match('/table\.grid thead \{\s*display: table-header-group;/', $shellCss) === 1);
    check('B) grid-shell.css satirlari bolmuyor (page-break-inside: avoid)',
        strpos($shellCss, 'page-break-inside: avoid') !== false && strpos($shellCss, 'break-inside: avoid') !== false);
    check('B) grid-shell.css yataya sigdirma (width 100% + metin sarma)',
        strpos($shellCss, 'overflow-wrap: anywhere') !== false);
    // Eski dar print listesi grid-export.css'e TASINDI, geride kopyasi kalmadi.
    check('B) grid-shell.css içinde ESKI .grid-add-row-plus print kurali kalmadi',
        strpos($shellCss, '.grid-add-row-plus,') === false);

    check('B) grid-export-png.js satir uyari esigi 500',
        preg_match('/ROW_WARN_THRESHOLD = 500\b/', $pngJs) === 1);
    check('B) grid-export-png.js yukseklik esigi de var (scrollHeight)',
        strpos($pngJs, 'HEIGHT_WARN_THRESHOLD') !== false && strpos($pngJs, 'table.scrollHeight') !== false);
    check('B) grid-export-png.js onay metni birebir (Turkce, sert engel degil)',
        strpos($pngJs, 'Bu görünüm büyük, PNG yavaş/okunmayabilir. Excel önerilir. Devam edilsin mi?') !== false);
    check('B) grid-export-png.js onclone ile ORTAK CSS medyasini ceviriyor',
        strpos($pngJs, 'data-grid-export-css') !== false && strpos($pngJs, "link.media = 'all'") !== false);

    // /browse ile bulunan GERCEK BUG'in regresyonu: klonda sol serit/gorunum
    // paneli gizlenince .gs-main genisliyor ve table.grid'in min-width:100%'i
    // tabloyu ekrandakinden genis yayiyordu (olculdu: canli 926px -> klon
    // 1240px). Canvas canli olcuyle acildigi icin sagdaki 314px KIRPILIYOR,
    // altta da gizlenen "+" satiri kadar bos serit kaliyordu.
    check('B) PNG: sutun genisligi ekrandan olculup klona sabitleniyor (kirpma bug regresyonu)',
        strpos($pngJs, 'colWidths') !== false
        && strpos($pngJs, "clonedTable.style.tableLayout = 'fixed'") !== false
        && strpos($pngJs, 'clonedTable.style.width = width') !== false);
    check('B) PNG: gizlenen "+" sutunu genislige, "+" satiri yukseklige KATILMIYOR',
        strpos($pngJs, 'th === addFieldTh') !== false
        && preg_match('/height\s*=\s*Math\.ceil\(table\.scrollHeight\s*-\s*\(addRow/', $pngJs) === 1);
    check('B) grid-view-manage.js landscape kuralini enjekte ediyor',
        strpos($manageJs, 'size: landscape') !== false && strpos($manageJs, 'beforeprint') !== false);
    check('B) landscape esigi 6 veri sutunu',
        preg_match('/PRINT_LANDSCAPE_MIN_COLUMNS = 6\b/', $manageJs) === 1);

    // =====================================================================
    // C) KAPSAM SINIRI: Kanban/Form'a dokunulmadi
    // =====================================================================
    echo "\n--- C) Kapsam siniri (yalnizca Grid) ---\n";
    foreach (array('kanban.php', 'form.php') as $other) {
        $src = file_get_contents(__DIR__ . '/../public/' . $other);
        check("C) {$other} PNG/html2canvas iceRMIYOR",
            stripos($src, 'html2canvas') === false && stripos($src, 'download-png') === false);
    }
    check('C) kanban.js PNG disa aktarma icermiyor',
        stripos(file_get_contents($assetsDir . '/kanban.js'), 'html2canvas') === false);

    // =====================================================================
    // D) Menu + sayfa ciktisi (canli HTTP)
    // =====================================================================
    echo "\n--- D) Menu ve sayfa ciktisi ---\n";
    $gridUrl = '/grid.php?table_id=' . $tWide;
    $g = http_request('GET', $gridUrl, $cookie);
    check('D) grid.php 200 donuyor', $g['status'] === 200, 'HTTP ' . $g['status']);
    $html = $g['body'];

    check('D) menude "PDF olarak indir" var', strpos($html, 'PDF olarak indir') !== false);
    check('D) eski "Gorunumu yazdir" etiketi KALMADI (yeniden adlandirildi)',
        strpos($html, 'Görünümü yazdır') === false);
    check('D) menude "PNG olarak indir" var', strpos($html, 'PNG olarak indir') !== false);
    check('D) PNG kalemi YEREL html2canvas yoluna isaret ediyor (mtime cache-bust)',
        preg_match('#data-html2canvas-src="/assets/vendor/html2canvas\.min\.js\?v=\d+"#', $html) === 1);
    check('D) ORTAK export CSS media="print" ile bagli',
        preg_match('#<link rel="stylesheet" href="/assets/grid-export\.css\?v=\d+" media="print" data-grid-export-css>#', $html) === 1);
    check('D) grid-export-png.js sayfaya dahil',
        preg_match('#<script src="/assets/grid-export-png\.js\?v=\d+" defer>#', $html) === 1);

    echo "\n--- D-R) Regresyon: bozulmamis olmasi gerekenler ---\n";
    check('D-R) "Excel indir" kalemi duruyor',
        strpos($html, 'Excel indir') !== false && strpos($html, 'gs-view-download-xlsx-item') !== false);
    check('D-R) yazdirma kaleminin id\'si (gs-view-print-item) korundu',
        strpos($html, 'id="gs-view-print-item"') !== false);
    check('D-R) "Kaydi yazdir" (kayit bazli) butonu duruyor',
        strpos($html, 'grid-detail-print-btn') !== false);
    check('D-R) kayit detay print meta div\'leri duruyor',
        strpos($html, 'grid-detail-print-meta-top') !== false && strpos($html, 'grid-detail-print-meta-bottom') !== false);

    echo "\n--- D-S) Statik dosyalar Apache uzerinden servis ediliyor ---\n";
    $v = http_request('GET', '/assets/vendor/html2canvas.min.js', $cookie);
    check('D-S) /assets/vendor/html2canvas.min.js 200', $v['status'] === 200, 'HTTP ' . $v['status']);
    check('D-S) servis edilen icerik diskteki dosyayla BIREBIR', $v['body'] === $vendorSrc);
    $c = http_request('GET', '/assets/grid-export.css', $cookie);
    check('D-S) /assets/grid-export.css 200', $c['status'] === 200, 'HTTP ' . $c['status']);
    $j = http_request('GET', '/assets/grid-export-png.js', $cookie);
    check('D-S) /assets/grid-export-png.js 200', $j['status'] === 200, 'HTTP ' . $j['status']);

    // =====================================================================
    // E) VERI KAPSAMI: PDF/PNG (grid HTML) == Excel (.xlsx)
    // =====================================================================
    echo "\n--- E) Veri kapsami: PDF/PNG ile Excel birebir mi ---\n";

    // E1) Filtresiz
    $ids = grid_record_ids($html);
    $rows = fetch_xlsx_rows('?table_id=' . $tWide, $cookie);
    check('E1) xlsx okunabildi', is_array($rows) && count($rows) > 0);
    $xlsxData = is_array($rows) ? array_slice($rows, 1) : array();
    check('E1) filtresiz: grid satir sayisi == xlsx satir sayisi',
        count($ids) === 12 && count($xlsxData) === 12,
        'grid=' . count($ids) . ' xlsx=' . count($xlsxData));

    $headerNames = grid_header_names($html);
    check('E1) grid basliklari == xlsx basliklari (sira dahil)',
        $headerNames === $wideFieldNames && $rows[0] === $wideFieldNames,
        'grid=[' . implode('|', $headerNames) . '] xlsx=[' . implode('|', (array) $rows[0]) . ']');

    // E2) FILTRE: Sehir = Ankara  (12 kayittan 4'u)
    $qFilter = '?table_id=' . $tWide . '&filter_field_1=' . $wideFieldIds['Sehir'] . '&filter_cond_1=equals&filter_value_1=Ankara';
    $gf = http_request('GET', '/grid.php' . $qFilter, $cookie);
    $idsF = grid_record_ids($gf['body']);
    $rowsF = fetch_xlsx_rows($qFilter, $cookie);
    $xlsxF = is_array($rowsF) ? array_slice($rowsF, 1) : array();
    check('E2) filtre: grid 4 kayit gosteriyor', count($idsF) === 4, 'grid=' . count($idsF));
    check('E2) filtre: xlsx de 4 kayit', count($xlsxF) === 4, 'xlsx=' . count($xlsxF));

    $gridAdsF = array();
    foreach ($idsF as $rid) {
        $gridAdsF[] = (string) bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
            array(':r' => $rid, ':f' => $wideFieldIds['Ad']));
    }
    $xlsxAdsF = array_map(function ($r) { return isset($r[0]) ? $r[0] : ''; }, $xlsxF);
    check('E2) filtre: AYNI kayitlar, AYNI sirada', $gridAdsF === $xlsxAdsF,
        'grid=[' . implode('|', $gridAdsF) . '] xlsx=[' . implode('|', $xlsxAdsF) . ']');

    $sehirCol = array_search('Sehir', $wideFieldNames, true);
    $disari = false;
    foreach ($xlsxF as $r) { if (!isset($r[$sehirCol]) || $r[$sehirCol] !== 'Ankara') { $disari = true; } }
    check('E2) filtre: xlsx\'te filtre DISI kayit yok', $disari === false);

    // E3) SIRALAMA: Ad DESC
    $qSort = '?table_id=' . $tWide . '&sort_field_1=' . $wideFieldIds['Ad'] . '&sort_dir_1=desc';
    $gs = http_request('GET', '/grid.php' . $qSort, $cookie);
    $idsS = grid_record_ids($gs['body']);
    $rowsS = fetch_xlsx_rows($qSort, $cookie);
    $xlsxS = is_array($rowsS) ? array_slice($rowsS, 1) : array();
    $gridAdsS = array();
    foreach ($idsS as $rid) {
        $gridAdsS[] = (string) bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
            array(':r' => $rid, ':f' => $wideFieldIds['Ad']));
    }
    $xlsxAdsS = array_map(function ($r) { return isset($r[0]) ? $r[0] : ''; }, $xlsxS);
    check('E3) siralama: grid sirasi tersten (Kisi 12 ilk)',
        isset($gridAdsS[0]) && $gridAdsS[0] === 'Kisi 12', isset($gridAdsS[0]) ? $gridAdsS[0] : 'YOK');
    check('E3) siralama: grid sirasi == xlsx sirasi', $gridAdsS === $xlsxAdsS,
        'grid=[' . implode('|', $gridAdsS) . '] xlsx=[' . implode('|', $xlsxAdsS) . ']');

    // E4) GIZLI SUTUN: Telefon + Notlar gizli
    $hidden = $wideFieldIds['Telefon'] . ',' . $wideFieldIds['Notlar'];
    $qHide = '?table_id=' . $tWide . '&hidden_fields=' . $hidden;
    $gh = http_request('GET', '/grid.php' . $qHide, $cookie);
    $headHide = grid_header_names($gh['body']);
    $rowsH = fetch_xlsx_rows($qHide, $cookie);
    $beklenen = array('Ad', 'Sehir', 'Departman', 'Unvan', 'Adres', 'Durum');
    check('E4) gizli sutun: grid thead\'inde Telefon/Notlar YOK', $headHide === $beklenen,
        '[' . implode('|', $headHide) . ']');
    check('E4) gizli sutun: xlsx basliginda da YOK', is_array($rowsH) && $rowsH[0] === $beklenen,
        is_array($rowsH) ? '[' . implode('|', (array) $rowsH[0]) . ']' : 'xlsx okunamadi');
    check('E4) gizli sutun: gorunur sutunlar iki tarafta AYNI SIRADA',
        $headHide === (is_array($rowsH) ? $rowsH[0] : null));

    // E5) UCU BIR ARADA: filtre + siralama + gizli sutun
    $qAll = '?table_id=' . $tWide
        . '&filter_field_1=' . $wideFieldIds['Sehir'] . '&filter_cond_1=equals&filter_value_1=Ankara'
        . '&sort_field_1=' . $wideFieldIds['Ad'] . '&sort_dir_1=desc'
        . '&hidden_fields=' . $hidden;
    $ga = http_request('GET', '/grid.php' . $qAll, $cookie);
    $idsA = grid_record_ids($ga['body']);
    $headA = grid_header_names($ga['body']);
    $rowsA = fetch_xlsx_rows($qAll, $cookie);
    $xlsxA = is_array($rowsA) ? array_slice($rowsA, 1) : array();
    $gridAdsA = array();
    foreach ($idsA as $rid) {
        $gridAdsA[] = (string) bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
            array(':r' => $rid, ':f' => $wideFieldIds['Ad']));
    }
    $xlsxAdsA = array_map(function ($r) { return isset($r[0]) ? $r[0] : ''; }, $xlsxA);
    check('E5) filtre+sira+gizli: basliklar ayni', $headA === $beklenen && is_array($rowsA) && $rowsA[0] === $beklenen);
    check('E5) filtre+sira+gizli: kayitlar ayni ve ayni sirada', $gridAdsA === $xlsxAdsA && count($gridAdsA) === 4,
        'grid=[' . implode('|', $gridAdsA) . '] xlsx=[' . implode('|', $xlsxAdsA) . ']');

    // =====================================================================
    // F) Landscape esigi + PNG uyari esigi (veri tarafi)
    // =====================================================================
    echo "\n--- F) Esikler ---\n";
    // JS'in saydigi seyin AYNISI: thead th sayisi - rownum - (varsa) "+" sutunu.
    $countDataCols = function ($pageHtml) {
        if (!preg_match('#<thead>(.*?)</thead>#s', $pageHtml, $m)) { return -1; }
        $total = preg_match_all('#<th[\s>]#', $m[1]);
        $total -= 1; // .grid-rownum
        if (strpos($m[1], 'grid-add-field-th') !== false) { $total -= 1; }
        return $total;
    };
    check('F) genis tablo 8 veri sutunu -> landscape esigi (>=6) tetiklenir',
        $countDataCols($html) === 8, 'sayim=' . $countDataCols($html));

    $gn = http_request('GET', '/grid.php?table_id=' . $tNarrow, $cookie);
    check('F) dar tablo 3 veri sutunu -> dikey kalir (<6)',
        $countDataCols($gn['body']) === 3, 'sayim=' . $countDataCols($gn['body']));

    $gb = http_request('GET', '/grid.php?table_id=' . $tBig, $cookie);
    $bigIds = grid_record_ids($gb['body']);
    check('F) buyuk tablo 520 satir -> PNG uyari esigi (500) asiliyor',
        count($bigIds) === 520, 'satir=' . count($bigIds));
    check('F) gizli sutun sonrasi bile grid-export.css tek kaynak (kopya liste yok)',
        substr_count($exportCss, '.grid-add-row,') === 1);

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . "\n";
    $cleanup();
    $results[] = false;
}

// --- Gercek base (15) SONRA sayaclari --------------------------------------
echo "\n--- Gercek base (id " . REAL_BASE_ID . ") dokunulmadi mi ---\n";
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
