<?php
// KVKK ekip izolasyonu testi.
// TY ekibi üyesi bir kullanıcının GULF ekibinin verisine erişemediğini,
// kendi ekibinin verisine erişebildiğini doğrudan doğrular. curl/HTTP kullanılmaz:
// - erişim kararı, uygulamanın gerçek require_team_access() fonksiyonunu ayrı bir
//   PHP alt sürecinde çağırarak test edilir (_isolation_case.php üzerinden),
// - veri filtresi, dashboard.php ile birebir aynı SQL deseniyle test edilir.
//
// Çalıştırma: C:\php73\php.exe scripts\test_isolation.php
//
// Betik kendi test kullanıcılarını/kayıtlarını kurar ve sonunda (başarılı ya da
// başarısız fark etmeksizin) temizler; veritabanında kalıcı iz bırakmaz.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';

$pdo = bcc_get_pdo();

const TEST_TY_EMAIL = 'izole.test.ty@bcc-test.local';
const TEST_GULF_EMAIL = 'izole.test.gulf@bcc-test.local';
const TEST_BASE_TY = 'IZOLASYON_TEST_BASE_TY';
const TEST_BASE_GULF = 'IZOLASYON_TEST_BASE_GULF';

$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo "         detay: " . $detail . "\n";
    }
}

// require_team_access($teamId)'i, verilen kullanıcı için ayrı bir PHP sürecinde
// çalıştırır ve erişimin verilip verilmediğini döndürür.
function run_access_case($userId, $teamId, &$rawOutput = null)
{
    // NOT: PHP_BINARY (ör. C:\php73\php.exe) kasıtlı olarak escapeshellarg ile
    // sarılmıyor — cmd.exe, komut dizisi tırnakla başlayıp ortada başka tırnaklı
    // parça daha varsa dış tırnakları yanlış yorumluyor ("sözdizimi hatalı" hatası).
    // Yol boşluk içermediği için çıplak kullanmak güvenli.
    $php = PHP_BINARY;
    $script = escapeshellarg(__DIR__ . '/_isolation_case.php');
    $cmd = "{$php} {$script} " . (int) $userId . ' ' . (int) $teamId;

    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        $rawOutput = 'alt süreç başlatılamadı';
        return false;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $rawOutput = trim($stdout . $stderr);

    return strpos($stdout, 'ERISIM_VAR') !== false;
}

function cleanup($pdo)
{
    $pdo->prepare('DELETE FROM bases WHERE name IN (:b1, :b2)')
        ->execute(array(':b1' => TEST_BASE_TY, ':b2' => TEST_BASE_GULF));

    $pdo->prepare('DELETE FROM users WHERE email IN (:e1, :e2)')
        ->execute(array(':e1' => TEST_TY_EMAIL, ':e2' => TEST_GULF_EMAIL));
    // team_members ve created_by referansları ON DELETE CASCADE / SET NULL ile temizlenir.
}

// Önceki başarısız bir çalıştırmadan kalıntı olabilir; baştan temizle.
cleanup($pdo);

try {
    // --- Kurulum -------------------------------------------------------
    $teamRows = $pdo->query("SELECT id, name FROM teams WHERE name IN ('TY', 'GULF')")->fetchAll();
    $teamIdByName = array();
    foreach ($teamRows as $row) {
        $teamIdByName[$row['name']] = (int) $row['id'];
    }

    if (!isset($teamIdByName['TY']) || !isset($teamIdByName['GULF'])) {
        echo "HATA: 'TY' ve/veya 'GULF' ekibi teams tablosunda bulunamadı. Önce migrations/001_faz1.sql uygulanmalı.\n";
        exit(1);
    }

    $tyTeamId = $teamIdByName['TY'];
    $gulfTeamId = $teamIdByName['GULF'];

    $hash = password_hash('IzolasyonTest!' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT);

    $insertUser = $pdo->prepare(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :full_name, 0, 1)'
    );

    $insertUser->execute(array(':email' => TEST_TY_EMAIL, ':hash' => $hash, ':full_name' => 'Izolasyon Test (TY)'));
    $tyUserId = (int) $pdo->lastInsertId();

    $insertUser->execute(array(':email' => TEST_GULF_EMAIL, ':hash' => $hash, ':full_name' => 'Izolasyon Test (GULF)'));
    $gulfUserId = (int) $pdo->lastInsertId();

    $insertMember = $pdo->prepare(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)'
    );
    $insertMember->execute(array(':team_id' => $tyTeamId, ':user_id' => $tyUserId, ':role' => 'viewer'));
    $insertMember->execute(array(':team_id' => $gulfTeamId, ':user_id' => $gulfUserId, ':role' => 'viewer'));

    $insertBase = $pdo->prepare(
        'INSERT INTO bases (team_id, name, description) VALUES (:team_id, :name, :description)'
    );
    $insertBase->execute(array(':team_id' => $tyTeamId, ':name' => TEST_BASE_TY, ':description' => 'izolasyon testi'));
    $tyBaseId = (int) $pdo->lastInsertId();

    $insertBase->execute(array(':team_id' => $gulfTeamId, ':name' => TEST_BASE_GULF, ':description' => 'izolasyon testi'));
    $gulfBaseId = (int) $pdo->lastInsertId();

    echo "Kurulum tamam: TY kullanicisi #{$tyUserId}, GULF kullanicisi #{$gulfUserId}.\n\n";

    // --- Test 1-4: require_team_access() gerçek fonksiyonu ------------
    $out = null;

    $ok = run_access_case($tyUserId, $tyTeamId, $out);
    check('TY kullanicisi kendi ekibine (TY) erisebiliyor', $ok === true, $out);

    $out = null;
    $ok = run_access_case($tyUserId, $gulfTeamId, $out);
    check('TY kullanicisi GULF ekibine erisemiyor (KVKK izolasyonu)', $ok === false, $out);

    $out = null;
    $ok = run_access_case($gulfUserId, $gulfTeamId, $out);
    check('GULF kullanicisi kendi ekibine (GULF) erisebiliyor', $ok === true, $out);

    $out = null;
    $ok = run_access_case($gulfUserId, $tyTeamId, $out);
    check('GULF kullanicisi TY ekibine erisemiyor (KVKK izolasyonu)', $ok === false, $out);

    // --- Test 5: veri sorgusu düzeyinde izolasyon (dashboard.php deseni) ---
    // current_user_team_ids() ile aynı sorgu: kullanıcının üye olduğu ekipler.
    $stmt = $pdo->prepare('SELECT team_id FROM team_members WHERE user_id = :uid');
    $stmt->execute(array(':uid' => $tyUserId));
    $tyAccessibleTeamIds = array_map('intval', array_column($stmt->fetchAll(), 'team_id'));

    $placeholders = implode(',', array_fill(0, count($tyAccessibleTeamIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM bases WHERE team_id IN ($placeholders)");
    $stmt->execute($tyAccessibleTeamIds);
    $visibleBaseIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

    check(
        'TY kullanicisinin filtrelenmis base sorgusu kendi base\'ini iceriyor',
        in_array($tyBaseId, $visibleBaseIds, true),
        'gorunen id\'ler: ' . implode(',', $visibleBaseIds)
    );
    check(
        'TY kullanicisinin filtrelenmis base sorgusu GULF base\'ini ICERMIYOR',
        !in_array($gulfBaseId, $visibleBaseIds, true),
        'gorunen id\'ler: ' . implode(',', $visibleBaseIds)
    );
} finally {
    cleanup($pdo);
    echo "\nTemizlik tamam (test kullanicilari/base'leri silindi).\n";
}

// --- Özet ----------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results));
$failed = $total - $passed;

echo "\n==================================\n";
if ($failed === 0) {
    echo "SONUC: GECTI ({$passed}/{$total})\n";
} else {
    echo "SONUC: KALDI ({$passed}/{$total} basarili, {$failed} basarisiz)\n";
}
echo "==================================\n";

exit($failed === 0 ? 0 : 1);
