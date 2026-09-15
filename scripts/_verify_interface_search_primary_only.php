<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

$BASE = 'http://localhost';
$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$COOKIE = tempnam(sys_get_temp_dir(), 'bccifs');

function istek($url, $post = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $COOKIE, CURLOPT_COOKIEFILE => $COOKIE,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) { return null; }
    return array('code' => $code, 'body' => substr($raw, $hlen));
}

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$sayim = function () {
    $o = array();
    foreach (array('users', 'teams', 'team_members', 'bases', 'tables_meta', 'fields', 'records', 'cell_values') as $t) {
        $o[$t] = (int) bcc_fetch_column('SELECT COUNT(*) FROM ' . $t);
    }
    return $o;
};
$once = $sayim();

$SON = bin2hex(random_bytes(4));
$SIFRE = 'IfAra!' . $SON;
$EMAIL = "ifara.owner.$SON@bcc-test.local";

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'IfAra ' . $SON));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array('e' => $EMAIL, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => 'IfAra Owner'));
$ownerId = (int) bcc_last_insert_id();
bcc_execute("INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,'owner')", array('t' => $teamId, 'u' => $ownerId));

$baseId = 0;
$cleanup = function () use ($teamId, $ownerId, $EMAIL, $COOKIE, &$baseId) {
    if ($baseId) {
        bcc_execute('DELETE FROM bases WHERE id = :b', array('b' => $baseId));
    }
    bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM audit_log WHERE user_id = :u', array('u' => $ownerId));
    bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $EMAIL));
    bcc_execute('DELETE FROM users WHERE id = :u', array('u' => $ownerId));
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

$baseId = (int) bcc_create_base($teamId, 'IfAra Base ' . $SON, '', $ownerId)['id'];
bcc_execute("INSERT INTO tables_meta (base_id, name, position) VALUES (:b,'Tablo',0)", array('b' => $baseId));
$tableId = (int) bcc_last_insert_id();
bcc_execute("INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,'Firma','single_line_text',0)", array('t' => $tableId));
$fBaslik = (int) bcc_last_insert_id();
bcc_execute("INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,'Notlar','long_text',1)", array('t' => $tableId));
$fNot = (int) bcc_last_insert_id();

$K = array();
foreach (array(
    'alkan' => array('ALKAN AKSESUAR FİRMASI', 'Faturalar kabul edilmeyecek.'),
    'kral' => array('KRALSPORT FİRMASI', 'Alkan ile karıştırılmasın; fatura kontrolü.'),
    'tiens' => array('TİENS', 'Aksesuar belgeleri yok.'),
) as $k => $v) {
    bcc_execute('INSERT INTO records (table_id, position, slack_notified_at) VALUES (:t, :p, NOW())', array('t' => $tableId, 'p' => count($K)));
    $rid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $fBaslik, 'v' => $v[0]));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $fNot, 'v' => $v[1]));
    $K[$k] = $rid;
}

$lp = istek($BASE . '/login.php');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $lp['body'], $m);
$g = istek($BASE . '/login.php', 'csrf_token=' . (isset($m[1]) ? $m[1] : '') . '&email=' . rawurlencode($EMAIL) . '&password=' . rawurlencode($SIFRE));
check('giris yapildi (302)', $g && $g['code'] === 302, $g ? $g['code'] : 'yok');

$ara = function ($uc, $q) use ($BASE, $tableId) {
    $r = istek($BASE . '/api/' . $uc . '?table_id=' . $tableId . '&q=' . rawurlencode($q));
    $j = $r ? json_decode($r['body'], true) : null;
    if (!is_array($j)) {
        return null;
    }
    if (isset($j['record_ids'])) {
        $ids = array_map('intval', $j['record_ids']);
    } else {
        $ids = array();
        foreach (isset($j['items']) ? $j['items'] : array() as $it) {
            if ($it['t'] === 'r') { $ids[] = (int) $it['id']; }
        }
    }
    sort($ids);
    return $ids;
};

foreach (array('interface_records.php', 'interface_search.php') as $uc) {
    echo "\n$uc\n";
    check("$uc: 'ALKAN' -> yalnizca basliginda gecen kayit (notunda 'Alkan' gecen KRALSPORT gelmiyor)",
        $ara($uc, 'ALKAN') === array($K['alkan']), json_encode($ara($uc, 'ALKAN')));
    check("$uc: 'Faturalar' (yalnizca notta) -> sonuc yok",
        $ara($uc, 'Faturalar') === array(), json_encode($ara($uc, 'Faturalar')));
    check("$uc: 'fatura' (yalnizca notlarda) -> sonuc yok",
        $ara($uc, 'fatura') === array(), json_encode($ara($uc, 'fatura')));
    check("$uc: 'FİRMASI' -> iki basliktaki kayit",
        $ara($uc, 'FİRMASI') === array_values(array_filter(array($K['alkan'], $K['kral']))), json_encode($ara($uc, 'FİRMASI')));
    check("$uc: basligin ortasindan parca ('AKSESUAR') -> baslik esleşiyor, notunda 'Aksesuar' gecen TİENS gelmiyor",
        $ara($uc, 'AKSESUAR') === array($K['alkan']), json_encode($ara($uc, 'AKSESUAR')));
}

echo "\nKaynak\n";
$src = (string) file_get_contents(__DIR__ . '/../src/schema.php');
check('bcc_interface_fetch_records artik ozet alani parametresi almiyor',
    strpos($src, 'function bcc_interface_fetch_records($tableId, $primaryFieldId, $searchTerm = null)') !== false);
check('arama tek alan: cv.field_id = ? (IN listesi yok)',
    preg_match('/function bcc_interface_fetch_records.*?cv\.field_id = \?.*?\n\}/s', $src) === 1
    && preg_match('/function bcc_interface_fetch_records.*?cv\.field_id IN.*?\n\}/s', $src) === 0);

$cleanup();
$cleanup = function () {};
check('Kirlilik: sayaclar oncekiyle ayni', $sayim() === $once, json_encode(array_diff_assoc($sayim(), $once)));

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
