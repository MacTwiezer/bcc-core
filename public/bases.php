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
        "SELECT id, team_id, name, description FROM bases WHERE team_id IN ($placeholders) ORDER BY name",
        $teamIds
    );

    foreach ($baseRows as $b) {
        $basesByTeam[$b['team_id']][] = $b;
    }
}
$pageTitle = "Base'ler";
require __DIR__ . '/../src/partials/header.php';
require __DIR__ . '/../src/partials/top_nav.php';
?>
<div class="page">
    <h1>Base'ler</h1>

    <?php require __DIR__ . '/../src/partials/flash.php'; ?>

    <?php if (empty($teams)): ?>
        <div class="card">
            <p>Henüz hiçbir ekibe üye değilsiniz. Erişim için bir platform admini ile iletişime geçin.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($teams as $t):
        $canEdit = in_array($t['role'], array('editor', 'owner'), true);
    ?>
        <div class="card">
            <h2>
                <?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>
                <span class="pill"><?php echo htmlspecialchars($t['role'], ENT_QUOTES, 'UTF-8'); ?></span>
            </h2>

            <?php if (!empty($basesByTeam[$t['id']])): ?>
                <table>
                    <tr><th>Base</th><th>Açıklama</th></tr>
                    <?php foreach ($basesByTeam[$t['id']] as $b): ?>
                        <tr>
                            <td><a href="/base_tables.php?base_id=<?php echo (int) $b['id']; ?>"><?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                            <td><?php echo htmlspecialchars((string) $b['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p>Bu ekipte henüz base yok.</p>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <form class="stacked" method="post" action="/bases.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="team_id" value="<?php echo (int) $t['id']; ?>">
                    <label>Yeni base adı
                        <input type="text" name="name" required>
                    </label>
                    <label>Açıklama (opsiyonel)
                        <input type="text" name="description">
                    </label>
                    <button type="submit">Base Oluştur</button>
                </form>
            <?php else: ?>
                <p class="hint">Bu ekipte base oluşturmak için editor veya owner rolü gerekir.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
