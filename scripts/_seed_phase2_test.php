<?php
// Faz 2 manuel/otomatik test için geçici kullanıcılar kurar.
// Çalıştırma: C:\php73\php.exe scripts\_seed_phase2_test.php
// Temizlik: C:\php73\php.exe scripts\_cleanup_phase2_test.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';

$pdo = bcc_get_pdo();

$stmt = $pdo->query("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
$team = $stmt->fetch();
if (!$team) {
    fwrite(STDERR, "HATA: TY ekibi bulunamadi.\n");
    exit(1);
}
$teamId = (int) $team['id'];

$pdo->prepare('DELETE FROM users WHERE email IN (:e1, :e2)')
    ->execute(array(':e1' => 'faz2.test.editor@bcc-test.local', ':e2' => 'faz2.test.viewer@bcc-test.local'));

$password = 'Faz2Test!2026';
$hash = password_hash($password, PASSWORD_DEFAULT);

$insertUser = $pdo->prepare(
    'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :full_name, 0, 1)'
);

$insertUser->execute(array(':email' => 'faz2.test.editor@bcc-test.local', ':hash' => $hash, ':full_name' => 'Faz2 Test Editor'));
$editorId = (int) $pdo->lastInsertId();

$insertUser->execute(array(':email' => 'faz2.test.viewer@bcc-test.local', ':hash' => $hash, ':full_name' => 'Faz2 Test Viewer'));
$viewerId = (int) $pdo->lastInsertId();

$insertMember = $pdo->prepare('INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)');
$insertMember->execute(array(':team_id' => $teamId, ':user_id' => $editorId, ':role' => 'editor'));
$insertMember->execute(array(':team_id' => $teamId, ':user_id' => $viewerId, ':role' => 'viewer'));

echo "Kuruldu (TY ekibi, id={$teamId}):\n";
echo "  editor: faz2.test.editor@bcc-test.local / {$password} (id={$editorId})\n";
echo "  viewer: faz2.test.viewer@bcc-test.local / {$password} (id={$viewerId})\n";
