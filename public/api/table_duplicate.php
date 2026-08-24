<?php
// AJAX uçnoktası: tablo sekmesi menüsündeki "Tabloyu çoğalt".
//
// ⚠️ GÖRÜNÜM PANELİNDEKİ "Bağımsız kopya oluştur" DA BURAYI ÇAĞIRIYOR.
// O kalem eskiden "Görünümü çoğalt" idi ve api/view_duplicate.php'yi
// çağırıyordu; kullanıcı ÜÇ kez "kopyadan yaptığım değişiklik orijinali
// etkiliyor" diye bildirdi. Sebep bir hata değil, çoğaltmanın SEVİYESİydi:
// görünüm, tablonun verisine bakan bir MERCEKtir — çoğaltılınca table_id aynı
// kalır, yani iki görünüm AYNI kayıtları gösterir. O uçnokta KALDIRILDI
// (UI çağıranı kalmayınca bırakmak, istenmeyen davranışı API'den erişilebilir
// bırakmak olurdu). Aynı veriye bakan ikinci bir görünüm isteyen
// api/view_create.php ("+ Yeni oluştur...") yolunu kullanır.
//
// GERÇEKTEN bağımsız bir kopya tablo düzeyinde olur ve bu uçnokta onu yapar:
// alanlar + görünümler (+ isteğe bağlı kayıtlar, hücreler ve dosya ekleri)
// yeni bir tabloya kopyalanır; iki taraf bundan sonra birbirini ETKİLEMEZ.
//
// Yetki: tablo oluşturmak/silmekle AYNI eşik — owner. api/table_create.php ve
// base_tables.php ile aynı kapı.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$user = current_user();
$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$name = isset($_POST['name']) ? (string) $_POST['name'] : '';
// Varsayılan AÇIK: "çoğalt" denince beklenen şey verinin de gelmesidir.
// Kullanıcı yalnızca şemayı isterse kutuyu kapatır.
$withRecords = !empty($_POST['with_records']);

$table = bcc_fetch_one(
    'SELECT t.id, t.base_id, t.name, b.team_id
     FROM tables_meta t INNER JOIN bases b ON b.id = t.base_id
     WHERE t.id = :id AND b.deleted_at IS NULL',
    array('id' => $tableId)
);

if (!$table) {
    json_fail(404, 'Tablo bulunamadı.');
}

// require_role() BİLEREK kullanılmadı: düz metinle die() eder ve bu uçnoktanın
// JSON sözleşmesini bozardı (api/base_create.php'deki AYNI gerekçe). Üyelik
// (KVKK izolasyonu) ve rol AYRI AYRI kontrol edilir; ikisi de 403 döner ki
// üye olunmayan bir tablonun VAR olup olmadığı sızmasın.
if (!in_array((int) $table['team_id'], current_user_team_ids(), true)) {
    json_fail(403, 'Bu tabloya erişim yetkiniz yok.');
}
if (!bcc_can_manage_schema(current_user_role_in_team((int) $table['team_id']))) {
    json_fail(403, 'Tabloyu çoğaltmak için Owner yetkisi gerekir.');
}

if (trim($name) === '') {
    // Ad verilmediyse "X kopyası" — aynı base'te çakışırsa sonuna sayı eklenir
    // (view_create.php'deki AYNI cakisma-dongusu deseni).
    $base = $table['name'] . ' kopyası';
    $name = $base;
    $suffix = 2;
    while (bcc_name_taken('tables_meta', $table['base_id'], $name)) {
        $name = $base . ' ' . $suffix;
        $suffix++;
        if ($suffix > 200) {
            json_fail(422, 'Uygun bir kopya adı üretilemedi, lütfen elle bir ad girin.');
        }
    }
}

try {
    $result = bcc_duplicate_table($table['id'], $name, $withRecords, $user['id']);
} catch (Throwable $e) {
    json_fail(500, 'Tablo çoğaltılamadı (veritabanı hatası).');
}

if (!$result['ok']) {
    json_fail(422, $result['error']);
}

// redirect_url SUNUCUDA kuruluyor — istemci id'den URL uydurmasın
// (table_create.php / team_create.php ile AYNI karar).
echo json_encode(array(
    'ok' => true,
    'table_id' => $result['id'],
    'record_count' => $result['record_count'],
    'attachment_count' => $result['attachment_count'],
    'redirect_url' => '/grid.php?table_id=' . $result['id'],
), JSON_UNESCAPED_UNICODE);
