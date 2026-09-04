<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_purge_own_audit();

define('PROBE_MAIL', 'xlsxcell.owner@bcc-test.local');
define('PROBE_TEAM', 'ZZ XLSX Hucre Testi');

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

echo "A) Yazicinin sozlesmesi\n";

$writerSrc = '';
foreach (token_get_all(file_get_contents(__DIR__ . '/../src/xlsx_writer.php')) as $t) {
    if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { continue; }
    $writerSrc .= is_array($t) ? $t[1] : $t;
}
check('A) tum hucreler inlineStr olarak yaziliyor',
    strpos($writerSrc, 't="inlineStr"') !== false);
check('A) formul ogesi (<f>) HIC uretilmiyor (enjeksiyon yolu yok)',
    strpos($writerSrc, '<f>') === false);

echo "\nB) Disa aktarma uc noktalari hucreyi ONISLEMDEN gecirmiyor\n";

$suclular = array();
foreach (array('view_export_xlsx.php', 'team_members_export_xlsx.php', 'note_view_export_xlsx.php') as $dosya) {
    $kod = '';
    foreach (token_get_all(file_get_contents(__DIR__ . '/../public/api/' . $dosya)) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { continue; }
        $kod .= is_array($t) ? $t[1] : $t;
    }
    if (strpos($kod, 'bcc_csv_injection_guard(') !== false) { $suclular[] = $dosya; }
}
check('B) hicbir XLSX uc noktasi bcc_csv_injection_guard cagirmiyor',
    empty($suclular), implode(', ', $suclular));

echo "\nC) CANLI: gercek disa aktarmada degerler BOZULMUYOR\n";

$BASE = 'http://localhost';
$COOKIE = tempnam(sys_get_temp_dir(), 'bccxc');

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
    array(':e' => PROBE_MAIL, ':h' => password_hash($sifre, PASSWORD_DEFAULT), ':n' => 'XLSX Hucre Owner'));
$userId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)',
    array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t,:n,:u)',
    array(':t' => $teamId, ':n' => 'XLSX Hucre Base', ':u' => $userId));
$baseId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b,:n,0)',
    array(':b' => $baseId, ':n' => 'Hucreler'));
$tableId = (int) bcc_last_insert_id();

$alanlar = array(
    array('Ad', 'single_line_text', 'Ahmet'),
    array('Telefon', 'phone', '+90 555 111 22 33'),
    array('Not', 'single_line_text', '-indirimli'),
    array('Etiket', 'single_line_text', '@kullanici'),
    array('Formul', 'single_line_text', '=TOPLA(A1)'),
);
$fieldIds = array();
foreach ($alanlar as $i => $a) {
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:ft,:p)',
        array(':t' => $tableId, ':n' => $a[0], ':ft' => $a[1], ':p' => $i));
    $fieldIds[$a[0]] = (int) bcc_last_insert_id();
}
bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t,0,:u)',
    array(':t' => $tableId, ':u' => $userId));
$recordId = (int) bcc_last_insert_id();
foreach ($alanlar as $a) {
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)',
        array(':r' => $recordId, ':f' => $fieldIds[$a[0]], ':v' => $a[2]));
}

$r = istek($BASE . '/login.php');
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
istek($BASE . '/login.php', 'csrf_token=' . $m[1] . '&email=' . rawurlencode(PROBE_MAIL) . '&password=' . rawurlencode($sifre));

$x = istek($BASE . '/api/view_export_xlsx.php?table_id=' . $tableId);
check('C) disa aktarma 200 donuyor', $x['code'] === 200, 'HTTP ' . $x['code']);

$tmp = tempnam(sys_get_temp_dir(), 'bccx') . '.xlsx';
file_put_contents($tmp, $x['body']);
$hucreler = array();
$zip = new ZipArchive();
if ($zip->open($tmp) === true) {
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    preg_match_all('#<t xml:space="preserve">(.*?)</t>#s', $sheet, $mm);
    foreach ($mm[1] as $v) { $hucreler[] = html_entity_decode($v, ENT_QUOTES, 'UTF-8'); }
}
@unlink($tmp);
check('C) dosya gecerli bir XLSX (sheet1.xml okunabildi)', !empty($hucreler),
    'hucre sayisi: ' . count($hucreler));

foreach ($alanlar as $a) {
    check('C) "' . $a[2] . '" degeri BOZULMADAN aktarildi',
        in_array($a[2], $hucreler, true),
        'gelen hucreler: ' . implode(' | ', $hucreler));
}
$tirnaklilar = array();
foreach ($hucreler as $h) {
    if ($h !== '' && $h[0] === "'") { $tirnaklilar[] = $h; }
}
check('C) HICBIR hucre bastan tek tirnak almadi', empty($tirnaklilar),
    implode(' | ', $tirnaklilar));

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
