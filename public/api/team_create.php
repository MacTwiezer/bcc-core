<?php
// AJAX uçnoktası: workspaces.php'nin "Yeni Çalışma Alanı" butonunun ve
// admin/index.php'nin "+ Yeni ekip oluştur" bağlantısının açtığı modal.
// admin/create_team.php'nin klasik form POST'u ile AYNI işi yapar — ikisi de
// bcc_create_team()'i çağırır (doğrulama/INSERT/üyelik/audit tek yerde),
// burada yalnızca istek biçimi (JSON) ve yetki reddi farklıdır.
// (bases.php / api/base_create.php ikilisiyle birebir aynı desen.)
//
// Yetki: ekip = çalışma alanı ve ekip oluşturmak PLATFORM ADMİNİNİN işi —
// eşik admin/create_team.php'deki require_admin() ile AYNI. Buradaki kontrol
// asıl kapıdır: butonu görmeyen bir kullanıcı bu uçnoktaya elle istek atsa da
// reddedilir (workspaces.php butonu admin olmayana zaten basmıyor).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();

// require_admin() BİLEREK kullanılmadı: o, hata durumunda düz metinle die()
// eder ve bu uçnoktanın JSON sözleşmesini bozardı (base_create.php'nin
// require_role() için yazdığı gerekçenin aynısı).
if ((int) $user['is_admin'] !== 1) {
    json_fail(403, 'Çalışma alanı oluşturmak için platform yöneticisi olmanız gerekir.');
}

$name = isset($_POST['name']) ? $_POST['name'] : '';

try {
    $result = bcc_create_team($name, $user['id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

if (!$result['ok']) {
    // Doğrulama hatası (boş ad / uzunluk / isim çakışması) — 500 değil 422;
    // mesaj modalda alanın altında gösterilir.
    json_fail(422, $result['error']);
}

// redirect_url SUNUCUDA kuruluyor: istemci id'den URL uydurmasın (grid.php'nin
// table_delete.php'sinde alınan AYNI karar). Yeni çalışma alanı seçili gelsin
// diye workspaces.php'ye team_id ile dönülüyor.
echo json_encode(array(
    'ok' => true,
    'team_id' => $result['id'],
    'redirect_url' => '/workspaces.php?team_id=' . $result['id'],
), JSON_UNESCAPED_UNICODE);
