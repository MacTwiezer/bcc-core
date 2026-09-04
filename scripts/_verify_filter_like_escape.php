<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();
bcc_test_purge_own_audit();

define('PROBE_MAIL', 'likeesc.owner@bcc-test.local');
define('PROBE_TEAM', 'ZZ LIKE Kacis Testi');

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

echo "A) bcc_like_escape() sozlesmesi\n";

check('A) yuzde kacirilir', bcc_like_escape('50%off') === '50\\%off', bcc_like_escape('50%off'));
check('A) alt tire kacirilir', bcc_like_escape('a_b') === 'a\\_b', bcc_like_escape('a_b'));
check('A) ters boluk kacirilir', bcc_like_escape('a\\b') === 'a\\\\b', bcc_like_escape('a\\b'));
check('A) duz metin degismez', bcc_like_escape('duz metin') === 'duz metin');

check('A) kacis SIRASI dogru ("\\%" tek turda)', bcc_like_escape('\\%') === '\\\\\\%', bcc_like_escape('\\%'));

echo "\nB) filter_condition_sql ESCAPE cumlesini uretiyor\n";

foreach (array('contains', 'not_contains') as $op) {
    $r = filter_condition_sql('single_line_text', $op, '50%off', 'fv0', ':fval0');
    check('B) ' . $op . ' ESCAPE tasiyor', strpos($r['sql'], "ESCAPE '\\\\'") !== false, $r['sql']);
    check('B) ' . $op . ' baglanan deger kacirilmis',
        $r['params'][':fval0'] === '%50\\%off%', json_encode($r['params']));
}

$r = filter_condition_sql('single_line_text', 'equals', '50%off', 'fv0', ':fval0');
check('B) equals ham degeri kullaniyor (LIKE degil)',
    $r['params'][':fval0'] === '50%off' && strpos($r['sql'], 'LIKE') === false, json_encode($r));

foreach (array('single_line_text', 'long_text', 'single_select', 'url', 'email', 'phone') as $tip) {
    $r = filter_condition_sql($tip, 'contains', '%', 'fv0', ':fval0');
    check('B) ' . $tip . ' de kaciriyor', $r !== null && $r['params'][':fval0'] === '%\\%%',
        $r === null ? 'null' : json_encode($r['params']));
}

echo "\nC) CANLI SQL: dogruluk tablosu\n";

$vakalar = array(
    array('50 lira ve off',   '50%off', false),
    array('50%off',           '50%off', true),
    array('axb',              'a_b',    false),
    array('a_b',              'a_b',    true),
    array('herhangi bir sey', '%',      false),
    array('100% pamuk',       '100%',   true),
    array('C:\\yol\\dosya',   '\\yol',  true),
);
foreach ($vakalar as $v) {
    $f = filter_condition_sql('single_line_text', 'contains', $v[1], 'fv0', ':fval0');
    $sql = 'SELECT :metin ' . str_replace('fv0.value_text ', '', $f['sql']);
    $sonuc = (bool) bcc_fetch_column($sql, array_merge(array(':metin' => $v[0]), $f['params']));
    check('C) "' . $v[0] . '" ~ "' . $v[1] . '" => ' . ($v[2] ? 'eslesmeli' : 'ESLESMEMELI'),
        $sonuc === $v[2]);
}

echo "\nD) CANLI: gercek grid sayfasi dogru satiri gosteriyor\n";

$BASE = 'http://localhost';
$COOKIE = tempnam(sys_get_temp_dir(), 'bcclk');

function istek($url, $post = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $COOKIE,
        CURLOPT_COOKIEFILE => $COOKIE, CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $c, 'body' => (string) $b);
}

$r = istek($BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$cleanup = function () use ($COOKIE) {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => PROBE_TEAM)) as $x) {
        bcc_execute('DELETE FROM teams WHERE id = :i', array(':i' => $x['id']));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => PROBE_MAIL));
    @unlink($COOKIE);
};
$cleanup();
register_shutdown_function($cleanup);

$sifre = 'p' . bin2hex(random_bytes(10));
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => PROBE_TEAM));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array(':e' => PROBE_MAIL, ':h' => password_hash($sifre, PASSWORD_DEFAULT), ':n' => 'LIKE Kacis Owner'));
$userId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)',
    array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t,:n,:u)',
    array(':t' => $teamId, ':n' => 'LIKE Kacis Base', ':u' => $userId));
$baseId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b,:n,0)',
    array(':b' => $baseId, ':n' => 'Kampanyalar'));
$tableId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:ft,0)',
    array(':t' => $tableId, ':n' => 'Baslik', ':ft' => 'single_line_text'));
$fieldId = (int) bcc_last_insert_id();

$kayitlar = array('50%off', '50 lira ve off');
$recIds = array();
foreach ($kayitlar as $i => $metin) {
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t,:p,:u)',
        array(':t' => $tableId, ':p' => $i, ':u' => $userId));
    $rid = (int) bcc_last_insert_id();
    $recIds[$metin] = $rid;
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)',
        array(':r' => $rid, ':f' => $fieldId, ':v' => $metin));
}

$r = istek($BASE . '/login.php');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
istek($BASE . '/login.php', 'csrf_token=' . $m[1] . '&email=' . rawurlencode(PROBE_MAIL) . '&password=' . rawurlencode($sifre));

$url = $BASE . '/grid.php?' . http_build_query(array(
    'table_id' => $tableId,
    'filter_field_1' => $fieldId,
    'filter_cond_1' => 'contains',
    'filter_value_1' => '50%off',
));
$g = istek($url);
check('D) grid sayfasi geldi', $g['code'] === 200, 'HTTP ' . $g['code']);

$gorunen = array();
foreach ($recIds as $metin => $rid) {
    if (strpos($g['body'], 'data-record-id="' . $rid . '"') !== false) { $gorunen[] = $metin; }
}
check('D) "50%off" kaydi gorunuyor', in_array('50%off', $gorunen, true), implode(' | ', $gorunen));
check('D) "50 lira ve off" kaydi GORUNMUYOR',
    !in_array('50 lira ve off', $gorunen, true), implode(' | ', $gorunen));
check('D) toplam yalnizca 1 satir dondu', count($gorunen) === 1, implode(' | ', $gorunen));

$url2 = $BASE . '/grid.php?' . http_build_query(array(
    'table_id' => $tableId, 'filter_field_1' => $fieldId,
    'filter_cond_1' => 'contains', 'filter_value_1' => '%',
));
$g2 = istek($url2);
$gorunen2 = 0;
foreach ($recIds as $rid) {
    if (strpos($g2['body'], 'data-record-id="' . $rid . '"') !== false) { $gorunen2++; }
}
check('D) tek basina "%" filtresi 1 kayit getiriyor (hepsini degil)', $gorunen2 === 1, (string) $gorunen2);

echo "\nE) Excel disa aktarimi AYNI sorgu kurucusunu kullaniyor\n";

$fieldsById = array($fieldId => array('id' => $fieldId, 'field_type' => 'single_line_text', 'options' => null));
$kurallar = parse_grid_filter_rules(array(
    'filter_field_1' => $fieldId, 'filter_cond_1' => 'contains', 'filter_value_1' => '50%off',
), $fieldsById);
list($sql, $params) = bcc_build_grid_records_query($tableId, array(), array(), $kurallar, 'AND');
check('E) uretilen sorgu ESCAPE tasiyor', strpos($sql, "ESCAPE '\\\\'") !== false);
$satirlar = bcc_fetch_all($sql, $params);
check('E) sorgu yalnizca 1 kayit donduruyor', count($satirlar) === 1, count($satirlar) . ' kayit');
check('E) donen kayit dogru olan', !empty($satirlar) && (int) $satirlar[0]['id'] === $recIds['50%off']);

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
