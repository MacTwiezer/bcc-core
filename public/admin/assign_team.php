<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();
$user = current_user();

$users = bcc_fetch_all('SELECT id, email, full_name FROM users WHERE is_active = 1 ORDER BY email');
$teams = bcc_fetch_all('SELECT id, name FROM teams ORDER BY name');

$error = null;
$success = null;
// Canonical rol listesi BCC_ROLE_RANK'ten (src/auth.php) — burada elle tekrar
// yazılmaz, yeni bir rol eklenirse otomatik yansır.
$roles = array_keys($GLOBALS['BCC_ROLE_RANK']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
    $role = isset($_POST['role']) ? $_POST['role'] : '';

    if ($userId <= 0 || $teamId <= 0 || !in_array($role, $roles, true)) {
        $error = 'Geçersiz seçim.';
    } elseif (!bcc_fetch_one('SELECT id FROM users WHERE id = :id AND is_active = 1', array('id' => $userId))) {
        $error = 'Kullanıcı bulunamadı.';
    } elseif (!bcc_fetch_one('SELECT id FROM teams WHERE id = :id', array('id' => $teamId))) {
        $error = 'Ekip bulunamadı.';
    } else {
        // INSERT ... ON DUPLICATE KEY UPDATE hem yeni üyeliği hem de mevcut bir
        // üyenin rol değişikliğini AYNI sorguyla yapar — hangisi olduğunu ancak
        // önceden bakarak biliriz (bulunan gerçek bug: bu ayrım yapılmadığı için
        // salt rol değişikliğinde bile bildirim "ekibe yeni bir üye ekledi"
        // diyordu, bkz. src/audit.php'deki team_member.role_change case'i).
        $existingMember = bcc_fetch_one(
            'SELECT id FROM team_members WHERE team_id = :team_id AND user_id = :user_id',
            array('team_id' => $teamId, 'user_id' => $userId)
        );

        bcc_execute(
            'INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)
             ON DUPLICATE KEY UPDATE role = VALUES(role)',
            array('team_id' => $teamId, 'user_id' => $userId, 'role' => $role)
        );
        // $teamId 5. parametre olarak GEÇİLMELİ — audit_log.team_id kolonu bu
        // olmadan NULL kalır (bulunan gerçek bug: team_member.assign zaten
        // bildirim beyaz listesinde ama team_id NULL olduğu için `WHERE team_id
        // IN (...)` filtresine hiçbir zaman uymuyordu, bildirim hiç görünmüyordu).
        $auditAction = $existingMember ? 'team_member.role_change' : 'team_member.assign';
        log_audit($auditAction, 'team_member', null, array('team_id' => $teamId, 'user_id' => $userId, 'role' => $role), $teamId);
        $success = 'Atama kaydedildi.';
    }
}
// Sol panelin "Yıldızlılar" listesi ARTIK BURADA ÇEKİLMİYOR: kabuk
// (src/partials/home_shell_top.php) bcc_starred_bases_for_current_user()'ı
// kendisi çağırıyor — bkz. src/schema.php'deki tek kaynak notu.

$homeActiveNav = 'admin';
$homePageTitle = bcc_brand_domain() . ' — Ekibe Ata';
require __DIR__ . '/../../src/partials/home_shell_top.php';
?>
        <div class="settings-breadcrumb">
            <a href="/admin/index.php">&larr; Admin paneline dön</a>
        </div>
        <div class="home-main-header">
            <h1>Kullanıcıyı Ekibe Ata</h1>
        </div>

        <div class="settings-card">
            <?php require __DIR__ . '/../../src/partials/flash.php'; ?>
            <form class="settings-form settings-form-stacked" method="post" action="/admin/assign_team.php">
                <?php echo csrf_field(); ?>
                <label class="settings-field">Kullanıcı
                    <select name="user_id" required>
                        <option value="">— seçin —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int) $u['id']; ?>">
                                <?php echo htmlspecialchars($u['full_name'] . ' (' . $u['email'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="settings-field">Ekip
                    <select name="team_id" required>
                        <option value="">— seçin —</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>">
                                <?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="settings-field">Rol
                    <select name="role" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$r], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="settings-btn settings-btn-primary">Ata</button>
            </form>
        </div>
<?php require __DIR__ . '/../../src/partials/home_shell_bottom.php'; ?>
