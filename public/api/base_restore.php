<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$baseId = isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0;

try {
    $base = bcc_fetch_one('SELECT id, team_id, name FROM bases WHERE id = :id AND deleted_at IS NOT NULL LIMIT 1', array(':id' => $baseId));

    if (!$base) {
        json_fail(404, 'Silinmiş base bulunamadı.');
    }

    require_role($base['team_id'], 'owner');

    if (bcc_name_taken('bases', $base['team_id'], $base['name'])) {
        json_fail(422, 'Bu adda aktif bir base zaten var. Geri yüklemeden önce birini yeniden adlandırın.');
    }

    bcc_execute('UPDATE bases SET deleted_at = NULL, deleted_by = NULL WHERE id = :id', array(':id' => $base['id']));

    log_audit('base.restore', 'base', $base['id'], array('name' => $base['name']), $base['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$cardHtml = '';

try {
    $row = bcc_fetch_one(
        "SELECT b.id, b.team_id, b.name, b.description, b.icon, b.icon_color, b.created_at, al.last_opened
         FROM bases b
         LEFT JOIN (
             SELECT entity_id, MAX(created_at) AS last_opened
             FROM audit_log
             WHERE action = 'base.open' AND entity_type = 'base'
             GROUP BY entity_id
         ) al ON al.entity_id = b.id
         WHERE b.id = :id LIMIT 1",
        array(':id' => $base['id'])
    );

    if ($row) {
        $role = current_user_role_in_team($base['team_id']);
        $teamName = (string) bcc_fetch_column('SELECT name FROM teams WHERE id = :t', array(':t' => $base['team_id']));
        $counts = bcc_base_table_counts(array($base['id']));

        ob_start();
        bcc_render_home_base_card(
            $row,
            bcc_base_icon_color($row['id'], $row['icon_color']),
            isset(bcc_starred_base_ids_for_current_user()[(int) $row['id']]),
            $teamName,
            $role !== null && bcc_can_manage_bases($role),
            null,
            'standard',
            isset($counts[(int) $row['id']]) ? $counts[(int) $row['id']] : null
        );
        $cardHtml = trim(ob_get_clean());
    }
} catch (Throwable $e) {
    $cardHtml = '';
}

echo json_encode(array(
    'ok' => true,
    'base_id' => (int) $base['id'],
    'team_id' => (int) $base['team_id'],
    'card_html' => $cardHtml,
), JSON_UNESCAPED_UNICODE);
