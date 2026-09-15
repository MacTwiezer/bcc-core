<?php

/* Kucuk kare resim yuklemeleri (profil fotografi, calisma alani resmi) icin
   ortak dogrulama ve kayit. Iki uc da ayni kurallardan geciyor; kurallar tek
   yerde. Sunucuda GD yok: resim burada yeniden kodlanmiyor, arayuz tarayicida
   256px JPEG'e ceviriyor. Buradaki sinirlar uca dogrudan istek atan biri icin. */

const BCC_SQUARE_IMAGE_MAX_BYTES = 2 * 1024 * 1024;
const BCC_SQUARE_IMAGE_MIN_SIDE = 16;
const BCC_SQUARE_IMAGE_MAX_SIDE = 4096;

/* $noun: hata mesajlarindaki ad ("Fotoğraf", "Resim").
   Basarida array('ok' => true, 'bytes', 'width', 'height');
   hatada array('ok' => false, 'status', 'error'). */
function bcc_clean_uploaded_image($filesKey, $noun)
{
    $fail = function ($status, $message) {
        return array('ok' => false, 'status' => $status, 'error' => $message);
    };

    if (!isset($_FILES[$filesKey]) || !is_uploaded_file($_FILES[$filesKey]['tmp_name'])) {
        return $fail(422, 'Dosya alınamadı.');
    }

    $upload = $_FILES[$filesKey];

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $tooBig = ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE);
        return $fail(422, $tooBig ? 'Dosya çok büyük.' : 'Dosya yüklenemedi.');
    }

    if ($upload['size'] <= 0 || $upload['size'] > BCC_SQUARE_IMAGE_MAX_BYTES) {
        return $fail(422, $noun . ' 2MB\'ı aşamaz.');
    }

    $bytes = file_get_contents($upload['tmp_name']);
    if ($bytes === false || $bytes === '') {
        return $fail(422, 'Dosya okunamadı.');
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
        return $fail(422, 'Yalnızca PNG veya JPEG ' . mb_strtolower($noun, 'UTF-8') . ' yüklenebilir.');
    }

    if ($clean === null) {
        return $fail(422, $noun . ' dosyası bozuk ya da desteklenmiyor.');
    }

    /* getimagesize GD gerektirmez. Ayiklanmis bayt dizisinin hala gecerli ve ayni
       turde bir resim oldugu burada dogrulaniyor — ayiklama bir seyi bozduysa
       diske yazilmadan yakalanir. */
    $info = @getimagesizefromstring($clean);
    if (!$info || (int) $info[2] !== $expectedType) {
        return $fail(422, $noun . ' dosyası bozuk ya da desteklenmiyor.');
    }

    $w = (int) $info[0];
    $h = (int) $info[1];
    if ($w < BCC_SQUARE_IMAGE_MIN_SIDE || $h < BCC_SQUARE_IMAGE_MIN_SIDE) {
        return $fail(422, $noun . ' çok küçük.');
    }
    if ($w > BCC_SQUARE_IMAGE_MAX_SIDE || $h > BCC_SQUARE_IMAGE_MAX_SIDE) {
        return $fail(422, $noun . ' boyutları çok büyük.');
    }

    return array('ok' => true, 'bytes' => $clean, 'width' => $w, 'height' => $h);
}

/* Once gecici dosyaya, sonra yerine: yari yazilmis bir dosya asla
   sunulmasin. Windows'ta hedef o an okunuyorsa rename basarisiz olabilir,
   o durumda eskisi silinip tekrar deneniyor. */
function bcc_write_file_atomically($target, $bytes)
{
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = $target . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $target)) {
        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
    }

    return true;
}

/* Diskteki bir resmi sunar: tur icerikten yeniden olculuyor ve yalnizca iki
   resim turune izin veriliyor — dosya bir sekilde degistirilse bile HTML/SVG
   olarak sunulamaz. Adres surum tasidigi icin uzun ve "private" onbellek. */
function bcc_serve_stored_image($path)
{
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $path) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    if ($mime !== 'image/jpeg' && $mime !== 'image/png') {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: inline');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($path));

    readfile($path);
    exit;
}
