<?php

require __DIR__ . '/../config/database.php';

$dbError = null;
$serverVersion = null;
$connectionCharset = null;
$dbNameResult = null;
$tables = array();
$turkceTestSonucu = null;

try {
    $pdo = bcc_get_pdo();

    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    $row = $pdo->query("SELECT DATABASE() AS db_name")->fetch();
    $dbNameResult = $row['db_name'];

    $row = $pdo->query("SHOW VARIABLES LIKE 'character_set_connection'")->fetch();
    $connectionCharset = $row ? $row['Value'] : null;

    $stmt = $pdo->query("SHOW TABLES");
    while ($tableRow = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $tableRow[0];
    }

    // Türkçe karakter round-trip testi: geçici tabloya yaz, oku, sil.
    $pdo->exec("CREATE TEMPORARY TABLE bcc_turkce_test (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        deger VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ornekMetin = 'ş ç ğ ı İ ö ü - Şişli, Çanakkale, Iğdır, Öğretmen, Üzüm';

    $insertStmt = $pdo->prepare("INSERT INTO bcc_turkce_test (deger) VALUES (:deger)");
    $insertStmt->execute(array(':deger' => $ornekMetin));

    $okunanRow = $pdo->query("SELECT deger FROM bcc_turkce_test ORDER BY id DESC LIMIT 1")->fetch();
    $turkceTestSonucu = $okunanRow ? $okunanRow['deger'] : null;
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>BCC-Core — Tanı Sayfası</title>
<style>
    body { font-family: Segoe UI, Arial, sans-serif; margin: 2rem; background: #f5f5f7; color: #1d1d1f; }
    h1 { font-size: 1.4rem; }
    .card { background: #fff; border: 1px solid #d8d8dc; border-radius: 8px; padding: 1rem 1.5rem; margin-bottom: 1rem; }
    .ok { color: #1a7f37; font-weight: 600; }
    .fail { color: #c62828; font-weight: 600; }
    table { border-collapse: collapse; width: 100%; }
    td, th { text-align: left; padding: 0.25rem 0.75rem; border-bottom: 1px solid #eee; }
    code { background: #eee; padding: 0.1rem 0.35rem; border-radius: 4px; }
</style>
</head>
<body>
<h1>BCC-Core — Faz 0 Tanı Sayfası</h1>

<div class="card">
    <h2>Veritabanı Bağlantısı</h2>
    <?php if ($dbError !== null): ?>
        <p class="fail">HATA: <?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php else: ?>
        <p class="ok">Bağlantı başarılı</p>
        <table>
            <tr><th>Sunucu sürümü</th><td><?php echo htmlspecialchars((string) $serverVersion, ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Aktif veritabanı</th><td><code><?php echo htmlspecialchars((string) $dbNameResult, ENT_QUOTES, 'UTF-8'); ?></code></td></tr>
            <tr><th>Bağlantı karakter seti</th><td><code><?php echo htmlspecialchars((string) $connectionCharset, ENT_QUOTES, 'UTF-8'); ?></code></td></tr>
        </table>
    <?php endif; ?>
</div>

<?php if ($dbError === null): ?>
<div class="card">
    <h2>Tablo Listesi (<?php echo count($tables); ?>)</h2>
    <?php if (empty($tables)): ?>
        <p class="fail">Henüz tablo yok — <code>schema.sql</code> içe aktarılmamış olabilir.</p>
    <?php else: ?>
        <table>
            <?php foreach ($tables as $t): ?>
                <tr><td><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Türkçe Karakter Testi</h2>
    <p>Yazılan: <code>ş ç ğ ı İ ö ü - Şişli, Çanakkale, Iğdır, Öğretmen, Üzüm</code></p>
    <p>DB'den okunan: <code><?php echo htmlspecialchars((string) $turkceTestSonucu, ENT_QUOTES, 'UTF-8'); ?></code></p>
    <?php if ($turkceTestSonucu === 'ş ç ğ ı İ ö ü - Şişli, Çanakkale, Iğdır, Öğretmen, Üzüm'): ?>
        <p class="ok">Türkçe karakterler doğru okunup yazıldı.</p>
    <?php else: ?>
        <p class="fail">Türkçe karakter testi başarısız — karakter seti ayarlarını kontrol edin.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>
