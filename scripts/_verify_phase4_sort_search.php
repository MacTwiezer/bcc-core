<?php
// Faz 4 (Arama + Sıralama) doğrulaması. curl KULLANILMAZ — PHP'nin kendi
// http:// stream sarmalayıcısı ile gerçek grid.php uçnoktasına, gerçek bir
// oturum çerezi ile istek atılır. Kendi test kullanıcısını/verisini kurar,
// doğrular, sonunda temizler (test_isolation.php ile aynı desen).
//
// Ön koşul: Apache ayakta olmalı (DocumentRoot = public, localhost:80).
// Çalıştırma: C:\php73\php.exe scripts\_verify_phase4_sort_search.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'faz4.test.editor@bcc-test.local');
define('TEST_PASS', 'Faz4Test!2026');

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
    $status = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $parts = explode(';', substr($h, 11));
                $newCookie = trim($parts[0]);
            }
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
    }

    return array('body' => $body, 'cookie' => $newCookie, 'status' => $status);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

// Bir alan id'sine ait hücrelerin DOM sırasını (data-value) döndürür.
function extract_field_values_in_order($html, $fieldId)
{
    $pattern = '/data-field-id="' . preg_quote((string) $fieldId, '/') . '"[^>]*data-value="([^"]*)"/';
    preg_match_all($pattern, $html, $m);
    return isset($m[1]) ? $m[1] : array();
}

bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => TEST_EMAIL)
    ), 'id');
    foreach ($baseIds as $baseId) {
        bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $baseId));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));
};

try {
    $team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$team) {
        echo "HATA: TY ekibi bulunamadi.\n";
        exit(1);
    }
    $teamId = (int) $team['id'];

    $hash = password_hash(TEST_PASS, PASSWORD_DEFAULT);
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :name, 0, 1)', array(':email' => TEST_EMAIL, ':hash' => $hash, ':name' => 'Faz4 Test Editor'));
    $userId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:tid, :uid, :role)', array(':tid' => $teamId, ':uid' => $userId, ':role' => 'editor'));

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:tid, :name, :uid)', array(':tid' => $teamId, ':name' => 'Faz4 Sort Test', ':uid' => $userId));
    $baseId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:bid, :name, 0)', array(':bid' => $baseId, ':name' => 'Sort Test'));
    $tableId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:tid, :name, :type, 0)', array(':tid' => $tableId, ':name' => 'Deger', ':type' => 'number'));
    $fieldId = (int) bcc_last_insert_id();

    // Kasıtlı olarak sırasız (30, 10, 20) — sırala olmadan ekleme sırası, sıralayla sayısal sıra beklenir.
    $values = array(30, 10, 20);
    foreach ($values as $i => $v) {
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, :uid)', array(':tid' => $tableId, ':pos' => $i, ':uid' => $userId));
        $recordId = (int) bcc_last_insert_id();

        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:rid, :fid, :val)', array(':rid' => $recordId, ':fid' => $fieldId, ':val' => $v));
    }

    echo "Kurulum tamam: table_id={$tableId}, field_id={$fieldId}\n\n";

    // --- Oturum aç -------------------------------------------------------
    $resp = http_request('GET', '/login.php');
    $csrf = extract_csrf($resp['body']);
    $cookie = $resp['cookie'];

    $resp = http_request('POST', '/login.php', $cookie, array(
        'email' => TEST_EMAIL,
        'password' => TEST_PASS,
        'csrf_token' => $csrf,
    ));
    if ($resp['cookie']) {
        $cookie = $resp['cookie'];
    }

    check('Giris yapildi (login sonrasi oturum cerezi alindi)', $cookie !== null, 'cookie=' . var_export($cookie, true));

    // --- Sıralamasız (varsayılan: ekleme sırası) -------------------------
    $resp = http_request('GET', '/grid.php?table_id=' . $tableId, $cookie);
    $order = extract_field_values_in_order($resp['body'], $fieldId);
    check('Sirasiz grid ekleme sirasini gosteriyor (30,10,20)', $order === array('30', '10', '20'), 'bulunan: ' . implode(',', $order));

    // --- Artan sıralama ----------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&sort_field_1={$fieldId}&sort_dir_1=asc", $cookie);
    $order = extract_field_values_in_order($resp['body'], $fieldId);
    check('Artan siralama dogru (10,20,30)', $order === array('10', '20', '30'), 'bulunan: ' . implode(',', $order));
    check('Aktif siralama sayaci "Sort (1)" gosteriyor', strpos($resp['body'], 'Sort (1)') !== false);

    // --- Azalan sıralama ----------------------------------------------------
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&sort_field_1={$fieldId}&sort_dir_1=desc", $cookie);
    $order = extract_field_values_in_order($resp['body'], $fieldId);
    check('Azalan siralama dogru (30,20,10)', $order === array('30', '20', '10'), 'bulunan: ' . implode(',', $order));

    // --- Gecersiz/yabanci alan id'si sessizce yok sayilmali (baska tabloya ait olabilir) ---
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&sort_field_1=999999&sort_dir_1=asc", $cookie);
    check('Gecersiz alan id sayfayi kirmiyor (200 dönüyor)', $resp['status'] === 200 && strpos($resp['body'], 'class="grid') !== false);

    // --- Arama kutusu ve sayaç DOM'da mevcut (client-side JS elle test edilir) ---
    check('Arama input alani sayfada mevcut', strpos($resp['body'], 'id="grid-search"') !== false);
    check('grid-toolbar.js dahil edilmis', strpos($resp['body'], '/assets/grid-toolbar.js') !== false);
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
