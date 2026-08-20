<?php
// AJAX uçnoktası: bir tabloyu SİLER (grid.php sekme menüsü → "Sil").
//
// Neden yeni uçnokta: silmenin tek yolu base_tables.php'nin TAM SAYFA POST'uydu;
// grid.php'den oraya yönlendirmek kullanıcıyı çalıştığı ekrandan çıkarıyordu.
//
// ⚠️ BULUNAN GERÇEK HATA — base_tables.php'nin delete_table dalı
// bcc_delete_attachment_files_by_table() ÇAĞIRMIYOR: tables_meta silinince
// attachments SATIRLARI CASCADE ile gidiyor ama DİSKTEKİ DOSYALAR öksüz
// kalıyordu. table_clear_data.php bunu doğru yapıyor, silme yolu o düzeltmeden
// pay almamıştı. Burada dosyalar DB'den ÖNCE temizleniyor.
//
// Güvenlik: CSRF + owner. base_tables.php ile AYNI eşik
// (require_role($base['team_id'], 'owner')) — tablo silmek ŞEMA işidir,
// editor'ın veri düzenleme yetkisiyle karıştırılmaz. "Verileri temizle"
// (table_clear_data.php) editor'a açık kalır: o veriyi siler, tabloyu DEĞİL.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

$table = find_table_or_404($tableId);
require_role($table['team_id'], 'owner');

$tableName = $table['name'];
$baseId = (int) $table['base_id'];

// Silinen tablo AÇIK olan tabloysa istemcinin nereye gideceğini SUNUCU söyler
// (istemci "bir sonraki sekme hangisiydi" tahminine kalmasın). Aynı base'deki
// başka bir tablo varsa oraya, yoksa tablo listesine.
$nextTableId = (int) bcc_fetch_column(
    'SELECT id FROM tables_meta WHERE base_id = :b AND id <> :t ORDER BY position, id LIMIT 1',
    array(':b' => $baseId, ':t' => $table['id'])
);

try {
    // Fiziksel dosyalar DB satırlarından ÖNCE — ters sırada hangi dosyaların bu
    // tabloya ait olduğunu bulmanın yolu kalmazdı (attachments satırları
    // CASCADE ile çoktan gitmiş olurdu).
    bcc_delete_attachment_files_by_table($table['id']);

    // DELETE + log_audit AYNI transaction'da (base_tables.php'deki AYNI
    // gerekçe): tables_meta silinince fields/records/views/cell_values CASCADE
    // ile gidiyor — audit satırı yazılamazsa geriye "neyin silindiğini söyleyen
    // hiçbir kayıt olmadan yok olmuş bir tablo" kalırdı.
    bcc_begin_transaction();

    bcc_execute('DELETE FROM tables_meta WHERE id = :id', array(':id' => $table['id']));
    log_audit('table.delete', 'table', $table['id'], array('name' => $tableName), $table['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Tablo silinemedi (veritabanı hatası).');
}

echo json_encode(array(
    'ok' => true,
    'redirect_url' => $nextTableId > 0
        ? '/grid.php?table_id=' . $nextTableId
        : '/base_tables.php?base_id=' . $baseId,
), JSON_UNESCAPED_UNICODE);
