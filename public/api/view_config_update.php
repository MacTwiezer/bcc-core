<?php
// AJAX uçnoktası: aktif görünümün config JSON'ındaki SÜTUN YERLEŞİMİ anahtarlarını
// günceller — frozen_column_count (sütun dondurma sürükleme,
// assets/grid-freeze-columns.js) ve column_widths (sütun genişliği sürükleme,
// assets/grid-column-resize.js). Diğer config anahtarları EZİLMEZ — oku, ilgili
// anahtarı değiştir, geri yaz. Güvenlik: CSRF + require_role('editor') +
// view_id'nin var olduğu ve team_id'nin ondan geldiği kontrolü (view_rename.php
// ile aynı desen).
//
// İKİ AYRI ÖZELLİK, TEK UÇ NOKTA — her anahtar YALNIZCA gönderildiğinde yazılır.
// Bulunan gerçek bug (sütun genişliği eklenirken ortaya çıktı): $frozenCount
// isset kontrolü olmadan `: 1` varsayılanına düşüyor ve HER İSTEKTE yazılıyordu.
// Yalnızca column_widths gönderen bir istek, kullanıcının dondurulmuş sütun
// sayısını SESSİZCE 1'e geri alırdı. Artık ikisi de isset() ile korunuyor.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$hasFrozenCount = isset($_POST['frozen_column_count']);
$frozenCount = $hasFrozenCount ? (int) $_POST['frozen_column_count'] : null;
$hasColumnWidths = isset($_POST['column_widths']);
$stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

try {
    $view = bcc_fetch_one(
        'SELECT v.id, v.table_id, v.config, b.team_id
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

    // Üst sınır: gerçek GÖRÜNÜR alan sayısına göre (hidden_fields state'i
    // record_add.php'deki AYNI whitelist fonksiyonuyla çözülür) — istemci de aynı
    // kuralı uygular, sunucu burada ayrıca kırpar.
    // ORDER BY position, id — record_add.php/view_export_xlsx.php/table_import_xlsx.php
    // ile AYNI sıralama (bulunan gerçek bug: bu yoktu, MySQL'in ORDER BY'sız
    // sıralaması id sırasına göre gelebiliyor; alanlar yeniden sıralandıysa
    // (move_field) $fields[0]/$primaryFieldId POSITION 0'daki gerçek birincil
    // alan değil, en önce OLUŞTURULMUŞ alan olurdu).
    $fields = bcc_fetch_all('SELECT id FROM fields WHERE table_id = :table_id ORDER BY position, id', array(':table_id' => $view['table_id']));
    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;

    $stateParams = array();
    parse_str($stateQueryString, $stateParams);
    $hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);
    $visibleFieldCount = count($fields) - count($hiddenFieldIds);

    $maxAllowed = bcc_max_frozen_columns($visibleFieldCount);

    $changes = array();

    if ($hasFrozenCount) {
        if ($frozenCount < 1) {
            $frozenCount = 1;
        }
        if ($frozenCount > $maxAllowed) {
            $frozenCount = $maxAllowed;
        }
        $changes['frozen_column_count'] = $frozenCount;
    }

    $columnWidths = null;
    if ($hasColumnWidths) {
        // İstemci JSON dizgisi gönderir ({"row":219,"f12":320}) — düz bir POST
        // dizisi yerine, çünkü değer bir HARİTA ve anahtarları sunucuda
        // whitelist'ten geçiyor. Bozuk JSON sessizce boş haritaya düşer.
        $rawWidths = json_decode((string) $_POST['column_widths'], true);
        $columnWidths = bcc_sanitize_column_widths($rawWidths, $fieldsById);

        // Boş harita = "genişlikleri sıfırla, otomatik yerleşime dön":
        // bcc_update_view_config'te null, anahtarı SİLMEK demek.
        $changes['column_widths'] = empty($columnWidths) ? null : $columnWidths;
    }

    if (empty($changes)) {
        json_fail(400, 'Güncellenecek bir ayar gönderilmedi.');
    }

    // Oku-değiştir-yaz ortak yardımcıya taşındı (bcc_update_view_config) —
    // form_edit.php ve kanban_config_update.php AYNI diski paylaştığı için
    // "diğer anahtarları ezme" disiplini tek yerde.
    bcc_update_view_config($view['id'], $changes);

    log_audit('view.config_update', 'view', $view['id'], $changes, $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

$response = array('ok' => true);
if ($hasFrozenCount) {
    $response['frozen_column_count'] = $frozenCount;
}
if ($hasColumnWidths) {
    $response['column_widths'] = (object) $columnWidths;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
