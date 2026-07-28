<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$users = bcc_fetch_all('SELECT id, email, full_name, is_admin, is_active, created_at FROM users ORDER BY created_at DESC');
$teams = bcc_fetch_all('SELECT id, name, created_at FROM teams ORDER BY name');

$memberRows = bcc_fetch_all(
    'SELECT tm.team_id, tm.role, u.id AS user_id, u.email, u.full_name
     FROM team_members tm
     INNER JOIN users u ON u.id = tm.user_id
     ORDER BY tm.team_id, u.email'
);

$membersByTeam = array();
foreach ($memberRows as $row) {
    $membersByTeam[$row['team_id']][] = $row;
}
$pageTitle = 'Admin';
require __DIR__ . '/../../src/partials/header.php';
require __DIR__ . '/../../src/partials/top_nav.php';
?>
<div class="page">
    <h1>Admin</h1>

    <div class="card">
        <div class="admin-section-header">
            <h2>Kullanıcılar</h2>
            <span class="admin-section-count"><?php echo count($users); ?></span>
        </div>
        <table class="admin-table">
            <thead>
                <tr><th>Kullanıcı</th><th>Yetki</th><th>Durum</th><th>Oluşturuldu</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="admin-user-cell">
                                <div class="admin-avatar"><?php echo htmlspecialchars(bcc_user_initial($u), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div>
                                    <div class="admin-user-name"><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="admin-user-email"><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php if ((int) $u['is_admin'] === 1): ?><span class="admin-pill admin-pill-blue">Admin</span><?php endif; ?></td>
                        <td>
                            <?php if ((int) $u['is_active'] === 1): ?>
                                <span class="admin-pill admin-pill-green">Aktif</span>
                            <?php else: ?>
                                <span class="admin-pill admin-pill-gray">Pasif</span>
                            <?php endif; ?>
                        </td>
                        <td class="admin-muted"><?php echo htmlspecialchars($u['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <a href="/admin/create_user.php" class="admin-add-link">+ Yeni kullanıcı oluştur</a>
    </div>

    <div class="card">
        <div class="admin-section-header">
            <h2>Ekipler</h2>
            <span class="admin-section-count"><?php echo count($teams); ?></span>
        </div>
        <?php
        $roleColors = array('owner' => 'blue', 'editor' => 'green', 'commenter' => 'amber', 'viewer' => 'gray');
        ?>
        <?php foreach ($teams as $t): ?>
            <div class="admin-team-block">
                <div class="admin-team-header">
                    <h3><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <?php if (!empty($membersByTeam[$t['id']])): ?>
                    <table class="admin-table">
                        <thead>
                            <tr><th>Üye</th><th>Rol</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($membersByTeam[$t['id']] as $m): ?>
                                <tr>
                                    <td>
                                        <div class="admin-user-cell">
                                            <div class="admin-avatar"><?php echo htmlspecialchars(bcc_user_initial($m), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div>
                                                <div class="admin-user-name"><?php echo htmlspecialchars($m['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="admin-user-email"><?php echo htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="admin-pill admin-pill-<?php echo isset($roleColors[$m['role']]) ? $roleColors[$m['role']] : 'gray'; ?>">
                                            <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$m['role']], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="admin-empty">Bu ekipte henüz üye yok.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="admin-actions-row">
            <a href="/admin/create_team.php" class="admin-add-link">+ Yeni ekip oluştur</a>
            <a href="/admin/assign_team.php" class="admin-add-link">Kullanıcıyı ekibe ata</a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../src/partials/footer.php'; ?>
