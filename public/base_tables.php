<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$pdo = bcc_get_pdo();

$baseId = isset($_GET['base_id']) ? (int) $_GET['base_id'] : (isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0);
$base = find_base_or_404($baseId);

// Her erişimde KVKK ekip izolasyonu: bu base'in ekibine üye olmayan hiçbir şey göremez.
require_team_access($base['team_id']);

$role = current_user_role_in_team($base['team_id']);
$canEdit = in_array($role, array('editor', 'owner'), true);

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    // Değiştirme yalnızca editor+ rolünde açık.
    require_role($base['team_id'], 'editor');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_table') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if ($name === '') {
            $error = 'Tablo adı boş olamaz.';
        } else {
            $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM tables_meta WHERE base_id = :base_id');
            $posStmt->execute(array(':base_id' => $base['id']));
            $nextPos = (int) $posStmt->fetch()['next_pos'];

            $stmt = $pdo->prepare(
                'INSERT INTO tables_meta (base_id, name, description, position) VALUES (:base_id, :name, :description, :position)'
            );
            $stmt->execute(array(
                ':base_id' => $base['id'],
                ':name' => $name,
                ':description' => $description !== '' ? $description : null,
                ':position' => $nextPos,
            ));
            $newId = $pdo->lastInsertId();
            log_audit('table.create', 'table', $newId, array('name' => $name, 'base_id' => $base['id']), $base['team_id']);
            $success = 'Tablo oluşturuldu: ' . $name;
        }
    } elseif ($action === 'rename_table' || $action === 'delete_table' || $action === 'move_table') {
        $tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
        $table = find_table_or_404($tableId);

        if ((int) $table['base_id'] !== (int) $base['id']) {
            http_response_code(403);
            die('Bu tablo bu base\'e ait değil.');
        }

        if ($action === 'rename_table') {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';

            if ($name === '') {
                $error = 'Tablo adı boş olamaz.';
            } else {
                $stmt = $pdo->prepare('UPDATE tables_meta SET name = :name, description = :description WHERE id = :id');
                $stmt->execute(array(
                    ':name' => $name,
                    ':description' => $description !== '' ? $description : null,
                    ':id' => $table['id'],
                ));
                log_audit('table.update', 'table', $table['id'], array('name' => $name), $base['team_id']);
                $success = 'Tablo güncellendi: ' . $name;
            }
        } elseif ($action === 'delete_table') {
            $stmt = $pdo->prepare('DELETE FROM tables_meta WHERE id = :id');
            $stmt->execute(array(':id' => $table['id']));
            log_audit('table.delete', 'table', $table['id'], array('name' => $table['name']), $base['team_id']);
            $success = 'Tablo silindi: ' . $table['name'];
        } elseif ($action === 'move_table') {
            $direction = isset($_POST['direction']) ? $_POST['direction'] : '';

            $siblingStmt = $pdo->prepare('SELECT id, position FROM tables_meta WHERE base_id = :base_id ORDER BY position, id');
            $siblingStmt->execute(array(':base_id' => $base['id']));
            $siblings = $siblingStmt->fetchAll();

            $index = null;
            foreach ($siblings as $i => $row) {
                if ((int) $row['id'] === $table['id']) {
                    $index = $i;
                    break;
                }
            }

            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index !== null && $swapWith >= 0 && $swapWith < count($siblings)) {
                $a = $siblings[$index];
                $b = $siblings[$swapWith];

                $upd = $pdo->prepare('UPDATE tables_meta SET position = :pos WHERE id = :id');
                $pdo->beginTransaction();
                $upd->execute(array(':pos' => $b['position'], ':id' => $a['id']));
                $upd->execute(array(':pos' => $a['position'], ':id' => $b['id']));
                $pdo->commit();

                log_audit('table.reorder', 'table', $table['id'], array('direction' => $direction), $base['team_id']);
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT id, name, description, position FROM tables_meta WHERE base_id = :base_id ORDER BY position, id');
$stmt->execute(array(':base_id' => $base['id']));
$tables = $stmt->fetchAll();
$pageTitle = $base['name'];
require __DIR__ . '/../src/partials/header.php';
require __DIR__ . '/../src/partials/top_nav.php';
?>
<div class="page">
    <p><a href="/bases.php">&larr; Base'lere dön</a></p>
    <h1><?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($base['description']): ?>
        <p><?php echo htmlspecialchars($base['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($success !== null): ?>
        <p class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="card">
        <h2>Tablolar (<?php echo count($tables); ?>)</h2>

        <?php if (empty($tables)): ?>
            <p>Bu base'de henüz tablo yok.</p>
        <?php else: ?>
            <table>
                <tr><th>Tablo</th><th>Açıklama</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr>
                <?php foreach ($tables as $i => $t): ?>
                    <tr>
                        <td><a href="/grid.php?table_id=<?php echo (int) $t['id']; ?>"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><?php echo htmlspecialchars((string) $t['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <?php if ($canEdit): ?>
                        <td class="row-actions">
                            <form method="post" action="/base_tables.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="move_table">
                                <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                                <input type="hidden" name="table_id" value="<?php echo (int) $t['id']; ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn-sm" <?php echo $i === 0 ? 'disabled' : ''; ?>>&uarr;</button>
                            </form>
                            <form method="post" action="/base_tables.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="move_table">
                                <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                                <input type="hidden" name="table_id" value="<?php echo (int) $t['id']; ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn-sm" <?php echo $i === count($tables) - 1 ? 'disabled' : ''; ?>>&darr;</button>
                            </form>
                            <a class="btn-sm" href="/base_tables.php?base_id=<?php echo (int) $base['id']; ?>&edit=<?php echo (int) $t['id']; ?>">Düzenle</a>
                            <form method="post" action="/base_tables.php" onsubmit="return confirm('Bu tabloyu ve içindeki tüm alanları silmek istediğinize emin misiniz?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_table">
                                <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                                <input type="hidden" name="table_id" value="<?php echo (int) $t['id']; ?>">
                                <button type="submit" class="btn-sm btn-danger">Sil</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($canEdit):
        $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $editTable = null;
        if ($editId > 0) {
            foreach ($tables as $t) {
                if ((int) $t['id'] === $editId) {
                    $editTable = $t;
                    break;
                }
            }
        }
    ?>
        <?php if ($editTable): ?>
            <div class="card">
                <h2>Tabloyu Düzenle: <?php echo htmlspecialchars($editTable['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <form class="stacked" method="post" action="/base_tables.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="rename_table">
                    <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                    <input type="hidden" name="table_id" value="<?php echo (int) $editTable['id']; ?>">
                    <label>Tablo adı
                        <input type="text" name="name" value="<?php echo htmlspecialchars($editTable['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <label>Açıklama (opsiyonel)
                        <input type="text" name="description" value="<?php echo htmlspecialchars((string) $editTable['description'], ENT_QUOTES, 'UTF-8'); ?>">
                    </label>
                    <button type="submit">Kaydet</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Yeni Tablo</h2>
            <form class="stacked" method="post" action="/base_tables.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_table">
                <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                <label>Tablo adı
                    <input type="text" name="name" required>
                </label>
                <label>Açıklama (opsiyonel)
                    <input type="text" name="description">
                </label>
                <button type="submit">Tablo Oluştur</button>
            </form>
        </div>
    <?php else: ?>
        <p class="hint">Bu ekipte tablo oluşturmak/düzenlemek için editor veya owner rolü gerekir.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
