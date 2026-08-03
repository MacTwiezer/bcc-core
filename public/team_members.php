<?php
// F10 (owner/editor/commenter/viewer izin seviyeleri) — takımın HER üyesi için
// (owner'a özel değil) kendi rütbesi ve ALTINDAKİ (eşit dahil) kullanıcıları
// yönetebildiği üye/rol yönetimi. admin/assign_team.php'nin (platform admin,
// TÜM takımlar) YANINDA, ondan BAĞIMSIZ ek bir yetki katmanı — o dosyaya
// dokunulmadı, hâlâ her şeyi atayabiliyor. Airtable'ın "invite/assign at or
// below your permission level" kuralı (support.airtable.com/docs/base-permissions,
// "Invite users at or below your permission level" ✅ dört rolde de +
// managing-billable-collaborators FAQ: "can also add collaborators at the SAME
// or a lower permission level") burada BCC_ROLE_RANK üzerinden uygulanıyor —
// eşit rütbe DAHİL. Erişim: workspaces.php'nin "Paylaş" butonu artık takımın
// her üyesine aktif link olarak gösteriliyor (require_team_access zaten en dış
// koruma); sayfa girişte çağıranın GERÇEK rütbesine göre neyi yönetebileceğine
// karar veriyor.

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
require_role($teamId, 'viewer');

$team = bcc_fetch_one('SELECT id, name FROM teams WHERE id = :id', array('id' => $teamId));
if (!$team) {
    http_response_code(404);
    die('Ekip bulunamadı.');
}

// Atanabilecek roller: çağıranın GERÇEK rütbesi kadar ve altı (eşit dahil) —
// require_role('viewer') dört rolün de sayfaya girmesine izin verdiği için
// artık 'owner' hardcode edilemez, current_user_role_in_team() ile okunuyor.
// bcc_assignable_roles() (src/auth.php) — grid.php'nin Paylaş popup'ıyla PAYLAŞILAN
// mantık, kopya YOK.
$myRank = $GLOBALS['BCC_ROLE_RANK'][current_user_role_in_team($teamId)];
$assignableRoles = bcc_assignable_roles($myRank);

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    if ($action === 'assign') {
        $role = isset($_POST['role']) ? $_POST['role'] : '';

        if ($targetUserId <= 0 || !in_array($role, $assignableRoles, true)) {
            $error = 'Geçersiz seçim.';
        } elseif (!bcc_fetch_one('SELECT id FROM users WHERE id = :id AND is_active = 1', array('id' => $targetUserId))) {
            $error = 'Kullanıcı bulunamadı.';
        } else {
            // admin/assign_team.php ile AYNI desen: INSERT ... ON DUPLICATE KEY
            // UPDATE hem yeni üyeliği hem mevcut bir üyenin rol değişikliğini AYNI
            // sorguyla yapar; hangisi olduğunu doğru audit action için önceden bakarak
            // biliriz (bkz. assign_team.php'deki "bulunan gerçek bug" notu — burada
            // baştan doğru).
            $existingMember = bcc_fetch_one(
                'SELECT id, role FROM team_members WHERE team_id = :team_id AND user_id = :user_id',
                array('team_id' => $teamId, 'user_id' => $targetUserId)
            );

            // Hiyerarşi kapısı SUNUCU tarafında da: sayfa artık owner-only değil,
            // UI'da salt-okunur gösterilen bir satırın Permission'ı doğrudan POST
            // ile de değiştirilemesin — hedefin ŞU ANKİ rütbesi benimkinden
            // yüksekse (rank(hedef) > rank(ben)) reddedilir, $assignableRoles
            // kontrolü yalnızca ATANACAK rolü sınırlıyordu, hedefin mevcut
            // durumunu değil.
            if ($existingMember && $GLOBALS['BCC_ROLE_RANK'][$existingMember['role']] > $myRank) {
                $error = 'Bu kullanıcıyı yönetme yetkiniz yok.';
            } else {
                bcc_execute(
                    'INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)
                     ON DUPLICATE KEY UPDATE role = VALUES(role)',
                    array('team_id' => $teamId, 'user_id' => $targetUserId, 'role' => $role)
                );
                $auditAction = $existingMember ? 'team_member.role_change' : 'team_member.assign';
                log_audit($auditAction, 'team_member', null, array('team_id' => $teamId, 'user_id' => $targetUserId, 'role' => $role), $teamId);
                $success = 'Atama kaydedildi.';
            }
        }
    } elseif ($action === 'remove' || $action === 'remove_bulk') {
        // Tek satır "Çıkar" (remove) ve toplu seçim "Çıkar" (remove_bulk) AYNI
        // sonuca varır (bir liste dolaşır) — burada tek/çoklu diye iki AYRI kod
        // yolu yok, remove tek elemanlı bir liste olarak remove_bulk'a düşer.
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
            $removedIds = array();
            $skipped = 0;

            foreach ($targetUserIds as $id) {
                if ($id === (int) $user['id']) {
                    // Kasıtlı olarak KOŞULSUZ engellenir (son owner olup olmadığına
                    // bakılmaksızın) — admin/index.php'deki "kendi hesabını pasif
                    // yapamama" korumasıyla AYNI ilke, burada da tek satırla sağlanır.
                    $skipped++;
                    continue;
                }

                $targetMember = bcc_fetch_one(
                    'SELECT role FROM team_members WHERE team_id = :team_id AND user_id = :user_id',
                    array('team_id' => $teamId, 'user_id' => $id)
                );

                if (!$targetMember) {
                    $skipped++;
                    continue;
                }

                // Aynı hiyerarşi kapısı: rank(hedef) > rank(ben) ise dokunulamaz.
                if ($GLOBALS['BCC_ROLE_RANK'][$targetMember['role']] > $myRank) {
                    $skipped++;
                    continue;
                }

                if ($targetMember['role'] === 'owner') {
                    $ownerCount = (int) bcc_fetch_column(
                        "SELECT COUNT(*) FROM team_members WHERE team_id = :team_id AND role = 'owner'",
                        array('team_id' => $teamId)
                    );
                    if ($ownerCount <= 1) {
                        $skipped++;
                        continue;
                    }
                }

                bcc_execute('DELETE FROM team_members WHERE team_id = :team_id AND user_id = :user_id', array('team_id' => $teamId, 'user_id' => $id));
                $removedIds[] = $id;
            }

            if (!empty($removedIds)) {
                // admin/index.php'nin remove_from_team action'ıyla AYNI audit action
                // adı ('team_member.remove') — tutarlılık için, iki ayrı isim yok.
                log_audit('team_member.remove', 'team', $teamId, array('user_ids' => $removedIds), $teamId);
            }

            if (empty($removedIds)) {
                $error = 'Kimse çıkarılamadı (kendiniz, son owner veya yetkiniz dışındaki bir rütbe).';
            } elseif ($skipped > 0) {
                $success = count($removedIds) . ' kişi çıkarıldı, ' . $skipped . ' kişi atlandı (kendiniz, son owner veya yetkiniz dışında).';
            } else {
                $success = count($removedIds) === 1 ? 'Ekipten çıkarıldı.' : count($removedIds) . ' kişi ekipten çıkarıldı.';
            }
        }
    } else {
        $error = 'Geçersiz işlem.';
    }
}

// workspaces.php'nin BENZER sorgusu — is_active DAHİL (aynı ders: admin bir
// kullanıcıyı pasif yaptığında team_members satırı SİLİNMEZ, pasif bir üye
// aktif bir katılımcıdan ayırt edilebilsin) + tm.created_at ("Eklenme tarihi"
// kolonu, client-side sıralanabilir).
$members = bcc_fetch_all(
    'SELECT u.id, u.full_name, u.email, u.is_active, tm.role, tm.created_at
     FROM team_members tm
     INNER JOIN users u ON u.id = tm.user_id
     WHERE tm.team_id = :team_id
     ORDER BY u.full_name',
    array('team_id' => $teamId)
);

// "Ekleyen" kolonu — bkz. src/schema.php'deki fonksiyon yorumu.
$invitedByMap = bcc_team_members_invited_by($teamId);

// admin/assign_team.php ile AYNI sorgu — yeni HESAP oluşturma YOK (create_user.php
// hâlâ yalnızca platform admin'de), yalnızca var olan aktif kullanıcılar arasından seçim.
$allUsers = bcc_fetch_all('SELECT id, email, full_name FROM users WHERE is_active = 1 ORDER BY email');

// Sol panel "Yıldızlılar" listesi — workspaces.php ile AYNI desen.
$starredBases = array();
$teamIdsForStar = current_user_team_ids();
if (!empty($teamIdsForStar)) {
    $starredPlaceholders = implode(',', array_fill(0, count($teamIdsForStar), '?'));
    $starredBases = bcc_fetch_all(
        "SELECT b.id, b.name FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($starredPlaceholders) AND b.deleted_at IS NULL
         WHERE usb.user_id = ? ORDER BY b.name",
        array_merge($teamIdsForStar, array((int) $user['id']))
    );
}

$homeActiveNav = 'workspaces';
$homePageTitle = 'BCC-Core — ' . $team['name'] . ' Üyeleri';
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1><?php echo htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8'); ?> — Üyeler</h1>
        </div>

        <div class="ws-detail tm-detail">
            <?php require __DIR__ . '/../src/partials/flash.php'; ?>

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
                    <a class="tm-export-btn" href="/api/team_members_export_csv.php?team_id=<?php echo (int) $teamId; ?>" title="CSV indir" aria-label="CSV indir">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2v8m0 0l-3-3m3 3l3-3M3 12.5h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </div>
            </div>

            <div class="tm-bulk-bar" data-tm-bulk-bar>
                <span><span data-tm-selected-count>0</span> seçili</span>
                <button type="submit" form="tm-bulk-remove-form" class="tm-remove-btn" data-tm-bulk-remove-btn disabled>Çıkar</button>
            </div>

            <div class="tm-table-wrap">
                <table class="tm-table">
                    <thead>
                        <tr>
                            <th class="tm-col-check"><input type="checkbox" data-tm-select-all aria-label="Tümünü seç"></th>
                            <th>Üye</th>
                            <th>Permission</th>
                            <th>Ekleyen</th>
                            <th class="tm-sortable" data-tm-sort-created tabindex="0" role="button">Eklenme tarihi</th>
                        </tr>
                    </thead>
                    <tbody data-tm-rows>
                        <?php foreach ($members as $m):
                            $memberRank = $GLOBALS['BCC_ROLE_RANK'][$m['role']];
                            $manageable = $memberRank <= $myRank;
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

            <form id="tm-bulk-remove-form" method="post" action="/team_members.php?team_id=<?php echo (int) $teamId; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove_bulk">
            </form>
        </div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
<script src="/assets/team-members.js"></script>
