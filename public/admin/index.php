<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];

$error = null;
$success = null;

// Kullanıcı/ekip-üyeliği toplu işlemleri — hem "..." satır menüsü hem de
// tablo altındaki "İşlemler" (bulk) menüsü AYNI action isimlerini ve AYNI
// user_ids[] listesini POST eder, tek yerde işlenir (iki ayrı uç nokta yok).
// Admin'in kendi hesabını admin'likten düşürmesi / pasif yapması KASITLI
// olarak engellenir (Airtable admin panelindeki aynı kural), sessizce
// listeden çıkarılır — işlem geri kalan seçili kullanıcılar için devam eder.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $userIds = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : array();
    $userIds = array_values(array_unique(array_filter($userIds, function ($id) {
        return $id > 0;
    })));

    if (empty($userIds)) {
        $error = 'En az bir kullanıcı seçin.';
    } elseif ($action === 'grant_admin' || $action === 'revoke_admin') {
        $applyIds = $userIds;
        $skippedSelf = false;

        if ($action === 'revoke_admin' && in_array($currentUserId, $applyIds, true)) {
            $applyIds = array_values(array_diff($applyIds, array($currentUserId)));
            $skippedSelf = true;
        }

        if (!empty($applyIds)) {
            $placeholders = implode(',', array_fill(0, count($applyIds), '?'));
            $newValue = $action === 'grant_admin' ? 1 : 0;
            bcc_execute("UPDATE users SET is_admin = ? WHERE id IN ($placeholders)", array_merge(array($newValue), $applyIds));
            log_audit($action === 'grant_admin' ? 'user.grant_admin' : 'user.revoke_admin', 'user', null, array('user_ids' => $applyIds));
        }

        $success = $skippedSelf ? 'Güncellendi (kendi admin yetkinizi kaldıramazsınız, atlandı).' : 'Güncellendi.';
    } elseif ($action === 'activate' || $action === 'deactivate') {
        $applyIds = $userIds;
        $skippedSelf = false;

        if ($action === 'deactivate' && in_array($currentUserId, $applyIds, true)) {
            $applyIds = array_values(array_diff($applyIds, array($currentUserId)));
            $skippedSelf = true;
        }

        if (!empty($applyIds)) {
            $placeholders = implode(',', array_fill(0, count($applyIds), '?'));
            $newValue = $action === 'activate' ? 1 : 0;
            bcc_execute("UPDATE users SET is_active = ? WHERE id IN ($placeholders)", array_merge(array($newValue), $applyIds));
            log_audit($action === 'activate' ? 'user.activate' : 'user.deactivate', 'user', null, array('user_ids' => $applyIds));
        }

        $success = $skippedSelf ? 'Güncellendi (kendi hesabınızı pasif yapamazsınız, atlandı).' : 'Güncellendi.';
    } elseif ($action === 'remove_from_team') {
        $teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;

        if ($teamId <= 0) {
            $error = 'Geçersiz ekip.';
        } else {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            bcc_execute("DELETE FROM team_members WHERE team_id = ? AND user_id IN ($placeholders)", array_merge(array($teamId), $userIds));
            log_audit('team_member.remove', 'team', $teamId, array('user_ids' => $userIds));
            $success = 'Ekipten çıkarıldı.';
        }
    } else {
        $error = 'Geçersiz işlem.';
    }
}

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

        <?php require __DIR__ . '/../../src/partials/flash.php'; ?>

        <div class="admin-toolbar">
            <input type="text" class="admin-search-input" id="admin-users-search" placeholder="İsim veya e-posta ara..." autocomplete="off">
            <a href="/admin/export_users_csv.php" class="admin-csv-link">CSV indir</a>
        </div>

        <form id="admin-users-bulk-form" method="post" action="/admin/index.php">
            <?php echo csrf_field(); ?>
        </form>

        <table class="admin-table" id="admin-users-table">
            <thead>
                <tr>
                    <th class="admin-checkbox-col"><input type="checkbox" class="admin-select-all" aria-label="Tümünü seç"></th>
                    <th>Kullanıcı</th><th>Yetki</th><th>Durum</th><th>Oluşturuldu</th>
                    <th class="admin-actions-col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): $uid = (int) $u['id']; $isSelf = $uid === $currentUserId; ?>
                    <tr data-user-search="<?php echo htmlspecialchars(mb_strtolower($u['full_name'] . ' ' . $u['email'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                        <td class="admin-checkbox-cell">
                            <input type="checkbox" name="user_ids[]" value="<?php echo $uid; ?>" form="admin-users-bulk-form" class="admin-row-checkbox">
                        </td>
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
                        <td class="admin-actions-cell">
                            <details class="admin-menu">
                                <summary class="admin-row-menu-btn" aria-label="İşlemler">&#8942;</summary>
                                <div class="admin-menu-panel">
                                    <?php if ((int) $u['is_admin'] === 1): ?>
                                        <form method="post" action="/admin/index.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="revoke_admin">
                                            <input type="hidden" name="user_ids[]" value="<?php echo $uid; ?>">
                                            <button type="submit" class="admin-menu-item"<?php echo $isSelf ? ' disabled title="Kendi admin yetkinizi kaldıramazsınız"' : ''; ?>>Admin yetkisini kaldır</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/admin/index.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="grant_admin">
                                            <input type="hidden" name="user_ids[]" value="<?php echo $uid; ?>">
                                            <button type="submit" class="admin-menu-item">Admin yap</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int) $u['is_active'] === 1): ?>
                                        <form method="post" action="/admin/index.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="user_ids[]" value="<?php echo $uid; ?>">
                                            <button type="submit" class="admin-menu-item"<?php echo $isSelf ? ' disabled title="Kendi hesabınızı pasif yapamazsınız"' : ''; ?>>Pasif yap</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/admin/index.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="user_ids[]" value="<?php echo $uid; ?>">
                                            <button type="submit" class="admin-menu-item">Aktif yap</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="admin-bulk-bar">
            <details class="admin-menu">
                <summary class="admin-bulk-trigger">İşlemler &#9662;</summary>
                <div class="admin-menu-panel">
                    <button type="submit" form="admin-users-bulk-form" name="action" value="grant_admin" class="admin-menu-item">Admin yap</button>
                    <button type="submit" form="admin-users-bulk-form" name="action" value="revoke_admin" class="admin-menu-item">Admin yetkisini kaldır</button>
                    <button type="submit" form="admin-users-bulk-form" name="action" value="activate" class="admin-menu-item">Aktif yap</button>
                    <button type="submit" form="admin-users-bulk-form" name="action" value="deactivate" class="admin-menu-item">Pasif yap</button>
                </div>
            </details>
        </div>

        <a href="/admin/create_user.php" class="admin-add-link">+ Yeni kullanıcı oluştur</a>
    </div>

    <div class="card">
        <div class="admin-section-header">
            <h2>Ekipler</h2>
            <span class="admin-section-count"><?php echo count($teams); ?></span>
        </div>

        <div class="admin-toolbar admin-toolbar-end">
            <a href="/admin/export_team_members_csv.php" class="admin-csv-link">CSV indir</a>
        </div>

        <?php
        $roleColors = array('owner' => 'blue', 'editor' => 'green', 'commenter' => 'amber', 'viewer' => 'gray');
        ?>
        <?php foreach ($teams as $t): $teamId = (int) $t['id']; $bulkFormId = 'admin-team-' . $teamId . '-bulk-form'; ?>
            <div class="admin-team-block">
                <div class="admin-team-header">
                    <h3><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <?php if (!empty($membersByTeam[$teamId])): ?>
                    <form id="<?php echo $bulkFormId; ?>" method="post" action="/admin/index.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remove_from_team">
                        <input type="hidden" name="team_id" value="<?php echo $teamId; ?>">
                    </form>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th class="admin-checkbox-col"><input type="checkbox" class="admin-select-all" aria-label="Tümünü seç"></th>
                                <th>Üye</th><th>Rol</th>
                                <th class="admin-actions-col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($membersByTeam[$teamId] as $m): $muid = (int) $m['user_id']; ?>
                                <tr data-user-search="<?php echo htmlspecialchars(mb_strtolower($m['full_name'] . ' ' . $m['email'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <td class="admin-checkbox-cell">
                                        <input type="checkbox" name="user_ids[]" value="<?php echo $muid; ?>" form="<?php echo $bulkFormId; ?>" class="admin-row-checkbox">
                                    </td>
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
                                    <td class="admin-actions-cell">
                                        <details class="admin-menu">
                                            <summary class="admin-row-menu-btn" aria-label="İşlemler">&#8942;</summary>
                                            <div class="admin-menu-panel">
                                                <form method="post" action="/admin/index.php" onsubmit="return confirm('Bu kullanıcıyı ekipten çıkarmak istediğinize emin misiniz?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="remove_from_team">
                                                    <input type="hidden" name="team_id" value="<?php echo $teamId; ?>">
                                                    <input type="hidden" name="user_ids[]" value="<?php echo $muid; ?>">
                                                    <button type="submit" class="admin-menu-item admin-menu-item-danger">Ekipten çıkar</button>
                                                </form>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="admin-bulk-bar">
                        <button type="submit" form="<?php echo $bulkFormId; ?>" class="admin-bulk-trigger admin-bulk-trigger-muted" onclick="return confirm('Seçili kullanıcıları bu ekipten çıkarmak istediğinize emin misiniz?');">Seçilenleri ekipten çıkar</button>
                    </div>
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
<script src="/assets/admin.js"></script>
<?php require __DIR__ . '/../../src/partials/footer.php'; ?>
