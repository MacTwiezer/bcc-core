<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    if ($view['view_type'] !== 'kanban') {
        json_fail(422, 'Bu görünüm bir Kanban görünümü değil.');
    }

    $fields = bcc_fetch_all(
        'SELECT id, field_type FROM fields WHERE table_id = :tid ORDER BY position, id',
        array(':tid' => $view['table_id'])
    );
    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }

    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : 0;

    $changes = array();

    if (array_key_exists('kanban_field_id', $_POST)) {
        $requested = (int) $_POST['kanban_field_id'];

        if ($requested === 0) {
            $changes['kanban_field_id'] = 0;
        } elseif (!isset($fieldsById[$requested])) {
            json_fail(422, 'Alan bu tabloya ait değil.');
        } elseif (!bcc_field_allowed_for_kanban($fieldsById[$requested]['field_type'])) {
            json_fail(422, 'Bu alan tipine göre sütunlanamaz (yalnızca Tekli seçim).');
        } else {
            $changes['kanban_field_id'] = $requested;
        }
    }

    if (array_key_exists('kanban_card_fields', $_POST)) {
        $posted = is_array($_POST['kanban_card_fields']) ? $_POST['kanban_card_fields'] : array();
        $columnFieldId = isset($changes['kanban_field_id'])
            ? (int) $changes['kanban_field_id']
            : bcc_kanban_config_from_view($view)['kanban_field_id'];

        $selected = array();
        foreach ($posted as $rawId) {
            if (!is_scalar($rawId)) {
                continue;
            }
            $fid = (int) $rawId;
            if ($fid > 0 && $fid !== $primaryFieldId && $fid !== $columnFieldId
                && isset($fieldsById[$fid]) && !in_array($fid, $selected, true)) {
                $selected[] = $fid;
            }
        }

        $changes['kanban_card_fields'] = $selected;
    }

    if (empty($changes)) {
        json_fail(422, 'Değiştirilecek bir ayar gönderilmedi.');
    }

    bcc_update_view_config($view['id'], $changes);

    log_audit('view.kanban_config', 'view', $view['id'], $changes, $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
