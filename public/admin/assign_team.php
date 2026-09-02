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

    if ($teamId <= 0) {
        $error = 'Geçersiz seçim.';
    } elseif (!bcc_fetch_one('SELECT id FROM teams WHERE id = :id', array('id' => $teamId))) {
        $error = 'Ekip bulunamadı.';
    } else {
        // team_members.php ve paylaşım modalı ile AYNI fonksiyon. Burada eskiden
        // aynı doğrulama + upsert + audit üçlüsü elle tekrar yazılıydı; ikisi
        // ayrışırsa yalnızca birinde düzelen bir hata diğerinde yaşamaya devam
        // ederdi (audit_log.team_id'nin NULL kalması tam olarak böyle oldu).
        // Platform admini her ekipte sanal owner'dır, rütbe buna göre veriliyor.
        $result = bcc_team_member_assign($teamId, $userId, $role, $GLOBALS['BCC_ROLE_RANK']['owner'], $roles);

        if ($result['ok']) {
            $success = 'Atama kaydedildi.';
        } else {
            $error = $result['error'];
        }
    }
}
// Sol panelin "Yıldızlılar" listesi ARTIK BURADA ÇEKİLMİYOR: kabuk
// (src/partials/home_shell_top.php) bcc_starred_bases_for_current_user()'ı
// kendisi çağırıyor — bkz. src/schema.php'deki tek kaynak notu.

$homeActiveNav = 'admin';
$homePageTitle = bcc_tab_title('Ekibe Ata');
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
