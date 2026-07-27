<?php
// AJAX uçnoktası: dashboard'daki base kartının yıldız ikonuna tıklanınca
// favori durumunu toggle eder (user_starred_bases). Kullanıcı bazlı bir
// tercih — içerik değişikliği değil, bu yüzden require_role('editor') değil
// require_team_access() yeterli (viewer da yıldızlayabilir). Güvenlik: CSRF +
// require_team_access() (base'in team_id'si İSTEKTEN değil DB'den okunur).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$baseId = isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0;
$user = current_user();

try {
    $base = bcc_fetch_one('SELECT id, team_id FROM bases WHERE id = :id LIMIT 1', array(':id' => $baseId));

    if (!$base) {
        json_fail(404, 'Base bulunamadı.');
    }

    require_team_access($base['team_id']);

    $existing = bcc_fetch_one(
        'SELECT id FROM user_starred_bases WHERE user_id = :uid AND base_id = :bid LIMIT 1',
        array(':uid' => $user['id'], ':bid' => $base['id'])
    );

    if ($existing) {
        bcc_execute('DELETE FROM user_starred_bases WHERE id = :id', array(':id' => $existing['id']));
        $starred = false;
    } else {
        bcc_execute(
            'INSERT INTO user_starred_bases (user_id, base_id) VALUES (:uid, :bid)',
            array(':uid' => $user['id'], ':bid' => $base['id'])
        );
        $starred = true;
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'starred' => $starred), JSON_UNESCAPED_UNICODE);
