<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $name = isset($_POST['name']) ? trim($_POST['name']) : '';

    if ($name === '') {
        $error = 'Ekip adı boş olamaz.';
    } else {
        $pdo = bcc_get_pdo();
        $stmt = $pdo->prepare('SELECT id FROM teams WHERE name = :name');
        $stmt->execute(array(':name' => $name));

        if ($stmt->fetch()) {
            $error = 'Bu isimde bir ekip zaten var.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO teams (name) VALUES (:name)');
            $stmt->execute(array(':name' => $name));
            $newId = $pdo->lastInsertId();
            log_audit('team.create', 'team', $newId, array('name' => $name));
            $success = 'Ekip oluşturuldu: ' . $name;
        }
    }
}
$pageTitle = 'Yeni Ekip';
require __DIR__ . '/../../src/partials/header.php';
require __DIR__ . '/../../src/partials/top_nav.php';
?>
<div class="page">
    <h1>Yeni Ekip Oluştur</h1>
    <p><a href="/admin/index.php">&larr; Admin paneline dön</a></p>

    <div class="card">
        <?php if ($error !== null): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($success !== null): ?>
            <p class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <form class="stacked" method="post" action="/admin/create_team.php">
            <?php echo csrf_field(); ?>
            <label>Ekip adı
                <input type="text" name="name" required>
            </label>
            <button type="submit">Oluştur</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../src/partials/footer.php'; ?>
