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

    // require_role hem üyeliği (KVKK izolasyonu) hem de editor+ rolünü doğrular.
    require_role($teamId, 'editor');

    if ($name === '') {
        $error = 'Base adı boş olamaz.';
    } elseif (mb_strlen($name, 'UTF-8') > 150) {
        // bases.name VARCHAR(150) — base_tables.php'deki AYNI gerekçe: bu kontrol
        // olmadan uzun bir base adı sql_mode'da STRICT_TRANS_TABLES kapalı olduğu
        // için hatasız sessizce kırpılıyordu.
        $error = 'Base adı en fazla 150 karakter olabilir.';
    } elseif (mb_strlen($description, 'UTF-8') > 500) {
        // bases.description VARCHAR(500) — aynı sessiz kırpılma riski.
        $error = 'Açıklama en fazla 500 karakter olabilir.';
    } else {
        bcc_execute(
            'INSERT INTO bases (team_id, name, description, created_by) VALUES (:team_id, :name, :description, :created_by)',
            array(
                'team_id' => $teamId,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'created_by' => $user['id'],
            )
        );
        $newId = bcc_last_insert_id();
        log_audit('base.create', 'base', $newId, array('name' => $name), $teamId);
        $success = 'Base oluşturuldu: ' . $name;
    }
}

$teams = bcc_fetch_all(
    'SELECT t.id, t.name, m.role
     FROM team_members m
     INNER JOIN teams t ON t.id = m.team_id
     WHERE m.user_id = :uid
     ORDER BY t.name',
    array('uid' => $user['id'])
);

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
// Sol panel "Yıldızlılar" listesi — workspaces.php/team_members.php ile AYNI desen.
$starredBases = array();
if (!empty($teamIds)) {
    $starredPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
    $starredBases = bcc_fetch_all(
        "SELECT b.id, b.name FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($starredPlaceholders) AND b.deleted_at IS NULL
         WHERE usb.user_id = ? ORDER BY b.name",
        array_merge($teamIds, array((int) $user['id']))
    );
}

$homeActiveNav = 'bases';
$homePageTitle = "BCC-Core — Base'ler";
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
            $canEdit = in_array($t['role'], array('editor', 'owner'), true);
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
                    <p class="settings-hint">Bu ekipte base oluşturmak için editor veya owner rolü gerekir.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
