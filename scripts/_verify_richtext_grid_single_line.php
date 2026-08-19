<?php
// Zengin metin (long_text) hucresinin KAPALI grid gorunumu: OpsFlow davranışı
// TEK SATIR + yatay akis.
//
// Kapsam:
//   A) bcc_rich_text_grid_html() birim davranisi (<br> -> bosluk, gerisi durur)
//   B) grid.php render: .cell-view'da <br> YOK, data-value'da VAR
//   C) cell_update.php: 'display' donusmus, 'raw' ham kalmis
//   D) CSS: tek satir kurallari + satir ici blok elemanlar + 320px tavani
//   E) DEGISMEMESI gerekenler: duzenleyici, detay paneli, orta/uzun satir
//      yukseklikleri (Duyuru ekrani zaten sunucuda strip_tags ediyor)
//   F) Gercek base (15) dokunulmamis olmali
//
// GEOMETRI NOTU: "<br> tek satirda durmuyor" davranisi TARAYICIDA olculdu
// (/browse): white-space:nowrap ile kutu 3 satira (h=50px) cikiyordu;
// display:inline / inline-block+width / content:"" da ayni sonucu verdi.
// display:none tek satira (h=18px) dusurdu. Burasi o kararin KODDA durdugunu
// ve regresyona ugramadigini bekler.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_richtext_grid_single_line.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'rtline.owner@bcc-test.local');
define('TEST_PASS', 'RtLine!2026');
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

function extract_csrf_meta($html)
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
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

// CSS/JS yorumlarini soyar: bu projede testler aciklama YORUMLARINA takilip
// birden fazla kez yanlis "GECTI" verdi — kural metnine bakiyoruz.
function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

// Bir seciciye ait kural govdesini dondurur (yorumlar zaten soyulmus olmali).
function rule_body($css, $selector)
{
    $q = preg_quote($selector, '#');
    if (preg_match('#(?:^|[};])\s*' . $q . '\s*\{([^}]*)\}#s', $css, $m)) { return $m[1]; }
    return null;
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

$realBefore = array(
    'tablo'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => REAL_BASE_ID)),
    'alan'    => (int) bcc_fetch_column('SELECT COUNT(*) FROM fields f INNER JOIN tables_meta t ON t.id = f.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'kayit'   => (int) bcc_fetch_column('SELECT COUNT(*) FROM records r INNER JOIN tables_meta t ON t.id = r.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
    'gorunum' => (int) bcc_fetch_column('SELECT COUNT(*) FROM views v INNER JOIN tables_meta t ON t.id = v.table_id WHERE t.base_id = :b', array(':b' => REAL_BASE_ID)),
);

try {
    $assetsDir = __DIR__ . '/../public/assets';
    $styleCss = css_rules(file_get_contents($assetsDir . '/style.css'));
    $interfaceCss = css_rules(file_get_contents($assetsDir . '/interface.css'));

    // =====================================================================
    // A) bcc_rich_text_grid_html() BIRIM DAVRANISI
    // =====================================================================
    echo "--- A) bcc_rich_text_grid_html() ---\n";
    check('A) <br> bosluga donuyor',
        bcc_rich_text_grid_html('Bir<br>Iki') === 'Bir Iki',
        bcc_rich_text_grid_html('Bir<br>Iki'));
    check('A) <br/> ve <br /> ve <BR> varyantlari da',
        bcc_rich_text_grid_html('a<br/>b<br />c<BR>d') === 'a b c d',
        bcc_rich_text_grid_html('a<br/>b<br />c<BR>d'));
    // Bicimlendirme ve linkler KAYBOLMAMALI — yalnizca satir sonu duzlesiyor.
    check('A) <strong>/<em>/<a> DOKUNULMADAN duruyor',
        bcc_rich_text_grid_html('<strong>K</strong><br><a href="https://x.example">L</a>')
        === '<strong>K</strong> <a href="https://x.example">L</a>');
    check('A) <br> yoksa metin AYNEN doner (no-op)',
        bcc_rich_text_grid_html('duz metin') === 'duz metin');
    check('A) bos/null girdi patlamiyor',
        bcc_rich_text_grid_html('') === '' && bcc_rich_text_grid_html(null) === '');
    // Gecersiz UTF-8: /u modifikatoru olsaydi preg_replace null doner, icerik
    // SESSIZCE SILINIRDI (normalize_cell_value phone dalindaki AYNI tuzak).
    $badUtf8 = "Bir<br>\xC3\x28Iki";
    check('A) gecersiz UTF-8 girdide icerik KAYBOLMUYOR',
        strpos(bcc_rich_text_grid_html($badUtf8), 'Iki') !== false
        && strpos(bcc_rich_text_grid_html($badUtf8), '<br>') === false,
        bin2hex(bcc_rich_text_grid_html($badUtf8)));

    // =====================================================================
    // B) + C) RENDER VE API
    // =====================================================================
    echo "\n--- B/C) grid.php render + cell_update.php ---\n";
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'RtLine Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'RichText TekSatir Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Not Tablo'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Notlar', ':ft' => 'long_text'));
    $fieldId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)',
        array(':t' => $tableId, ':u' => $ownerId));
    $recordId = (int) bcc_last_insert_id();

    $multiLine = 'Birinci satir<br>Ikinci satir<br><strong>Ucuncu</strong> satir';
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
        array(':r' => $recordId, ':f' => $fieldId, ':v' => $multiLine));

    $cookie = login(OWNER_EMAIL);
    check('B) Giris yapildi', $cookie !== null);

    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    check('B) grid.php 200', $g['status'] === 200, 'HTTP ' . $g['status']);
    $html = $g['body'];

    $cellView = null;
    if (preg_match('#<div class="cell-view rich-text-view">(.*?)</div>#s', $html, $m)) { $cellView = $m[1]; }
    check('B) .cell-view render edildi', $cellView !== null);
    check('B) .cell-view icinde <br> KALMADI (tek satir)',
        $cellView !== null && stripos($cellView, '<br') === false, (string) $cellView);
    check('B) satirlar BOSLUKLA ayrildi (kelimeler yapismiyor)',
        $cellView !== null && strpos($cellView, 'Birinci satir Ikinci satir') !== false, (string) $cellView);
    check('B) bicimlendirme korundu (<strong> duruyor)',
        $cellView !== null && strpos($cellView, '<strong>Ucuncu</strong>') !== false, (string) $cellView);
    // data-value duzenleyicinin kaynagi: <br>'ler AYNEN durmali.
    check('B) data-value HAM HTML olarak <br> leri KORUYOR (duzenleyici bozulmaz)',
        strpos($html, 'data-value="Birinci satir&lt;br&gt;Ikinci satir') !== false);

    $csrf = extract_csrf_meta($html);
    $r = http_request('POST', '/api/cell_update.php', $cookie, array(
        'csrf_token' => $csrf, 'record_id' => $recordId, 'field_id' => $fieldId,
        'value' => 'Yeni bir<br>Yeni iki',
    ));
    check('C) cell_update 200', $r['status'] === 200, 'HTTP ' . $r['status']);
    $json = json_decode($r['body'], true);
    check('C) yanit display <br> ICERMIYOR (kaydettikten HEMEN sonra da tek satir)',
        is_array($json) && stripos($json['display'], '<br') === false, $r['body']);
    check('C) yanit display BOSLUKLA ayrilmis',
        is_array($json) && strpos($json['display'], 'Yeni bir Yeni iki') !== false, $r['body']);
    check('C) yanit raw HAM <br> leri koruyor (duzenleyici/data-value)',
        is_array($json) && stripos($json['raw'], '<br') !== false, $r['body']);

    // =====================================================================
    // D) CSS
    // =====================================================================
    echo "\n--- D) CSS: tek satir + yatay akis ---\n";
    $shortRule = rule_body($styleCss, 'table.grid.row-h-short .cell-view.rich-text-view');
    check('D) row-h-short zengin metin kurali var', $shortRule !== null);
    check('D) white-space: nowrap',
        $shortRule !== null && strpos($shortRule, 'white-space: nowrap;') !== false, (string) $shortRule);
    check('D) overflow: hidden',
        $shortRule !== null && strpos($shortRule, 'overflow: hidden;') !== false, (string) $shortRule);
    check('D) text-overflow: ellipsis',
        $shortRule !== null && strpos($shortRule, 'text-overflow: ellipsis;') !== false, (string) $shortRule);
    // Bulunan gercek bug: eski hali -webkit-box + white-space:normal + clamp:1 idi;
    // <br> sonrasi metin IKINCI satirda kaliyor, sutun genisletilse bile
    // ilk satira HIC katilmadigi icin geri gelmiyordu.
    check('D) eski -webkit-line-clamp:1 / white-space:normal KALKTI',
        $shortRule !== null && strpos($shortRule, 'line-clamp') === false
        && strpos($shortRule, 'white-space: normal;') === false, (string) $shortRule);
    $brRule = rule_body($styleCss, 'table.grid.row-h-short .cell-view.rich-text-view br');
    check('D) <br> display:none ile etkisizlestiriliyor (tarayicida olculdu)',
        $brRule !== null && strpos($brRule, 'display: none;') !== false, (string) $brRule);
    check('D) p/div/ul/ol/li satir ici (display: inline)',
        preg_match('#\.rich-text-view p,\s*table\.grid\.row-h-short \.cell-view\.rich-text-view div,#s', $styleCss) === 1
        && preg_match('#\.rich-text-view li \{[^}]*display: inline;#s', $styleCss) === 1);
    check('D) ardisik maddeler arasina gorunur ayirici konuyor',
        preg_match('#\.rich-text-view li \+ li::before \{[^}]*content:#s', $styleCss) === 1);
    // Bulunan gercek tuzak: taban .cell-view'in max-width:320px tavani, sutun
    // 320px'in otesine cekildiginde metnin genislemeyi izlemesini ENGELLERDI.
    $wideRule = rule_body($styleCss, 'table.grid.grid-has-col-widths .cell-view.rich-text-view');
    check('D) sutun genisletilince 320px tavani kalkiyor (max-width: none)',
        $wideRule !== null && strpos($wideRule, 'max-width: none;') !== false, (string) $wideRule);

    // =====================================================================
    // E) DEGISMEMESI GEREKENLER
    // =====================================================================
    echo "\n--- E) Kapsam disi kalanlar (regresyon) ---\n";
    // 3. sart: duzenleyici ve detay gorunumu TAM cok satirli zengin metin.
    $editableRule = rule_body($styleCss, '.richtext-editable');
    check('E) duzenleyici (.richtext-editable) nowrap ALMADI',
        $editableRule !== null && strpos($editableRule, 'nowrap') === false, (string) $editableRule);
    $readonlyRule = rule_body($styleCss, '.grid-detail-field-value-readonly .cell-view');
    check('E) detay panelinin salt-okunur hucresi hala white-space: normal',
        $readonlyRule !== null && strpos($readonlyRule, 'white-space: normal;') !== false, (string) $readonlyRule);
    // Satir yuksekligi ozelligi (Kisa disindakiler) BILEREK korundu.
    check('E) orta/uzun/ekstra satir yukseklikleri hala cok satirli (line-clamp)',
        strpos($styleCss, '-webkit-line-clamp: 2;') !== false
        && strpos($styleCss, '-webkit-line-clamp: 4;') !== false
        && strpos($styleCss, '-webkit-line-clamp: 6;') !== false);
    // Duyuru ekrani: tablo grid'i YOK, ozet zaten sunucuda strip_tags ediliyor
    // ve satir zaten nowrap+ellipsis — degisiklik GEREKMEDI, dogrulaniyor.
    $summaryRule = rule_body($interfaceCss, '.if-record-summary');
    check('E) interface.php ozeti zaten tek satir (nowrap+hidden+ellipsis)',
        $summaryRule !== null && strpos($summaryRule, 'white-space: nowrap;') !== false
        && strpos($summaryRule, 'overflow: hidden;') !== false
        && strpos($summaryRule, 'text-overflow: ellipsis;') !== false, (string) $summaryRule);
    check('E) interface.php ozeti sunucuda strip_tags ile duz metne indiriliyor',
        strpos(file_get_contents(__DIR__ . '/../public/interface.php'), 'strip_tags(cell_display_text(') !== false);
    // Detay paneli zengin metni (is_rich) HALA HTML olarak basiliyor.
    check('E) Duyuru detay paneli zengin metni HTML olarak gosteriyor',
        strpos(file_get_contents($assetsDir . '/interface.js'), 'value.innerHTML = f.value;') !== false);

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
