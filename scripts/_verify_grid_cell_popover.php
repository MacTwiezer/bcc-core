<?php
// Hucre duzenleme popover'i (tasma/konumlandirma) + "+ Yeni olustur..." menusu
// etiketi dogrulamasi.
//
// Kapsam:
//   A) Ortak konumlandirma yardimcisi (bcc_positionFloating) ve dikey cevirme
//   B) grid.js'in IKI hucre popover'inin da yardimciyi KULLANMASI (kopya yok)
//      + dinleyici sizintisi olmamasi
//   C) CSS: ek dosya popover'i artik fixed, ikisi de max-height'i kaydirabilir
//   D) Yukleme sirasi (yardimci grid.js'ten ONCE)
//   E) "+ Yeni olustur..." bir GORUNUM yaratir, TABLO degil; etiket bunu soyler
//   F) Gercek base (15) dokunulmamis olmali
//
// GEOMETRI NOTU: "asagi sigmazsa yukari cevir" davranisi TARAYICIDA olculdu
// (/browse): viewport dibindeki bir hucrede eski kod popover'i 140px ekran
// disina tasiriyordu, en sagdaki sutunda 113px; yeni kodda ikisi de 0.
// Burasi o davranisin KODDA durdugunu ve regresyona ugramadigini bekler.
//
// On kosul: Apache ayakta olmali. Calistirma:
//   C:\php73\php.exe scripts\_verify_grid_cell_popover.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'popover.owner@bcc-test.local');
define('TEST_PASS', 'Popover!2026');
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

// CSS yorumlarini soyar: gecmiste testler ACIKLAMA YORUMLARINA takilip yanlis
// "GECTI" verdi (bu projede uc kez oldu), kural metnine bakiyoruz.
function css_rules($css)
{
    return preg_replace('#/\*.*?\*/#s', '', $css);
}

// JS yorumlarini (satir ve blok) soyar - AYNI tuzak.
function js_code_only($js)
{
    $js = preg_replace('#/\*.*?\*/#s', '', $js);
    return preg_replace('#^\s*//.*$#m', '', $js);
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
    $panelJs = js_code_only(file_get_contents($assetsDir . '/dismissable-panel.js'));
    $gridJs  = js_code_only(file_get_contents($assetsDir . '/grid.js'));
    $styleCss = css_rules(file_get_contents($assetsDir . '/style.css'));

    // =====================================================================
    // A) ORTAK KONUMLANDIRMA YARDIMCISI
    // =====================================================================
    echo "--- A) Ortak yardimci (bcc_positionFloating) ---\n";
    check('A) yardimci disa aciliyor',
        strpos($panelJs, 'window.bcc_positionFloating = function') !== false);
    check('A) DIKEY cevirme var (asagi/yukari bosluk karsilastirmasi)',
        strpos($panelJs, 'spaceBelow') !== false && strpos($panelJs, 'spaceAbove') !== false
        && strpos($panelJs, 'panel.style.bottom =') !== false);
    check('A) hicbir tarafa sigmazsa max-height ile kirpiliyor (gizlenmiyor)',
        strpos($panelJs, 'panel.style.maxHeight =') !== false);
    // Bulunan gercek tuzak: max-height sifirlanmazsa bir kez daralan panel,
    // yer acildiginda dar KALIRDI.
    check('A) max-height her cagride SIFIRLANIYOR',
        preg_match("/panel\.style\.maxHeight = '';[\s\S]{0,200}offsetHeight/", $panelJs) === 1);
    check('A) YATAY tasma korumasi korundu (sag + sol kenar)',
        strpos($panelJs, 'window.innerWidth - margin - panelWidth') !== false
        && strpos($panelJs, 'window.innerWidth - margin - pw') !== false);
    check('A) bindFloatingPanel matematigi KOPYALAMIYOR, yardimciya deleg ediyor',
        preg_match('/function position\(\) \{\s*window\.bcc_positionFloating\(panel, anchor\.getBoundingClientRect\(\), options\);\s*\}/', $panelJs) === 1);
    // Regresyon: eski surumde konum matematigi bindFloatingPanel govdesindeydi.
    check('A) eski satir-ici matematik bindFloatingPanel den KALKTI',
        substr_count($panelJs, 'var rightOffset = window.innerWidth - rect.right;') === 1);

    // =====================================================================
    // B) grid.js — IKI HUCRE POPOVER'I DA YARDIMCIYI KULLANIYOR
    // =====================================================================
    echo "\n--- B) grid.js hucre popover'lari ---\n";
    check('B) zengin metin popover i yardimciyi cagiriyor',
        substr_count($gridJs, 'window.bcc_positionFloating(popover, td.getBoundingClientRect())') === 2,
        'cagri sayisi=' . substr_count($gridJs, 'window.bcc_positionFloating(popover, td.getBoundingClientRect())'));
    // Bulunan gercek bug: kosulsuz "hucrenin ALTI".
    check('B) kosulsuz "tdRect.bottom + 4" konumlandirmasi KALKTI',
        strpos($gridJs, 'tdRect.bottom + 4') === false);
    check('B) grid.js kendi tasma matematigini YAZMIYOR (kopya yok)',
        strpos($gridJs, 'window.innerHeight -') === false && strpos($gridJs, 'window.innerWidth -') === false);

    // Dinleyici sizintisi: her eklenen scroll/resize dinleyicisi KALDIRILMALI.
    // (Popover'lar surekli yaratilip yok ediliyor; sizinti birikirdi.)
    check('B) scroll dinleyicisi eklenen kadar KALDIRILIYOR',
        substr_count($gridJs, "addEventListener('scroll', positionPopover, true)")
        === substr_count($gridJs, "removeEventListener('scroll', positionPopover, true)"),
        'ekle=' . substr_count($gridJs, "addEventListener('scroll', positionPopover, true)")
        . ' kaldir=' . substr_count($gridJs, "removeEventListener('scroll', positionPopover, true)"));
    check('B) resize dinleyicisi eklenen kadar KALDIRILIYOR',
        substr_count($gridJs, "addEventListener('resize', positionPopover)")
        === substr_count($gridJs, "removeEventListener('resize', positionPopover)"),
        'ekle=' . substr_count($gridJs, "addEventListener('resize', positionPopover)")
        . ' kaldir=' . substr_count($gridJs, "removeEventListener('resize', positionPopover)"));
    check('B) her IKI popover da resize e abone (pencere kuculunce yeniden olcum)',
        substr_count($gridJs, "addEventListener('resize', positionPopover)") === 2);

    // =====================================================================
    // C) CSS
    // =====================================================================
    echo "\n--- C) CSS ---\n";
    check('C) ek dosya popover i artik position: fixed',
        preg_match('/\.attachment-popover \{[^}]*position: fixed;/s', $styleCss) === 1);
    // absolute iken .grid-wrap { overflow:auto } kutusuna kirpiliyordu.
    check('C) ek dosya popover inda position: absolute KALMADI',
        preg_match('/\.attachment-popover \{[^}]*position: absolute;/s', $styleCss) === 0);
    check('C) CSS teki sabit top/left kalintisi temizlendi (konum JS ten)',
        preg_match('/\.attachment-popover \{[^}]*top: calc\(100% \+ 0\.35rem\);/s', $styleCss) === 0);
    check('C) zengin metin popover i fixed kaldi',
        preg_match('/\.richtext-popover \{[^}]*position: fixed;/s', $styleCss) === 1);
    // max-height kirpma yerine KAYDIRMA olsun diye.
    check('C) iki popover da max-height i kaydirabiliyor (overflow-y: auto)',
        preg_match('/\.richtext-popover \{[^}]*overflow-y: auto;/s', $styleCss) === 1
        && preg_match('/\.attachment-popover \{[^}]*overflow-y: auto;/s', $styleCss) === 1);

    // =====================================================================
    // D) YUKLEME SIRASI
    // =====================================================================
    echo "\n--- D) Yukleme sirasi ---\n";
    $gridPhp = file_get_contents(__DIR__ . '/../public/grid.php');
    check('D) dismissable-panel.js, grid.js ten ONCE yukleniyor',
        strpos($gridPhp, "bcc_asset_url('dismissable-panel.js')") < strpos($gridPhp, "bcc_asset_url('grid.js')"));

    // =====================================================================
    // E) "+ Yeni olustur..." GORUNUM yaratir, TABLO degil
    // =====================================================================
    echo "\n--- E) \"+ Yeni olustur...\" etiketi ve davranisi ---\n";
    check('E) grid etiketi artik "Tablo gorunumu" (yalin "Tablo" degil)',
        $GLOBALS['BCC_VIEW_TYPES']['grid'] === 'Tablo görünümü',
        $GLOBALS['BCC_VIEW_TYPES']['grid']);

    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi yok.\n"; exit(1); }

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => OWNER_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'Popover Owner'));
    $ownerId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamId, ':u' => $ownerId, ':r' => 'owner'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'Popover Test', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array(':b' => $baseId, ':n' => 'Popover Tablo'));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
        array(':t' => $tableId, ':n' => 'Ad', ':ft' => 'single_line_text'));
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 1)',
        array(':t' => $tableId, ':n' => 'Notlar', ':ft' => 'long_text'));

    $cookie = login(OWNER_EMAIL);
    check('E) Giris yapildi', $cookie !== null);

    $g = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    check('E) grid.php 200', $g['status'] === 200, 'HTTP ' . $g['status']);
    $html = $g['body'];
    $csrf = extract_csrf_meta($html);

    check('E) menude "Tablo görünümü" yaziyor',
        strpos($html, '>Tablo görünümü<') !== false);
    check('E) menu basligi ne yaratildigini soyluyor',
        strpos($html, 'Bu tablonun yeni görünümü') !== false);
    check('E) GERCEK yeni tablo yolu duruyor (tablo sekmelerindeki "+")',
        strpos($html, 'class="gs-table-tab-add"') !== false
        && strpos($html, '/base_tables.php?base_id=') !== false);

    // DAVRANIS: uc nokta yeni bir TABLO degil, AYNI tablonun yeni GORUNUMUNU yaratir.
    $tablesBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => $baseId));
    $fieldsBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :t', array(':t' => $tableId));
    $r = http_request('POST', '/api/view_create.php', $cookie, array(
        'csrf_token' => $csrf, 'table_id' => $tableId, 'view_type' => 'grid',
    ));
    check('E) view_create 200', $r['status'] === 200, 'HTTP ' . $r['status']);

    $tablesAfter = (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array(':b' => $baseId));
    check('E) YENI TABLO OLUSMADI (tablo sayisi ayni)', $tablesAfter === $tablesBefore,
        "once={$tablesBefore} sonra={$tablesAfter}");
    check('E) ALAN SEMASI KOPYALANMADI (yeni alan yok)',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :t', array(':t' => $tableId)) === $fieldsBefore);

    $newView = bcc_fetch_all('SELECT id, name, table_id, view_type FROM views WHERE table_id = :t ORDER BY id DESC LIMIT 1',
        array(':t' => $tableId));
    check('E) yeni GORUNUM ayni table_id yi paylasiyor',
        $newView && (int) $newView[0]['table_id'] === $tableId);
    check('E) yeni gorunum adi "Tablo görünümü 2" (varsayilanla tutarli)',
        $newView && $newView[0]['name'] === 'Tablo görünümü 2',
        $newView ? $newView[0]['name'] : 'YOK');

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
