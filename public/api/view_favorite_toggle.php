<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$user = current_user();

try {
    $view = bcc_find_view_by_id($viewId);

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_team_access($view['team_id']);

    $deleted = bcc_execute(
        'DELETE FROM user_favorite_views WHERE user_id = :uid AND view_id = :vid',
        array(':uid' => $user['id'], ':vid' => $view['id'])
    );

    if ((int) $deleted === 0) {
        bcc_execute(
            'INSERT IGNORE INTO user_favorite_views (user_id, view_id) VALUES (:uid, :vid)',
            array(':uid' => $user['id'], ':vid' => $view['id'])
        );
        $favorited = true;
    } else {
        $favorited = false;
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'favorited' => $favorited), JSON_UNESCAPED_UNICODE);
