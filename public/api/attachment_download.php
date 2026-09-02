<?php

require __DIR__ . '/../../src/bootstrap.php';

require_login();

$attachmentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$attachment = bcc_find_attachment($attachmentId);

if (!$attachment) {
    http_response_code(404);
    die('Dosya bulunamadı.');
}

require_team_access($attachment['team_id']);

$path = bcc_attachment_storage_path($attachment['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    die('Dosya diskte bulunamadı.');
}

$safeName = str_replace(array("\r", "\n", '"'), '', $attachment['original_name']);
$isImage = strpos($attachment['mime_type'], 'image/') === 0;

header('Content-Type: ' . $attachment['mime_type']);
header('X-Content-Type-Options: nosniff');
header(
    'Content-Disposition: ' . ($isImage ? 'inline' : 'attachment')
    . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($attachment['original_name'])
);
header('Content-Length: ' . filesize($path));

readfile($path);
