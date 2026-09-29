<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

/* Calisma alani silme (2026-09-22). Iki yol da burada deneniyor: arayuzdeki
   dugme -> POST /api/team_delete.php ve admin paneli -> POST /admin/index.php
   (action=delete_team). Kullanici karari: icerigi ne olursa olsun silinebilir,
   yalnizca onay kutusu cikar; yetki owner (platform yoneticisi her ekipte
   owner sayilir). */

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

$COOKIE = tempnam(sys_get_temp_dir(), 'bcctd');

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

function jeton($url)
{
    $r = istek($url);
    if ($r && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m)) { return $m[1]; }
    if ($r && preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $r['body'], $m)) { return $m[1]; }
    return '';
}

function girisYap($BASE, $email, $sifre)
{
    /* Cerez sifirlanir: login.php girisli oturumu dogrudan dashboard'a
       yonlendiriyor, yani eski oturum durursa yeni giris HIC olmaz. */
    global $COOKIE;
    file_put_contents($COOKIE, '');
    $t = jeton($BASE . '/login.php');
    istek($BASE . '/login.php', 'csrf_token=' . $t . '&email=' . urlencode($email) . '&password=' . urlencode($sifre));
}

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$SON = bin2hex(random_bytes(4));
$SIFRE = 'TdTest!' . $SON;
$MAILLER = array(
    'owner'  => 'td.owner.' . $SON . '@bcc-test.local',
    'viewer' => 'td.viewer.' . $SON . '@bcc-test.local',
    'admin'  => 'td.admin.' . $SON . '@bcc-test.local',
);

$temizle = function () use ($MAILLER, $SON) {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name LIKE :n', array('n' => 'TdTeam ' . $SON . '%')) as $x) {
        bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $x['id']));
        bcc_execute('DELETE FROM teams WHERE id = :i', array('i' => $x['id']));
    }
    foreach ($MAILLER as $mail) {
        $u = bcc_fetch_one('SELECT id FROM users WHERE email = :e', array('e' => $mail));
        if ($u) {
            bcc_execute('DELETE FROM audit_log WHERE user_id = :i', array('i' => $u['id']));
            bcc_execute('DELETE FROM users WHERE id = :i', array('i' => $u['id']));
        }
    }
    bcc_execute('DELETE FROM audit_log WHERE action = :a AND team_id IS NULL AND details LIKE :d',
        array('a' => 'team.delete', 'd' => '%TdTeam ' . $SON . '%'));
    bcc_execute('DELETE FROM login_attempts WHERE email LIKE :p', array('p' => '%' . $SON . '@bcc-test.local'));
    foreach (glob(bcc_attachment_storage_path('td-' . $SON . '-*')) as $f) { @unlink($f); }
};
register_shutdown_function($temizle);
$temizle();

$kullanici = function ($mail, $ad, $admin) use ($SIFRE) {
    bcc_execute(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,:a,1)',
        array('e' => $mail, 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => $ad, 'a' => $admin)
    );
    return (int) bcc_last_insert_id();
};

$ownerId  = $kullanici($MAILLER['owner'], 'Td Owner', 0);
$viewerId = $kullanici($MAILLER['viewer'], 'Td Viewer', 0);
$adminId  = $kullanici($MAILLER['admin'], 'Td Admin', 1);

$ekipKur = function ($ad) use ($ownerId, $viewerId, $SON) {
    bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => $ad));
    $teamId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)', array('t' => $teamId, 'u' => $ownerId, 'r' => 'owner'));
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t,:u,:r)', array('t' => $teamId, 'u' => $viewerId, 'r' => 'viewer'));

    $aktif = bcc_create_base($teamId, 'TdBase Aktif ' . $teamId, '', $ownerId);
    $copte = bcc_create_base($teamId, 'TdBase Copte ' . $teamId, '', $ownerId);
    bcc_execute('UPDATE bases SET deleted_at = NOW(), deleted_by = :u WHERE id = :i', array('u' => $ownerId, 'i' => $copte['id']));

    bcc_execute('INSERT INTO tables_meta (base_id, name, description, position) VALUES (:b,:n,:d,1)',
        array('b' => $aktif['id'], 'n' => 'TdTablo', 'd' => ''));
    $tableId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:f,1)',
        array('t' => $tableId, 'n' => 'Ad', 'f' => 'single_line_text'));
    $fieldId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t,:n,:f,2)',
        array('t' => $tableId, 'n' => 'Dosya', 'f' => 'attachment'));
    $fileFieldId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t,1,:u)', array('t' => $tableId, 'u' => $ownerId));
    $recordId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r,:f,:v)',
        array('r' => $recordId, 'f' => $fieldId, 'v' => 'deger'));

    $stored = 'td-' . $SON . '-' . $teamId . '.txt';
    file_put_contents(bcc_attachment_storage_path($stored), 'silinmeli');
    bcc_execute('INSERT INTO attachments (field_id, record_id, original_name, stored_name, mime_type, file_size, uploaded_by)
                 VALUES (:f,:r,:o,:s,:m,9,:u)',
        array('f' => $fileFieldId, 'r' => $recordId, 'o' => 't.txt', 's' => $stored, 'm' => 'text/plain', 'u' => $ownerId));

    file_put_contents(bcc_team_image_path($teamId), 'resim');

    return array('team' => $teamId, 'table' => $tableId, 'record' => $recordId, 'stored' => $stored);
};

$A = $ekipKur('TdTeam ' . $SON . ' A');
$B = $ekipKur('TdTeam ' . $SON . ' B');

echo "PHP " . PHP_VERSION . " - calisma alani silme testi\n\n";

echo "A) Viewer silemez (ne dugmeyi gorur ne uc kabul eder)\n";

girisYap($BASE, $MAILLER['viewer'], $SIFRE);
$sayfa = istek($BASE . '/workspaces.php?team_id=' . $A['team']);
check('A) viewer sayfasinda silme dugmesi YOK',
    $sayfa && strpos($sayfa['body'], 'data-team-delete') === false);
$t = jeton($BASE . '/workspaces.php?team_id=' . $A['team']);
$r = istek($BASE . '/api/team_delete.php', 'csrf_token=' . $t . '&team_id=' . $A['team']);
check('A) viewer uctan silemiyor (403)', $r && $r['code'] === 403, $r ? $r['code'] . ' ' . $r['body'] : 'null');
check('A) ekip yerinde duruyor',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :i', array('i' => $A['team'])) === 1);

echo "\nB) CSRF jetonu olmadan reddediliyor\n";

$r = istek($BASE . '/api/team_delete.php', 'team_id=' . $A['team']);
check('B) jetonsuz istek 403', $r && $r['code'] === 403, $r ? $r['code'] : 'null');
check('B) ekip hala duruyor',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :i', array('i' => $A['team'])) === 1);

echo "\nC) Owner siliyor: butun zincir gidiyor, hesaplar kaliyor\n";

girisYap($BASE, $MAILLER['owner'], $SIFRE);
$sayfa = istek($BASE . '/workspaces.php?team_id=' . $A['team']);
check('C) owner sayfasinda silme dugmesi VAR',
    $sayfa && strpos($sayfa['body'], 'data-team-delete="' . $A['team'] . '"') !== false);

$t = jeton($BASE . '/workspaces.php?team_id=' . $A['team']);
$r = istek($BASE . '/api/team_delete.php', 'csrf_token=' . $t . '&team_id=' . $A['team']);
$json = $r ? json_decode($r['body'], true) : null;
check('C) uc ok donuyor', is_array($json) && !empty($json['ok']), $r ? $r['body'] : 'null');
check('C) sayimlar donuyor (2 base, 2 uye)',
    is_array($json) && isset($json['counts']['bases']) && (int) $json['counts']['bases'] === 2
    && (int) $json['counts']['members'] === 2,
    is_array($json) && isset($json['counts']) ? json_encode($json['counts']) : 'yok');

check('C) teams satiri silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :i', array('i' => $A['team'])) === 0);
check('C) base satirlari silindi (copteki dahil)',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :i', array('i' => $A['team'])) === 0);
check('C) uyelikler silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :i', array('i' => $A['team'])) === 0);
check('C) tablo silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE id = :i', array('i' => $A['table'])) === 0);
check('C) kayit silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE id = :i', array('i' => $A['record'])) === 0);
check('C) hucre degerleri silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM cell_values WHERE record_id = :i', array('i' => $A['record'])) === 0);
check('C) ek satiri silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM attachments WHERE record_id = :i', array('i' => $A['record'])) === 0);
check('C) ek DOSYASI diskten silindi', !is_file(bcc_attachment_storage_path($A['stored'])));
check('C) ekip resmi diskten silindi', !is_file(bcc_team_image_path($A['team'])));
check('C) KULLANICI HESAPLARI duruyor (yalnizca uyelik kalkti)',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE id IN (:a, :b)', array('a' => $ownerId, 'b' => $viewerId)) === 2);

$audit = bcc_fetch_one(
    'SELECT team_id, details FROM audit_log WHERE action = :a AND entity_id = :e ORDER BY id DESC LIMIT 1',
    array('a' => 'team.delete', 'e' => $A['team'])
);
check('C) denetim kaydi yazildi', $audit !== false);
check('C) denetim kaydinin team_id si NULL a dustu (FK SET NULL)',
    $audit !== false && $audit['team_id'] === null);
check('C) denetim kaydinda ad ve sayimlar var',
    $audit !== false && strpos((string) $audit['details'], 'TdTeam') !== false
    && strpos((string) $audit['details'], '"bases":2') !== false,
    $audit !== false ? (string) $audit['details'] : 'yok');

echo "\nD) Silinmis / olmayan ekip\n";

$t = jeton($BASE . '/workspaces.php');
$r = istek($BASE . '/api/team_delete.php', 'csrf_token=' . $t . '&team_id=' . $A['team']);
check('D) ayni ekip ikinci kez -> 404', $r && $r['code'] === 404, $r ? $r['code'] : 'null');
$r = istek($BASE . '/api/team_delete.php', 'csrf_token=' . $t . '&team_id=0');
check('D) team_id=0 -> 400', $r && $r['code'] === 400, $r ? $r['code'] : 'null');

echo "\nE) Admin paneli yolu (action=delete_team)\n";

girisYap($BASE, $MAILLER['owner'], $SIFRE);
$t = jeton($BASE . '/workspaces.php');
$r = istek($BASE . '/admin/index.php', 'csrf_token=' . $t . '&action=delete_team&team_id=' . $B['team']);
check('E) admin olmayan owner admin panelinden silemiyor',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :i', array('i' => $B['team'])) === 1,
    $r ? (string) $r['code'] : 'null');

girisYap($BASE, $MAILLER['admin'], $SIFRE);
$sayfa = istek($BASE . '/admin/index.php');
check('E) admin panelinde silme formu VAR',
    $sayfa && strpos($sayfa['body'], 'name="team_id" value="' . $B['team'] . '"') !== false);
$t = jeton($BASE . '/admin/index.php');
$r = istek($BASE . '/admin/index.php', 'csrf_token=' . $t . '&action=delete_team&team_id=' . $B['team']);
check('E) basari mesaji', $r && strpos($r['body'], 'çalışma alanı silindi') !== false);
check('E) teams satiri silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :i', array('i' => $B['team'])) === 0);
check('E) base/tablo/kayit silindi',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :i', array('i' => $B['team'])) === 0
    && (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE id = :i', array('i' => $B['record'])) === 0);
check('E) ek DOSYASI ve ekip resmi diskten silindi',
    !is_file(bcc_attachment_storage_path($B['stored'])) && !is_file(bcc_team_image_path($B['team'])));
$audit = bcc_fetch_one(
    'SELECT team_id, details FROM audit_log WHERE action = :a AND entity_id = :e ORDER BY id DESC LIMIT 1',
    array('a' => 'team.delete', 'e' => $B['team'])
);
check('E) denetim kaydi via=admin', $audit !== false && strpos((string) $audit['details'], '"via":"admin"') !== false);
check('E) owner/viewer hesaplari duruyor',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE id IN (:a, :b)', array('a' => $ownerId, 'b' => $viewerId)) === 2);

@unlink($COOKIE);

echo "\nSonuc: $gecti gecti, $kaldi kaldi\n";
exit($kaldi > 0 ? 1 : 0);
