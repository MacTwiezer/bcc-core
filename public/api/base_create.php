<?php
// AJAX uçnoktası: Home'daki "+ Yeni Base Oluştur" kutucuğunun açtığı modal.
// public/bases.php'nin klasik form POST'u ile AYNI işi yapar — ikisi de
// bcc_create_base()'i çağırır (doğrulama/INSERT/audit tek yerde), burada yalnızca
// istek biçimi (JSON) ve yetki reddi farklıdır.
//
// Yetki: bir çalışma alanına base EKLEMEK yalnızca Owner'a açıktır (Editor
// kayıt düzenler ama base ekleyemez) — eşik src/auth.php'deki
// bcc_can_manage_bases()'te TEK yerde tanımlı, dashboard.php'nin kutucuğu
// gizleme kararı da aynı fonksiyondan gelir. Buradaki kontrol asıl kapıdır:
// kutucuğu görmeyen bir kullanıcı bu uçnoktaya elle istek atsa da reddedilir.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$teamId = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
$name = isset($_POST['name']) ? $_POST['name'] : '';
$description = isset($_POST['description']) ? $_POST['description'] : '';

// require_role() BİLEREK kullanılmadı: o, hata durumunda düz metinle die() eder
// ve bu uçnoktanın JSON sözleşmesini bozardı. Aynı iki adım (önce üyelik =
// KVKK izolasyonu, sonra rol) burada json_fail() ile yapılır. Üye olmama ve
// yetkisi yetmeme AYRI mesaj alır ama ikisi de 403'tür — üye olmadığı bir
// çalışma alanının VAR olup olmadığı böylece sızmaz.
if (!in_array($teamId, current_user_team_ids(), true)) {
    json_fail(403, 'Bu çalışma alanına erişim yetkiniz yok.');
}

if (!bcc_can_manage_bases(current_user_role_in_team($teamId))) {
    json_fail(403, 'Bu çalışma alanında base oluşturmak için Owner yetkisi gerekir.');
}

try {
    $result = bcc_create_base($teamId, $name, $description, $user['id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

if (!$result['ok']) {
    // Doğrulama hatası (boş ad / uzunluk) — 500 değil 422; mesaj modalda
    // alanın altında gösterilir.
    json_fail(422, $result['error']);
}

echo json_encode(array('ok' => true, 'id' => $result['id']), JSON_UNESCAPED_UNICODE);
