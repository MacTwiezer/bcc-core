<?php
// Isim benzersizliginin SCOPE'LU oldugunu UCTAN UCA dogrular.
//
// Kural: bir isim yalnizca AIT OLDUGU UST YAPI icinde benzersizdir.
//   Base A -> "Musteriler"  +  Base B -> "Musteriler"   = GECERLI
//   Base A -> "Musteriler"  +  Base A -> "Musteriler"   = GECERSIZ
//
// Yontem: sayfa/uc nokta akislari GERCEK oturumla, ayri PHP alt sureclerinde
// calistirilir (_post_as_case.php); paylasilan fonksiyonlar (bcc_create_base,
// bcc_create_field) dogrudan cagrilir. Yetki/validation mantiginin kopyasi
// test edilmez.
//
// KENDI KURBAN VERISINI KURAR VE SILER. Gercek verilere dokunmaz.
//
// Calistirma: C:\php73\php.exe scripts\_verify_scoped_name_uniqueness.php

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

// base_tables.php gibi TAM SAYFA POST akislari icin.
function post_page($userId, $page, $query, $post)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_post_as_case.php')
        . ' ' . escapeshellarg((string) $userId)
        . ' ' . escapeshellarg($page)
        . ' ' . escapeshellarg($query)
        . ' ' . escapeshellarg(base64_encode(json_encode($post)));

    return (string) shell_exec($cmd . ' 2>&1');
}

echo "=== A) Veritabani kisitlari ===\n";

$uq = array();
foreach (bcc_fetch_all(
    "SELECT TABLE_NAME t, INDEX_NAME i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
      GROUP BY TABLE_NAME, INDEX_NAME") as $r) {
    $uq[$r['t'] . '.' . $r['i']] = $r['c'];
}

check('tables_meta: UNIQUE(base_id, name) — GLOBAL degil',
    isset($uq['tables_meta.uq_tables_meta_base_name']) && $uq['tables_meta.uq_tables_meta_base_name'] === 'base_id,name');
check('fields: UNIQUE(table_id, name)',
    isset($uq['fields.uq_fields_table_name']) && $uq['fields.uq_fields_table_name'] === 'table_id,name');
check('views: UNIQUE(table_id, name)',
    isset($uq['views.uq_views_table_name']) && $uq['views.uq_views_table_name'] === 'table_id,name');

// Asil regresyon: hicbiri YALNIZCA name uzerinde olmamali (= global benzersizlik).
$globalNameIdx = array();
foreach ($uq as $key => $cols) {
    if ($cols === 'name' && strpos($key, 'teams.') !== 0) {
        $globalNameIdx[] = $key;
    }
}
check('HICBIR tabloda GLOBAL UNIQUE(name) yok (teams haric)',
    empty($globalNameIdx), implode(', ', $globalNameIdx));

// teams BILEREK global: veri modelinin en ust yapisi, ustunde scope yok.
check('teams.name GLOBAL kaliyor (en ust yapi, dogru davranis)',
    isset($uq['teams.uq_teams_name']) && $uq['teams.uq_teams_name'] === 'name');

// bases'te DB kisiti OLMAMALI (soft-delete: cop kutusundaki ad blokelemesin).
$basesNameIdx = false;
foreach ($uq as $key => $cols) {
    if (strpos($key, 'bases.') === 0 && strpos($cols, 'name') !== false) { $basesNameIdx = $key . ' => ' . $cols; }
}
check('bases: DB UNIQUE index YOK (soft-delete, uygulama katmaninda uygulanir)',
    $basesNameIdx === false, (string) $basesNameIdx);

echo "\n=== B) Scope haritasi ===\n";

check('BCC_NAME_SCOPES tanimli', isset($GLOBALS['BCC_NAME_SCOPES']));
check('bases -> team_id', $GLOBALS['BCC_NAME_SCOPES']['bases']['scope'] === 'team_id');
check('tables_meta -> base_id', $GLOBALS['BCC_NAME_SCOPES']['tables_meta']['scope'] === 'base_id');
check('fields -> table_id', $GLOBALS['BCC_NAME_SCOPES']['fields']['scope'] === 'table_id');
check('views -> table_id', $GLOBALS['BCC_NAME_SCOPES']['views']['scope'] === 'table_id');
check('bases soft-delete dikkate aliniyor', $GLOBALS['BCC_NAME_SCOPES']['bases']['soft_delete'] === true);

$threw = false;
try { bcc_name_taken('users', 1, 'x'); } catch (InvalidArgumentException $e) { $threw = true; }
check('whitelist disi varlik ISTISNA atiyor (sessizce kapanmiyor)', $threw);

echo "\n=== C) Gecici kurban verisi ===\n";

bcc_execute("INSERT INTO teams (name) VALUES ('ZZ Isim Scope Testi')");
$teamId = (int) bcc_last_insert_id();
bcc_execute("INSERT INTO teams (name) VALUES ('ZZ Isim Scope Testi 2')");
$teamId2 = (int) bcc_last_insert_id();

$owner = bcc_fetch_one('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1');
$ownerId = (int) $owner['id'];
foreach (array($teamId, $teamId2) as $t) {
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, \'owner\')',
        array('t' => $t, 'u' => $ownerId));
}

register_shutdown_function(function () use ($teamId, $teamId2) {
    bcc_execute('DELETE FROM teams WHERE id IN (:a, :b)', array('a' => $teamId, 'b' => $teamId2));
});

$a = bcc_create_base($teamId, 'Base A', '', $ownerId);
$b = bcc_create_base($teamId, 'Base B', '', $ownerId);
check('iki test base olusturuldu', $a['ok'] && $b['ok'], json_encode(array($a, $b), JSON_UNESCAPED_UNICODE));
$baseA = (int) $a['id'];
$baseB = (int) $b['id'];
echo "  ekip=$teamId baseA=$baseA baseB=$baseB\n";

echo "\n=== D) Kullanicinin 6 test senaryosu (TABLO) ===\n";

// Test 1: Base A -> Table A  +  Base A -> Table A  => ENGELLENMELI
post_page($ownerId, 'base_tables.php', 'base_id=' . $baseA, array('action' => 'create_table', 'name' => 'Table A'));
$n = (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
    array('b' => $baseA, 'n' => 'Table A'));
check('kurulum: Base A -> "Table A" olusturuldu', $n === 1, 'adet=' . $n);

$html = post_page($ownerId, 'base_tables.php', 'base_id=' . $baseA, array('action' => 'create_table', 'name' => 'Table A'));
$n = (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
    array('b' => $baseA, 'n' => 'Table A'));
check('TEST 1: Base A -> "Table A" IKINCI kez => ENGELLENDI', $n === 1, 'adet=' . $n);
check('TEST 1: kullaniciya scope\'u anlatan hata gosteriliyor',
    strpos($html, 'zaten kullanılıyor') !== false && strpos($html, "base") !== false);

// Test 2 / 6: Base B -> Table A => IZIN VERILMELI
post_page($ownerId, 'base_tables.php', 'base_id=' . $baseB, array('action' => 'create_table', 'name' => 'Table A'));
$n = (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
    array('b' => $baseB, 'n' => 'Table A'));
check('TEST 2+6: Base B -> "Table A" => IZIN VERILDI (farkli scope)', $n === 1, 'adet=' . $n);

// Test 3: Base A -> Table B => IZIN VERILMELI
post_page($ownerId, 'base_tables.php', 'base_id=' . $baseA, array('action' => 'create_table', 'name' => 'Table B'));
$n = (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
    array('b' => $baseA, 'n' => 'Table B'));
check('TEST 3: Base A -> "Table B" => IZIN VERILDI (farkli isim)', $n === 1, 'adet=' . $n);

$tblA = (int) bcc_fetch_column('SELECT id FROM tables_meta WHERE base_id = :b AND name = :n',
    array('b' => $baseA, 'n' => 'Table A'));
$tblB = (int) bcc_fetch_column('SELECT id FROM tables_meta WHERE base_id = :b AND name = :n',
    array('b' => $baseA, 'n' => 'Table B'));

// Test 4: mevcut tabloyu AYNI isimle guncelle => KENDI KAYDI, ENGELLENMEMELI
post_page($ownerId, 'base_tables.php', 'base_id=' . $baseA, array(
    'action' => 'rename_table', 'table_id' => $tblA, 'name' => 'Table A', 'description' => 'aciklama degisti',
));
$row = bcc_fetch_one('SELECT name, description FROM tables_meta WHERE id = :id', array('id' => $tblA));
check('TEST 4: ayni isimle guncelleme ENGELLENMEDI (kendi kaydi haric tutuldu)',
    $row['name'] === 'Table A' && $row['description'] === 'aciklama degisti',
    json_encode($row, JSON_UNESCAPED_UNICODE));

// Test 5: Table B -> "Table A" rename => ENGELLENMELI
$html = post_page($ownerId, 'base_tables.php', 'base_id=' . $baseA, array(
    'action' => 'rename_table', 'table_id' => $tblB, 'name' => 'Table A', 'description' => '',
));
$stillB = bcc_fetch_column('SELECT name FROM tables_meta WHERE id = :id', array('id' => $tblB));
check('TEST 5: ayni base\'de baskasinin adiyla rename => ENGELLENDI', $stillB === 'Table B', 'ad=' . $stillB);
check('TEST 5: hata mesaji gosterildi', strpos($html, 'zaten kullanılıyor') !== false);

// Farkli base'deki bir adla rename SERBEST olmali.
post_page($ownerId, 'base_tables.php', 'base_id=' . $baseB, array('action' => 'create_table', 'name' => 'Sadece B'));
$html = post_page($ownerId, 'base_tables.php', 'base_id=' . $baseA, array(
    'action' => 'rename_table', 'table_id' => $tblB, 'name' => 'Sadece B', 'description' => '',
));
$renamed = bcc_fetch_column('SELECT name FROM tables_meta WHERE id = :id', array('id' => $tblB));
check('BASKA base\'de kullanilan adla rename => IZIN VERILDI', $renamed === 'Sadece B', 'ad=' . $renamed);

echo "\n=== E) Buyuk/kucuk harf ===\n";

$html = post_page($ownerId, 'base_tables.php', 'base_id=' . $baseA, array('action' => 'create_table', 'name' => 'TABLE A'));
$n = (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b', array('b' => $baseA));
check('"TABLE A" ile "Table A" AYNI sayiliyor => ENGELLENDI (utf8mb4_unicode_ci)', $n === 2, 'toplam tablo=' . $n);

echo "\n=== F) BASE adlari (scope: team_id) ===\n";

$dup = bcc_create_base($teamId, 'Base A', '', $ownerId);
check('ayni ekipte ayni base adi => ENGELLENDI', $dup['ok'] === false, json_encode($dup, JSON_UNESCAPED_UNICODE));
check('base hata mesaji scope\'u soyluyor',
    $dup['error'] !== null && strpos($dup['error'], 'çalışma alanında') !== false, (string) $dup['error']);

$other = bcc_create_base($teamId2, 'Base A', '', $ownerId);
check('BASKA ekipte ayni base adi => IZIN VERILDI', $other['ok'] === true, json_encode($other, JSON_UNESCAPED_UNICODE));

// Cop kutusundaki base adi BLOKE ETMEMELI.
bcc_execute('UPDATE bases SET deleted_at = NOW() WHERE id = :id', array('id' => $baseB));
$reuse = bcc_create_base($teamId, 'Base B', '', $ownerId);
check('SILINMIS base\'in adi yeniden kullanilabiliyor', $reuse['ok'] === true, json_encode($reuse, JSON_UNESCAPED_UNICODE));
bcc_execute('DELETE FROM bases WHERE id = :id', array('id' => $reuse['id']));
bcc_execute('UPDATE bases SET deleted_at = NULL WHERE id = :id', array('id' => $baseB));

echo "\n=== G) ALAN adlari (scope: table_id) ===\n";

$f1 = bcc_create_field($tblA, $teamId, array('name' => 'Telefon', 'field_type' => 'single_line_text'));
check('kurulum: "Telefon" alani olusturuldu', $f1['ok'], json_encode($f1, JSON_UNESCAPED_UNICODE));

$f2 = bcc_create_field($tblA, $teamId, array('name' => 'Telefon', 'field_type' => 'single_line_text'));
check('AYNI tabloda ayni alan adi => ENGELLENDI', $f2['ok'] === false, json_encode($f2, JSON_UNESCAPED_UNICODE));
check('alan hata mesaji scope\'u soyluyor',
    $f2['ok'] === false && strpos($f2['error'], 'tabloda') !== false, (string) $f2['error']);

$f3 = bcc_create_field($tblB, $teamId, array('name' => 'Telefon', 'field_type' => 'single_line_text'));
check('BASKA tabloda ayni alan adi => IZIN VERILDI', $f3['ok'] === true, json_encode($f3, JSON_UNESCAPED_UNICODE));

// Alan guncelleme: kendi kaydi haric tutuluyor mu?
$fieldId = (int) bcc_fetch_column('SELECT id FROM fields WHERE table_id = :t AND name = :n',
    array('t' => $tblA, 'n' => 'Telefon'));
post_page($ownerId, 'table_fields.php', 'table_id=' . $tblA, array(
    'action' => 'update_field', 'field_id' => $fieldId, 'name' => 'Telefon', 'field_type' => 'long_text',
));
$after = bcc_fetch_one('SELECT name, field_type FROM fields WHERE id = :id', array('id' => $fieldId));
check('alan: ayni isimle tip degisimi ENGELLENMEDI (kendi kaydi)',
    $after['name'] === 'Telefon' && $after['field_type'] === 'long_text', json_encode($after, JSON_UNESCAPED_UNICODE));

bcc_create_field($tblA, $teamId, array('name' => 'Adres', 'field_type' => 'single_line_text'));
$adresId = (int) bcc_fetch_column('SELECT id FROM fields WHERE table_id = :t AND name = :n',
    array('t' => $tblA, 'n' => 'Adres'));
$html = post_page($ownerId, 'table_fields.php', 'table_id=' . $tblA, array(
    'action' => 'update_field', 'field_id' => $adresId, 'name' => 'Telefon', 'field_type' => 'single_line_text',
));
$stillAdres = bcc_fetch_column('SELECT name FROM fields WHERE id = :id', array('id' => $adresId));
check('alan: baskasinin adiyla rename => ENGELLENDI', $stillAdres === 'Adres', 'ad=' . $stillAdres);
check('alan rename hata mesaji gosterildi', strpos($html, 'zaten kullanılıyor') !== false);

echo "\n=== H) GORUNUM adlari (scope: table_id) ===\n";

$vA = bcc_get_or_create_default_view($tblA);
$vB = bcc_get_or_create_default_view($tblB);
check('iki tabloda da AYNI adli varsayilan gorunum olabiliyor (farkli scope)',
    $vA && $vB && $vA['name'] === $vB['name'] && (int) $vA['id'] !== (int) $vB['id'],
    json_encode(array($vA['name'], $vB['name']), JSON_UNESCAPED_UNICODE));

bcc_execute("INSERT INTO views (table_id, name, view_type, position) VALUES (:t, 'Ikinci', 'grid', 1)",
    array('t' => $tblA));
$ikinciId = (int) bcc_last_insert_id();

check('ayni tabloda ayni gorunum adi bcc_name_taken ile yakalaniyor',
    bcc_name_taken('views', $tblA, 'Ikinci') === true);
check('gorunumun KENDISI haric tutulunca yakalanmiyor',
    bcc_name_taken('views', $tblA, 'Ikinci', $ikinciId) === false);
check('BASKA tabloda ayni gorunum adi serbest',
    bcc_name_taken('views', $tblB, 'Ikinci') === false);

echo "\n=== I) Veritabani son savunma hatti ===\n";

// Uygulama kontrolu atlansa bile DB reddetmeli.
$dbBlocked = false;
try {
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 99)',
        array('b' => $baseA, 'n' => 'Table A'));
} catch (Throwable $e) {
    $dbBlocked = strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false;
}
check('uygulama atlansa bile DB duplicate tabloyu REDDEDIYOR', $dbBlocked);

// Ayni adi HENUZ ICERMEYEN ucuncu bir base: DB kisiti global olsaydi burasi da
// reddedilirdi. ($baseB zaten "Table A" iceriyor, onu kullanmak yaniltirdi.)
$c = bcc_create_base($teamId, 'Base C', '', $ownerId);
$dbAllowed = true;
$dbErr = '';
try {
    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)',
        array('b' => (int) $c['id'], 'n' => 'Table A'));
} catch (Throwable $e) {
    $dbAllowed = false;
    $dbErr = $e->getMessage();
}
check('DB kisiti FARKLI base\'de ayni adi ENGELLEMIYOR (scope\'lu, global degil)', $dbAllowed, $dbErr);

echo "\n=== J) Kaynak taramasi ===\n";

// Global duplicate kontrolu geri sizmasin.
$srcFiles = array_merge(
    glob(__DIR__ . '/../public/*.php'),
    glob(__DIR__ . '/../public/api/*.php'),
    glob(__DIR__ . '/../src/*.php')
);
$globalChecks = array();
foreach ($srcFiles as $file) {
    $src = file_get_contents($file);
    // Yorumlari soy: karari ACIKLAYAN yorumlara takilmasin (projede ucuncu kez
    // ayni ders, bkz. docs/PROJE-DURUM.md grid-export.css vakasi).
    $code = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && in_array($tok[0], array(T_COMMENT, T_DOC_COMMENT), true)) { continue; }
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    // "FROM <tablo> WHERE name = ..." — arada scope kolonu YOKSA global demektir.
    if (preg_match('/FROM\s+(tables_meta|fields|views|bases)\s+WHERE\s+name\s*=/i', $code, $m)) {
        $globalChecks[] = basename($file) . ' (' . $m[1] . ')';
    }
}
check('kodda GLOBAL "FROM <tablo> WHERE name =" kontrolu YOK',
    empty($globalChecks), implode(', ', $globalChecks));

$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
check('schema.sql: tables_meta UNIQUE(base_id, name) icyor',
    strpos($schemaSql, 'uq_tables_meta_base_name (base_id, name)') !== false);
check('schema.sql: fields UNIQUE(table_id, name)',
    strpos($schemaSql, 'uq_fields_table_name (table_id, name)') !== false);
check('schema.sql: views UNIQUE(table_id, name)',
    strpos($schemaSql, 'uq_views_table_name (table_id, name)') !== false);

echo "\n" . str_repeat('-', 62) . "\n";
$passed = count(array_filter($results));
$total = count($results);
echo ($passed === $total ? 'SONUC: ' : 'SONUC: DIKKAT — ') . $passed . '/' . $total . " kontrol gecti\n";
exit($passed === $total ? 0 : 1);
