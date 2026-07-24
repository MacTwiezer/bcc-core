<?php
// AJAX uçnoktası: aktif görünümün config JSON'ındaki sürüklemeyle değişen
// görüntü ayarlarını günceller — frozen_column_count (grid-freeze-columns.js)
// VE column_widths[field_id] (grid-resize-columns.js) AYNI uçnoktayı paylaşır
// (ikisi de "sürükle bırak, anında kaydet" deseni; ikinci bir endpoint YOK).
// İstek hangi anahtarı taşıyorsa (field_id varsa genişlik, yoksa dondurma) o
// dal çalışır; diğer config anahtarları EZİLMEZ — oku, tek anahtarı değiştir,
// geri yaz. Güvenlik: CSRF + require_role('editor') + view_id'nin var olduğu ve
// team_id'nin ondan geldiği kontrolü (view_rename.php ile aynı desen).

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function json_fail($status, $message)
{
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Yalnızca POST.');
}

if (!is_logged_in()) {
    json_fail(401, 'Giriş gerekli.');
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!csrf_verify($token)) {
    json_fail(403, 'Geçersiz istek (CSRF). Sayfayı yenileyip tekrar deneyin.');
}

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;
$isWidthUpdate = isset($_POST['field_id']);

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

    $config = array();
    if ($view['config'] !== null && $view['config'] !== '') {
        $decoded = json_decode($view['config'], true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    if ($isWidthUpdate) {
        $fieldId = (int) $_POST['field_id'];
        $width = isset($_POST['width']) ? (int) $_POST['width'] : 0;

        $fieldExists = bcc_fetch_column(
            'SELECT COUNT(*) FROM fields WHERE id = :id AND table_id = :table_id',
            array(':id' => $fieldId, ':table_id' => $view['table_id'])
        );
        if (!$fieldExists) {
            json_fail(404, 'Alan bulunamadı.');
        }

        if ($width < 80) {
            $width = 80;
        }
        if ($width > 800) {
            $width = 800;
        }

        if (!isset($config['column_widths']) || !is_array($config['column_widths'])) {
            $config['column_widths'] = array();
        }
        $config['column_widths'][(string) $fieldId] = $width;

        $responseData = array('field_id' => $fieldId, 'width' => $width);
        $auditData = array('field_id' => $fieldId, 'width' => $width);
    } else {
        $frozenCount = isset($_POST['frozen_column_count']) ? (int) $_POST['frozen_column_count'] : 1;
        $stateQueryString = isset($_POST['state_query_string']) ? (string) $_POST['state_query_string'] : '';

        // Üst sınır: gerçek GÖRÜNÜR alan sayısına göre (hidden_fields state'i
        // record_add.php'deki AYNI whitelist fonksiyonuyla çözülür) — istemci de
        // aynı kuralı uygular, sunucu burada ayrıca kırpar.
        $fields = bcc_fetch_all('SELECT id FROM fields WHERE table_id = :table_id', array(':table_id' => $view['table_id']));
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

        if ($frozenCount < 1) {
            $frozenCount = 1;
        }
        if ($frozenCount > $maxAllowed) {
            $frozenCount = $maxAllowed;
        }

        $config['frozen_column_count'] = $frozenCount;

        $responseData = array('frozen_column_count' => $frozenCount);
        $auditData = array('frozen_column_count' => $frozenCount);
    }

    bcc_execute(
        'UPDATE views SET config = :config WHERE id = :id',
        array(':config' => json_encode($config, JSON_UNESCAPED_UNICODE), ':id' => $view['id'])
    );

    log_audit('view.config_update', 'view', $view['id'], $auditData, $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true) + $responseData, JSON_UNESCAPED_UNICODE);
