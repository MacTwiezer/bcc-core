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

    $moved = bcc_reorder_sibling('views', 'table_id', $view['table_id'], $view['id'], $direction);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

if ($moved) {
    // bcc_reorder_sibling() KENDİ transaction'ını açıp commit ediyor (iki UPDATE'i
    // sarmalıyor, table_fields.php/base_tables.php ile PAYLAŞILAN bir fonksiyon —
    // burada genişletilemez). log_audit() bu commit'ten SONRA, ayrı bir çağrı —
    // bulunan gerçek risk: burada bir istisna atarsa, sıralama zaten kalıcı
    // olarak değişmiş olur ama eski kodda istemci yine de "Veritabanı hatası"
    // görüp az önce olmuş bir işlemi başarısız sanırdı. Audit kaydı burada
    // BİLEREK sessizce yutulur — geç kalan bir istisna, zaten gerçekleşmiş bir
    // kullanıcı eylemini yanlışlıkla "başarısız" göstermemeli.
    try {
        log_audit('view.reorder', 'view', $view['id'], array('direction' => $direction), $view['team_id']);
    } catch (Throwable $e) {
        // Kasıtlı: aşağıya devam, istemciye yine de ok:true dönülür.
    }
}

echo json_encode(array('ok' => true, 'moved' => $moved), JSON_UNESCAPED_UNICODE);
