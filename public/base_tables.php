<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$baseId = isset($_GET['base_id']) ? (int) $_GET['base_id'] : (isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0);
$base = find_base_or_404($baseId);

// Her erişimde KVKK ekip izolasyonu: bu base'in ekibine üye olmayan hiçbir şey göremez.
require_team_access($base['team_id']);

$role = current_user_role_in_team($base['team_id']);
$canEdit = ($role === 'owner');

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    // Değiştirme yalnızca owner rolünde açık.
    require_role($base['team_id'], 'owner');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_table') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if ($name === '') {
            $error = 'Tablo adı boş olamaz.';
        } elseif (mb_strlen($name, 'UTF-8') > 150) {
            // tables_meta.name VARCHAR(150) — bu kontrol olmadan uzun bir tablo adı
            // hatasız sessizce kırpılıyordu (create_team.php/create_user.php'deki
            // AYNI gerekçe, sql_mode'da STRICT_TRANS_TABLES kapalı olduğu için
            // MySQL hata vermeden kesiyor).
            $error = 'Tablo adı en fazla 150 karakter olabilir.';
        } elseif (mb_strlen($description, 'UTF-8') > 500) {
            // tables_meta.description VARCHAR(500) — aynı sessiz kırpılma riski.
            $error = 'Açıklama en fazla 500 karakter olabilir.';
        } else {
            $nextPos = (int) bcc_fetch_column(
                'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM tables_meta WHERE base_id = :base_id',
                array('base_id' => $base['id'])
            );

            // INSERT + log_audit AYNI transaction'da — view_create.php/
            // record_add.php/table_clear_data.php'de bulunan AYNI sınıf bug:
            // ikisi ayrı commit edilseydi, log_audit() bir istisna atarsa
            // (nadir ama mümkün) tablo satırı ZATEN yazılmış olurdu ve
            // kullanıcı "Veritabanı hatası" görüp tekrar denerken ikinci bir
            // tablo oluştururdu. Bu dosya o düzeltmeden pay almamıştı.
            try {
                bcc_begin_transaction();

                bcc_execute(
                    'INSERT INTO tables_meta (base_id, name, description, position) VALUES (:base_id, :name, :description, :position)',
                    array(
                        'base_id' => $base['id'],
                        'name' => $name,
                        'description' => $description !== '' ? $description : null,
                        'position' => $nextPos,
                    )
                );
                $newId = bcc_last_insert_id();
                log_audit('table.create', 'table', $newId, array('name' => $name, 'base_id' => $base['id']), $base['team_id']);

                bcc_commit();
                $success = 'Tablo oluşturuldu: ' . $name;
            } catch (Throwable $e) {
                bcc_rollback();
                $error = 'Tablo oluşturulamadı (veritabanı hatası).';
            }
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
            } elseif (mb_strlen($name, 'UTF-8') > 150) {
                $error = 'Tablo adı en fazla 150 karakter olabilir.';
            } elseif (mb_strlen($description, 'UTF-8') > 500) {
                $error = 'Açıklama en fazla 500 karakter olabilir.';
            } else {
                // UPDATE + log_audit AYNI transaction'da — create_table/
                // delete_table ile AYNI gerekçe.
                try {
                    bcc_begin_transaction();

                    bcc_execute(
                        'UPDATE tables_meta SET name = :name, description = :description WHERE id = :id',
                        array(
                            'name' => $name,
                            'description' => $description !== '' ? $description : null,
                            'id' => $table['id'],
                        )
                    );
                    log_audit('table.update', 'table', $table['id'], array('name' => $name), $base['team_id']);

                    bcc_commit();
                    $success = 'Tablo güncellendi: ' . $name;
                } catch (Throwable $e) {
                    bcc_rollback();
                    $error = 'Tablo güncellenemedi (veritabanı hatası).';
                }
            }
        } elseif ($action === 'delete_table') {
            // DELETE + log_audit AYNI transaction'da (create_table ile AYNI
            // gerekçe). Burada ekstra önemli: tables_meta silinince fields/
            // records/views/cell_values CASCADE ile gidiyor — audit satırı
            // yazılamazsa geriye "neyin silindiğini söyleyen hiçbir kayıt
            // olmadan yok olmuş bir tablo" kalırdı.
            try {
                bcc_begin_transaction();

                bcc_execute('DELETE FROM tables_meta WHERE id = :id', array('id' => $table['id']));
                log_audit('table.delete', 'table', $table['id'], array('name' => $table['name']), $base['team_id']);

                bcc_commit();
                $success = 'Tablo silindi: ' . $table['name'];
            } catch (Throwable $e) {
                bcc_rollback();
                $error = 'Tablo silinemedi (veritabanı hatası).';
            }
        } elseif ($action === 'move_table') {
            $direction = isset($_POST['direction']) ? $_POST['direction'] : '';

            // İKİ UPDATE + log_audit TEK transaction'da. bcc_reorder_sibling()
            // artık kendi transaction'ını AÇMIYOR (iç içe transaction mysqli'de
            // desteklenmiyor, içteki commit dıştakini erkenden commit ederdi) —
            // sözleşme gereği transaction'ı ÇAĞIRAN açar.
            //
            // Kritik senaryo: takasın iki UPDATE'i yarım kalırsa iki satır AYNI
            // position'da kalırdı. Ayrıca eskiden log_audit() commit'ten SONRA
            // çalışıyordu, yani "sırası değişmiş ama hiçbir izi olmayan" satır
            // mümkündü. İkisi de artık aynı sınırın içinde.
            try {
                bcc_begin_transaction();

                $moved = bcc_reorder_sibling('tables_meta', 'base_id', $base['id'], $table['id'], $direction);

                if ($moved) {
                    log_audit('table.reorder', 'table', $table['id'], array('direction' => $direction), $base['team_id']);
                }

                bcc_commit();
            } catch (Throwable $e) {
                bcc_rollback();
                $error = 'Tablo taşınamadı (veritabanı hatası).';
            }
        }
    }
}

$tables = bcc_fetch_all(
    'SELECT id, name, description, position FROM tables_meta WHERE base_id = :base_id ORDER BY position, id',
    array('base_id' => $base['id'])
);
// Sol panel "Yıldızlılar" listesi — workspaces.php/team_members.php ile AYNI desen.
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

$homeActiveNav = 'bases';
$homePageTitle = 'BCC-Core — ' . $base['name'];
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="settings-breadcrumb">
            <a href="/bases.php">&larr; Base'lere dön</a>
        </div>
        <div class="home-main-header">
            <h1><?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <?php if ($base['description']): ?>
                <p class="settings-hint"><?php echo htmlspecialchars($base['description'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
        </div>

        <?php require __DIR__ . '/../src/partials/flash.php'; ?>

        <div class="settings-card">
            <h2>Tablolar (<?php echo count($tables); ?>)</h2>

            <?php if (empty($tables)): ?>
                <p class="settings-empty">Bu base'de henüz tablo yok.</p>
            <?php else: ?>
                <div class="settings-table-wrap">
                    <table class="settings-table">
                        <thead><tr><th>Tablo</th><th>Açıklama</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($tables as $i => $t): ?>
                            <tr>
                                <td><a href="/grid.php?table_id=<?php echo (int) $t['id']; ?>"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                                <td><?php echo htmlspecialchars((string) $t['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php if ($canEdit): ?>
                                <td class="settings-row-actions">
                                    <form method="post" action="/base_tables.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="move_table">
                                        <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                                        <input type="hidden" name="table_id" value="<?php echo (int) $t['id']; ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="settings-btn settings-btn-sm" <?php echo $i === 0 ? 'disabled' : ''; ?>>&uarr;</button>
                                    </form>
                                    <form method="post" action="/base_tables.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="move_table">
                                        <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                                        <input type="hidden" name="table_id" value="<?php echo (int) $t['id']; ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="settings-btn settings-btn-sm" <?php echo $i === count($tables) - 1 ? 'disabled' : ''; ?>>&darr;</button>
                                    </form>
                                    <a class="settings-btn settings-btn-sm" href="/base_tables.php?base_id=<?php echo (int) $base['id']; ?>&edit=<?php echo (int) $t['id']; ?>">Düzenle</a>
                                    <form method="post" action="/base_tables.php" onsubmit="return confirm('Bu tabloyu ve içindeki tüm alanları silmek istediğinize emin misiniz?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_table">
                                        <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                                        <input type="hidden" name="table_id" value="<?php echo (int) $t['id']; ?>">
                                        <button type="submit" class="settings-btn settings-btn-danger settings-btn-sm">Sil</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                <div class="settings-card">
                    <h2>Tabloyu Düzenle: <?php echo htmlspecialchars($editTable['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <form class="settings-form settings-form-stacked" method="post" action="/base_tables.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="rename_table">
                        <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                        <input type="hidden" name="table_id" value="<?php echo (int) $editTable['id']; ?>">
                        <label class="settings-field">Tablo adı
                            <input type="text" name="name" value="<?php echo htmlspecialchars($editTable['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label class="settings-field">Açıklama (opsiyonel)
                            <input type="text" name="description" value="<?php echo htmlspecialchars((string) $editTable['description'], ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <button type="submit" class="settings-btn settings-btn-primary">Kaydet</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="settings-card">
                <h2>Yeni Tablo</h2>
                <form class="settings-form settings-form-stacked" method="post" action="/base_tables.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_table">
                    <input type="hidden" name="base_id" value="<?php echo (int) $base['id']; ?>">
                    <label class="settings-field">Tablo adı
                        <input type="text" name="name" required>
                    </label>
                    <label class="settings-field">Açıklama (opsiyonel)
                        <input type="text" name="description">
                    </label>
                    <button type="submit" class="settings-btn settings-btn-primary">Tablo Oluştur</button>
                </form>
            </div>
        <?php else: ?>
            <p class="settings-hint">Bu ekipte tablo oluşturmak/düzenlemek için owner rolü gerekir.</p>
        <?php endif; ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
