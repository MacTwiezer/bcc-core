<?php
// AJAX uçnoktası: aktif görünümün sort/filter/group/hidden fields/row height/wrap
// headers durumunu (şimdiye kadar yalnızca URL'de taşınıyordu) views.config'in
// grid_state anahtarına kaydeder (docs/PROJE-DURUM.md Kalan İşler #8). Durum,
// grid.php'nin $_GET'i parse ettiği AYNI parse_grid_* fonksiyonlarıyla yeniden
// doğrulanır (istemciden gelen state_query_string güvenilmez sayılır);
// frozen_column_count gibi diğer config anahtarları EZİLMEZ (read-modify-write,
// view_config_update.php ile aynı desen). Güvenlik: CSRF + require_role('editor')
// + view_id'nin var olduğu ve team_id'nin ondan geldiği kontrolü (istekten değil).

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

    $fields = bcc_fetch_all('SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id', array(':table_id' => $view['table_id']));
    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;

    $stateParams = array();
    parse_str($stateQueryString, $stateParams);

    $sortRules = parse_grid_sort_rules($stateParams, $fieldsById);
    $groupRules = parse_grid_group_rules($stateParams, $fieldsById);
    $filterRules = parse_grid_filter_rules($stateParams, $fieldsById);
    $filterLogic = (isset($stateParams['filter_logic']) && $stateParams['filter_logic'] === 'or') ? 'or' : 'and';
    $hiddenFieldIds = parse_grid_hidden_fields($stateParams, $fieldsById, $primaryFieldId);
    $rowHeight = parse_grid_row_height($stateParams);
    $wrapHeaders = parse_grid_wrap_headers($stateParams);

    $gridState = bcc_grid_state_to_array($sortRules, $groupRules, $filterRules, $filterLogic, $hiddenFieldIds, $rowHeight, $wrapHeaders);

    $config = array();
    if ($view['config'] !== null && $view['config'] !== '') {
        $decoded = json_decode($view['config'], true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }
    $config['grid_state'] = $gridState;

    bcc_execute(
        'UPDATE views SET config = :config WHERE id = :id',
        array(':config' => json_encode($config, JSON_UNESCAPED_UNICODE), ':id' => $view['id'])
    );

    log_audit('view.save_state', 'view', $view['id'], array('grid_state' => $gridState), $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
