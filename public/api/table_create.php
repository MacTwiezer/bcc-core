<?php
// AJAX uçnoktası: grid.php'deki "+ Yeni oluştur... → Boş tablo oluştur"
// modalından çağrılır. Aynı sayfada kalarak yeni bir BOŞ tablo yaratır.
//
// Neden yeni uçnokta: tablo oluşturmanın tek yolu base_tables.php'nin TAM SAYFA
// POST'uydu; grid.php'den oraya yönlendirmek kullanıcıyı çalıştığı ekrandan
// çıkarıyordu. Doğrulama kuralları ve INSERT deseni base_tables.php'nin
// create_table dalıyla BİREBİR AYNI tutuldu (mesajlar dahil) — iki yol
// birbirinden ayrışmasın.
//
// require_role() BİLEREK kullanılmadı: düz metinle die() eder ve bu uçnoktanın
// JSON sözleşmesini bozardı. api/base_create.php ile AYNI iki adım: önce üyelik
// (KVKK izolasyonu), sonra rol — ikisi de 403, ama ayrı mesaj, böylece üye
// olunmayan bir base'in VAR olup olmadığı sızmaz.
//
// Tablo oluşturma OWNER işidir (bcc_can_manage_schema) — base_tables.php'nin
// require_role($base['team_id'], 'owner') kapısıyla AYNI eşik.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$baseId = isset($_POST['base_id']) ? (int) $_POST['base_id'] : 0;
$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
$description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';

// find_base_or_404() BURADA KULLANILMAZ: o da HTML ile die() eder.
$base = bcc_fetch_one(
    'SELECT id, team_id, name FROM bases WHERE id = :id AND deleted_at IS NULL LIMIT 1',
    array('id' => $baseId)
);
if (!$base) {
    json_fail(404, 'Base bulunamadı.');
}

if (!in_array((int) $base['team_id'], current_user_team_ids(), true)) {
    json_fail(403, 'Bu base\'e erişim yetkiniz yok.');
}

if (!bcc_can_manage_schema(current_user_role_in_team($base['team_id']))) {
    json_fail(403, 'Tablo oluşturmak için Owner yetkisi gerekir.');
}

// Doğrulama — base_tables.php'deki AYNI sıra ve AYNI metinler.
// Uzunluk kontrolleri şart: sql_mode'da STRICT_TRANS_TABLES kapalı olduğu için
// MySQL uzun değeri hatasız SESSİZCE kırpar.
if ($name === '') {
    json_fail(422, 'Tablo adı boş olamaz.');
}
if (mb_strlen($name, 'UTF-8') > 150) {
    json_fail(422, 'Tablo adı en fazla 150 karakter olabilir.');
}
if (mb_strlen($description, 'UTF-8') > 500) {
    json_fail(422, 'Açıklama en fazla 500 karakter olabilir.');
}

// Aynı base'de aynı tablo adı olamaz; BAŞKA base'de serbest
// (bkz. src/schema.php bcc_name_taken() scope haritası).
if (bcc_name_taken('tables_meta', $base['id'], $name)) {
    json_fail(422, bcc_name_taken_error('tables_meta', 'tablo'));
}

$nextPos = (int) bcc_fetch_column(
    'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM tables_meta WHERE base_id = :base_id',
    array('base_id' => $base['id'])
);

try {
    // INSERT + log_audit AYNI transaction'da — base_tables.php'deki AYNI
    // gerekçe: ayrı commit edilseydi log_audit() patladığında tablo ZATEN
    // yazılmış olurdu ve kullanıcı hata görüp tekrar denerken ikinci bir
    // tablo oluştururdu.
    bcc_begin_transaction();

    bcc_execute(
        'INSERT INTO tables_meta (base_id, name, description, position)
         VALUES (:base_id, :name, :description, :position)',
        array(
            'base_id' => $base['id'],
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'position' => $nextPos,
        )
    );
    $newId = (int) bcc_last_insert_id();

    log_audit('table.create', 'table', $newId, array('name' => $name, 'base_id' => $base['id']), $base['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Tablo oluşturulamadı (veritabanı hatası).');
}

// Slack bildirimi COMMIT'TEN SONRA, transaction'ın DIŞINDA — base_tables.php ve
// bcc_create_field() ile AYNI gerekçe: geri alınmış bir tablo için bildirim
// gitmesin, Slack yavaşsa transaction açık kalmasın, gönderim hatası tablo
// oluşturmayı başarısız saymasın.
bcc_notify_slack_new_table($newId, $user['full_name']);

// Yeni tablo BOŞ gelir: alan yok, kayıt yok, görünüm yok. İlk grid açılışında
// bcc_get_or_create_default_view() varsayılan görünümü tembel oluşturur —
// burada elle bir şey yaratılmaz, mevcut tablonun HİÇBİR ŞEYİ kopyalanmaz.
echo json_encode(array(
    'ok' => true,
    'table_id' => $newId,
    'redirect_url' => '/grid.php?table_id=' . $newId,
), JSON_UNESCAPED_UNICODE);
