<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

// Her erişimde KVKK ekip izolasyonu: bu tablonun ekibine üye olmayan hiçbir şey göremez.
require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = in_array($role, array('editor', 'owner'), true);

$fieldTypes = $GLOBALS['BCC_FIELD_TYPES'];
$typeBadges = $GLOBALS['BCC_FIELD_TYPE_BADGE'];

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    // Değiştirme yalnızca editor+ rolünde açık.
    require_role($table['team_id'], 'editor');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_field' || $action === 'update_field') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $fieldType = isset($_POST['field_type']) ? $_POST['field_type'] : '';
        $isRequired = !empty($_POST['is_required']) ? 1 : 0;
        $optionsText = isset($_POST['options_text']) ? $_POST['options_text'] : '';

        if ($name === '') {
            $error = 'Alan adı boş olamaz.';
        } elseif (!isset($fieldTypes[$fieldType])) {
            $error = 'Geçersiz alan tipi.';
        } else {
            $options = null;

            if (is_select_field_type($fieldType)) {
                $choices = parse_select_choices($optionsText);
                if (empty($choices)) {
                    $error = 'Tekli/çoklu seçim alanları için en az bir seçenek girilmeli (her satıra bir tane).';
                } else {
                    $optionsData = array('choices' => $choices);

                    // Color: seçenek başına renk yalnızca "Alanı Düzenle" formunda
                    // gönderilir (create formunda hiç renk seçici yok — yeni alanlar
                    // render sırasında palete otomatik sırayla düşer, bkz.
                    // bcc_resolved_choice_color_key). colors[i]: i, $choices
                    // dizisindeki İNDEKS'tir (seçenek metnini array key yapmak
                    // yerine — özel karakter/[] riski yok). Whitelist dışı renk
                    // KEY'i ya da $choices sınırları dışındaki indeks sessizce
                    // yok sayılır. (Aynı istekte hem metni hem rengi değiştirmek
                    // indeksleri kaydırabilir — kozmetik bir sınır, sonradan
                    // düzeltilebilir.)
                    if (isset($_POST['colors']) && is_array($_POST['colors'])) {
                        $palette = $GLOBALS['BCC_CHOICE_COLORS'];
                        $colors = array();
                        foreach ($_POST['colors'] as $i => $colorKey) {
                            if (!ctype_digit((string) $i) || !isset($choices[(int) $i]) || !isset($palette[$colorKey])) {
                                continue;
                            }
                            $colors[$choices[(int) $i]] = $colorKey;
                        }
                        if (!empty($colors)) {
                            $optionsData['colors'] = $colors;
                        }
                    }

                    $options = json_encode($optionsData, JSON_UNESCAPED_UNICODE);
                }
            }

            if ($error === null) {
                if ($action === 'create_field') {
                    $nextPos = (int) bcc_fetch_column(
                        'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM fields WHERE table_id = :table_id',
                        array('table_id' => $table['id'])
                    );

                    bcc_execute(
                        'INSERT INTO fields (table_id, name, field_type, options, position, is_required)
                         VALUES (:table_id, :name, :field_type, :options, :position, :is_required)',
                        array(
                            'table_id' => $table['id'],
                            'name' => $name,
                            'field_type' => $fieldType,
                            'options' => $options,
                            'position' => $nextPos,
                            'is_required' => $isRequired,
                        )
                    );
                    $newId = bcc_last_insert_id();
                    log_audit('field.create', 'field', $newId, array('name' => $name, 'field_type' => $fieldType, 'table_id' => $table['id']), $table['team_id']);
                    $success = 'Alan oluşturuldu: ' . $name;
                } else {
                    $fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;

                    $existing = bcc_fetch_one(
                        'SELECT id FROM fields WHERE id = :id AND table_id = :table_id LIMIT 1',
                        array('id' => $fieldId, 'table_id' => $table['id'])
                    );

                    if (!$existing) {
                        http_response_code(403);
                        die('Bu alan bu tabloya ait değil.');
                    }

                    bcc_execute(
                        'UPDATE fields SET name = :name, field_type = :field_type, options = :options, is_required = :is_required WHERE id = :id',
                        array(
                            'name' => $name,
                            'field_type' => $fieldType,
                            'options' => $options,
                            'is_required' => $isRequired,
                            'id' => $fieldId,
                        )
                    );
                    log_audit('field.update', 'field', $fieldId, array('name' => $name, 'field_type' => $fieldType), $table['team_id']);
                    $success = 'Alan güncellendi: ' . $name;
                }
            }
        }
    } elseif ($action === 'delete_field' || $action === 'move_field') {
        $fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;

        $field = bcc_fetch_one(
            'SELECT id, name, position FROM fields WHERE id = :id AND table_id = :table_id LIMIT 1',
            array('id' => $fieldId, 'table_id' => $table['id'])
        );

        if (!$field) {
            http_response_code(403);
            die('Bu alan bu tabloya ait değil.');
        }

        if ($action === 'delete_field') {
            bcc_execute('DELETE FROM fields WHERE id = :id', array('id' => $field['id']));
            log_audit('field.delete', 'field', $field['id'], array('name' => $field['name']), $table['team_id']);
            $success = 'Alan silindi: ' . $field['name'];
        } else {
            $direction = isset($_POST['direction']) ? $_POST['direction'] : '';

            $moved = bcc_reorder_sibling('fields', 'table_id', $table['id'], $field['id'], $direction);

            if ($moved) {
                log_audit('field.reorder', 'field', $field['id'], array('direction' => $direction), $table['team_id']);
            }
        }
    }
}

$fields = bcc_fetch_all(
    'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id',
    array('table_id' => $table['id'])
);

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editField = null;
if ($canEdit && $editId > 0) {
    foreach ($fields as $f) {
        if ((int) $f['id'] === $editId) {
            $editField = $f;
            break;
        }
    }
}
$pageTitle = $table['name'];
require __DIR__ . '/../src/partials/header.php';
require __DIR__ . '/../src/partials/top_nav.php';
?>
<div class="page">
    <p>
        <a href="/base_tables.php?base_id=<?php echo (int) $table['base_id']; ?>">&larr; <?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?> tablolarına dön</a>
        · <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">Grid'i görüntüle</a>
        · <a href="/slack_settings.php?table_id=<?php echo (int) $table['id']; ?>">Slack bildirimleri</a>
    </p>
    <h1><?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($table['description']): ?>
        <p><?php echo htmlspecialchars($table['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php require __DIR__ . '/../src/partials/flash.php'; ?>

    <div class="card">
        <h2>Alanlar (<?php echo count($fields); ?>)</h2>

        <?php if (empty($fields)): ?>
            <p>Bu tabloda henüz alan yok.</p>
        <?php else: ?>
            <table>
                <tr><th>Alan</th><th>Tip</th><th>Seçenekler</th><th>Zorunlu</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr>
                <?php foreach ($fields as $i => $f):
                    $choices = is_select_field_type($f['field_type']) ? select_choices_from_options($f['options']) : array();
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($fieldTypes[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $choices ? htmlspecialchars(implode(', ', $choices), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td><?php echo ((int) $f['is_required'] === 1) ? 'Evet' : '—'; ?></td>
                        <?php if ($canEdit): ?>
                        <td class="row-actions">
                            <form method="post" action="/table_fields.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="move_field">
                                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn-sm" <?php echo $i === 0 ? 'disabled' : ''; ?>>&uarr;</button>
                            </form>
                            <form method="post" action="/table_fields.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="move_field">
                                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn-sm" <?php echo $i === count($fields) - 1 ? 'disabled' : ''; ?>>&darr;</button>
                            </form>
                            <a class="btn-sm" href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>&edit=<?php echo (int) $f['id']; ?>">Düzenle</a>
                            <form method="post" action="/table_fields.php" onsubmit="return confirm('Bu alanı silmek istediğinize emin misiniz?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_field">
                                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>">
                                <button type="submit" class="btn-sm btn-danger">Sil</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
        <?php if ($editField): ?>
            <div class="card">
                <h2>Alanı Düzenle: <?php echo htmlspecialchars($editField['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <form class="stacked" method="post" action="/table_fields.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_field">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <input type="hidden" name="field_id" value="<?php echo (int) $editField['id']; ?>">
                    <label>Alan adı
                        <input type="text" name="name" value="<?php echo htmlspecialchars($editField['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <label>Tip
                        <select name="field_type" required>
                            <?php foreach ($fieldTypes as $typeKey => $typeLabel): ?>
                                <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $editField['field_type'] === $typeKey ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Seçenekler (yalnızca Tekli/Çoklu seçim için — her satıra bir seçenek)
                        <textarea name="options_text" rows="4"><?php echo htmlspecialchars(implode("\n", select_choices_from_options($editField['options'])), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </label>
                    <?php if (is_select_field_type($editField['field_type'])):
                        $editChoices = select_choices_from_options($editField['options']);
                        $editSavedColors = select_choice_colors_from_options($editField['options']);
                        $editColorMap = bcc_build_choice_color_map($editChoices, $editSavedColors);
                    ?>
                        <div class="choice-color-picker">
                            <p class="hint">Renkler (her seçenek için)</p>
                            <?php foreach ($editChoices as $ci => $choiceText): ?>
                                <div class="choice-color-row">
                                    <span class="choice-color-choice-name"><?php echo htmlspecialchars($choiceText, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php foreach ($GLOBALS['BCC_CHOICE_COLORS'] as $colorKey => $hex):
                                        $inputId = 'cc-' . (int) $editField['id'] . '-' . $ci . '-' . $colorKey;
                                    ?>
                                        <input
                                            type="radio"
                                            id="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="choice-color-input"
                                            name="colors[<?php echo $ci; ?>]"
                                            value="<?php echo htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $editColorMap[$choiceText] === $colorKey ? 'checked' : ''; ?>
                                        >
                                        <label for="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>" class="choice-color-swatch" style="background:<?php echo htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8'); ?>"></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <label>
                        <input type="checkbox" name="is_required" value="1" <?php echo ((int) $editField['is_required'] === 1) ? 'checked' : ''; ?> style="display:inline-block;width:auto;">
                        Zorunlu alan
                    </label>
                    <button type="submit">Kaydet</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Yeni Alan</h2>
            <form class="stacked" method="post" action="/table_fields.php" id="new-field-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_field">
                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                <input type="hidden" name="field_type" id="new-field-type-input" required>

                <!-- Adım 1: önce TİP (Airtable gibi) — liste $GLOBALS['BCC_FIELD_TYPES']'tan gelir, elle tekrar yazılmaz. -->
                <div id="new-field-type-step">
                    <p class="hint">Alan tipini seçin</p>
                    <div class="field-type-grid">
                        <?php foreach ($fieldTypes as $typeKey => $typeLabel): ?>
                            <button
                                type="button"
                                class="field-type-option"
                                data-field-type="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>"
                                data-field-type-label="<?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <span class="field-type-badge"><?php echo htmlspecialchars($typeBadges[$typeKey], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="field-type-label"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Adım 2: TİP seçilince görünür — başlık burada sorulur. -->
                <div id="new-field-details-step" hidden>
                    <p class="hint">
                        Seçilen tip: <strong id="new-field-type-chosen-label"></strong>
                        · <button type="button" class="link-btn" id="new-field-type-change">Tip değiştir</button>
                    </p>
                    <label>Alan adı
                        <input type="text" name="name" id="new-field-name-input">
                    </label>
                    <label id="new-field-options-row" hidden>Seçenekler (her satıra bir seçenek)
                        <textarea name="options_text" rows="4"></textarea>
                    </label>
                    <label>
                        <input type="checkbox" name="is_required" value="1" style="display:inline-block;width:auto;">
                        Zorunlu alan
                    </label>
                    <button type="submit">Alan Oluştur</button>
                </div>
            </form>
        </div>
        <script>
            var BCC_SELECT_FIELD_TYPES = <?php echo json_encode($GLOBALS['BCC_SELECT_FIELD_TYPES'], JSON_UNESCAPED_UNICODE); ?>;
            (function () {
                'use strict';

                var typeStep = document.getElementById('new-field-type-step');
                var detailsStep = document.getElementById('new-field-details-step');
                var typeInput = document.getElementById('new-field-type-input');
                var chosenLabel = document.getElementById('new-field-type-chosen-label');
                var optionsRow = document.getElementById('new-field-options-row');
                var nameInput = document.getElementById('new-field-name-input');

                Array.prototype.forEach.call(document.querySelectorAll('.field-type-option'), function (btn) {
                    btn.addEventListener('click', function () {
                        var type = btn.getAttribute('data-field-type');

                        typeInput.value = type;
                        chosenLabel.textContent = btn.getAttribute('data-field-type-label');
                        optionsRow.hidden = (BCC_SELECT_FIELD_TYPES.indexOf(type) === -1);

                        typeStep.hidden = true;
                        detailsStep.hidden = false;
                        nameInput.focus();
                    });
                });

                document.getElementById('new-field-type-change').addEventListener('click', function () {
                    detailsStep.hidden = true;
                    typeStep.hidden = false;
                });
            })();
        </script>
    <?php else: ?>
        <p class="hint">Bu ekipte alan oluşturmak/düzenlemek için editor veya owner rolü gerekir.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
