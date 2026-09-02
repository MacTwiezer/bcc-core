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

    $existing = bcc_fetch_one(
        'SELECT id FROM user_favorite_views WHERE user_id = :uid AND view_id = :vid LIMIT 1',
        array(':uid' => $user['id'], ':vid' => $view['id'])
    );

    if ($existing) {
        bcc_execute('DELETE FROM user_favorite_views WHERE id = :id', array(':id' => $existing['id']));
        $favorited = false;
    } else {
        bcc_execute(
            'INSERT INTO user_favorite_views (user_id, view_id) VALUES (:uid, :vid)',
            array(':uid' => $user['id'], ':vid' => $view['id'])
        );
        $favorited = true;
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'favorited' => $favorited), JSON_UNESCAPED_UNICODE);
