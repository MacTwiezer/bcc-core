<?php
// Faz 4 (Filtreleme) doğrulaması. curl KULLANILMAZ — PHP'nin http:// stream
// sarmalayıcısıyla gerçek oturum çerezi alınıp gerçek grid.php'ye istek atılır.
// Kendi test verisini kurar, doğrular, sonunda temizler.
//
// Ön koşul: dev server ayakta olmalı -> C:\php73\php.exe -S localhost:8000 -t public
// Çalıştırma: C:\php73\php.exe scripts\_verify_phase4_filter.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('BASE_URL', 'http://localhost:8000');
define('TEST_EMAIL', 'faz4filter.test.editor@bcc-test.local');
define('TEST_PASS', 'Faz4Test!2026');

$pdo = bcc_get_pdo();
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
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));

    if ($method === 'POST') {
        $body = http_build_query($postFields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = $body;
    }

    $options['http']['header'] = implode("\r\n", $headers);

    $context = stream_context_create($options);
    $body = @file_get_contents(BASE_URL . $path, false, $context);

    $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $parts = explode(';', substr($h, 11));
                $newCookie = trim($parts[0]);
            }
        }
    }

    return array('body' => $body, 'cookie' => $newCookie);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

function row_count($html)
{
    return substr_count($html, 'data-record-id="');
}

// Bir alan id'sine ait hücrelerin DOM sırasını (data-value) döndürür.
function extract_field_values($html, $fieldId)
{
    $pattern = '/data-field-id="' . preg_quote((string) $fieldId, '/') . '"[^>]*data-value="([^"]*)"/';
    preg_match_all($pattern, $html, $m);
    return isset($m[1]) ? $m[1] : array();
}

function same_set($a, $b)
{
    sort($a);
    sort($b);
    return $a === $b;
}

$pdo->prepare('DELETE FROM users WHERE email = :e')->execute(array(':e' => TEST_EMAIL));

$cleanup = function () use ($pdo) {
    $stmt = $pdo->prepare("SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e");
    $stmt->execute(array(':e' => TEST_EMAIL));
    foreach (array_column($stmt->fetchAll(), 'id') as $baseId) {
        $pdo->prepare('DELETE FROM bases WHERE id = :id')->execute(array(':id' => $baseId));
    }
    $pdo->prepare('DELETE FROM users WHERE email = :e')->execute(array(':e' => TEST_EMAIL));
};

try {
    $team = $pdo->query("SELECT id FROM teams WHERE name = 'TY' LIMIT 1")->fetch();
    if (!$team) {
        echo "HATA: TY ekibi bulunamadi.\n";
        exit(1);
    }
    $teamId = (int) $team['id'];

    $hash = password_hash(TEST_PASS, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :name, 0, 1)');
    $stmt->execute(array(':email' => TEST_EMAIL, ':hash' => $hash, ':name' => 'Faz4 Filter Test'));
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO team_members (team_id, user_id, role) VALUES (:tid, :uid, :role)')
        ->execute(array(':tid' => $teamId, ':uid' => $userId, ':role' => 'editor'));

    $stmt = $pdo->prepare('INSERT INTO bases (team_id, name, created_by) VALUES (:tid, :name, :uid)');
    $stmt->execute(array(':tid' => $teamId, ':name' => 'Faz4 Filter Test', ':uid' => $userId));
    $baseId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO tables_meta (base_id, name, position) VALUES (:bid, :name, 0)');
    $stmt->execute(array(':bid' => $baseId, ':name' => 'Filter Test'));
    $tableId = (int) $pdo->lastInsertId();

    $insertField = $pdo->prepare('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:tid, :name, :type, :options, :pos)');
    $selectOptions = json_encode(array('choices' => array('Kirmizi', 'Yesil', 'Mavi')), JSON_UNESCAPED_UNICODE);
    $tagOptions = json_encode(array('choices' => array('A', 'B', 'C')), JSON_UNESCAPED_UNICODE);

    $defs = array(
        array('Ad', 'single_line_text', null),
        array('Miktar', 'number', null),
        array('Aktif', 'checkbox', null),
        array('Tarih', 'date', null),
        array('Renk', 'single_select', $selectOptions),
        array('Etiketler', 'multiple_select', $tagOptions),
    );
    $fieldIds = array();
    foreach ($defs as $i => $d) {
        $insertField->execute(array(':tid' => $tableId, ':name' => $d[0], ':type' => $d[1], ':options' => $d[2], ':pos' => $i));
        $fieldIds[$d[0]] = (int) $pdo->lastInsertId();
    }

    $insertRecord = $pdo->prepare('INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)');
    $insertText = $pdo->prepare('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:rid, :fid, :val)');
    $insertNumber = $pdo->prepare('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:rid, :fid, :val)');
    $insertDate = $pdo->prepare('INSERT INTO cell_values (record_id, field_id, value_date) VALUES (:rid, :fid, :val)');
    $insertJson = $pdo->prepare('INSERT INTO cell_values (record_id, field_id, value_json) VALUES (:rid, :fid, :val)');

    // rec1=Elma, rec2=Armut, rec3=Kiraz, rec4=Muz, rec5=(bos kayit)
    $rows = array(
        array('name' => 'Elma', 'miktar' => 10, 'aktif' => 1, 'tarih' => '2026-01-10', 'renk' => 'Kirmizi', 'tags' => array('A')),
        array('name' => 'Armut', 'miktar' => 20, 'aktif' => 0, 'tarih' => '2026-02-15', 'renk' => 'Yesil', 'tags' => array('A', 'B')),
        array('name' => 'Kiraz', 'miktar' => 30, 'aktif' => 1, 'tarih' => '2026-03-20', 'renk' => 'Mavi', 'tags' => array('B')),
        array('name' => 'Muz', 'miktar' => 5, 'aktif' => 0, 'tarih' => '2026-01-01', 'renk' => 'Kirmizi', 'tags' => array()),
        null, // tamamen bos kayit
    );

    $recordIds = array();
    foreach ($rows as $i => $r) {
        $insertRecord->execute(array(':tid' => $tableId, ':pos' => $i, ':uid' => $userId));
        $rid = (int) $pdo->lastInsertId();
        $recordIds[] = $rid;

        if ($r === null) {
            continue;
        }

        $insertText->execute(array(':rid' => $rid, ':fid' => $fieldIds['Ad'], ':val' => $r['name']));
        $insertNumber->execute(array(':rid' => $rid, ':fid' => $fieldIds['Miktar'], ':val' => $r['miktar']));
        $insertNumber->execute(array(':rid' => $rid, ':fid' => $fieldIds['Aktif'], ':val' => $r['aktif']));
        $insertDate->execute(array(':rid' => $rid, ':fid' => $fieldIds['Tarih'], ':val' => $r['tarih'] . ' 00:00:00'));
        $insertText->execute(array(':rid' => $rid, ':fid' => $fieldIds['Renk'], ':val' => $r['renk']));
        if (!empty($r['tags'])) {
            $insertJson->execute(array(':rid' => $rid, ':fid' => $fieldIds['Etiketler'], ':val' => json_encode($r['tags'], JSON_UNESCAPED_UNICODE)));
        }
    }

    echo "Kurulum tamam: table_id={$tableId}\n\n";

    // --- Oturum ac ---------------------------------------------------------
    $resp = http_request('GET', '/login.php');
    $csrf = extract_csrf($resp['body']);
    $cookie = $resp['cookie'];
    $resp = http_request('POST', '/login.php', $cookie, array('email' => TEST_EMAIL, 'password' => TEST_PASS, 'csrf_token' => $csrf));
    if ($resp['cookie']) {
        $cookie = $resp['cookie'];
    }
    check('Giris yapildi', $cookie !== null);

    $adId = $fieldIds['Ad'];
    $miktarId = $fieldIds['Miktar'];
    $aktifId = $fieldIds['Aktif'];
    $tarihId = $fieldIds['Tarih'];
    $renkId = $fieldIds['Renk'];
    $etiketId = $fieldIds['Etiketler'];

    // --- Filtresiz: 5 kayit ------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}", $cookie);
    check('Filtresiz grid 5 kayit gosteriyor', row_count($resp['body']) === 5, 'bulunan: ' . row_count($resp['body']));

    // --- Metin: contains -----------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$adId}&filter_cond_1=contains&filter_value_1=" . urlencode('rmu'), $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Metin "icerir" -> sadece Armut', $names === array('Armut'), 'bulunan: ' . implode(',', $names));

    // --- Metin: equals -------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$adId}&filter_cond_1=equals&filter_value_1=" . urlencode('Kiraz'), $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Metin "esittir" -> sadece Kiraz', $names === array('Kiraz'), 'bulunan: ' . implode(',', $names));

    // --- Metin: bos ------------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$adId}&filter_cond_1=empty", $cookie);
    check('Metin "bos" -> 1 kayit (Ad hucresi olmayan)', row_count($resp['body']) === 1, 'bulunan: ' . row_count($resp['body']));

    // --- Sayi: > ---------------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$miktarId}&filter_cond_1=gt&filter_value_1=15", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Sayi ">15" -> Armut+Kiraz', same_set($names, array('Armut', 'Kiraz')), 'bulunan: ' . implode(',', $names));

    // --- Sayi: <= ---------------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$miktarId}&filter_cond_1=lte&filter_value_1=10", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Sayi "<=10" -> Elma+Muz', same_set($names, array('Elma', 'Muz')), 'bulunan: ' . implode(',', $names));

    // --- Sayi: gecersiz deger -> kural yok sayilir, filtresiz gibi davranir ---
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$miktarId}&filter_cond_1=gt&filter_value_1=" . urlencode('abc'), $cookie);
    check('Sayida gecersiz deger -> kural yok sayilir (5 kayit)', row_count($resp['body']) === 5, 'bulunan: ' . row_count($resp['body']));

    // --- Checkbox: checked -------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$aktifId}&filter_cond_1=checked", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Checkbox "isaretli" -> Elma+Kiraz', same_set($names, array('Elma', 'Kiraz')), 'bulunan: ' . implode(',', $names));

    // --- Checkbox: unchecked (bos kayit dahil) -----------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$aktifId}&filter_cond_1=unchecked", $cookie);
    check('Checkbox "isaretsiz" -> 3 kayit (Armut, Muz, bos)', row_count($resp['body']) === 3, 'bulunan: ' . row_count($resp['body']));

    // --- Tarih: once/sonra -------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$tarihId}&filter_cond_1=before&filter_value_1=2026-02-01", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Tarih "once 2026-02-01" -> Elma+Muz', same_set($names, array('Elma', 'Muz')), 'bulunan: ' . implode(',', $names));

    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$tarihId}&filter_cond_1=after&filter_value_1=2026-02-01", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Tarih "sonra 2026-02-01" -> Armut+Kiraz', same_set($names, array('Armut', 'Kiraz')), 'bulunan: ' . implode(',', $names));

    // --- Coklu secim: icerir -------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$etiketId}&filter_cond_1=contains&filter_value_1=B", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('Coklu secim "B icerir" -> Armut+Kiraz', same_set($names, array('Armut', 'Kiraz')), 'bulunan: ' . implode(',', $names));

    // --- VE (AND): Renk=Kirmizi VE Miktar>=10 -> sadece Elma ---------------
    $q = "filter_field_1={$renkId}&filter_cond_1=equals&filter_value_1=" . urlencode('Kirmizi')
       . "&filter_field_2={$miktarId}&filter_cond_2=gte&filter_value_2=10&filter_logic=and";
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&{$q}", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('VE: Renk=Kirmizi VE Miktar>=10 -> sadece Elma', $names === array('Elma'), 'bulunan: ' . implode(',', $names));
    check('VE modunda "VE (tüm kurallar)" secili', strpos($resp['body'], 'value="and" checked') !== false);

    // --- VEYA (OR): Renk=Mavi VEYA Miktar<10 -> Kiraz+Muz -------------------
    $q = "filter_field_1={$renkId}&filter_cond_1=equals&filter_value_1=" . urlencode('Mavi')
       . "&filter_field_2={$miktarId}&filter_cond_2=lt&filter_value_2=10&filter_logic=or";
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&{$q}", $cookie);
    $names = extract_field_values($resp['body'], $adId);
    check('VEYA: Renk=Mavi VEYA Miktar<10 -> Kiraz+Muz', same_set($names, array('Kiraz', 'Muz')), 'bulunan: ' . implode(',', $names));

    // --- Guvenlik: sahte/yabanci alan id'si sessizce yok sayilir ------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1=999999&filter_cond_1=equals&filter_value_1=x", $cookie);
    check('Sahte alan id -> kural yok sayilir, 5 kayit doner', row_count($resp['body']) === 5, 'bulunan: ' . row_count($resp['body']));

    // --- Guvenlik: alan tipine uymayan operator (whitelist disi) yok sayilir ---
    // Ad (metin) alanina "gt" (sayi operatoru) gonderiliyor -> parse_grid_filter_rules reddetmeli.
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$adId}&filter_cond_1=gt&filter_value_1=x", $cookie);
    check('Alan tipine uymayan operator -> kural yok sayilir, 5 kayit doner', row_count($resp['body']) === 5, 'bulunan: ' . row_count($resp['body']));

    // --- Filtre panel özeti ve sayfa saglamligi -----------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&filter_field_1={$renkId}&filter_cond_1=equals&filter_value_1=" . urlencode('Kirmizi'), $cookie);
    check('Filtre paneli ozet sayaci "Filtrele (1)" gosteriyor', strpos($resp['body'], 'Filtrele (1)') !== false);
} finally {
    $cleanup();
    echo "\nTemizlik tamam (test kullanicisi/base'i silindi).\n";
}

$total = count($results);
$passed = count(array_filter($results));
$failed = $total - $passed;

echo "\n==================================\n";
echo ($failed === 0) ? "SONUC: GECTI ({$passed}/{$total})\n" : "SONUC: KALDI ({$passed}/{$total} basarili)\n";
echo "==================================\n";

exit($failed === 0 ? 0 : 1);
