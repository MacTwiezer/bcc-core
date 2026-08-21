<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();
$user = current_user();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $name = isset($_POST['name']) ? trim($_POST['name']) : '';

    // Doğrulama + INSERT + oluşturanı 'owner' üye yapma TEK YERDE:
    // bcc_create_team() (bkz. src/schema.php). api/team_create.php de AYNI
    // fonksiyonu çağırıyor — bases.php / api/base_create.php ile aynı desen.
    try {
        $result = bcc_create_team($name, $user['id']);
    } catch (Throwable $e) {
        $result = array('ok' => false, 'error' => 'Kaydedilemedi (veritabanı hatası).', 'id' => null);
    }

    if ($result['ok']) {
        $success = 'Ekip oluşturuldu: ' . $name;
        // Bir sonraki ekip için formu temizle.
        $name = '';
    } else {
        $error = $result['error'];
    }
}
// Sol panelin "Yıldızlılar" listesi ARTIK BURADA ÇEKİLMİYOR: kabuk
// (src/partials/home_shell_top.php) bcc_starred_bases_for_current_user()'ı
// kendisi çağırıyor — bkz. src/schema.php'deki tek kaynak notu.

$homeActiveNav = 'admin';
$homePageTitle = bcc_brand_domain() . ' — Yeni Ekip';
require __DIR__ . '/../../src/partials/home_shell_top.php';
?>
        <div class="settings-breadcrumb">
            <a href="/admin/index.php">&larr; Admin paneline dön</a>
        </div>
        <div class="home-main-header">
            <h1>Yeni Ekip Oluştur</h1>
        </div>

        <div class="settings-card">
            <?php require __DIR__ . '/../../src/partials/flash.php'; ?>
            <form class="settings-form settings-form-stacked" method="post" action="/admin/create_team.php">
                <?php echo csrf_field(); ?>
                <label class="settings-field">Ekip adı
                    <input type="text" name="name" value="<?php echo htmlspecialchars(isset($name) ? $name : '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <div class="settings-form-actions">
                    <button type="submit" class="settings-btn settings-btn-primary">Oluştur</button>
                    <a href="/admin/index.php" class="settings-cancel-link">İptal</a>
                </div>
            </form>
        </div>
<?php require __DIR__ . '/../../src/partials/home_shell_bottom.php'; ?>
