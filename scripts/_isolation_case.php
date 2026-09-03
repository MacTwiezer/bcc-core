<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../src/auth.php';

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$teamId = isset($argv[2]) ? (int) $argv[2] : 0;

$_SESSION = array('user_id' => $userId);

require_team_access($teamId);

echo "ERISIM_VAR\n";
