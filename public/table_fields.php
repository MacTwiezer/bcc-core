<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = bcc_can_manage_schema($role);

$fieldTypes = $GLOBALS['BCC_FIELD_TYPES'];

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    require_role($table['team_id'], 'owner');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_field') {
        $result = bcc_create_field($table['id'], $table['team_id'], $_POST);
        if ($result['ok']) {
            $success = 'Alan oluşturuldu: ' . $result['name'];
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'update_field') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $fieldType = isset($_POST['field_type']) ? $_POST['field_type'] : '';
        $isRequired = bcc_normalize_is_required($fieldType, isset($_POST['is_required']) ? $_POST['is_required'] : null);
        $optionsText = isset($_POST['options_text']) ? $_POST['options_text'] : '';

        if ($name === '') {
            $error = 'Alan adı boş olamaz.';
        } elseif (mb_strlen($name, 'UTF-8') > 150) {
            $error = 'Alan adı en fazla 150 karakter olabilir.';
        } elseif (!isset($fieldTypes[$fieldType])) {
            $error = 'Geçersiz alan tipi.';
        } else {
            $optionsResult = bcc_build_field_options($fieldType, $optionsText, isset($_POST['colors']) ? $_POST['colors'] : null, $_POST);

            if (!$optionsResult['ok']) {
                $error = $optionsResult['error'];
            } else {
                $fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;

                $existing = bcc_fetch_one(
                    'SELECT id FROM fields WHERE id = :id AND table_id = :table_id LIMIT 1',
                    array('id' => $fieldId, 'table_id' => $table['id'])
                );

                if (!$existing) {
                    bcc_error_page('Alan bulunamadı', 'Bu alan bu tabloya ait değil.', 404);
                }

                if (bcc_name_taken('fields', $table['id'], $name, $fieldId)) {
                    $error = bcc_name_taken_error('fields', 'alan');
                } else {
                    try {
                        bcc_begin_transaction();

                        bcc_execute(
                            'UPDATE fields SET name = :name, field_type = :field_type, options = :options, is_required = :is_required WHERE id = :id',
                            array(
                                'name' => $name,
                                'field_type' => $fieldType,
                                'options' => $optionsResult['options'],
                                'is_required' => $isRequired,
                                'id' => $fieldId,
                            )
                        );

                        if ($fieldType === 'autonumber') {
                            bcc_backfill_autonumber_field($fieldId, (int) $table['id']);
                        }

                        log_audit('field.update', 'field', $fieldId, array('name' => $name, 'field_type' => $fieldType), $table['team_id']);

                        bcc_commit();
                    } catch (Throwable $e) {
                        bcc_rollback();
                        throw $e;
                    }

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
            bcc_error_page('Alan bulunamadı', 'Bu alan bu tabloya ait değil.', 404);
        }

        if ($action === 'delete_field') {
            bcc_delete_attachment_files_by_field($field['id']);
            bcc_execute('DELETE FROM fields WHERE id = :id', array('id' => $field['id']));
            log_audit('field.delete', 'field', $field['id'], array('name' => $field['name']), $table['team_id']);
            $success = 'Alan silindi: ' . $field['name'];
        } else {
            $direction = isset($_POST['direction']) ? $_POST['direction'] : '';

            try {
                bcc_begin_transaction();

                $moved = bcc_reorder_sibling('fields', 'table_id', $table['id'], $field['id'], $direction);

                if ($moved) {
                    log_audit('field.reorder', 'field', $field['id'], array('direction' => $direction), $table['team_id']);
                }

                bcc_commit();
            } catch (Throwable $e) {
                bcc_rollback();
                $error = 'Alan taşınamadı (veritabanı hatası).';
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

$homeActiveNav = 'fields';
$homePageTitle = bcc_tab_title($table['name'] . ': Alanlar');
$homeExtraCss = array('settings-page.css', 'table-fields.css');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
<div class="sp-page">
        <div class="settings-breadcrumb">
            <a href="/base_tables.php?base_id=<?php echo (int) $table['base_id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?> tabloları
            </a>
            <span>·</span>
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M3 8h14M8 8v8" stroke="currentColor" stroke-width="1.4"/></svg>
                Grid'i görüntüle
            </a>
            <span>·</span>
            <a href="/slack_settings.php?table_id=<?php echo (int) $table['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7.5 3v9a2 2 0 11-2-2h9a2 2 0 11-2 2V3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Slack bildirimleri
            </a>
        </div>
        <div class="home-main-header">
            <h1><?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <?php if ($table['description']): ?>
                <p class="settings-hint"><?php echo htmlspecialchars($table['description'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
        </div>

        <?php require __DIR__ . '/../src/partials/flash.php'; ?>

        <div class="settings-card">
            <h2>Alanlar <span class="sp-count"><?php echo count($fields); ?></span></h2>

            <?php if (empty($fields)): ?>
                <p class="settings-empty">
                    <strong>Bu tabloda henüz alan yok.</strong>
                    <span class="sp-muted">Aşağıdan bir alan tipi seçerek başlayın.</span>
                </p>
            <?php else: ?>
                <div class="settings-table-wrap">
                    <table class="settings-table">
                        <thead><tr><th>Alan</th><th>Tip</th><th>Seçenekler</th><th>Zorunlu</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($fields as $i => $f):
                            $choices = is_select_field_type($f['field_type']) ? select_choices_from_options($f['options']) : array();
                        ?>
                            <tr>
                                <td class="sp-primary-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="tf-type-pill">
                                        <span class="field-type-badge field-type-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <?php echo htmlspecialchars($fieldTypes[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="<?php echo $choices ? '' : 'sp-muted'; ?>"><?php echo $choices ? htmlspecialchars(implode(', ', $choices), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                <td>
                                    <?php if ((int) $f['is_required'] === 1): ?>
                                        <span class="tf-required-yes">
                                            <svg width="13" height="13" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.5 10.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            Evet
                                        </span>
                                    <?php else: ?>
                                        <span class="sp-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canEdit): ?>
                                <td class="settings-row-actions">
                                    <span class="sp-move-group">
                                        <form method="post" action="/table_fields.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="move_field">
                                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                            <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="sp-icon-btn" title="Yukarı taşı" aria-label="Yukarı taşı" <?php echo $i === 0 ? 'disabled' : ''; ?>>
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 15V5m0 0l-4 4m4-4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        </form>
                                        <form method="post" action="/table_fields.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="move_field">
                                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                            <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="sp-icon-btn" title="Aşağı taşı" aria-label="Aşağı taşı" <?php echo $i === count($fields) - 1 ? 'disabled' : ''; ?>>
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 5v10m0 0l4-4m-4 4l-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        </form>
                                    </span>
                                    <a class="sp-icon-btn" title="Düzenle" aria-label="Alanı düzenle" href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>&edit=<?php echo (int) $f['id']; ?>">
                                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13.2 3.8l3 3L7.5 15.5l-3.7.7.7-3.7 8.7-8.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                                    </a>
                                    <form method="post" action="/table_fields.php" data-confirm="Bu alanı silmek istediğinize emin misiniz?" data-confirm-title="Alanı sil">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_field">
                                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                        <input type="hidden" name="field_id" value="<?php echo (int) $f['id']; ?>">
                                        <button type="submit" class="sp-icon-btn sp-icon-btn--danger" title="Sil" aria-label="Alanı sil">
                                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </button>
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

        <?php if ($canEdit): ?>
            <?php if ($editField): ?>
                <div class="settings-card">
                    <h2>Alanı Düzenle: <?php echo htmlspecialchars($editField['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <form class="settings-form settings-form-stacked" method="post" action="/table_fields.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_field">
                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                        <input type="hidden" name="field_id" value="<?php echo (int) $editField['id']; ?>">
                        <label class="settings-field">Alan adı
                            <input type="text" name="name" value="<?php echo htmlspecialchars($editField['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label class="settings-field">Tip
                            <select name="field_type" required>
                                <?php foreach ($fieldTypes as $typeKey => $typeLabel): ?>
                                    <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $editField['field_type'] === $typeKey ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="settings-field">Seçenekler (yalnızca Tekli/Çoklu seçim için — her satıra bir seçenek)
                            <textarea name="options_text" rows="4"><?php echo htmlspecialchars(implode("\n", select_choices_from_options($editField['options'])), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </label>
                        <?php
                        $editFieldOptions = json_decode((string) $editField['options'], true);
                        $editFieldOptions = is_array($editFieldOptions) ? $editFieldOptions : array();
                        ?>
                        <?php if ($editField['field_type'] === 'currency'): ?>
                            <label class="settings-field">Para birimi sembolü
                                <input type="text" name="currency_symbol" maxlength="5" value="<?php echo htmlspecialchars(isset($editFieldOptions['currency_symbol']) && $editFieldOptions['currency_symbol'] !== '' ? $editFieldOptions['currency_symbol'] : '₺', ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                            <label class="settings-field">Ondalık basamak
                                <input type="number" name="currency_decimal_places" min="0" max="6" value="<?php echo isset($editFieldOptions['decimal_places']) ? (int) $editFieldOptions['decimal_places'] : 2; ?>">
                            </label>
                        <?php elseif ($editField['field_type'] === 'percent'): ?>
                            <label class="settings-field">Ondalık basamak
                                <input type="number" name="percent_decimal_places" min="0" max="6" value="<?php echo isset($editFieldOptions['decimal_places']) ? (int) $editFieldOptions['decimal_places'] : 0; ?>">
                            </label>
                        <?php elseif ($editField['field_type'] === 'rating'): ?>
                            <label class="settings-field">Maksimum yıldız
                                <input type="number" name="max_rating" min="1" max="10" value="<?php echo isset($editFieldOptions['max_rating']) ? (int) $editFieldOptions['max_rating'] : 5; ?>">
                            </label>
                        <?php endif; ?>
                        <?php if (is_select_field_type($editField['field_type'])):
                            $editChoices = select_choices_from_options($editField['options']);
                            $editSavedColors = select_choice_colors_from_options($editField['options']);
                            $editColorMap = bcc_build_choice_color_map($editChoices, $editSavedColors);
                        ?>
                            <div class="choice-color-picker">
                                <p class="settings-hint">Renkler (her seçenek için)</p>
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
                        <?php if ($editField['field_type'] !== 'autonumber'): ?>
                            <label class="settings-field settings-field-checkbox">
                                <input type="checkbox" name="is_required" value="1" <?php echo ((int) $editField['is_required'] === 1) ? 'checked' : ''; ?>>
                                Zorunlu alan
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="settings-btn settings-btn-primary">Kaydet</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="settings-card">
                <h2>Yeni Alan</h2>
                <form class="settings-form settings-form-stacked" method="post" action="/table_fields.php" id="new-field-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_field">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <input type="hidden" name="field_type" id="new-field-type-input" required>

                    <?php
                    $fieldTypeLabels = $fieldTypes;
                    $fieldWizardShowRequired = false;
                    require __DIR__ . '/../src/partials/field_type_wizard_fields.php';
                    ?>
                </form>
            </div>
            <script>
                var BCC_SELECT_FIELD_TYPES = <?php echo json_encode($GLOBALS['BCC_SELECT_FIELD_TYPES'], JSON_UNESCAPED_UNICODE); ?>;
            </script>
            <script src="<?php echo bcc_asset_url('field-type-wizard.js'); ?>" defer></script>
            <script src="<?php echo bcc_asset_url('table-fields.js'); ?>" defer></script>
        <?php else: ?>
            <p class="settings-hint">Bu ekipte alan oluşturmak/düzenlemek için owner rolü gerekir.</p>
        <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
