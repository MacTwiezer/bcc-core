<?php
// AJAX uçnoktası: bir "attachment" (dosya eki) hücresine yeni dosya yükler.
// grid.php / assets/grid.js (ve satır genişletme paneli, grid-row-detail.js —
// window.BCC_GRID.uploadAttachment üzerinden AYNI uç nokta) çağırır.
// Güvenlik: CSRF + require_role('editor') + kaydın gerçekten bu alanın tablosuna
// ait olduğu kontrolü (cell_update.php ile AYNI desen). Dosya, gerçek içeriğinden
// (finfo — istemcinin gönderdiği MIME'a GÜVENİLMEZ) + sabit uzantı whitelist'inden
// doğrulanır; diskte RASTGELE bir adla saklanır (orijinal ad yalnızca DB'de,
// yalnızca gösterim için). Depolama public/ DIŞINDA (storage/attachments/) —
// tek erişim yolu attachment_download.php, her indirmede KVKK kontrolünden geçer.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

// Uzantı => [izinli uzantı, izinli gerçek MIME'ler, DB'ye yazılacak kanonik MIME].
// Bazı Office (OOXML) dosyaları finfo'da bazen genel "application/zip" olarak
// algılanabiliyor (bilinen bir finfo/magic-db kısıtı) — bu yüzden xlsx/docx/pptx
// için zip de kabul ediliyor, ama DB'ye HER ZAMAN kanonik MIME yazılıyor (indirme
// sırasında doğru Content-Type için).
$ALLOWED = array(
    'png' => array('mimes' => array('image/png'), 'canonical' => 'image/png'),
    'jpg' => array('mimes' => array('image/jpeg'), 'canonical' => 'image/jpeg'),
    'jpeg' => array('mimes' => array('image/jpeg'), 'canonical' => 'image/jpeg'),
    'pdf' => array('mimes' => array('application/pdf'), 'canonical' => 'application/pdf'),
    'doc' => array('mimes' => array('application/msword'), 'canonical' => 'application/msword'),
    'docx' => array('mimes' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'), 'canonical' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    'xls' => array('mimes' => array('application/vnd.ms-excel'), 'canonical' => 'application/vnd.ms-excel'),
    'xlsx' => array('mimes' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'), 'canonical' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    'ppt' => array('mimes' => array('application/vnd.ms-powerpoint'), 'canonical' => 'application/vnd.ms-powerpoint'),
    'pptx' => array('mimes' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'), 'canonical' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'),
);

const BCC_ATTACHMENT_MAX_BYTES = 20 * 1024 * 1024; // 20MB — php.ini'deki upload_max_filesize'dan bağımsız ikinci bir üst sınır.

$fieldId = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
$recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;

$field = bcc_find_field($fieldId);
if (!$field) {
    json_fail(404, 'Alan bulunamadı.');
}

// KVKK ekip izolasyonu + editor+ rolü — team_id bu satırdan geliyor, istekten değil.
require_role($field['team_id'], 'editor');

if ($field['field_type'] !== 'attachment') {
    json_fail(400, 'Bu alan dosya eki tipinde değil.');
}

$record = bcc_fetch_one('SELECT id, table_id, deleted_at FROM records WHERE id = :id LIMIT 1', array(':id' => $recordId));
if (!$record || (int) $record['table_id'] !== (int) $field['table_id']) {
    json_fail(400, 'Bu kayıt bu alana ait değil.');
}
// Adım 3c: silinmiş (çöp kutusundaki) bir kayda dosya eklenemez — cell_update.php
// ile AYNI gerekçe/desen.
if ($record['deleted_at'] !== null) {
    json_fail(400, 'Bu kayıt silinmiş, düzenlenemez.');
}

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_fail(422, 'Dosya alınamadı.');
}

$upload = $_FILES['file'];

if ($upload['error'] !== UPLOAD_ERR_OK) {
    $tooBig = ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE);
    json_fail(422, $tooBig ? 'Dosya çok büyük.' : 'Dosya yüklenemedi.');
}

if ($upload['size'] <= 0 || $upload['size'] > BCC_ATTACHMENT_MAX_BYTES) {
    json_fail(422, 'Dosya boyutu 20MB\'ı aşamaz.');
}

$originalName = trim((string) $upload['name']);
if ($originalName === '') {
    json_fail(422, 'Geçersiz dosya adı.');
}
$originalName = mb_substr($originalName, 0, 255, 'UTF-8');

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!isset($ALLOWED[$ext])) {
    json_fail(422, 'Desteklenmeyen dosya türü. İzinli: PNG, JPEG, PDF, Word, Excel, PowerPoint.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : false;
if ($finfo) {
    finfo_close($finfo);
}

if (!$detectedMime || !in_array($detectedMime, $ALLOWED[$ext]['mimes'], true)) {
    json_fail(422, 'Dosya içeriği uzantısıyla uyuşmuyor.');
}

$storedName = bin2hex(random_bytes(16)) . '.' . $ext;
$destDir = dirname(bcc_attachment_storage_path(''));
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}
$destPath = bcc_attachment_storage_path($storedName);

if (!move_uploaded_file($upload['tmp_name'], $destPath)) {
    json_fail(500, 'Dosya kaydedilemedi.');
}

try {
    $user = current_user();
    bcc_execute(
        'INSERT INTO attachments (field_id, record_id, original_name, stored_name, mime_type, file_size, uploaded_by)
         VALUES (:field_id, :record_id, :original_name, :stored_name, :mime_type, :file_size, :uploaded_by)',
        array(
            'field_id' => $fieldId,
            'record_id' => $recordId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $ALLOWED[$ext]['canonical'],
            'file_size' => $upload['size'],
            'uploaded_by' => $user ? $user['id'] : null,
        )
    );
    $newId = (int) bcc_last_insert_id();

    // record_add.php ile AYNI desen: log_audit() try/catch İÇİNDE — dışarıda
    // olursa (bulunan gerçek bug) burada atılan bir istisna yakalanmaz, ham PHP
    // hata çıktısı (display_errors=On, bkz. C:\xampp\php\php.ini) doğrudan
    // istemciye sızar; dosya aslında başarıyla yüklenmiş olsa bile.
    log_audit('attachment.upload', 'record', $recordId, array('field_id' => $fieldId, 'file_name' => $originalName), $field['team_id']);
} catch (Throwable $e) {
    unlink($destPath);
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array(
    'ok' => true,
    'file' => array(
        'id' => $newId,
        'name' => $originalName,
        'mime' => $ALLOWED[$ext]['canonical'],
        'size' => (int) $upload['size'],
    ),
), JSON_UNESCAPED_UNICODE);
