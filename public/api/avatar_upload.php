<?php

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

/* Arayuz resmi tarayicida 256px JPEG'e cevirip gonderiyor (~30KB). Sinir,
   uca dogrudan istek atan biri icin; buyuk bir resim her sayfanin ust
   cubugunda indirilecegi icin comert tutulmadi. */
const BCC_AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const BCC_AVATAR_MIN_SIDE = 16;
const BCC_AVATAR_MAX_SIDE = 4096;

$user = current_user();

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_fail(422, 'Dosya alınamadı.');
}

$upload = $_FILES['file'];

if ($upload['error'] !== UPLOAD_ERR_OK) {
    $tooBig = ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE);
    json_fail(422, $tooBig ? 'Dosya çok büyük.' : 'Dosya yüklenemedi.');
}

if ($upload['size'] <= 0 || $upload['size'] > BCC_AVATAR_MAX_BYTES) {
    json_fail(422, 'Fotoğraf 2MB\'ı aşamaz.');
}

$bytes = file_get_contents($upload['tmp_name']);
if ($bytes === false || $bytes === '') {
    json_fail(422, 'Dosya okunamadı.');
}

/* Uzantiya ve tarayicinin bildirdigi turune bakilmiyor: ikisi de istemcinin
   elinde. Karar dosyanin ICERIGINDEN veriliyor. */
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_buffer($finfo, $bytes) : false;
if ($finfo) {
    finfo_close($finfo);
}

if ($mime === 'image/jpeg') {
    $clean = bcc_avatar_strip_jpeg($bytes);
    $expectedType = IMAGETYPE_JPEG;
} elseif ($mime === 'image/png') {
    $clean = bcc_avatar_strip_png($bytes);
    $expectedType = IMAGETYPE_PNG;
} else {
    json_fail(422, 'Yalnızca PNG veya JPEG fotoğraf yüklenebilir.');
}

if ($clean === null) {
    json_fail(422, 'Fotoğraf dosyası bozuk ya da desteklenmiyor.');
}

/* getimagesize GD gerektirmez. Ayiklanmis bayt dizisinin hala gecerli ve ayni
   turde bir resim oldugu burada dogrulaniyor — ayiklama bir seyi bozduysa
   diske yazilmadan yakalanir. */
$info = @getimagesizefromstring($clean);
if (!$info || (int) $info[2] !== $expectedType) {
    json_fail(422, 'Fotoğraf dosyası bozuk ya da desteklenmiyor.');
}

$w = (int) $info[0];
$h = (int) $info[1];
if ($w < BCC_AVATAR_MIN_SIDE || $h < BCC_AVATAR_MIN_SIDE) {
    json_fail(422, 'Fotoğraf çok küçük.');
}
if ($w > BCC_AVATAR_MAX_SIDE || $h > BCC_AVATAR_MAX_SIDE) {
    json_fail(422, 'Fotoğrafın boyutları çok büyük.');
}

$dir = bcc_avatar_storage_dir();
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    json_fail(500, 'Fotoğraf kaydedilemedi.');
}

$target = bcc_avatar_path($user['id']);

/* Once gecici dosyaya, sonra yerine: yari yazilmis bir dosya asla
   sunulmasin. Windows'ta hedef o an okunuyorsa rename basarisiz olabilir,
   o durumda eskisi silinip tekrar deneniyor. */
$tmp = $target . '.tmp-' . bin2hex(random_bytes(6));
if (file_put_contents($tmp, $clean) !== strlen($clean)) {
    @unlink($tmp);
    json_fail(500, 'Fotoğraf kaydedilemedi.');
}

if (!@rename($tmp, $target)) {
    @unlink($target);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        json_fail(500, 'Fotoğraf kaydedilemedi.');
    }
}

try {
    log_audit('user.avatar_updated', 'user', $user['id'], array('bytes' => strlen($clean), 'width' => $w, 'height' => $h));
} catch (Throwable $e) {
}

echo json_encode(array('ok' => true, 'url' => bcc_avatar_url($user['id'])), JSON_UNESCAPED_UNICODE);
