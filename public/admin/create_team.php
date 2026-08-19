<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();
$user = current_user();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $name = isset($_POST['name']) ? trim($_POST['name']) : '';

    if ($name === '') {
        $error = 'Ekip adı boş olamaz.';
    } elseif (mb_strlen($name, 'UTF-8') > 150) {
        // teams.name VARCHAR(150) — bu kontrol olmadan uzun bir ekip adı hatasız
        // sessizce kırpılıyordu (create_user.php'deki email/full_name kontrolüyle
        // AYNI gerekçe, bkz. o dosyadaki yorum).
        $error = 'Ekip adı en fazla 150 karakter olabilir.';
    } else {
        $existing = bcc_fetch_one('SELECT id FROM teams WHERE name = :name', array('name' => $name));

        if ($existing) {
            $error = 'Bu isimde bir ekip zaten var.';
        } else {
            bcc_execute('INSERT INTO teams (name) VALUES (:name)', array('name' => $name));
            $newId = bcc_last_insert_id();
            log_audit('team.create', 'team', $newId, array('name' => $name));
            $success = 'Ekip oluşturuldu: ' . $name;
            // Bir sonraki ekip için formu temizle.
            $name = '';
        }
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
