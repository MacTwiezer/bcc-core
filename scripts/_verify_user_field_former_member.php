<?php

/*
 * "Kullanici" alan tipinde ekipten cikarilmis / pasiflestirilmis uyenin adi —
 * 2026-09-14 (kullanici karari: "adini goster").
 *
 * Eskiden hucre bos kaliyordu: cell_display_text() adi yalnizca ekibin AKTIF
 * uyelerinden ariyordu. "Olusturan" sutununa 2d8dd65'te eklenen yedek
 * (bcc_actor_name_by_id) artik kullanici alaninda da var.
 *
 * Dogrulananlar (gercek HTTP, Apache):
 *  - ekipten cikarilan uyenin adi grid'de, kanban/arayuzun kullandigi ortak
 *    fonksiyonda ve Excel disa aktarmada gorunuyor;
 *  - pasiflestirilen uyenin adi da gorunuyor;
 *  - YAZMA tarafi degismedi: ekipte olmayan biri alana yeniden SECILEMEZ (422);
 *  - baska ekipten biri de secilemez (KVKK: ad yedegi ekip disi bir kimlige
 *    hic ulasamaz, cunku o kimlik tabloya yazilamaz);
 *  - cikarilan uyenin fotografi bakan kisiye gosterilmez, bas harf kalir.
 *
 * Kendi verisini kurar, sonunda hepsini siler ve sayilari dogrular.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$BASE = 'http://localhost';
$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

function istek($jar, $url, $post = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return $raw === false ? array('code' => 0, 'body' => '') : array('code' => $code, 'body' => substr($raw, $hlen));
}

function jeton($jar, $url)
{
    global $BASE;
    $r = istek($jar, $BASE . $url);
    if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m)) {
        return $m[1];
    }
    return preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
}

$r = istek(tempnam(sys_get_temp_dir(), 'bccuf'), $BASE . '/login.php');
if ($r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$sayim = function () {
    return array(
        'users' => (int) bcc_fetch_column('SELECT COUNT(*) FROM users'),
        'teams' => (int) bcc_fetch_column('SELECT COUNT(*) FROM teams'),
        'bases' => (int) bcc_fetch_column('SELECT COUNT(*) FROM bases'),
        'records' => (int) bcc_fetch_column('SELECT COUNT(*) FROM records'),
        'team_members' => (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members'),
    );
};
$once = $sayim();

$SON = bin2hex(random_bytes(4));
$SIFRE = 'Uye!' . $SON;
$K = array();
foreach (array('A' => 'Ayla Sahip', 'B' => 'Bora Ayrilan', 'P' => 'Pelin Pasif', 'C' => 'Cenk Baskaekip') as $k => $ad) {
    $email = 'ufield.' . strtolower($k) . ".$SON@bcc-test.local";
    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array('e' => $email, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $ad));
    $K[$k] = array('id' => (int) bcc_last_insert_id(), 'email' => $email, 'ad' => $ad, 'jar' => tempnam(sys_get_temp_dir(), 'bccuf'));
}

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "UFIELD-1-$SON"));
$T1 = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => "UFIELD-2-$SON"));
$T2 = (int) bcc_last_insert_id();
foreach (array(array($T1, 'A', 'owner'), array($T1, 'B', 'editor'), array($T1, 'P', 'editor'), array($T2, 'C', 'owner')) as $uy) {
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array('t' => $uy[0], 'u' => $K[$uy[1]]['id'], 'r' => $uy[2]));
}

bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array('t' => $T1, 'n' => "UF Base $SON", 'u' => $K['A']['id']));
$B1 = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array('b' => $B1, 'n' => 'Gorevler'));
$TB = (int) bcc_last_insert_id();
bcc_execute("INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, 'Gorev', 'single_line_text', 0)", array('t' => $TB));
bcc_execute("INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, 'Sorumlu', 'user', 1)", array('t' => $TB));
$FU = (int) bcc_last_insert_id();
$R = array();
foreach (array(0, 1, 2) as $i) {
    bcc_execute('INSERT INTO records (table_id, position, created_by, slack_notified_at) VALUES (:t, :p, :u, NOW())', array('t' => $TB, 'p' => $i, 'u' => $K['A']['id']));
    $R[] = (int) bcc_last_insert_id();
}

$cleanup = function () use (&$K, $T1, $T2, $B1) {
    bcc_execute('DELETE FROM bases WHERE id = :id', array('id' => $B1));
    foreach ($K as $k) {
        bcc_execute('DELETE FROM audit_log WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM login_attempts WHERE email = :e', array('e' => $k['email']));
        bcc_execute('DELETE FROM team_members WHERE user_id = :id', array('id' => $k['id']));
        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $k['id']));
        @unlink($k['jar']);
    }
    bcc_execute('DELETE FROM audit_log WHERE team_id IN (' . (int) $T1 . ',' . (int) $T2 . ')');
    bcc_execute('DELETE FROM teams WHERE id IN (' . (int) $T1 . ',' . (int) $T2 . ')');
};
register_shutdown_function($cleanup);

$A = $K['A'];
$tok = jeton($A['jar'], '/login.php');
$r = istek($A['jar'], $BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode($A['email']) . '&password=' . rawurlencode($SIFRE));
check('A giris yapti (302)', $r['code'] === 302, $r['code']);
$csrf = jeton($A['jar'], '/grid.php?table_id=' . $TB);

$ata = function ($recId, $userId) use ($A, $BASE, $csrf, $FU) {
    $r = istek($A['jar'], $BASE . '/api/cell_update.php', 'csrf_token=' . $csrf . '&record_id=' . $recId . '&field_id=' . $FU . '&value=' . $userId);
    $r['json'] = json_decode($r['body'], true);
    return $r;
};
$hucre = function ($recId) use ($A, $BASE, $TB, $FU) {
    $r = istek($A['jar'], $BASE . '/grid.php?table_id=' . $TB);
    if (!preg_match('/<tr[^>]*data-record-id="' . (int) $recId . '"[^>]*>(.*?)<\/tr>/s', $r['body'], $m)) {
        return null;
    }
    if (!preg_match('/<td[^>]*data-field-id="' . (int) $FU . '"[^>]*>(.*?)<\/td>/s', $m[1], $t)) {
        return null;
    }
    return $t[1];
};
$adi = function ($td) {
    return ($td !== null && preg_match('/class="cell-user-name">([^<]*)</', $td, $m)) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
};

echo "\nA) Uyeler hala ekipteyken atama (hazirlik)\n";
check('R1 <- B (ekip uyesi) 200', $ata($R[0], $K['B']['id'])['code'] === 200);
check('R2 <- P (ekip uyesi) 200', $ata($R[1], $K['P']['id'])['code'] === 200);
check('R1 hucresi "Bora Ayrilan"', $adi($hucre($R[0])) === 'Bora Ayrilan', $adi($hucre($R[0])));

echo "\nB) B ekipten CIKARILDI\n";
bcc_execute('DELETE FROM team_members WHERE team_id = :t AND user_id = :u', array('t' => $T1, 'u' => $K['B']['id']));
$td = $hucre($R[0]);
check('R1 hucresi BOS DEGIL, ad gorunuyor: "Bora Ayrilan"', $adi($td) === 'Bora Ayrilan', var_export($adi($td), true));
check('cikarilan uyenin FOTOGRAFI yok, bas harf "B"', $td !== null && strpos($td, 'bcc-avatar-img') === false && (bool) preg_match('/cell-user-avatar"[^>]*>B</', $td));

$fonk = cell_display_text('user', array('value_text' => null, 'value_number' => $K['B']['id'], 'value_date' => null, 'value_json' => null), bcc_team_users_by_id($T1), null);
check('kanban / arayuz / Slack / disa aktarmanin ortak fonksiyonu da adi veriyor', $fonk === 'Bora Ayrilan', var_export($fonk, true));

$r = istek($A['jar'], $BASE . '/api/view_export_xlsx.php?table_id=' . $TB);
$xlsxAd = false;
if ($r['code'] === 200 && strlen($r['body']) > 100) {
    $tmp = tempnam(sys_get_temp_dir(), 'bccufx');
    file_put_contents($tmp, $r['body']);
    $zip = new ZipArchive();
    if ($zip->open($tmp) === true) {
        $icerik = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $icerik .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        $xlsxAd = strpos($icerik, 'Bora Ayrilan') !== false;
    }
    @unlink($tmp);
}
check('Excel disa aktarmada da ad var', $xlsxAd, 'kod ' . $r['code'] . ', ' . strlen($r['body']) . ' bayt');

echo "\nC) P PASIFLESTIRILDI (ekipte duruyor ama is_active=0)\n";
bcc_execute('UPDATE users SET is_active = 0 WHERE id = :id', array('id' => $K['P']['id']));
check('R2 hucresi "Pelin Pasif"', $adi($hucre($R[1])) === 'Pelin Pasif', var_export($adi($hucre($R[1])), true));

echo "\nD) YAZMA tarafi degismedi — ekipte olmayan biri alana SECILEMEZ\n";
$r = $ata($R[2], $K['B']['id']);
check('cikarilan B yeniden atanamaz -> 422', $r['code'] === 422, $r['code'] . ' ' . $r['body']);
$r = $ata($R[2], $K['P']['id']);
check('pasif P yeniden atanamaz -> 422', $r['code'] === 422, $r['code'] . ' ' . $r['body']);
$r = $ata($R[2], $K['C']['id']);
check('BASKA EKIPTEN C atanamaz -> 422 (ad yedegi ekip disi kimlige ulasamaz)', $r['code'] === 422, $r['code'] . ' ' . $r['body']);
$yazilan = bcc_fetch_column('SELECT value_number FROM cell_values WHERE record_id = :r AND field_id = :f', array('r' => $R[2], 'f' => $FU));
check('R3 hucresine hicbir sey yazilmadi', $yazilan === false || $yazilan === null, var_export($yazilan, true));

/* Paylasim penceresinin davet aday listesi (BCC_SHARE_CANDIDATES) ekip SAHIBINE
   sistemdeki butun aktif kullanicilarin ad+e-postasini gonderiyor — bu
   degisiklikten ONCE de boyleydi, bu betigin konusu degil (gunluk 2026-09-14
   §13, acik madde G). Kontrol o satir disindaki sayfaya bakiyor: C'nin adi
   hucrelere, gruplara ya da baska bir yere kullanici alani yoluyla sizmamali. */
$r = istek($A['jar'], $BASE . '/grid.php?table_id=' . $TB);
$adaySatiri = preg_match('/var BCC_SHARE_CANDIDATES = .*?;\s*$/m', $r['body']) === 1;
$adaysiz = preg_replace('/var BCC_SHARE_CANDIDATES = .*?;\s*$/m', '', $r['body']);
check('davet aday listesi DISINDA grid sayfasinda C\'nin adi yok', strpos($adaysiz, 'Cenk Baskaekip') === false);
echo '  [BILGI] davet aday listesi sayfada: ' . ($adaySatiri ? 'var (sahip olarak tum aktif kullanicilar — onceden de boyle)' : 'yok') . "\n";

echo "\nE) Hala ekipte olan biri normal calisiyor\n";
$r = $ata($R[2], $A['id']);
check('R3 <- A 200', $r['code'] === 200 && isset($r['json']['display']) && $r['json']['display'] === 'Ayla Sahip', $r['body']);

$cleanup();

echo "\nF) Kirlilik\n";
$sonra = $sayim();
foreach ($once as $ad => $d) {
    check("$ad ayni ($d)", $sonra[$ad] === $d, $d . ' -> ' . $sonra[$ad]);
}

echo "\nSONUC: $gecti gecti, $kaldi kaldi\n";
exit($kaldi === 0 ? 0 : 1);
