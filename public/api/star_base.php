<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$baseId = isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0;
$user = current_user();

try {
    $base = bcc_fetch_one('SELECT id, team_id FROM bases WHERE id = :id AND deleted_at IS NULL LIMIT 1', array(':id' => $baseId));

    if (!$base) {
        json_fail(404, 'Base bulunamadı.');
    }

    require_team_access($base['team_id']);

    $deleted = bcc_execute(
        'DELETE FROM user_starred_bases WHERE user_id = :uid AND base_id = :bid',
        array(':uid' => $user['id'], ':bid' => $base['id'])
    );

    if ((int) $deleted === 0) {
        bcc_execute(
            'INSERT IGNORE INTO user_starred_bases (user_id, base_id) VALUES (:uid, :bid)',
            array(':uid' => $user['id'], ':bid' => $base['id'])
        );
        $starred = true;
    } else {
        $starred = false;
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'starred' => $starred), JSON_UNESCAPED_UNICODE);
