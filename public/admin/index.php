<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];
$user = $currentUser;

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $userIds = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : array();
    $userIds = array_values(array_unique(array_filter($userIds, function ($id) {
        return $id > 0;
    })));

    /* Calisma alanini silme kullanici SECIMI istemiyor, bu yuzden asagidaki
       "en az bir kullanici secin" kapisinin ONUNDE duruyor (2026-09-22).
       Islem geri alinamaz: teams satiri silinince bases -> tables -> records
       zinciri FK ile gider, audit_log/record_view_log'un team_id'si NULL'a
       duser (gecmis kalir), kullanici HESAPLARI silinmez. */
    if ($action === 'delete_team') {
        $teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
        $team = $teamId > 0
            ? bcc_fetch_one('SELECT id, name FROM teams WHERE id = :id LIMIT 1', array('id' => $teamId))
            : false;

        if (!$team) {
            $error = 'Geçersiz çalışma alanı.';
        } else {
            $baseCount = (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :t', array('t' => $teamId));
            $memberCount = (int) bcc_fetch_column('SELECT COUNT(*) FROM team_members WHERE team_id = :t', array('t' => $teamId));

            $attachmentPaths = array();
            foreach (bcc_fetch_all(
                'SELECT a.stored_name
                 FROM attachments a
                 INNER JOIN records r ON r.id = a.record_id
                 INNER JOIN tables_meta tm ON tm.id = r.table_id
                 INNER JOIN bases b ON b.id = tm.base_id
                 WHERE b.team_id = :t',
                array('t' => $teamId)
            ) as $row) {
                $attachmentPaths[] = bcc_attachment_storage_path($row['stored_name']);
            }

            $imagePath = bcc_team_image_path($teamId);

            try {
                bcc_begin_transaction();
                /* Denetim kaydi silmeden ONCE: FK ON DELETE SET NULL sayesinde
                   satir kalir, yalnizca team_id NULL olur. */
                log_audit('team.delete', 'team', $teamId, array(
                    'name' => $team['name'],
                    'bases' => $baseCount,
                    'members' => $memberCount,
                    'via' => 'admin',
                ), $teamId);
                bcc_execute('DELETE FROM teams WHERE id = :id', array('id' => $teamId));
                bcc_commit();

                foreach ($attachmentPaths as $path) {
                    if (is_file($path)) { @unlink($path); }
                }
                if (is_file($imagePath)) { @unlink($imagePath); }

                $success = '"' . $team['name'] . '" çalışma alanı silindi'
                    . ($baseCount > 0 ? ' (' . $baseCount . ' base ile birlikte)' : '') . '.';
            } catch (Throwable $e) {
                bcc_rollback();
                $error = 'Çalışma alanı silinemedi (veritabanı hatası).';
            }
        }
    } elseif (empty($userIds)) {
        $error = 'En az bir kullanıcı seçin.';
    } elseif ($action === 'grant_admin' || $action === 'revoke_admin') {
        $applyIds = $userIds;
        $skippedSelf = false;

        if ($action === 'revoke_admin' && in_array($currentUserId, $applyIds, true)) {
            $applyIds = array_values(array_diff($applyIds, array($currentUserId)));
            $skippedSelf = true;
        }

        if (empty($applyIds)) {
            $success = 'Hiçbir şey güncellenmedi (kendi admin yetkinizi kendiniz kaldıramazsınız).';
        } else {
            $placeholders = implode(',', array_fill(0, count($applyIds), '?'));
            $newValue = $action === 'grant_admin' ? 1 : 0;
            bcc_execute("UPDATE users SET is_admin = ? WHERE id IN ($placeholders)", array_merge(array($newValue), $applyIds));
            log_audit($action === 'grant_admin' ? 'user.grant_admin' : 'user.revoke_admin', 'user', null, array('user_ids' => $applyIds));
            $success = $skippedSelf ? 'Güncellendi (kendi admin yetkinizi kaldıramazsınız, atlandı).' : 'Güncellendi.';
        }
    } elseif ($action === 'activate' || $action === 'deactivate') {
        $applyIds = $userIds;
        $skippedSelf = false;

        if ($action === 'deactivate' && in_array($currentUserId, $applyIds, true)) {
            $applyIds = array_values(array_diff($applyIds, array($currentUserId)));
            $skippedSelf = true;
        }

        if (empty($applyIds)) {
            $success = 'Hiçbir şey güncellenmedi (kendi hesabınızı kendiniz pasif yapamazsınız).';
        } else {
            $placeholders = implode(',', array_fill(0, count($applyIds), '?'));
            $newValue = $action === 'activate' ? 1 : 0;
            bcc_execute("UPDATE users SET is_active = ? WHERE id IN ($placeholders)", array_merge(array($newValue), $applyIds));
            log_audit($action === 'activate' ? 'user.activate' : 'user.deactivate', 'user', null, array('user_ids' => $applyIds));
            $success = $skippedSelf ? 'Güncellendi (kendi hesabınızı pasif yapamazsınız, atlandı).' : 'Güncellendi.';
        }
    } elseif ($action === 'remove_from_team') {
        $teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;

        if ($teamId <= 0) {
            $error = 'Geçersiz ekip.';
        } else {
            $result = bcc_team_member_remove_many(
                $teamId,
                $userIds,
                $currentUserId,
                $GLOBALS['BCC_ROLE_RANK']['owner']
            );

            $message = bcc_team_member_remove_message($result);
            $error = $message['error'];
            $success = $message['success'];
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

$homeActiveNav = 'admin';
$homePageTitle = bcc_tab_title('Admin');
require __DIR__ . '/../../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1>Admin</h1>
        </div>

        <?php

        $bccOnlineUsers = bcc_online_users();
        ?>
        <div class="settings-card">
            <div class="admin-section-header">
                <h2>Şu an çevrimiçi</h2>
                <span class="admin-section-count"><?php echo count($bccOnlineUsers); ?></span>
            </div>

            <?php if (!$bccOnlineUsers): ?>
                <?php
                      ?>
                <p class="admin-muted">Son <?php echo (int) BCC_PRESENCE_WINDOW_MINUTES; ?> dakika içinde etkin olan kullanıcı yok.</p>
            <?php else: ?>
                <ul class="admin-online-list">
                    <?php foreach ($bccOnlineUsers as $bccOu): ?>
                        <li class="admin-online-item">
                            <div class="admin-avatar"><?php echo bcc_avatar_inner_for($bccOu['id'], $bccOu['full_name']); ?></div>
                            <div class="admin-online-info">
                                <div class="admin-user-name" title="<?php echo htmlspecialchars($bccOu['full_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bccOu['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="admin-user-email" title="<?php echo htmlspecialchars($bccOu['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bccOu['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <?php

                                  ?>
                            <span class="admin-online-when"><?php echo htmlspecialchars(bcc_time_ago($bccOu['last_activity_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="settings-card">
            <div class="admin-section-header">
            <h2>Kullanıcılar</h2>
            <span class="admin-section-count"><?php echo count($users); ?></span>
        </div>

        <?php require __DIR__ . '/../../src/partials/flash.php'; ?>

        <div class="admin-toolbar">
            <input type="text" class="admin-search-input" id="admin-users-search" placeholder="İsim veya e-posta ara..." autocomplete="off">
            <a href="/admin/export_users_xlsx.php" class="admin-xlsx-link">Excel indir</a>
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
                                <div class="admin-avatar"><?php echo bcc_avatar_inner_for($uid, $u['full_name']); ?></div>
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

        <div class="settings-card">
            <div class="admin-section-header">
            <h2>Ekipler</h2>
            <span class="admin-section-count"><?php echo count($teams); ?></span>
        </div>

        <div class="admin-toolbar admin-toolbar-end">
            <a href="/admin/export_team_members_xlsx.php" class="admin-xlsx-link">Excel indir</a>
        </div>

        <?php
        $roleColors = array('owner' => 'blue', 'editor' => 'green', 'commenter' => 'amber', 'viewer' => 'gray');
        ?>
        <?php foreach ($teams as $t): $teamId = (int) $t['id']; $bulkFormId = 'admin-team-' . $teamId . '-bulk-form'; ?>
            <div class="admin-team-block">
                <div class="admin-team-header">
                    <h3><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <?php
                    /* 2026-09-22: calisma alanini silme. Onay kutusu
                       confirm-modal.js'in form[data-confirm] kancasindan gelir;
                       ayni kanca ekipten cikarma formlarinda da kullaniliyor. */
                    $teamBaseCount = (int) bcc_fetch_column('SELECT COUNT(*) FROM bases WHERE team_id = :t', array('t' => $teamId));
                    $teamMemberCount = isset($membersByTeam[$teamId]) ? count($membersByTeam[$teamId]) : 0;
                    $teamDeleteMessage = '"' . $t['name'] . '" çalışma alanı silinecek.'
                        . ($teamBaseCount > 0
                            ? ' İçindeki ' . $teamBaseCount . ' base ve onlara bağlı bütün tablolar, kayıtlar ve ekler de silinecek.'
                            : '')
                        . ($teamMemberCount > 0
                            ? ' ' . $teamMemberCount . ' katılımcının bu alandaki üyeliği kalkacak (kullanıcı hesapları silinmez).'
                            : '')
                        . ' Bu işlem geri alınamaz.';
                    ?>
                    <form method="post" action="/admin/index.php" class="admin-team-delete-form"
                          data-confirm="<?php echo htmlspecialchars($teamDeleteMessage, ENT_QUOTES, 'UTF-8'); ?>"
                          data-confirm-title="Çalışma alanını sil"
                          data-confirm-label="Evet, sil">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_team">
                        <input type="hidden" name="team_id" value="<?php echo $teamId; ?>">
                        <button type="submit" class="admin-team-delete-btn">Çalışma alanını sil</button>
                    </form>
                </div>
                <?php if (!empty($membersByTeam[$teamId])): ?>
                    <form id="<?php echo $bulkFormId; ?>" method="post" action="/admin/index.php"
                          data-confirm="Seçili kullanıcıları bu ekipten çıkarmak istediğinize emin misiniz?"
                          data-confirm-title="Ekipten çıkar"
                          data-confirm-label="Evet, çıkar">
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
                                            <div class="admin-avatar"><?php echo bcc_avatar_inner_for($muid, $m['full_name']); ?></div>
                                            <div>
                                                <div class="admin-user-name"><?php echo htmlspecialchars($m['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="admin-user-email"><?php echo htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="admin-pill admin-pill-<?php echo isset($roleColors[$m['role']]) ? $roleColors[$m['role']] : 'gray'; ?>">
                                            <?php echo htmlspecialchars(isset($GLOBALS['BCC_ROLE_LABELS'][$m['role']]) ? $GLOBALS['BCC_ROLE_LABELS'][$m['role']] : $m['role'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td class="admin-actions-cell">
                                        <details class="admin-menu">
                                            <summary class="admin-row-menu-btn" aria-label="İşlemler">&#8942;</summary>
                                            <div class="admin-menu-panel">
                                                <form method="post" action="/admin/index.php"
                                                      data-confirm="Bu kullanıcıyı ekipten çıkarmak istediğinize emin misiniz?"
                                                      data-confirm-title="Ekipten çıkar"
                                                      data-confirm-label="Evet, çıkar">
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
                        <button type="submit" form="<?php echo $bulkFormId; ?>" class="admin-bulk-trigger admin-bulk-trigger-muted">Seçilenleri ekipten çıkar</button>
                    </div>
                <?php else: ?>
                    <p class="admin-empty">Bu ekipte henüz üye yok.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="admin-actions-row">
            <?php

                  ?>
            <a href="/admin/create_team.php" class="admin-add-link" data-create-team-btn>+ Yeni ekip oluştur</a>
            <a href="/admin/assign_team.php" class="admin-add-link" data-assign-team-btn>Kullanıcıyı ekibe ata</a>
        </div>
        </div>
<?php ?>
<?php require __DIR__ . '/../../src/partials/create_team_modal.php'; ?>
<script src="<?php echo bcc_asset_url('create-team-modal.js'); ?>" defer></script>
<?php require __DIR__ . '/../../src/partials/assign_team_modal.php'; ?>
<script src="<?php echo bcc_asset_url('assign-team-modal.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('admin.js'); ?>"></script>
<?php require __DIR__ . '/../../src/partials/home_shell_bottom.php'; ?>
