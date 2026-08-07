<?php
// AJAX uçnoktası: sol Views panelindeki "Move up"/"Move down" — mevcut
// bcc_reorder_sibling() (src/schema.php, table_fields.php/base_tables.php'de
// zaten kullanılıyordu) 'views' => 'table_id' eklenerek genişletildi, paralel
// bir sıralama mantığı YAZILMADI. Güvenlik deseni view_rename.php ile AYNI.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$direction = isset($_POST['direction']) ? $_POST['direction'] : '';

if ($direction !== 'up' && $direction !== 'down') {
    json_fail(422, 'Geçersiz yön.');
}

try {
    $view = bcc_fetch_one(
        'SELECT v.id, v.table_id, b.team_id
         FROM views v
         INNER JOIN tables_meta tm ON tm.id = v.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE v.id = :id LIMIT 1',
        array(':id' => $viewId)
    );

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    require_role($view['team_id'], 'editor');

    // ⚠️ Transaction, yetki/varlık kontrollerinden SONRA açılıyor: yukarıdaki
    // json_fail() çağrıları exit ediyor ve açık bir transaction'ı rollback
    // ETMEDEN çıkarlardı. Açık transaction'lı bir betiğin sonlanması InnoDB'de
    // örtük rollback ile biter (veri bozulmaz) ama bağlantıyı gereksiz yere
    // kilit tutarak bırakır — kontroller önce, transaction sonra.
    bcc_begin_transaction();

    // İKİ UPDATE + log_audit TEK transaction'da. bcc_reorder_sibling() artık
    // KENDİ transaction'ını açmıyor — sözleşme gereği çağıran açar (iç içe
    // transaction mysqli'de desteklenmiyor, içteki commit dıştakini erkenden
    // commit ederdi).
    //
    // ⚠️ ÖNCEKİ TASARIM KARARI DEĞİŞTİ, BİLEREK: log_audit() eskiden commit'ten
    // SONRA çağrılıyor ve istisnası SESSİZCE YUTULUYORDU ("zaten gerçekleşmiş
    // bir eylemi yanlışlıkla başarısız gösterme" gerekçesiyle). O gerekçe,
    // sıralamanın audit'ten ÖNCE kalıcılaştığı bir dünyada geçerliydi. Artık
    // ikisi aynı transaction'da: audit yazılamazsa sıralama da GERİ ALINIYOR,
    // yani "başarılı dedik ama iz yok" durumu hiç oluşmuyor ve istemciye 500
    // dönmek DOĞRU cevap — kullanıcı tekrar deneyince tutarlı bir durumdan
    // başlar. Diğer üç çağrı yeriyle de tutarlı.
    $moved = bcc_reorder_sibling('views', 'table_id', $view['table_id'], $view['id'], $direction);

    if ($moved) {
        log_audit('view.reorder', 'view', $view['id'], array('direction' => $direction), $view['team_id']);
    }

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true, 'moved' => $moved), JSON_UNESCAPED_UNICODE);
