<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$pdo = bcc_get_pdo();

$users = $pdo->query('SELECT id, email, full_name, is_admin, is_active, created_at FROM users ORDER BY created_at DESC')->fetchAll();
$teams = $pdo->query('SELECT id, name, created_at FROM teams ORDER BY name')->fetchAll();

$memStmt = $pdo->query(
    'SELECT tm.team_id, tm.role, u.id AS user_id, u.email, u.full_name
     FROM team_members tm
     INNER JOIN users u ON u.id = tm.user_id
     ORDER BY tm.team_id, u.email'
);

$membersByTeam = array();
foreach ($memStmt->fetchAll() as $row) {
    $membersByTeam[$row['team_id']][] = $row;
}
$pageTitle = 'Admin';
require __DIR__ . '/../../src/partials/header.php';
require __DIR__ . '/../../src/partials/top_nav.php';
?>
<div class="page">
    <h1>Admin</h1>

    <div class="card">
        <h2>Kullanıcılar (<?php echo count($users); ?>)</h2>
        <table>
            <tr><th>E-posta</th><th>Ad Soyad</th><th>Admin</th><th>Aktif</th><th>Oluşturuldu</th></tr>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo ((int) $u['is_admin'] === 1) ? 'Evet' : '—'; ?></td>
                    <td><?php echo ((int) $u['is_active'] === 1) ? 'Evet' : 'Pasif'; ?></td>
                    <td><?php echo htmlspecialchars($u['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p><a href="/admin/create_user.php">+ Yeni kullanıcı oluştur</a></p>
    </div>

    <div class="card">
        <h2>Ekipler (<?php echo count($teams); ?>)</h2>
        <?php foreach ($teams as $t): ?>
            <h3><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <?php if (!empty($membersByTeam[$t['id']])): ?>
                <table>
                    <tr><th>E-posta</th><th>Ad Soyad</th><th>Rol</th></tr>
                    <?php foreach ($membersByTeam[$t['id']] as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($m['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($m['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p>Bu ekipte henüz üye yok.</p>
            <?php endif; ?>
        <?php endforeach; ?>
        <p>
            <a href="/admin/create_team.php">+ Yeni ekip oluştur</a> ·
            <a href="/admin/assign_team.php">Kullanıcıyı ekibe ata</a>
        </p>
    </div>
</div>
<?php require __DIR__ . '/../../src/partials/footer.php'; ?>
