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

$COOKIE = tempnam(sys_get_temp_dir(), 'bccatt');

function istek($url, $post = null, $dosya = null)
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $COOKIE,
        CURLOPT_COOKIEFILE => $COOKIE,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dosya === null ? $post : array_merge($post, array('file' => $dosya)));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) { return null; }

    return array('code' => $code, 'head' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen));
}

function jeton($url)
{
    $r = istek($url);
    return ($r && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m)) ? $m[1] : '';
}

$r = istek($BASE . '/login.php');
if (!$r || $r['code'] !== 200) { fwrite(STDERR, "Apache'ye ulasilamadi.\n"); exit(2); }

$SON = bin2hex(random_bytes(4));
$SIFRE = 'AttTest!' . $SON;

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array('n' => 'AttTest ' . $SON));
$teamId = (int) bcc_last_insert_id();

bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array('e' => "att.editor.$SON@bcc-test.local", 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => 'Att Editor'));
$editorId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
    array('e' => "att.viewer.$SON@bcc-test.local", 'h' => password_hash($SIFRE, PASSWORD_DEFAULT), 'n' => 'Att Viewer'));
$viewerId = (int) bcc_last_insert_id();

bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array('t' => $teamId, 'u' => $editorId, 'r' => 'editor'));
bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array('t' => $teamId, 'u' => $viewerId, 'r' => 'viewer'));

bcc_execute('INSERT INTO bases (team_id, name) VALUES (:t, :n)', array('t' => $teamId, 'n' => 'AttBase ' . $SON));
$baseId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array('b' => $baseId, 'n' => 'Tablo'));
$tableId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, :ft, 0)',
    array('t' => $tableId, 'n' => 'Dosya', 'ft' => 'attachment'));
$fieldId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO records (table_id, position) VALUES (:t, 0)', array('t' => $tableId));
$recordId = (int) bcc_last_insert_id();

echo "Ortam: team=$teamId base=$baseId alan=$fieldId kayit=$recordId\n\n";

$pngPath = sys_get_temp_dir() . '/bcc_test_' . $SON . '.png';
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$sahtePath = sys_get_temp_dir() . '/bcc_test_' . $SON . '_sahte.png';
file_put_contents($sahtePath, "<?php echo 'zararli'; ?>");
$svgPath = sys_get_temp_dir() . '/bcc_test_' . $SON . '.svg';
file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

function cf($p, $mime) { return new CURLFile($p, $mime, basename($p)); }

$cleanup = function () use ($pngPath, $sahtePath, $svgPath, $COOKIE, $recordId, $fieldId, $tableId, $baseId, $teamId, $editorId, $viewerId, $SON) {
    @unlink($pngPath); @unlink($sahtePath); @unlink($svgPath); @unlink($COOKIE);
    bcc_execute('DELETE FROM attachments WHERE record_id = :r', array('r' => $recordId));
    bcc_execute('DELETE FROM records WHERE id = :i', array('i' => $recordId));
    bcc_execute('DELETE FROM fields WHERE id = :i', array('i' => $fieldId));
    bcc_execute('DELETE FROM tables_meta WHERE id = :i', array('i' => $tableId));
    bcc_execute('DELETE FROM bases WHERE id = :i', array('i' => $baseId));
    bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM team_members WHERE team_id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));
    bcc_execute('DELETE FROM login_attempts WHERE email LIKE :e', array('e' => "att.%.$SON@bcc-test.local"));
    bcc_execute('DELETE FROM users WHERE id IN (:a, :b)', array('a' => $editorId, 'b' => $viewerId));
};
register_shutdown_function($cleanup);

echo "A) VIEWER yukleyemez (editor gerekir)\n";

$tok = jeton($BASE . '/login.php');
istek($BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode("att.viewer.$SON@bcc-test.local") . '&password=' . rawurlencode($SIFRE));
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/attachment_upload.php', array('csrf_token' => $tok, 'field_id' => $fieldId, 'record_id' => $recordId), cf($pngPath, 'image/png'));
check('viewer yukleme -> 403', $r['code'] === 403, $r['code'] . ' ' . substr($r['body'], 0, 60));

echo "\nB) EDITOR yukler\n";

@unlink($COOKIE);
$tok = jeton($BASE . '/login.php');
istek($BASE . '/login.php', 'csrf_token=' . $tok . '&email=' . rawurlencode("att.editor.$SON@bcc-test.local") . '&password=' . rawurlencode($SIFRE));
$tok = jeton($BASE . '/account.php');

$r = istek($BASE . '/api/attachment_upload.php', array('csrf_token' => $tok, 'field_id' => $fieldId, 'record_id' => $recordId), cf($svgPath, 'image/svg+xml'));
check('SVG REDDEDILDI (beyaz listede yok)', $r['code'] === 422, $r['code'] . ' ' . substr($r['body'], 0, 70));

$r = istek($BASE . '/api/attachment_upload.php', array('csrf_token' => $tok, 'field_id' => $fieldId, 'record_id' => $recordId), cf($sahtePath, 'image/png'));
check('PHP icerikli .png REDDEDILDI (finfo uyusmadi)', $r['code'] === 422, $r['code'] . ' ' . substr($r['body'], 0, 70));

$r = istek($BASE . '/api/attachment_upload.php', array('csrf_token' => $tok, 'field_id' => $fieldId, 'record_id' => $recordId), cf($pngPath, 'image/png'));
check('gercek PNG kabul edildi', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 70));
$j = json_decode($r['body'], true);
$attId = ($j && isset($j['file']['id'])) ? (int) $j['file']['id'] : 0;
check('attachment id dondu', $attId > 0, $attId);

$row = bcc_fetch_one('SELECT stored_name, mime_type FROM attachments WHERE id = :i', array('i' => $attId));
check('DB mime kanonik (image/png)', $row && $row['mime_type'] === 'image/png', $row ? $row['mime_type'] : 'yok');
check('stored_name rastgele (32 hex + .png)', $row && preg_match('/^[0-9a-f]{32}\.png$/', $row['stored_name']) === 1, $row ? $row['stored_name'] : 'yok');
check('dosya diskte', $row && is_file(bcc_attachment_storage_path($row['stored_name'])));

echo "\nC) Indirme\n";

$r = istek($BASE . '/api/attachment_download.php?id=' . $attId);
check('editor indirebiliyor -> 200', $r['code'] === 200, $r['code']);
check('Content-Type image/png', stripos($r['head'], 'Content-Type: image/png') !== false);
check('nosniff basligi var', stripos($r['head'], 'X-Content-Type-Options: nosniff') !== false);
check('govde gercek PNG', strpos($r['body'], "\x89PNG") === 0);

echo "\nD) Silme\n";

$diskYol = bcc_attachment_storage_path($row['stored_name']);
$tok = jeton($BASE . '/account.php');
$r = istek($BASE . '/api/attachment_delete.php', array('csrf_token' => $tok, 'attachment_id' => $attId));
check('editor silebiliyor -> 200', $r['code'] === 200, $r['code'] . ' ' . substr($r['body'], 0, 60));
check('DB satiri gitti', (int) bcc_fetch_column('SELECT COUNT(*) FROM attachments WHERE id = :i', array('i' => $attId)) === 0);

clearstatcache(true, $diskYol);
check('DISKTEKI dosya da gitti', !is_file($diskYol), $diskYol);

$cleanup();

$kalan = (int) bcc_fetch_column('SELECT COUNT(*) FROM teams WHERE id = :t', array('t' => $teamId))
       + (int) bcc_fetch_column('SELECT COUNT(*) FROM users WHERE id IN (:a, :b)', array('a' => $editorId, 'b' => $viewerId));
check('test verisi tamamen silindi', $kalan === 0, $kalan);

echo "\n" . str_repeat('-', 50) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
