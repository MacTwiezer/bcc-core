<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    // require_role hem üyeliği (KVKK izolasyonu) hem de rolü doğrular. Eşik
    // 'editor' DEĞİL 'owner': "Add and delete bases in the shared workspace"
    // yalnızca Owner'a açık, Editor'a
    // kapalıdır (bkz. src/auth.php bcc_can_manage_bases()). Aynı eşik Home'daki
    // "+ Yeni Base Oluştur" kutucuğunu ve api/base_create.php'yi de yönetir —
    // üç giriş noktası tek kaynaktan beslenir.
    require_role($teamId, 'owner');

    // Doğrulama + INSERT + audit: bcc_create_base() (bkz. src/schema.php) —
    // api/base_create.php ile ORTAK, ikinci bir kopya yok.
    $result = bcc_create_base($teamId, $name, $description, $user['id']);

    if ($result['ok']) {
        $success = 'Base oluşturuldu: ' . $name;
    } else {
        $error = $result['error'];
    }
}

// Tek kaynak: bcc_teams_for_current_user() (src/schema.php). Sorgu BES
// sayfada birebir kopyalanmisti; admin kapsami gibi bir kural degisince
// ayrisma riski kalmasin diye tek yere alindi.
$teams = bcc_teams_for_current_user();

$basesByTeam = array();
if (!empty($teams)) {
    $teamIds = array();
    foreach ($teams as $t) {
        $teamIds[] = (int) $t['id'];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $baseRows = bcc_fetch_all(
        "SELECT id, team_id, name, description FROM bases WHERE team_id IN ($placeholders) AND deleted_at IS NULL ORDER BY name",
        $teamIds
    );

    foreach ($baseRows as $b) {
        $basesByTeam[$b['team_id']][] = $b;
    }
}
// Sol panel "Yıldızlılar" listesi ARTIK BURADA ÇEKİLMİYOR: kabuk
// (src/partials/home_shell_top.php) bcc_starred_bases_for_current_user()'ı
// kendisi çağırıyor — bkz. src/schema.php'deki tek kaynak notu.
// ($teamIds yukarıdaki base listesi için hâlâ gerekli, o yüzden kalıyor.)

$homeActiveNav = 'bases';
$homePageTitle = bcc_brand_domain() . " — Base'ler";
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1>Base'ler</h1>
        </div>

        <?php require __DIR__ . '/../src/partials/flash.php'; ?>

        <?php if (empty($teams)): ?>
            <div class="home-empty">
                <p>Henüz hiçbir ekibe üye değilsiniz. Erişim için bir platform admini ile iletişime geçin.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($teams as $t):
            // Formun görünürlüğü ile POST'un kabulü AYNI fonksiyondan gelir —
            // "gizlenen ama hâlâ kabul edilen" bir aksiyon oluşamaz.
            $canEdit = bcc_can_manage_bases($t['role']);
        ?>
            <div class="settings-card">
                <h2>
                    <?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="settings-pill"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$t['role']], ENT_QUOTES, 'UTF-8'); ?></span>
                </h2>

                <?php if (!empty($basesByTeam[$t['id']])): ?>
                    <div class="settings-table-wrap">
                        <table class="settings-table">
                            <thead><tr><th>Base</th><th>Açıklama</th></tr></thead>
                            <tbody>
                            <?php foreach ($basesByTeam[$t['id']] as $b): ?>
                                <tr>
                                    <td><a href="/base_tables.php?base_id=<?php echo (int) $b['id']; ?>"><?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                                    <td><?php echo htmlspecialchars((string) $b['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="settings-empty">Bu ekipte henüz base yok.</p>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form class="settings-form settings-form-stacked" method="post" action="/bases.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="team_id" value="<?php echo (int) $t['id']; ?>">
                        <label class="settings-field">Yeni base adı
                            <input type="text" name="name" required>
                        </label>
                        <label class="settings-field">Açıklama (opsiyonel)
                            <input type="text" name="description">
                        </label>
                        <button type="submit" class="settings-btn settings-btn-primary">Base Oluştur</button>
                    </form>
                <?php else: ?>
                    <p class="settings-hint">Bu çalışma alanında base oluşturmak için Owner rolü gerekir.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
