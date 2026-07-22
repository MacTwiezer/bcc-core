<?php
// Faz 2 manuel/otomatik test için geçici kullanıcılar kurar.
// Çalıştırma: C:\php73\php.exe scripts\_seed_phase2_test.php
// Temizlik: C:\php73\php.exe scripts\_cleanup_phase2_test.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';

$team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
if (!$team) {
    fwrite(STDERR, "HATA: TY ekibi bulunamadi.\n");
    exit(1);
}
$teamId = (int) $team['id'];

bcc_execute('DELETE FROM users WHERE email IN (:e1, :e2)', array(':e1' => 'faz2.test.editor@bcc-test.local', ':e2' => 'faz2.test.viewer@bcc-test.local'));

$password = 'Faz2Test!2026';
$hash = password_hash($password, PASSWORD_DEFAULT);

$insertUserSql = 'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :full_name, 0, 1)';

bcc_execute($insertUserSql, array(':email' => 'faz2.test.editor@bcc-test.local', ':hash' => $hash, ':full_name' => 'Faz2 Test Editor'));
$editorId = (int) bcc_last_insert_id();

bcc_execute($insertUserSql, array(':email' => 'faz2.test.viewer@bcc-test.local', ':hash' => $hash, ':full_name' => 'Faz2 Test Viewer'));
$viewerId = (int) bcc_last_insert_id();

$insertMemberSql = 'INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)';
bcc_execute($insertMemberSql, array(':team_id' => $teamId, ':user_id' => $editorId, ':role' => 'editor'));
bcc_execute($insertMemberSql, array(':team_id' => $teamId, ':user_id' => $viewerId, ':role' => 'viewer'));

echo "Kuruldu (TY ekibi, id={$teamId}):\n";
echo "  editor: faz2.test.editor@bcc-test.local / {$password} (id={$editorId})\n";
echo "  viewer: faz2.test.viewer@bcc-test.local / {$password} (id={$viewerId})\n";
