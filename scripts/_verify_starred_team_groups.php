<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

define('BASE_URL', 'http://localhost');
define('MULTI_EMAIL', 'stg.multi@bcc-test.local');
define('EMPTY_EMAIL', 'stg.empty@bcc-test.local');
define('TEST_PASS', 'StgGroup!2026');
define('TEAM_PREFIX', 'STG Test ');

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

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf_field($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function starred_panel($html)
{
    if (preg_match('#<div class="home-starred-list" id="home-starred-list">(.*?)\n\s*</div>\s*<a href="/workspaces\.php"#s', $html, $m)) {
        return $m[1];
    }
    return null;
}

function group_body($panel, $teamId)
{
    $re = '#<div class="home-starred-group" data-starred-team-id="' . (int) $teamId . '">(.*?)</div>\s*(?=<div class="home-starred-group"|$)#s';
    if (preg_match($re, $panel, $m)) { return $m[1]; }
    return null;
}

$wipe = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name LIKE :p', array(':p' => TEAM_PREFIX . '%')) as $r) {
        bcc_execute('DELETE FROM teams WHERE id = :id', array(':id' => $r['id']));
    }
    foreach (array(MULTI_EMAIL, EMPTY_EMAIL) as $mail) {
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $mail));
    }
};
$wipe();
register_shutdown_function($wipe);

try {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM_PREFIX . 'Alfa'));
    $teamAlfa = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => TEAM_PREFIX . 'Beta'));
    $teamBeta = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => MULTI_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'STG Multi'));
    $multiId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => EMPTY_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'STG Empty'));
    $emptyId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamAlfa, ':u' => $multiId, ':r' => 'owner'));
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamBeta, ':u' => $multiId, ':r' => 'editor'));
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array(':t' => $teamAlfa, ':u' => $emptyId, ':r' => 'owner'));

    $nameAlfa = 'STG Base Alfa';
    $nameBeta = 'STG Base Beta';
    $nameNoStar = 'STG Base Yildizsiz';
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamAlfa, ':n' => $nameAlfa, ':u' => $multiId));
    $baseAlfa = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamBeta, ':n' => $nameBeta, ':u' => $multiId));
    $baseBeta = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamAlfa, ':n' => $nameNoStar, ':u' => $multiId));
    $baseNoStar = (int) bcc_last_insert_id();

    foreach (array($baseAlfa, $baseBeta) as $bid) {
        bcc_execute('INSERT INTO user_starred_bases (user_id, base_id) VALUES (:u, :b)',
            array(':u' => $multiId, ':b' => $bid));
    }

    $multiCookie = login(MULTI_EMAIL);
    $emptyCookie = login(EMPTY_EMAIL);

    echo "\n--- A) Sol panel gruplama ---\n";
    $page = http_request('GET', '/dashboard.php', $multiCookie);
    check('A) dashboard.php 200', $page['status'] === 200, 'HTTP ' . $page['status']);

    $panel = starred_panel($page['body']);
    check('A) #home-starred-list hala var (home.js bu id yi ariyor)', $panel !== null);

    check('A) iki ekip grubu basildi',
        $panel !== null && substr_count($panel, 'home-starred-group') === 2,
        'adet: ' . ($panel === null ? 'panel yok' : substr_count($panel, 'home-starred-group')));

    $gAlfa = $panel === null ? null : group_body($panel, $teamAlfa);
    $gBeta = $panel === null ? null : group_body($panel, $teamBeta);
    check('A) Alfa ekibinin grubu var', $gAlfa !== null);
    check('A) Beta ekibinin grubu var', $gBeta !== null);

    check('A) Alfa grubunun basliginda EKIP ADI yaziyor',
        $gAlfa !== null && strpos($gAlfa, '<div class="home-starred-team" title="' . TEAM_PREFIX . 'Alfa">' . TEAM_PREFIX . 'Alfa</div>') !== false);
    check('A) Beta grubunun basliginda EKIP ADI yaziyor',
        $gBeta !== null && strpos($gBeta, '<div class="home-starred-team" title="' . TEAM_PREFIX . 'Beta">' . TEAM_PREFIX . 'Beta</div>') !== false);

    check('A) adsiz "Calisma alani #N" geri dusumu TETIKLENMEDI',
        $panel !== null && strpos($panel, 'Calisma alani #') === false && strpos($panel, 'Çalışma alanı #') === false);

    $posTeam = $panel === null ? false : strpos($panel, TEAM_PREFIX . 'Alfa');
    $posBase = $panel === null ? false : strpos($panel, $nameAlfa);
    check('A) ekip adi base adindan ONCE geliyor (baslik ustte)',
        $posTeam !== false && $posBase !== false && $posTeam < $posBase);

    echo "\n--- B) Base dogru grupta (sizinti yok) ---\n";
    check('B) Alfa base i Alfa grubunda', $gAlfa !== null && strpos($gAlfa, $nameAlfa) !== false);
    check('B) Alfa base i Beta grubunda DEGIL', $gBeta !== null && strpos($gBeta, $nameAlfa) === false);
    check('B) Beta base i Beta grubunda', $gBeta !== null && strpos($gBeta, $nameBeta) !== false);
    check('B) Beta base i Alfa grubunda DEGIL', $gAlfa !== null && strpos($gAlfa, $nameBeta) === false);
    check('B) YILDIZSIZ base panelde HIC yok',
        $panel !== null && strpos($panel, $nameNoStar) === false);
    check('B) toplam iki yildiz ogesi (fazlalik yok)',
        $panel !== null && substr_count($panel, 'home-starred-item"') === 2,
        'adet: ' . ($panel === null ? 'panel yok' : substr_count($panel, 'home-starred-item"')));

    echo "\n--- C) starred.php kendi sorgusuyla ayni paneli besliyor ---\n";
    $sp = http_request('GET', '/starred.php', $multiCookie);
    check('C) starred.php 200', $sp['status'] === 200, 'HTTP ' . $sp['status']);
    $spPanel = starred_panel($sp['body']);
    check('C) starred.php de panel gruplu',
        $spPanel !== null && substr_count($spPanel, 'home-starred-group') === 2,
        'adet: ' . ($spPanel === null ? 'panel yok' : substr_count($spPanel, 'home-starred-group')));
    check('C) starred.php panelinde EKIP ADLARI dolu (team_name JOIN i calisiyor)',
        $spPanel !== null
        && strpos($spPanel, TEAM_PREFIX . 'Alfa') !== false
        && strpos($spPanel, TEAM_PREFIX . 'Beta') !== false);
    check('C) starred.php de adsiz geri dusum YOK',
        $spPanel !== null && strpos($spPanel, 'alani #') === false && strpos($spPanel, 'alanı #') === false);

    echo "\n--- D) Yildizsiz kullanici: bos baslik yok ---\n";
    $ep = http_request('GET', '/dashboard.php', $emptyCookie);
    check('D) dashboard.php 200', $ep['status'] === 200, 'HTTP ' . $ep['status']);
    $epPanel = starred_panel($ep['body']);
    check('D) panel var ama HIC grup yok',
        $epPanel !== null && strpos($epPanel, 'home-starred-group') === false);
    check('D) hic yildiz ogesi yok',
        $epPanel !== null && strpos($epPanel, 'home-starred-item') === false);

    echo "\n--- E) Gruplama fonksiyonu (birim) ---\n";
    $g = bcc_group_starred_bases_by_team(array());
    check('E) bos girdi -> bos cikti', $g === array());

    $g = bcc_group_starred_bases_by_team(array(
        array('id' => 1, 'name' => 'B1', 'team_id' => 9, 'team_name' => 'Zeta'),
        array('id' => 2, 'name' => 'B2', 'team_id' => 3, 'team_name' => 'Alfa'),
        array('id' => 3, 'name' => 'B3', 'team_id' => 9, 'team_name' => 'Zeta'),
    ));
    check('E) iki gruba ayrildi', count($g) === 2, 'adet: ' . count($g));
    check('E) gruplar EKIP ADINA gore sirali (Alfa < Zeta)',
        isset($g[0]['team_name']) && $g[0]['team_name'] === 'Alfa' && $g[1]['team_name'] === 'Zeta',
        isset($g[0]['team_name']) ? ($g[0]['team_name'] . ' / ' . $g[1]['team_name']) : 'yapi bozuk');
    check('E) ayni ekibin iki base i TEK grupta', count($g[1]['bases']) === 2, 'adet: ' . count($g[1]['bases']));
    check('E) grup icinde base sirasi KORUNDU (sorgunun ORDER BY i)',
        $g[1]['bases'][0]['name'] === 'B1' && $g[1]['bases'][1]['name'] === 'B3');

    $g = bcc_group_starred_bases_by_team(array(array('id' => 7, 'name' => 'Bx', 'team_id' => 42)));
    check('E) team_name yoksa satir DUSURULMUYOR', count($g) === 1 && count($g[0]['bases']) === 1);
    check('E) team_name yoksa team_id ile etiketleniyor',
        isset($g[0]['team_name']) && strpos($g[0]['team_name'], '42') !== false,
        isset($g[0]['team_name']) ? $g[0]['team_name'] : 'yok');

    echo "\n--- F) home.js grup mantigi ---\n";
    $js = file_get_contents(__DIR__ . '/../public/assets/home.js');
    check('F) grubu data-team-id ile ariyor',
        strpos($js, '.home-starred-group[data-starred-team-id="') !== false);
    check('F) grup yoksa kuruyor (ilk yildiz)',
        strpos($js, "group.className = 'home-starred-group'") !== false);
    check('F) grup basligini .home-base-workspace ten okuyor (ikinci data-* YOK)',
        strpos($js, ".home-base-workspace") !== false
        && strpos($js, 'data-team-name') === false);
    check('F) oge duz listeye DEGIL gruba ekleniyor',
        strpos($js, '(group || starredList).appendChild(item)') !== false);
    check('F) son yildiz silinince grup da kaldiriliyor',
        strpos($js, "oldGroup.querySelector('.home-starred-item')") !== false
        && strpos($js, 'oldGroup.remove()') !== false);
    check('F) eski kosulsuz "starredList.appendChild(item)" satiri KALMADI',
        !preg_match('#\n\s*starredList\.appendChild\(item\);#', $js));

    echo "\n--- G) Cevrimici rozeti cercevesiz ---\n";
    $css = file_get_contents(__DIR__ . '/../public/assets/home.css');
    $badge = null;
    if (preg_match('#\.home-online-badge\s*\{(.*?)\}#s', $css, $m)) { $badge = $m[1]; }
    check('G) .home-online-badge kurali duruyor', $badge !== null);
    check('G) border KALKTI', $badge !== null && strpos($badge, 'border') === false,
        $badge !== null ? trim(preg_replace('/\s+/', ' ', $badge)) : '');
    check('G) hap zemini (background) KALKTI', $badge !== null && strpos($badge, 'background') === false);
    check('G) rozet HALA basiliyor (silinmedi, yalnizca cercevesi kalkti)',
        strpos($page['body'], 'home-online-badge') !== false);
    check('G) nokta + sayi duruyor',
        strpos($page['body'], 'home-online-dot') !== false
        && strpos($page['body'], 'home-online-count') !== false);

    echo "\n--- H) Kart data-team-id ---\n";
    check('H) dashboard kartinda data-team-id var',
        preg_match('#data-base-id="' . $baseAlfa . '" data-team-id="' . $teamAlfa . '"#', $page['body']) === 1);
    check('H) Beta karti KENDI ekibinin id sini tasiyor',
        preg_match('#data-base-id="' . $baseBeta . '" data-team-id="' . $teamBeta . '"#', $page['body']) === 1);
    check('H) .home-base-workspace hucresi dolu (JS grup adini buradan okuyor)',
        strpos($page['body'], '<div class="home-base-workspace">' . TEAM_PREFIX . 'Alfa</div>') !== false);
} catch (Throwable $e) {
    echo "\n[HATA] " . $e->getMessage() . "\n";
    $results[] = false;
}

$wipe();

$leftTeams = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE name LIKE :p', array(':p' => TEAM_PREFIX . '%'));
$leftUsers = (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE email IN (:a, :b)',
    array(':a' => MULTI_EMAIL, ':b' => EMPTY_EMAIL));
echo "\n--- Temizlik ---\n";
check('Z) test ekipleri silindi', $leftTeams === 0, 'kalan: ' . $leftTeams);
check('Z) test kullanicilari silindi', $leftUsers === 0, 'kalan: ' . $leftUsers);

$passed = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($passed === $total ? 'GECTI' : 'KALDI') . " ($passed/$total)\n";
echo "==================================\n";
exit($passed === $total ? 0 : 1);
