<?php
// Faz 3 (grid) manuel/otomatik test için geçici kullanıcılar + base/tablo/alanlar kurar.
// Çalıştırma: C:\php73\php.exe scripts\_seed_phase3_test.php
// Temizlik: C:\php73\php.exe scripts\_cleanup_phase3_test.php

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
    ->execute(array(':e1' => 'faz3.test.editor@bcc-test.local', ':e2' => 'faz3.test.viewer@bcc-test.local'));

$password = 'Faz3Test!2026';
$hash = password_hash($password, PASSWORD_DEFAULT);

$insertUser = $pdo->prepare(
    'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :full_name, 0, 1)'
);

$insertUser->execute(array(':email' => 'faz3.test.editor@bcc-test.local', ':hash' => $hash, ':full_name' => 'Faz3 Test Editor'));
$editorId = (int) $pdo->lastInsertId();

$insertUser->execute(array(':email' => 'faz3.test.viewer@bcc-test.local', ':hash' => $hash, ':full_name' => 'Faz3 Test Viewer'));
$viewerId = (int) $pdo->lastInsertId();

$insertMember = $pdo->prepare('INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)');
$insertMember->execute(array(':team_id' => $teamId, ':user_id' => $editorId, ':role' => 'editor'));
$insertMember->execute(array(':team_id' => $teamId, ':user_id' => $viewerId, ':role' => 'viewer'));

$insertBase = $pdo->prepare('INSERT INTO bases (team_id, name, description, created_by) VALUES (:team_id, :name, :description, :created_by)');
$insertBase->execute(array(':team_id' => $teamId, ':name' => 'Faz3 Grid Test', ':description' => 'grid smoke test', ':created_by' => $editorId));
$baseId = (int) $pdo->lastInsertId();

$insertTable = $pdo->prepare('INSERT INTO tables_meta (base_id, name, description, position) VALUES (:base_id, :name, :description, 0)');
$insertTable->execute(array(':base_id' => $baseId, ':name' => 'Grid Test', ':description' => 'tum alan tipleri'));
$tableId = (int) $pdo->lastInsertId();

$insertField = $pdo->prepare(
    'INSERT INTO fields (table_id, name, field_type, options, position, is_required) VALUES (:table_id, :name, :field_type, :options, :position, :is_required)'
);

$selectOptions = json_encode(array('choices' => array('Kirmizi', 'Yesil', 'Mavi')), JSON_UNESCAPED_UNICODE);

$fieldsToCreate = array(
    array('Ad', 'single_line_text', null, 0),
    array('Notlar', 'long_text', null, 1),
    array('Miktar', 'number', null, 2, 1),
    array('Aktif mi', 'checkbox', null, 3),
    array('Tarih', 'date', null, 4),
    array('Renk (tekli)', 'single_select', $selectOptions, 5),
    array('Etiketler (coklu)', 'multiple_select', $selectOptions, 6),
);

$fieldIds = array();
foreach ($fieldsToCreate as $f) {
    $insertField->execute(array(
        ':table_id' => $tableId,
        ':name' => $f[0],
        ':field_type' => $f[1],
        ':options' => $f[2],
        ':position' => $f[3],
        ':is_required' => isset($f[4]) ? $f[4] : 0,
    ));
    $fieldIds[$f[1]] = (int) $pdo->lastInsertId();
}

$insertRecord = $pdo->prepare('INSERT INTO records (table_id, position, created_by) VALUES (:table_id, :position, :created_by)');
$insertRecord->execute(array(':table_id' => $tableId, ':position' => 0, ':created_by' => $editorId));
$recordId = (int) $pdo->lastInsertId();

echo "Kuruldu (TY ekibi, id={$teamId}):\n";
echo "  editor: faz3.test.editor@bcc-test.local / {$password} (id={$editorId})\n";
echo "  viewer: faz3.test.viewer@bcc-test.local / {$password} (id={$viewerId})\n";
echo "  base_id={$baseId}, table_id={$tableId}, record_id={$recordId}\n";
echo "  grid: http://localhost:8000/grid.php?table_id={$tableId}\n";
