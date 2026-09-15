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
        $colorsPost = isset($_POST['colors']) && is_array($_POST['colors']) ? $_POST['colors'] : null;

        if (isset($_POST['choices']) && is_array($_POST['choices'])) {
            $choiceLines = array();
            $colorByChoice = array();
            foreach ($_POST['choices'] as $rowKey => $choiceText) {
                $choiceText = trim((string) $choiceText);
                if ($choiceText === '') {
                    continue;
                }
                $choiceLines[] = $choiceText;
                if ($colorsPost !== null && isset($colorsPost[$rowKey])) {
                    $colorByChoice[$choiceText] = (string) $colorsPost[$rowKey];
                }
            }
            $optionsText = implode("\n", $choiceLines);
            $colorsPost = array();
            foreach (parse_select_choices($optionsText) as $choiceIndex => $parsedChoice) {
                if (isset($colorByChoice[$parsedChoice])) {
                    $colorsPost[$choiceIndex] = $colorByChoice[$parsedChoice];
                }
            }
        }

        if ($name === '') {
            $error = 'Alan adı boş olamaz.';
        } elseif (mb_strlen($name, 'UTF-8') > 150) {
            $error = 'Alan adı en fazla 150 karakter olabilir.';
        } elseif (!isset($fieldTypes[$fieldType])) {
            $error = 'Geçersiz alan tipi.';
        } else {
            $optionsResult = bcc_build_field_options($fieldType, $optionsText, $colorsPost, $_POST);

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
$editError = null;
$editDraft = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_field' && $error !== null) {
    $editId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
    $editError = $error;
    $error = null;
    $draftRows = array();
    if (isset($_POST['choices']) && is_array($_POST['choices'])) {
        foreach ($_POST['choices'] as $rowKey => $choiceText) {
            $draftRows[] = array(
                'text' => (string) $choiceText,
                'color' => (isset($_POST['colors']) && is_array($_POST['colors']) && isset($_POST['colors'][$rowKey])) ? (string) $_POST['colors'][$rowKey] : null,
            );
        }
    }
    $editDraft = array(
        'name' => isset($_POST['name']) ? (string) $_POST['name'] : '',
        'field_type' => isset($_POST['field_type']) ? (string) $_POST['field_type'] : '',
        'is_required' => isset($_POST['is_required']) ? 1 : 0,
        'rows' => $draftRows,
    );
}
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
                    <table class="settings-table tf-fields-table">
                        <thead><tr><th class="tf-col-name">Alan</th><th class="tf-col-type">Tip</th><th class="tf-col-options">Seçenekler</th><?php if ($canEdit): ?><th class="tf-col-actions">İşlemler</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($fields as $i => $f):
                            $hasChoiceList = is_select_field_type($f['field_type']);
                            $choices = $hasChoiceList ? select_choices_from_options($f['options']) : array();
                        ?>
                            <tr>
                                <td class="sp-primary-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="tf-type-pill">
                                        <span class="field-type-badge field-type-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <?php echo htmlspecialchars($fieldTypes[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <?php if (!$hasChoiceList): ?>
                                <td></td>
                                <?php elseif ($choices): ?>
                                <td><?php echo htmlspecialchars(implode(', ', $choices), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php else: ?>
                                <td class="sp-muted">—</td>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                <td class="tf-actions-cell"><div class="settings-row-actions">
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
                                </div></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canEdit): ?>
            <?php if ($editField):
                $efName = $editDraft ? $editDraft['name'] : $editField['name'];
                $efType = ($editDraft && isset($fieldTypes[$editDraft['field_type']])) ? $editDraft['field_type'] : $editField['field_type'];
                $efRequired = $editDraft ? (int) $editDraft['is_required'] : (int) $editField['is_required'];
                $efOptions = json_decode((string) $editField['options'], true);
                $efOptions = is_array($efOptions) ? $efOptions : array();
                $efPalette = $GLOBALS['BCC_CHOICE_COLORS'];
                if ($editDraft) {
                    $efRows = $editDraft['rows'];
                } else {
                    $efChoices = select_choices_from_options($editField['options']);
                    $efColorMap = bcc_build_choice_color_map($efChoices, select_choice_colors_from_options($editField['options']));
                    $efRows = array();
                    foreach ($efChoices as $efChoice) {
                        $efRows[] = array('text' => $efChoice, 'color' => isset($efColorMap[$efChoice]) ? $efColorMap[$efChoice] : null);
                    }
                }
                $efCloseUrl = '/table_fields.php?table_id=' . (int) $table['id'];
            ?>
                <div class="home-modal-backdrop tf-edit-backdrop" id="tf-edit-modal" data-close-url="<?php echo htmlspecialchars($efCloseUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="home-modal tf-edit-modal" role="dialog" aria-modal="true" aria-labelledby="tf-edit-title">
                        <div class="home-modal-head">
                            <h2 id="tf-edit-title">Alanı Düzenle</h2>
                            <a class="home-modal-close" href="<?php echo htmlspecialchars($efCloseUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Kapat" data-tf-edit-close>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </a>
                        </div>
                        <form class="home-modal-form" id="tf-edit-form" method="post" action="/table_fields.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update_field">
                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                            <input type="hidden" name="field_id" value="<?php echo (int) $editField['id']; ?>">

                            <?php if ($editError !== null): ?>
                                <p class="home-modal-error"><?php echo htmlspecialchars($editError, ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>

                            <label class="home-modal-field">
                                <span class="home-modal-label">Alan adı</span>
                                <input type="text" name="name" class="home-modal-input" maxlength="150" required autocomplete="off" value="<?php echo htmlspecialchars($efName, ENT_QUOTES, 'UTF-8'); ?>">
                            </label>

                            <label class="home-modal-field">
                                <span class="home-modal-label">Tip</span>
                                <select name="field_type" class="home-modal-input" required data-tf-edit-type>
                                    <?php foreach ($fieldTypes as $typeKey => $typeLabel): ?>
                                        <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $efType === $typeKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="home-modal-field tf-choices" data-tf-choices<?php echo is_select_field_type($efType) ? '' : ' hidden'; ?>>
                                <span class="home-modal-label">Seçenekler</span>
                                <div class="tf-choice-list" data-tf-choice-list>
                                    <?php foreach ($efRows as $rowIndex => $efRow): ?>
                                        <div class="tf-choice-row" data-tf-choice-row>
                                            <input type="text" name="choices[<?php echo (int) $rowIndex; ?>]" class="home-modal-input tf-choice-input" maxlength="150" autocomplete="off" aria-label="Seçenek adı" value="<?php echo htmlspecialchars($efRow['text'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="tf-choice-colors">
                                                <?php foreach ($efPalette as $colorKey => $hex): ?>
                                                    <label class="tf-choice-color" title="<?php echo htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="radio" name="colors[<?php echo (int) $rowIndex; ?>]" value="<?php echo htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $efRow['color'] === $colorKey ? 'checked' : ''; ?>>
                                                        <span style="background:<?php echo htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>"></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </span>
                                            <button type="button" class="tf-choice-remove" aria-label="Seçeneği sil" title="Seçeneği sil" data-tf-choice-remove>
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="tf-choice-empty" data-tf-choice-empty<?php echo $efRows ? ' hidden' : ''; ?>>Henüz seçenek yok.</p>
                                <button type="button" class="tf-choice-add" data-tf-choice-add>+ Seçenek ekle</button>
                                <template data-tf-choice-template>
                                    <div class="tf-choice-row" data-tf-choice-row>
                                        <input type="text" name="choices[__ROW__]" class="home-modal-input tf-choice-input" maxlength="150" autocomplete="off" aria-label="Seçenek adı" value="">
                                        <span class="tf-choice-colors">
                                            <?php foreach ($efPalette as $colorKey => $hex): ?>
                                                <label class="tf-choice-color" title="<?php echo htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="radio" name="colors[__ROW__]" value="<?php echo htmlspecialchars($colorKey, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <span style="background:<?php echo htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>"></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </span>
                                        <button type="button" class="tf-choice-remove" aria-label="Seçeneği sil" title="Seçeneği sil" data-tf-choice-remove>
                                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <?php
                            $efExtra = function ($postKey, $optionKey, $default) use ($editDraft, $efOptions) {
                                if ($editDraft && isset($_POST[$postKey]) && is_scalar($_POST[$postKey])) {
                                    return (string) $_POST[$postKey];
                                }
                                return (isset($efOptions[$optionKey]) && $efOptions[$optionKey] !== '') ? (string) $efOptions[$optionKey] : (string) $default;
                            };
                            ?>
                            <div class="tf-edit-pair" data-tf-extra="currency"<?php echo $efType === 'currency' ? '' : ' hidden'; ?>>
                                <label class="home-modal-field">
                                    <span class="home-modal-label">Para birimi sembolü</span>
                                    <input type="text" name="currency_symbol" class="home-modal-input" maxlength="5" value="<?php echo htmlspecialchars($efExtra('currency_symbol', 'currency_symbol', '₺'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $efType === 'currency' ? '' : ' disabled'; ?>>
                                </label>
                                <label class="home-modal-field">
                                    <span class="home-modal-label">Ondalık basamak</span>
                                    <input type="number" name="currency_decimal_places" class="home-modal-input" min="0" max="6" value="<?php echo htmlspecialchars($efExtra('currency_decimal_places', 'decimal_places', 2), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $efType === 'currency' ? '' : ' disabled'; ?>>
                                </label>
                            </div>
                            <label class="home-modal-field" data-tf-extra="percent"<?php echo $efType === 'percent' ? '' : ' hidden'; ?>>
                                <span class="home-modal-label">Ondalık basamak</span>
                                <input type="number" name="percent_decimal_places" class="home-modal-input" min="0" max="6" value="<?php echo htmlspecialchars($efExtra('percent_decimal_places', 'decimal_places', 0), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $efType === 'percent' ? '' : ' disabled'; ?>>
                            </label>
                            <label class="home-modal-field" data-tf-extra="rating"<?php echo $efType === 'rating' ? '' : ' hidden'; ?>>
                                <span class="home-modal-label">Maksimum yıldız</span>
                                <input type="number" name="max_rating" class="home-modal-input" min="1" max="10" value="<?php echo htmlspecialchars($efExtra('max_rating', 'max_rating', 5), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $efType === 'rating' ? '' : ' disabled'; ?>>
                            </label>

                            <label class="home-modal-field home-modal-check" data-tf-required-row<?php echo $efType === 'autonumber' ? ' hidden' : ''; ?>>
                                <input type="checkbox" name="is_required" value="1" <?php echo $efRequired === 1 ? 'checked' : ''; ?>>
                                <span>Zorunlu alan</span>
                            </label>

                            <div class="home-modal-actions">
                                <a class="home-modal-btn" href="<?php echo htmlspecialchars($efCloseUrl, ENT_QUOTES, 'UTF-8'); ?>" data-tf-edit-close>Vazgeç</a>
                                <button type="submit" class="home-modal-btn home-modal-btn-primary">Kaydet</button>
                            </div>
                        </form>
                    </div>
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
                var BCC_SELECT_FIELD_TYPES = <?php echo bcc_json_for_script($GLOBALS['BCC_SELECT_FIELD_TYPES']); ?>;
            </script>
            <script src="<?php echo bcc_asset_url('field-type-wizard.js'); ?>" defer></script>
            <script src="<?php echo bcc_asset_url('table-fields.js'); ?>" defer></script>
        <?php else: ?>
            <p class="settings-hint">Bu ekipte alan oluşturmak/düzenlemek için owner rolü gerekir.</p>
        <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
