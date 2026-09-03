<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
require_role($teamId, 'viewer');

$team = bcc_fetch_one('SELECT id, name FROM teams WHERE id = :id', array('id' => $teamId));
if (!$team) {
    bcc_error_page('Ekip bulunamadı', 'Aradığınız ekip silinmiş ya da adresi değişmiş olabilir.', 404);
}

$myRole = current_user_role_in_team($teamId);
$myRank = $GLOBALS['BCC_ROLE_RANK'][$myRole];

$canManageMembers = bcc_can_manage_members($myRole);

$assignableRoles = $canManageMembers ? bcc_assignable_roles($myRank) : array();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    if (!$canManageMembers) {
        bcc_error_page('Yetkiniz yok', 'Üye yönetimi için Owner yetkisi gerekir.', 403);
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    if ($action === 'assign') {
        $role = isset($_POST['role']) ? $_POST['role'] : '';
        $result = bcc_team_member_assign($teamId, $targetUserId, $role, $myRank, $assignableRoles);

        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            $success = 'Atama kaydedildi.';
        }
    } elseif ($action === 'remove' || $action === 'remove_bulk') {
        $targetUserIds = array();
        if ($action === 'remove_bulk') {
            $rawIds = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? $_POST['user_ids'] : array();
            foreach ($rawIds as $rawId) {
                $id = (int) $rawId;
                if ($id > 0) {
                    $targetUserIds[] = $id;
                }
            }
        } elseif ($targetUserId > 0) {
            $targetUserIds[] = $targetUserId;
        }

        if (empty($targetUserIds)) {
            $error = 'Geçersiz seçim.';
        } else {
            $removeResult = bcc_team_member_remove_many($teamId, $targetUserIds, (int) $user['id'], $myRank);
            $messages = bcc_team_member_remove_message($removeResult);
            $error = $messages['error'];
            $success = $messages['success'];
        }
    } else {
        $error = 'Geçersiz işlem.';
    }
}

$members = bcc_team_members_with_roles($teamId);

$invitedByMap = bcc_team_members_invited_by($teamId);

$allUsers = bcc_fetch_all('SELECT id, email, full_name FROM users WHERE is_active = 1 ORDER BY email');


$homeActiveNav = 'workspaces';
$homePageTitle = bcc_tab_title($team['name'] . ' Üyeleri');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1><?php echo htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8'); ?> — Üyeler</h1>
        </div>

        <div class="ws-detail tm-detail">
            <?php require __DIR__ . '/../src/partials/flash.php'; ?>

            <?php if ($canManageMembers): ?>
                <form class="tm-assign-form" method="post" action="/team_members.php?team_id=<?php echo (int) $teamId; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="assign">
                    <label class="tm-assign-field">Kullanıcı
                        <select name="user_id" required>
                            <option value="">— seçin —</option>
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?php echo (int) $u['id']; ?>">
                                    <?php echo htmlspecialchars($u['full_name'] . ' (' . $u['email'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="tm-assign-field">Rol
                        <select name="role" required>
                            <?php foreach ($assignableRoles as $r): ?>
                                <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$r], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="tm-assign-submit">Ata</button>
                </form>
            <?php else: ?>
                <p class="tm-readonly-note">
                    Bu çalışma alanındaki rolünüz
                    <strong><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$myRole], ENT_QUOTES, 'UTF-8'); ?></strong>.
                    Katılımcı listesini görüntüleyebilirsiniz; üye ekleme, rol değiştirme ve
                    çıkarma işlemleri yalnızca <strong>Owner</strong> rolüne açıktır.
                </p>
            <?php endif; ?>

            <div class="tm-toolbar">
                <h3 class="ws-collab-heading">Üyeler (<?php echo count($members); ?>)</h3>
                <div class="tm-toolbar-actions">
                    <input type="search" class="tm-search-input" placeholder="Üye ara..." data-tm-search aria-label="Üye ara">
                    <select class="tm-filter-select" data-tm-role-filter aria-label="Role göre filtrele">
                        <option value="">Tüm roller</option>
                        <?php foreach ($GLOBALS['BCC_ROLE_RANK'] as $roleName => $rank): ?>
                            <option value="<?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$roleName], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a class="tm-export-btn" href="/api/team_members_export_xlsx.php?team_id=<?php echo (int) $teamId; ?>" title="Excel indir" aria-label="Excel indir">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2v8m0 0l-3-3m3 3l3-3M3 12.5h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </div>
            </div>

            <?php if ($canManageMembers): ?>
            <div class="tm-bulk-bar" data-tm-bulk-bar>
                <span><span data-tm-selected-count>0</span> seçili</span>
                <button type="submit" form="tm-bulk-remove-form" class="tm-remove-btn" data-tm-bulk-remove-btn disabled>Çıkar</button>
            </div>
            <?php endif; ?>

            <div class="tm-table-wrap">
                <table class="tm-table">
                    <thead>
                        <tr>
                            <?php if ($canManageMembers): ?>
                            <th class="tm-col-check"><input type="checkbox" data-tm-select-all aria-label="Tümünü seç"></th>
                            <?php endif; ?>
                            <th>Üye</th>
                            <th>Permission</th>
                            <th>Ekleyen</th>
                            <th class="tm-sortable" data-tm-sort-created tabindex="0" role="button">Eklenme tarihi</th>
                        </tr>
                    </thead>
                    <tbody data-tm-rows>
                        <?php foreach ($members as $m):
                            $memberRank = $GLOBALS['BCC_ROLE_RANK'][$m['role']];
                            $manageable = $canManageMembers && $memberRank <= $myRank;
                            $isSelf = (int) $m['id'] === (int) $user['id'];
                            $invitedByName = isset($invitedByMap[(int) $m['id']]) && $invitedByMap[(int) $m['id']] !== null
                                ? $invitedByMap[(int) $m['id']]
                                : null;
                        ?>
                            <tr class="tm-row"
                                data-tm-name="<?php echo htmlspecialchars(mb_strtolower($m['full_name'] . ' ' . $m['email'], 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-tm-role="<?php echo htmlspecialchars($m['role'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-tm-created="<?php echo (int) strtotime($m['created_at']); ?>"
                            >
                                <?php if ($canManageMembers): ?>
                                <td class="tm-col-check">
                                    <input
                                        type="checkbox"
                                        name="user_ids[]"
                                        value="<?php echo (int) $m['id']; ?>"
                                        form="tm-bulk-remove-form"
                                        data-tm-row-check
                                        <?php echo (!$manageable || $isSelf) ? 'disabled' : ''; ?>
                                        <?php echo $isSelf ? 'title="Kendinizi çıkaramazsınız"' : ''; ?>
                                    >
                                </td>
                                <?php endif; ?>
                                <td class="tm-cell-member">
                                    <div class="ws-collab-avatar"><?php echo htmlspecialchars(bcc_user_initial($m), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="ws-collab-info">
                                        <div class="ws-collab-name"><?php echo htmlspecialchars($m['full_name'], ENT_QUOTES, 'UTF-8'); ?><?php echo $isSelf ? ' (siz)' : ''; ?></div>
                                        <div class="ws-collab-email"><?php echo htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <?php if ((int) $m['is_active'] !== 1): ?>
                                        <div class="ws-collab-role">Pasif</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($manageable): ?>
                                        <form class="tm-role-form" method="post" action="/team_members.php?team_id=<?php echo (int) $teamId; ?>">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="assign">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $m['id']; ?>">
                                            <select name="role" class="tm-role-select" onchange="this.form.submit()">
                                                <?php foreach ($assignableRoles as $r): ?>
                                                    <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $r === $m['role'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$r], ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    <?php else: ?>
                                        <span class="tm-role-readonly"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$m['role']], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="tm-cell-muted"><?php echo $invitedByName !== null ? htmlspecialchars($invitedByName, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                <td class="tm-cell-muted"><?php echo htmlspecialchars(date('d.m.Y', strtotime($m['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="tm-empty-state" data-tm-empty hidden>Aramayla eşleşen üye yok.</p>
            </div>

            <?php if ($canManageMembers): ?>
            <form id="tm-bulk-remove-form" method="post" action="/team_members.php?team_id=<?php echo (int) $teamId; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove_bulk">
            </form>
            <?php endif; ?>
        </div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
<script src="<?php echo bcc_asset_url('team-members.js'); ?>"></script>
