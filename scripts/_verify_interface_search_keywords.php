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

$COOKIE = tempnam(sys_get_temp_dir(), 'bccifk');

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
$SIFRE = 'IfKelime!' . $SON;
$EMAIL = "ifkelime.owner.$SON@bcc-test.local";

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'IfKelime ' . $SON));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array('e' => $EMAIL, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => 'IfKelime Owner'));
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

/* Arayuz aramasi: harf aramasi basligin yaninda TUM metin alanlarinda arar
   (uzun metin/not, tekli/coklu secim, tek satir metin, e-posta...) —
   2026-10-06, musteri: "anahtar kelimeleri aramaya calistigimizda
   cikmamaktadir" (Kategori alani, canlida uzun metin). 2026-09-15'teki
   "yalnizca baslik" karari bilerek geri alindi; onu sinayan
   _verify_interface_search_primary_only.php kaldirildi. Uzun metinde yalnizca
   GORUNEN metin eslesir. Rakam aramasi _verify_interface_search_digits.php'de. */

$baseId = (int) bcc_create_base($teamId, 'IfKelime Base ' . $SON, '', $ownerId)['id'];
bcc_execute("INSERT INTO tables_meta (base_id, name, position) VALUES (:b,'Tablo',0)", array('b' => $baseId));
$tableId = (int) bcc_last_insert_id();

$secenekler = json_encode(array('choices' => array('Asistans Hizmetleri', 'Güzellik ve Sağlık', 'Yaşam', 'Ev/Bahçe')), JSON_UNESCAPED_UNICODE);

$F = array();
foreach (array(
    'baslik' => array('Firma', 'single_line_text', null),
    'not' => array('Notlar', 'long_text', null),
    'kategori' => array('Kategori', 'single_select', $secenekler),
    'etiket' => array('Etiket', 'multiple_select', $secenekler),
    'marka' => array('Marka', 'single_line_text', null),
    'eposta' => array('E-posta', 'email', null),
) as $k => $v) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t,:n,:ft,:o,:p)',
        array('t' => $tableId, 'n' => $v[0], 'ft' => $v[1], 'o' => $v[2], 'p' => count($F)));
    $F[$k] = (int) bcc_last_insert_id();
}

/* baslik, not (HTML), kategori, etiket (dizi), marka, e-posta */
$K = array();
foreach (array(
    'alkan' => array('ALKAN AKSESUAR', '<p>Asistans konusu görüşüldü.</p>', 'Güzellik ve Sağlık', array('Yaşam', 'Ev/Bahçe'), 'Vestel', null),
    'kral' => array('KRALSPORT', '<p>Yaşam notu.</p>', 'Asistans Hizmetleri', null, null, null),
    'tiens' => array('TİENS', '<p>Vestel ile ilgisi yok.</p>', null, array('Asistans Hizmetleri'), 'Arçelik', 'destek@ornek-firma.com'),
    /* Musterinin Kategori alani gibi: satir satir anahtar kelime, & varlik olarak. */
    'kumtel' => array('KUMTEL', "<p class=\"vurgu\" style=\"margin-left:4px\">Tekne &amp; Yat Ekipmanları
Oda Kokusu
DİKİŞ Makinesi</p>", null, null, null, null),
) as $k => $v) {
    bcc_execute('INSERT INTO records (table_id, position, slack_notified_at) VALUES (:t, :p, NOW())', array('t' => $tableId, 'p' => count($K)));
    $rid = (int) bcc_last_insert_id();
    foreach (array('baslik' => $v[0], 'not' => $v[1], 'kategori' => $v[2], 'marka' => $v[4], 'eposta' => $v[5]) as $alan => $deger) {
        if ($deger !== null) {
            bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)', array('r' => $rid, 'f' => $F[$alan], 'v' => $deger));
        }
    }
    if ($v[3] !== null) {
        /* Uygulamanin yazdigi bicimle ayni: "/" karakteri "\/" olarak saklanir. */
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_json) VALUES (:r,:f,:v)',
            array('r' => $rid, 'f' => $F['etiket'], 'v' => json_encode($v[3], JSON_UNESCAPED_UNICODE)));
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
    check("$uc: 'Asistans Hizmetleri' -> tekli + coklu secimde bulunuyor; notunda 'Asistans' gecen ALKAN gelmiyor",
        $ara($uc, 'Asistans Hizmetleri') === $bekle('kral', 'tiens'), json_encode($ara($uc, 'Asistans Hizmetleri')));
    check("$uc: 'asistans' (kucuk harf) -> tekli + coklu secim + not",
        $ara($uc, 'asistans') === $bekle('alkan', 'kral', 'tiens'), json_encode($ara($uc, 'asistans')));
    check("$uc: 'Hizmet' (parca) -> bulunuyor",
        $ara($uc, 'Hizmet') === $bekle('kral', 'tiens'), json_encode($ara($uc, 'Hizmet')));
    check("$uc: 'Güzellik' (tekli secim) -> bulunuyor",
        $ara($uc, 'Güzellik') === $bekle('alkan'), json_encode($ara($uc, 'Güzellik')));
    check("$uc: 'Yaşam' -> coklu secim (ALKAN) + not (KRALSPORT)",
        $ara($uc, 'Yaşam') === $bekle('alkan', 'kral'), json_encode($ara($uc, 'Yaşam')));
    check("$uc: 'Ev/Bahçe' (coklu secim, egik cizgi) -> bulunuyor",
        $ara($uc, 'Ev/Bahçe') === $bekle('alkan'), json_encode($ara($uc, 'Ev/Bahçe')));
    check("$uc: 'Vestel' -> tek satir metin (ALKAN) + not (TİENS)",
        $ara($uc, 'Vestel') === $bekle('alkan', 'tiens'), json_encode($ara($uc, 'Vestel')));
    check("$uc: 'ornek-firma' (e-posta alani) -> bulunuyor",
        $ara($uc, 'ornek-firma') === $bekle('tiens'), json_encode($ara($uc, 'ornek-firma')));
    check("$uc: 'KUMTEL' (baslik) -> baslik eslesmesi calisiyor",
        $ara($uc, 'KUMTEL') === $bekle('kumtel'), json_encode($ara($uc, 'KUMTEL')));
    check("$uc: 'konusu' (yalnizca notta) -> bulunuyor",
        $ara($uc, 'konusu') === $bekle('alkan'), json_encode($ara($uc, 'konusu')));
    check("$uc: 'Oda Kokusu' (uzun metinde bir satir) -> bulunuyor",
        $ara($uc, 'Oda Kokusu') === $bekle('kumtel'), json_encode($ara($uc, 'Oda Kokusu')));
    check("$uc: 'oda kok' (kucuk harf, parca) -> bulunuyor",
        $ara($uc, 'oda kok') === $bekle('kumtel'), json_encode($ara($uc, 'oda kok')));
    check("$uc: 'Tekne & Yat' (& varlik olarak sakli) -> bulunuyor",
        $ara($uc, 'Tekne & Yat') === $bekle('kumtel'), json_encode($ara($uc, 'Tekne & Yat')));
    check("$uc: 'dikiş' (metinde DİKİŞ) -> bulunuyor",
        $ara($uc, 'dikiş') === $bekle('kumtel'), json_encode($ara($uc, 'dikiş')));
    check("$uc: 'vurgu' (yalnizca HTML niteliginde) -> sonuc yok",
        $ara($uc, 'vurgu') === array(), json_encode($ara($uc, 'vurgu')));
    check("$uc: 'amp' (yalnizca &amp; varliginin icinde) -> sonuc yok",
        $ara($uc, 'amp') === array(), json_encode($ara($uc, 'amp')));
    check("$uc: 'Elektronik' (hicbir yerde yok) -> sonuc yok",
        $ara($uc, 'Elektronik') === array(), json_encode($ara($uc, 'Elektronik')));
    check("$uc: 'A%s' -> % joker degil, sonuc yok",
        $ara($uc, 'A%s') === array(), json_encode($ara($uc, 'A%s')));
}

echo "\nSilinen kayit\n";
bcc_execute('UPDATE records SET deleted_at = NOW() WHERE id = :r', array('r' => $K['kral']));
check("copteki kaydin kategorisi aranmiyor ('Asistans Hizmetleri' -> yalnizca TİENS)",
    $ara('interface_records.php', 'Asistans Hizmetleri') === $bekle('tiens'), json_encode($ara('interface_records.php', 'Asistans Hizmetleri')));

$cleanup();
$cleanup = function () {};
check('Kirlilik: sayaclar oncekiyle ayni', $sayim() === $once, json_encode(array_diff_assoc($sayim(), $once)));

echo "\nSonuc: $gecti gecti, $kaldi kaldi\n";
exit($kaldi > 0 ? 1 : 0);
