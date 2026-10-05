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

$COOKIE = tempnam(sys_get_temp_dir(), 'bccifd');

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
$SIFRE = 'IfSayi!' . $SON;
$EMAIL = "ifsayi.owner.$SON@bcc-test.local";

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'IfSayi ' . $SON));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array('e' => $EMAIL, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => 'IfSayi Owner'));
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

/* Arayuz aramasi: rakam iceren sorgu basligin disindaki alanlarda da arar
   (2026-10-05, musteri: "search alaninda sayi ile arama yapilamiyor").
   Harf aramasi yalnizca baslikta kalir — o yari
   _verify_interface_search_primary_only.php'de. */

$baseId = (int) bcc_create_base($teamId, 'IfSayi Base ' . $SON, '', $ownerId)['id'];
bcc_execute("INSERT INTO tables_meta (base_id, name, position) VALUES (:b,'Tablo',0)", array('b' => $baseId));
$tableId = (int) bcc_last_insert_id();

$F = array();
foreach (array(
    'baslik' => array('Firma', 'single_line_text'),
    'not' => array('Notlar', 'long_text'),
    'tutar' => array('Tutar', 'number'),
    'tel' => array('Telefon', 'phone'),
    'sorumlu' => array('Sorumlu', 'user'),
) as $k => $v) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:ft,:p)',
        array('t' => $tableId, 'n' => $v[0], 'ft' => $v[1], 'p' => count($F)));
    $F[$k] = (int) bcc_last_insert_id();
}

/* baslik, not (HTML), tutar, telefon, sorumlu (kullanici id'si) */
$K = array();
foreach (array(
    'alkan' => array('ALKAN AKSESUAR', '<p>Satıcı no: <b>529417</b> ZEYNEP</p>', null, null, null),
    'kral' => array('KRALSPORT (SPC1140921)', '<p>Alkan ile karıştırılmasın.</p>', 7350, null, null),
    'tiens' => array('TİENS', '<p style="margin-left:52px">Belge yok.</p>', null, '0532 111 52 52', null),
    'kumtel' => array('KUMTEL', '<p>Not yok &#52;</p>', 100, null, 777452),
) as $k => $v) {
    bcc_execute('INSERT INTO records (table_id, position, slack_notified_at) VALUES (:t, :p, NOW())', array('t' => $tableId, 'p' => count($K)));
    $rid = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $F['baslik'], 'v' => $v[0]));
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $F['not'], 'v' => $v[1]));
    if ($v[2] !== null) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $F['tutar'], 'v' => $v[2]));
    }
    if ($v[3] !== null) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $F['tel'], 'v' => $v[3]));
    }
    if ($v[4] !== null) {
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $F['sorumlu'], 'v' => $v[4]));
    }
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

$bekle = function () use ($K) {
    $ids = array();
    foreach (func_get_args() as $k) { $ids[] = $K[$k]; }
    sort($ids);
    return $ids;
};

foreach (array('interface_records.php', 'interface_search.php') as $uc) {
    echo "\n$uc\n";
    check("$uc: '529417' (yalnizca notta) -> kayit bulunuyor",
        $ara($uc, '529417') === $bekle('alkan'), json_encode($ara($uc, '529417')));
    check("$uc: '5294' (notun ortasindan parca) -> bulunuyor",
        $ara($uc, '5294') === $bekle('alkan'), json_encode($ara($uc, '5294')));
    check("$uc: '1140' (baslikta) -> baslik eslesmesi calisiyor",
        $ara($uc, '1140') === $bekle('kral'), json_encode($ara($uc, '1140')));
    check("$uc: 'SPC1140921' (harf+rakam, baslikta) -> bulunuyor",
        $ara($uc, 'SPC1140921') === $bekle('kral'), json_encode($ara($uc, 'SPC1140921')));
    check("$uc: '7350' (sayi alaninda) -> bulunuyor",
        $ara($uc, '7350') === $bekle('kral'), json_encode($ara($uc, '7350')));
    check("$uc: '735' (sayinin parcasi) -> bulunuyor",
        $ara($uc, '735') === $bekle('kral'), json_encode($ara($uc, '735')));
    check("$uc: '0532' (telefon alaninda) -> bulunuyor",
        $ara($uc, '0532') === $bekle('tiens'), json_encode($ara($uc, '0532')));
    check("$uc: '52' -> not + telefon; HTML niteligindeki (52px) ve &#52; sayilmiyor",
        $ara($uc, '52') === $bekle('alkan', 'tiens'), json_encode($ara($uc, '52')));
    check("$uc: '000' -> sayinin ondalik sifirlari (100.000000) eslesmiyor",
        $ara($uc, '000') === array(), json_encode($ara($uc, '000')));
    check("$uc: '777452' (kullanici alanindaki id) -> eslesmiyor",
        $ara($uc, '777452') === array(), json_encode($ara($uc, '777452')));
    check("$uc: '99887' (hicbir yerde yok) -> sonuc yok",
        $ara($uc, '99887') === array(), json_encode($ara($uc, '99887')));
    check("$uc: 'Alkan' (harf) -> yalnizca baslik; notunda gecen KRALSPORT gelmiyor",
        $ara($uc, 'Alkan') === $bekle('alkan'), json_encode($ara($uc, 'Alkan')));
    check("$uc: 'ZEYNEP' (harf, yalnizca notta) -> sonuc yok",
        $ara($uc, 'ZEYNEP') === array(), json_encode($ara($uc, 'ZEYNEP')));
    check("$uc: '5%' -> % joker degil, sonuc yok",
        $ara($uc, '5%') === array(), json_encode($ara($uc, '5%')));
}

echo "\nSilinen kayit\n";
bcc_execute('UPDATE records SET deleted_at = NOW() WHERE id = :r', array('r' => $K['alkan']));
check("copteki kaydin notu aranmiyor ('529417' -> sonuc yok)",
    $ara('interface_records.php', '529417') === array(), json_encode($ara('interface_records.php', '529417')));

$cleanup();
$cleanup = function () {};
check('Kirlilik: sayaclar oncekiyle ayni', $sayim() === $once, json_encode(array_diff_assoc($sayim(), $once)));

echo "\nSonuc: $gecti gecti, $kaldi kaldi\n";
exit($kaldi > 0 ? 1 : 0);
