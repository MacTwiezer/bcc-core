<?php
// Bir ek dosyayı indirir/görüntüler (grid.php'deki küçük resim <img> kaynağı VE
// indirme linki, interface.php'de aynı şekilde). GET — salt-okunur, CSRF gerekmez.
// GÜVENLİK: dosyalar public/ DIŞINDA (storage/attachments/) saklanıyor, bu yüzden
// web sunucusu onları hiçbir zaman doğrudan servis edemez — TEK erişim yolu bu
// dosya, ve her istekte require_team_access() ile KVKK kontrolünden geçer (team_id
// bcc_find_attachment() ile field_id -> table_id -> base_id zincirinden gelir,
// istekten değil). Görüntülemek (indirmek) düzenleme değildir — require_role
// DEĞİL, require_team_access yeterli (viewer da indirebilir, grid.php'yi
// görüntülemekle aynı yetki seviyesi).

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

// CRLF/tırnak enjeksiyonuna karşı (original_name kullanıcı girdisi) — header
// enjeksiyonunu önler, dosya adının kendisini DEĞİL yalnızca header'a yazımını etkiler.
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
