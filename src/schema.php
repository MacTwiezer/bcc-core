<?php

require_once __DIR__ . '/auth.php';

$GLOBALS['BCC_FIELD_TYPES'] = array(
    'single_line_text' => 'Tek satır metin',
    'long_text' => 'Uzun metin',
    'number' => 'Sayı',
    'checkbox' => 'Onay kutusu',
    'date' => 'Tarih',
    'single_select' => 'Tekli seçim',
    'multiple_select' => 'Çoklu seçim',
    'time' => 'Saat',
    'user' => 'Kullanıcı',
    'attachment' => 'Dosya eki',

    'created_time' => 'Oluşturulma zamanı',
    'created_by' => 'Oluşturan',

    'last_modified_time' => 'Son değişiklik zamanı',
    'last_modified_by' => 'Son değiştiren',

    'currency' => 'Para birimi',
    'percent' => 'Yüzde',
    'rating' => 'Değerlendirme',

    'autonumber' => 'Otomatik numara',

    'url' => 'URL',
    'email' => 'E-posta',
    'phone' => 'Telefon numarası',
);

$GLOBALS['BCC_SELECT_FIELD_TYPES'] = array('single_select', 'multiple_select');

$GLOBALS['BCC_USER_VALUE_FIELD_TYPES'] = array('user', 'created_by', 'last_modified_by');

function bcc_is_user_value_field_type($fieldType)
{
    return in_array($fieldType, $GLOBALS['BCC_USER_VALUE_FIELD_TYPES'], true);
}

$GLOBALS['BCC_READONLY_FIELD_TYPES'] = array(
    'created_time', 'created_by', 'last_modified_time', 'last_modified_by', 'autonumber',
);

$GLOBALS['BCC_VIEW_TYPES'] = array(
    'grid' => 'Tablo görünümü',
    'kanban' => 'Kanban',
);

$GLOBALS['BCC_VIEW_ROUTES'] = array(
    'grid' => '/grid.php',
    'kanban' => '/kanban.php',
);

function bcc_view_route_for($viewType, $tableId, $viewId)
{
    $page = isset($GLOBALS['BCC_VIEW_ROUTES'][$viewType])
        ? $GLOBALS['BCC_VIEW_ROUTES'][$viewType]
        : '/grid.php';

    return $page . '?table_id=' . (int) $tableId . '&view_id=' . (int) $viewId;
}

function bcc_config_field_id_list($config, $key)
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        return array();
    }

    $ids = array();
    foreach ($config[$key] as $rawId) {
        if (!is_scalar($rawId)) {
            continue;
        }
        $fid = (int) $rawId;
        if ($fid > 0 && !in_array($fid, $ids, true)) {
            $ids[] = $fid;
        }
    }

    return $ids;
}

function bcc_kanban_config_from_view($view)
{
    $config = array();
    if (isset($view['config']) && $view['config'] !== null && $view['config'] !== '') {
        $decoded = json_decode($view['config'], true);
        $config = is_array($decoded) ? $decoded : array();
    }

    return array(
        'kanban_field_id' => (isset($config['kanban_field_id']) && is_scalar($config['kanban_field_id']))
            ? (int) $config['kanban_field_id']
            : 0,

        'kanban_card_fields' => bcc_config_field_id_list($config, 'kanban_card_fields'),
    );
}

function bcc_field_allowed_for_kanban($fieldType)
{
    return $fieldType === 'single_select';
}

function bcc_update_view_config($viewId, array $changes)
{
    $current = bcc_fetch_column('SELECT config FROM views WHERE id = :id', array(':id' => $viewId));

    $config = array();
    if ($current !== false && $current !== null && $current !== '') {
        $decoded = json_decode($current, true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($config[$key]);
            continue;
        }
        $config[$key] = $value;
    }

    bcc_execute(
        'UPDATE views SET config = :config WHERE id = :id',
        array(':config' => json_encode($config, JSON_UNESCAPED_UNICODE), ':id' => $viewId)
    );

    return $config;
}

$GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES'] = array('single_line_text', 'long_text');

$GLOBALS['BCC_LINKIFIED_FIELD_TYPES'] = array('url', 'email', 'phone');

define('BCC_CELL_LINK_SCHEMES', '#^(https?://|mailto:|tel:)#i');

$GLOBALS['BCC_FIELD_VALUE_COLUMN'] = array(
    'single_line_text' => 'value_text',
    'long_text' => 'value_text',
    'number' => 'value_number',
    'checkbox' => 'value_number',
    'date' => 'value_date',
    'single_select' => 'value_text',
    'multiple_select' => 'value_json',

    'time' => 'value_text',

    'user' => 'value_number',

    'created_time' => 'value_date',
    'created_by' => 'value_number',

    'last_modified_time' => 'value_date',
    'last_modified_by' => 'value_number',

    'currency' => 'value_number',
    'percent' => 'value_number',
    'rating' => 'value_number',

    'autonumber' => 'value_number',

    'url' => 'value_text',
    'email' => 'value_text',
    'phone' => 'value_text',
);

$GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'] = array(
    'created_time' => 'created_at',
    'created_by' => 'created_by',
    'last_modified_time' => 'updated_at',
    'last_modified_by' => 'updated_by',
);

function bcc_record_column_expr($fieldType, $alias)
{
    if ($fieldType === 'last_modified_by') {
        return "COALESCE({$alias}.updated_by, {$alias}.created_by)";
    }

    return $alias . '.' . $GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'][$fieldType];
}

$GLOBALS['BCC_FIELD_TYPE_BADGE'] = array(
    'single_line_text' => 'Aa',
    'long_text' => '¶',
    'number' => '#',
    'checkbox' => '☑',
    'date' => '📅',
    'single_select' => '▾',
    'multiple_select' => '☰',
    'time' => '🕐',
    'user' => '@',
    'attachment' => '📎',
    'created_time' => '🕐',
    'created_by' => '@',
    'last_modified_time' => '🕐',
    'last_modified_by' => '@',
    'currency' => '💲',
    'percent' => '%',
    'rating' => '★',

    'autonumber' => '#',

    'url' => '🔗',
    'email' => '✉',
    'phone' => '☎',
);

$GLOBALS['BCC_FILTER_MAX_SLOTS'] = 5;

$GLOBALS['BCC_SORT_MAX_SLOTS'] = 3;

$GLOBALS['BCC_FILTER_OPERATORS'] = array(
    'single_line_text' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'long_text' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'single_select' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),

    'url' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'email' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'phone' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'number' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'checkbox' => array(
        'checked' => 'işaretli', 'unchecked' => 'işaretsiz',
    ),
    'date' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'multiple_select' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
    ),
    'time' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'user' => array(
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),

    'created_time' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
    ),

    'created_by' => array(
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),

    'last_modified_time' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
    ),

    'last_modified_by' => array(
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),

    'currency' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'percent' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'rating' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),

    'autonumber' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
);

$GLOBALS['BCC_FILTER_NO_VALUE_OPS'] = array('empty', 'not_empty', 'checked', 'unchecked');

$GLOBALS['BCC_SLACK_ROUTING_OPERATORS'] = array(
    'equals' => 'eşittir',
    'not_equals' => 'eşit değil',
);

$GLOBALS['BCC_GROUP_DIR_LABELS'] = array(
    'single_line_text' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'long_text' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'single_select' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'multiple_select' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'number' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    'date' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),
    'checkbox' => array('asc' => 'İşaretsiz → İşaretli', 'desc' => 'İşaretli → İşaretsiz'),
    'time' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),

    'user' => array('asc' => 'Küçük → Büyük', 'desc' => 'Büyük → Küçük'),

    'created_time' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),
    'last_modified_time' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),
    'created_by' => array('asc' => 'Küçük → Büyük', 'desc' => 'Büyük → Küçük'),
    'last_modified_by' => array('asc' => 'Küçük → Büyük', 'desc' => 'Büyük → Küçük'),

    'currency' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    'percent' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    'autonumber' => array('asc' => '1 → 9', 'desc' => '9 → 1'),

    'rating' => array('asc' => 'Az → Çok', 'desc' => 'Çok → Az'),

    'url' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'email' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'phone' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
);

function bcc_dir_labels($fieldType)
{
    $map = $GLOBALS['BCC_GROUP_DIR_LABELS'];

    return isset($map[$fieldType]) ? $map[$fieldType] : array('asc' => 'artan', 'desc' => 'azalan');
}

$GLOBALS['BCC_CHOICE_COLORS'] = array(
    'blue' => '#cfe2ff',
    'cyan' => '#cdf3f5',
    'teal' => '#d0f0e8',
    'green' => '#d7f0d1',
    'yellow' => '#fdf1c7',
    'orange' => '#fde2c8',
    'red' => '#fbdbd7',
    'pink' => '#fbdce8',
    'purple' => '#e6d9f7',
    'gray' => '#e6e6e9',
);

$GLOBALS['BCC_RICH_TEXT_FONT_SIZES'] = array(10, 12, 14, 16, 18, 24, 32);

$GLOBALS['BCC_ROW_HEIGHT_LABELS'] = array(
    'short' => 'Kısa',
    'medium' => 'Orta',
    'tall' => 'Uzun',
    'extra' => 'Ekstra uzun',
);

function find_base_or_404($baseId)
{
    $base = bcc_fetch_one(
        'SELECT id, team_id, name, description, icon, icon_color FROM bases WHERE id = :id AND deleted_at IS NULL LIMIT 1',
        array('id' => $baseId)
    );

    if (!$base) {
        bcc_error_page('Base bulunamadı', 'Aradığınız base silinmiş ya da adresi değişmiş olabilir.', 404);
    }

    return $base;
}

function find_table_or_404($tableId)
{
    $table = bcc_fetch_one(
        'SELECT tm.id, tm.base_id, tm.name, tm.description, tm.position, b.team_id, b.name AS base_name,
                b.icon AS base_icon, b.icon_color AS base_icon_color
         FROM tables_meta tm
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE tm.id = :id AND b.deleted_at IS NULL LIMIT 1',
        array('id' => $tableId)
    );

    if (!$table) {
        bcc_error_page('Tablo bulunamadı', 'Aradığınız tablo silinmiş ya da adresi değişmiş olabilir.', 404);
    }

    return $table;
}

function bcc_get_or_create_default_view($tableId)
{

    $sql = 'SELECT v.id, v.name, v.description, v.config, v.created_by, v.view_type,
                   u.full_name AS created_by_name
            FROM views v
            LEFT JOIN users u ON u.id = v.created_by
            WHERE v.table_id = :table_id ORDER BY v.id ASC LIMIT 1';

    $view = bcc_fetch_one($sql, array('table_id' => $tableId));

    if ($view) {
        return $view;
    }

    $creator = current_user();

    bcc_execute(
        'INSERT INTO views (table_id, name, view_type, created_by)
         SELECT :table_id, :name, :view_type, :created_by
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM views WHERE table_id = :table_id)',
        array(
            'table_id' => $tableId,
            'name' => 'Tablo görünümü',
            'view_type' => 'grid',
            'created_by' => $creator ? $creator['id'] : null,
        )
    );

    return bcc_fetch_one($sql, array('table_id' => $tableId));
}

function bcc_find_view_by_id($viewId)
{
    return bcc_fetch_one(
        'SELECT v.id, v.table_id, v.view_type, v.config, b.team_id
         FROM views v
         INNER JOIN tables_meta tm ON tm.id = v.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE v.id = :id AND b.deleted_at IS NULL LIMIT 1',
        array('id' => $viewId)
    );
}

function bcc_find_view($viewId, $tableId)
{
    return bcc_fetch_one(

        'SELECT v.id, v.name, v.description, v.config, v.created_by, v.view_type,
                   u.full_name AS created_by_name
         FROM views v
         LEFT JOIN users u ON u.id = v.created_by
         WHERE v.id = :id AND v.table_id = :table_id LIMIT 1',
        array('id' => $viewId, 'table_id' => $tableId)
    );
}

function bcc_list_table_views($tableId, $userId = null)
{
    return bcc_fetch_all(

        'SELECT v.id, v.name, v.description, v.position, v.created_by, v.view_type,
                u.full_name AS created_by_name,
                (ufv.id IS NOT NULL) AS is_favorite
         FROM views v
         LEFT JOIN users u ON u.id = v.created_by
         LEFT JOIN user_favorite_views ufv ON ufv.view_id = v.id AND ufv.user_id = :user_id
         WHERE v.table_id = :table_id
         ORDER BY is_favorite DESC, v.position, v.id',
        array('table_id' => $tableId, 'user_id' => $userId)
    );
}

function bcc_max_frozen_columns($visibleFieldCount)
{
    $total = $visibleFieldCount + 1;

    return max(1, (int) ceil($total / 2));
}

$GLOBALS['BCC_DEFAULT_FROZEN_COLUMNS'] = 2;

function bcc_get_frozen_column_count($configJson, $maxAllowed = null)
{
    $count = $GLOBALS['BCC_DEFAULT_FROZEN_COLUMNS'];

    if ($configJson !== null && $configJson !== '') {
        $decoded = json_decode($configJson, true);
        if (is_array($decoded) && isset($decoded['frozen_column_count']) && is_int($decoded['frozen_column_count'])) {
            $count = $decoded['frozen_column_count'];
        }
    }

    if ($count < 1) {
        $count = 1;
    }
    if ($maxAllowed !== null && $count > $maxAllowed) {
        $count = $maxAllowed;
    }

    return $count;
}

$GLOBALS['BCC_MIN_COLUMN_WIDTH'] = 80;
$GLOBALS['BCC_MAX_COLUMN_WIDTH'] = 800;

$GLOBALS['BCC_DEFAULT_COLUMN_WIDTH'] = 180;

$GLOBALS['BCC_ROW_COLUMN_WIDTH'] = 44;

function bcc_get_column_widths($configJson, $visibleFields = null)
{
    if ($configJson === null || $configJson === '') {
        return array();
    }

    $decoded = json_decode($configJson, true);
    if (!is_array($decoded) || !isset($decoded['column_widths']) || !is_array($decoded['column_widths'])) {
        return array();
    }

    $allowed = null;
    if ($visibleFields !== null) {
        $allowed = array('row' => true);
        foreach ($visibleFields as $f) {
            $allowed['f' . (int) $f['id']] = true;
        }
    }

    $out = array();
    foreach ($decoded['column_widths'] as $key => $value) {
        $key = (string) $key;
        if ($allowed !== null && !isset($allowed[$key])) {
            continue;
        }
        if (!is_int($value) && !(is_float($value) && $value == (int) $value)) {
            continue;
        }
        $out[$key] = bcc_clamp_column_width((int) $value);
    }

    return $out;
}

function bcc_clamp_column_width($width)
{
    $width = (int) $width;

    if ($width < $GLOBALS['BCC_MIN_COLUMN_WIDTH']) {
        return $GLOBALS['BCC_MIN_COLUMN_WIDTH'];
    }
    if ($width > $GLOBALS['BCC_MAX_COLUMN_WIDTH']) {
        return $GLOBALS['BCC_MAX_COLUMN_WIDTH'];
    }

    return $width;
}

function bcc_sanitize_column_widths($raw, $fieldsById)
{
    if (!is_array($raw)) {
        return array();
    }

    $out = array();
    foreach ($raw as $key => $value) {
        $key = (string) $key;

        if ($key !== 'row') {
            if (strpos($key, 'f') !== 0) {
                continue;
            }
            $fieldId = (int) substr($key, 1);
            if ($fieldId <= 0 || !isset($fieldsById[$fieldId])) {
                continue;
            }
        }

        if (!is_numeric($value)) {
            continue;
        }

        $out[$key] = bcc_clamp_column_width((int) round((float) $value));
    }

    return $out;
}

function bcc_list_base_tables($baseId)
{

    return bcc_fetch_all(
        'SELECT id, name, description FROM tables_meta WHERE base_id = :base_id ORDER BY position, id',
        array('base_id' => $baseId)
    );
}

function is_select_field_type($fieldType)
{
    return in_array($fieldType, $GLOBALS['BCC_SELECT_FIELD_TYPES'], true);
}

function parse_select_choices($optionsText)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $optionsText);
    $choices = array();

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $choices[] = $line;
        }
    }

    return $choices;
}

function select_choices_from_options($optionsJson)
{
    if ($optionsJson === null || $optionsJson === '') {
        return array();
    }

    $decoded = json_decode($optionsJson, true);

    if (is_array($decoded) && isset($decoded['choices']) && is_array($decoded['choices'])) {
        return $decoded['choices'];
    }

    return array();
}

function select_choice_colors_from_options($optionsJson)
{
    if ($optionsJson === null || $optionsJson === '') {
        return array();
    }

    $decoded = json_decode($optionsJson, true);

    if (is_array($decoded) && isset($decoded['colors']) && is_array($decoded['colors'])) {
        return $decoded['colors'];
    }

    return array();
}

function bcc_resolved_choice_color_key($choiceColors, $choice, $index)
{
    $palette = $GLOBALS['BCC_CHOICE_COLORS'];

    if (isset($choiceColors[$choice]) && isset($palette[$choiceColors[$choice]])) {
        return $choiceColors[$choice];
    }

    $keys = array_keys($palette);

    return $keys[$index % count($keys)];
}

function bcc_build_choice_color_map($choices, $savedColors)
{
    $map = array();
    foreach ($choices as $i => $choiceText) {
        $map[$choiceText] = bcc_resolved_choice_color_key($savedColors, $choiceText, $i);
    }

    return $map;
}

function bcc_choice_chip_data($values, $choiceColorMap)
{
    $palette = $GLOBALS['BCC_CHOICE_COLORS'];
    $chips = array();

    foreach ($values as $value) {
        $colorKey = isset($choiceColorMap[$value]) ? $choiceColorMap[$value] : null;
        $hex = ($colorKey !== null && isset($palette[$colorKey])) ? $palette[$colorKey] : $palette['gray'];
        $chips[] = array('text' => (string) $value, 'color' => $hex);
    }

    return $chips;
}

function bcc_build_field_options($fieldType, $optionsText, $colorsPost = null, $extraPost = array())
{
    if ($fieldType === 'currency') {
        $symbol = isset($extraPost['currency_symbol']) ? trim((string) $extraPost['currency_symbol']) : '';
        $symbol = $symbol !== '' ? mb_substr($symbol, 0, 5, 'UTF-8') : '₺';
        $decimals = (isset($extraPost['currency_decimal_places']) && ctype_digit((string) $extraPost['currency_decimal_places']))
            ? min(6, max(0, (int) $extraPost['currency_decimal_places']))
            : 2;

        return array('ok' => true, 'options' => json_encode(array('currency_symbol' => $symbol, 'decimal_places' => $decimals), JSON_UNESCAPED_UNICODE));
    }

    if ($fieldType === 'percent') {
        $decimals = (isset($extraPost['percent_decimal_places']) && ctype_digit((string) $extraPost['percent_decimal_places']))
            ? min(6, max(0, (int) $extraPost['percent_decimal_places']))
            : 0;

        return array('ok' => true, 'options' => json_encode(array('decimal_places' => $decimals), JSON_UNESCAPED_UNICODE));
    }

    if ($fieldType === 'rating') {
        $maxRating = (isset($extraPost['max_rating']) && ctype_digit((string) $extraPost['max_rating']))
            ? min(10, max(1, (int) $extraPost['max_rating']))
            : 5;

        return array('ok' => true, 'options' => json_encode(array('max_rating' => $maxRating), JSON_UNESCAPED_UNICODE));
    }

    if (!is_select_field_type($fieldType)) {
        return array('ok' => true, 'options' => null);
    }

    $choices = parse_select_choices($optionsText);
    if (empty($choices)) {
        return array('ok' => false, 'error' => 'Tekli/çoklu seçim alanları için en az bir seçenek girilmeli (her satıra bir tane).');
    }

    $optionsData = array('choices' => $choices);

    if (is_array($colorsPost)) {
        $palette = $GLOBALS['BCC_CHOICE_COLORS'];
        $colors = array();
        foreach ($colorsPost as $i => $colorKey) {
            if (!ctype_digit((string) $i) || !isset($choices[(int) $i]) || !isset($palette[$colorKey])) {
                continue;
            }
            $colors[$choices[(int) $i]] = $colorKey;
        }
        if (!empty($colors)) {
            $optionsData['colors'] = $colors;
        }
    }

    return array('ok' => true, 'options' => json_encode($optionsData, JSON_UNESCAPED_UNICODE));
}

function bcc_normalize_is_required($fieldType, $rawIsRequired)
{
    if ($fieldType === 'autonumber') {
        return 0;
    }

    return !empty($rawIsRequired) ? 1 : 0;
}

function bcc_create_field($tableId, $teamId, $postData)
{
    $fieldTypes = $GLOBALS['BCC_FIELD_TYPES'];
    $name = isset($postData['name']) ? trim($postData['name']) : '';
    $fieldType = isset($postData['field_type']) ? $postData['field_type'] : '';
    $isRequired = bcc_normalize_is_required($fieldType, isset($postData['is_required']) ? $postData['is_required'] : null);
    $optionsText = isset($postData['options_text']) ? $postData['options_text'] : '';

    if ($name === '') {
        return array('ok' => false, 'error' => 'Alan adı boş olamaz.');
    }
    if (mb_strlen($name, 'UTF-8') > 150) {

        return array('ok' => false, 'error' => 'Alan adı en fazla 150 karakter olabilir.');
    }
    if (!isset($fieldTypes[$fieldType])) {
        return array('ok' => false, 'error' => 'Geçersiz alan tipi.');
    }

    if (bcc_name_taken('fields', $tableId, $name)) {
        return array('ok' => false, 'error' => bcc_name_taken_error('fields', 'alan'));
    }

    $optionsResult = bcc_build_field_options($fieldType, $optionsText, isset($postData['colors']) ? $postData['colors'] : null, $postData);
    if (!$optionsResult['ok']) {
        return array('ok' => false, 'error' => $optionsResult['error']);
    }

    $nextPos = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM fields WHERE table_id = :table_id',
        array('table_id' => $tableId)
    );

    try {
        bcc_begin_transaction();

        bcc_execute(
            'INSERT INTO fields (table_id, name, field_type, options, position, is_required)
             VALUES (:table_id, :name, :field_type, :options, :position, :is_required)',
            array(
                'table_id' => $tableId,
                'name' => $name,
                'field_type' => $fieldType,
                'options' => $optionsResult['options'],
                'position' => $nextPos,
                'is_required' => $isRequired,
            )
        );
        $newId = bcc_last_insert_id();

        if ($fieldType === 'autonumber') {
            bcc_backfill_autonumber_field((int) $newId, $tableId);
        }

        log_audit('field.create', 'field', $newId, array('name' => $name, 'field_type' => $fieldType, 'table_id' => $tableId), $teamId);

        bcc_commit();
    } catch (Throwable $e) {
        bcc_rollback();
        throw $e;
    }

    $creator = current_user();
    bcc_notify_slack_new_field(
        $tableId,
        (int) $newId,
        $name,
        $fieldType,
        $creator ? $creator['full_name'] : null
    );

    return array('ok' => true, 'field_id' => $newId, 'name' => $name, 'field_type' => $fieldType, 'is_required' => $isRequired);
}

function bcc_render_choice_chips($values, $choiceColorMap)
{
    $palette = $GLOBALS['BCC_CHOICE_COLORS'];

    foreach ($values as $value) {
        $colorKey = isset($choiceColorMap[$value]) ? $choiceColorMap[$value] : null;
        $hex = ($colorKey !== null && isset($palette[$colorKey])) ? $palette[$colorKey] : $palette['gray'];
        ?>
        <span class="choice-chip" style="background:<?php echo htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php
    }
}

function bcc_render_grid_state_hidden_inputs($state)
{
    foreach ($state as $key => $value) {
        ?>
        <input type="hidden" name="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
        <?php
    }
}

function bcc_team_users_by_id($teamId)
{
    $rows = bcc_fetch_all(
        'SELECT u.id, u.full_name
         FROM team_members tm
         INNER JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = :team_id AND u.is_active = 1
         ORDER BY u.full_name',
        array('team_id' => $teamId)
    );

    $byId = array();
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row['full_name'];
    }

    return $byId;
}

function bcc_team_members_invited_by($teamId)
{
    $rows = bcc_fetch_all(
        "SELECT al.details, u.full_name AS actor_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.team_id = :team_id AND al.action IN ('team_member.assign', 'team_member.role_change')
         ORDER BY al.created_at ASC, al.id ASC",
        array('team_id' => $teamId)
    );

    $byTargetUserId = array();
    foreach ($rows as $row) {
        $details = $row['details'] !== null ? json_decode($row['details'], true) : null;
        if (!is_array($details) || !isset($details['user_id'])) {
            continue;
        }
        $byTargetUserId[(int) $details['user_id']] = $row['actor_name'];
    }

    return $byTargetUserId;
}

function bcc_team_members_with_roles($teamId)
{
    return bcc_fetch_all(
        'SELECT u.id, u.full_name, u.email, u.is_active, tm.role, tm.created_at
         FROM team_members tm
         INNER JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = :team_id
         ORDER BY u.full_name',
        array('team_id' => $teamId)
    );
}

function bcc_team_member_assign($teamId, $targetUserId, $role, $myRank, $assignableRoles)
{
    $teamId = (int) $teamId;
    $targetUserId = (int) $targetUserId;

    if ($targetUserId <= 0 || !in_array($role, $assignableRoles, true)) {
        return array('ok' => false, 'error' => 'Geçersiz seçim.', 'created' => false);
    }

    if (!bcc_fetch_one('SELECT id FROM users WHERE id = :id AND is_active = 1', array('id' => $targetUserId))) {
        return array('ok' => false, 'error' => 'Kullanıcı bulunamadı.', 'created' => false);
    }

    $existingMember = bcc_fetch_one(
        'SELECT id, role FROM team_members WHERE team_id = :team_id AND user_id = :user_id',
        array('team_id' => $teamId, 'user_id' => $targetUserId)
    );

    if ($existingMember && $GLOBALS['BCC_ROLE_RANK'][$existingMember['role']] > $myRank) {
        return array('ok' => false, 'error' => 'Bu kullanıcıyı yönetme yetkiniz yok.', 'created' => false);
    }

    bcc_execute(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)
         ON DUPLICATE KEY UPDATE role = VALUES(role)',
        array('team_id' => $teamId, 'user_id' => $targetUserId, 'role' => $role)
    );

    $auditAction = $existingMember ? 'team_member.role_change' : 'team_member.assign';
    log_audit($auditAction, 'team_member', null, array('team_id' => $teamId, 'user_id' => $targetUserId, 'role' => $role), $teamId);

    return array('ok' => true, 'error' => null, 'created' => !$existingMember);
}

function bcc_team_member_remove_many($teamId, $targetUserIds, $actorUserId, $myRank)
{
    $teamId = (int) $teamId;
    $actorUserId = (int) $actorUserId;
    $removedIds = array();
    $skipped = 0;

    foreach ($targetUserIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            $skipped++;
            continue;
        }

        if ($id === $actorUserId) {

            $skipped++;
            continue;
        }

        $targetMember = bcc_fetch_one(
            'SELECT role FROM team_members WHERE team_id = :team_id AND user_id = :user_id',
            array('team_id' => $teamId, 'user_id' => $id)
        );

        if (!$targetMember) {
            $skipped++;
            continue;
        }

        if ($GLOBALS['BCC_ROLE_RANK'][$targetMember['role']] > $myRank) {
            $skipped++;
            continue;
        }

        if ($targetMember['role'] === 'owner') {
            $ownerCount = (int) bcc_fetch_column(
                "SELECT COUNT(*) FROM team_members WHERE team_id = :team_id AND role = 'owner'",
                array('team_id' => $teamId)
            );
            if ($ownerCount <= 1) {
                $skipped++;
                continue;
            }
        }

        bcc_execute('DELETE FROM team_members WHERE team_id = :team_id AND user_id = :user_id', array('team_id' => $teamId, 'user_id' => $id));
        $removedIds[] = $id;
    }

    if (!empty($removedIds)) {

        log_audit('team_member.remove', 'team', $teamId, array('user_ids' => $removedIds), $teamId);
    }

    return array('removed' => $removedIds, 'skipped' => $skipped);
}

function bcc_team_member_remove_message($result)
{
    $removedCount = count($result['removed']);

    if ($removedCount === 0) {
        return array('error' => 'Kimse çıkarılamadı (kendiniz, son owner veya yetkiniz dışındaki bir rütbe).', 'success' => null);
    }
    if ($result['skipped'] > 0) {
        return array('error' => null, 'success' => $removedCount . ' kişi çıkarıldı, ' . $result['skipped'] . ' kişi atlandı (kendiniz, son owner veya yetkiniz dışında).');
    }

    return array(
        'error' => null,
        'success' => $removedCount === 1 ? 'Ekipten çıkarıldı.' : $removedCount . ' kişi ekipten çıkarıldı.',
    );
}

function bcc_find_field($fieldId)
{
    return bcc_fetch_one(
        'SELECT f.id, f.table_id, f.name, f.field_type, f.options, f.is_required, tm.base_id, b.team_id
         FROM fields f
         INNER JOIN tables_meta tm ON tm.id = f.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE f.id = :id LIMIT 1',
        array('id' => $fieldId)
    );
}

function bcc_find_record($recordId)
{
    return bcc_fetch_one(
        'SELECT r.id, r.table_id, tm.base_id, b.team_id
         FROM records r
         INNER JOIN tables_meta tm ON tm.id = r.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE r.id = :id LIMIT 1',
        array('id' => $recordId)
    );
}

function bcc_find_attachment($attachmentId)
{
    return bcc_fetch_one(
        'SELECT a.id, a.field_id, a.record_id, a.original_name, a.stored_name, a.mime_type, a.file_size,
                f.table_id, tm.base_id, b.team_id
         FROM attachments a
         INNER JOIN fields f ON f.id = a.field_id
         INNER JOIN tables_meta tm ON tm.id = f.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE a.id = :id LIMIT 1',
        array('id' => $attachmentId)
    );
}

function bcc_attachment_storage_dir()
{
    return __DIR__ . '/../storage/attachments';
}

function bcc_attachment_storage_dir_ensured()
{
    $dir = bcc_attachment_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function bcc_attachment_storage_path($storedName)
{
    return bcc_attachment_storage_dir() . '/' . $storedName;
}

function bcc_delete_attachment_files_by_record($recordId)
{
    $rows = bcc_fetch_all('SELECT stored_name FROM attachments WHERE record_id = :id', array('id' => $recordId));
    foreach ($rows as $row) {
        $path = bcc_attachment_storage_path($row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function bcc_delete_attachment_files_by_records(array $recordIds)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $recordIds))));
    if (!$ids) {
        return;
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (bcc_fetch_all("SELECT stored_name FROM attachments WHERE record_id IN ($ph)", $ids) as $row) {
        $path = bcc_attachment_storage_path($row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function bcc_delete_attachment_files_by_field($fieldId)
{
    $rows = bcc_fetch_all('SELECT stored_name FROM attachments WHERE field_id = :id', array('id' => $fieldId));
    foreach ($rows as $row) {
        $path = bcc_attachment_storage_path($row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function bcc_delete_attachment_files_by_table($tableId)
{
    $rows = bcc_fetch_all(
        'SELECT a.stored_name FROM attachments a INNER JOIN records r ON r.id = a.record_id WHERE r.table_id = :id',
        array('id' => $tableId)
    );
    foreach ($rows as $row) {
        $path = bcc_attachment_storage_path($row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function bcc_attachment_type_badge($mimeType)
{
    $map = array(
        'application/pdf' => 'PDF',
        'application/msword' => 'DOC',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOC',
        'application/vnd.ms-excel' => 'XLS',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLS',
        'application/vnd.ms-powerpoint' => 'PPT',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PPT',
    );

    return isset($map[$mimeType]) ? $map[$mimeType] : 'DOSYA';
}

function cell_raw_value($fieldType, $cellRow)
{
    if ($cellRow === null) {
        return $fieldType === 'multiple_select' ? '[]' : '';
    }

    switch ($fieldType) {
        case 'single_line_text':
        case 'long_text':
        case 'single_select':

        case 'url':
        case 'email':
        case 'phone':
            return (string) $cellRow['value_text'];
        case 'number':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';
        case 'checkbox':
            return ((int) $cellRow['value_number'] === 1) ? '1' : '0';
        case 'date':
            return $cellRow['value_date'] !== null ? substr($cellRow['value_date'], 0, 10) : '';
        case 'multiple_select':
            return $cellRow['value_json'] !== null ? $cellRow['value_json'] : '[]';
        case 'time':
            return (string) $cellRow['value_text'];
        case 'user':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';

        case 'currency':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';

        case 'autonumber':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';

        case 'percent':
            return $cellRow['value_number'] !== null ? (string) ((float) $cellRow['value_number'] * 100) : '';

        case 'rating':
            return $cellRow['value_number'] !== null ? (string) (int) round((float) $cellRow['value_number']) : '';

        case 'created_time':
        case 'last_modified_time':
            return $cellRow['value_date'] !== null ? (string) $cellRow['value_date'] : '';
        case 'created_by':
        case 'last_modified_by':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';
        default:
            return '';
    }
}

function cell_display_text($fieldType, $cellRow, $usersById = array(), $options = null)
{
    if ($cellRow === null) {
        return '';
    }

    if (is_string($options)) {
        $decodedOptions = json_decode($options, true);
        $options = is_array($decodedOptions) ? $decodedOptions : null;
    }

    switch ($fieldType) {
        case 'single_line_text':
        case 'long_text':
        case 'single_select':

        case 'url':
        case 'email':
        case 'phone':
            return (string) $cellRow['value_text'];
        case 'number':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';

        case 'autonumber':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';

        case 'currency':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $curOpts = is_array($options) ? $options : array();
            $symbol = isset($curOpts['currency_symbol']) && $curOpts['currency_symbol'] !== '' ? $curOpts['currency_symbol'] : '₺';
            $decimals = isset($curOpts['decimal_places']) ? (int) $curOpts['decimal_places'] : 2;
            return $symbol . number_format((float) $cellRow['value_number'], $decimals, ',', '.');

        case 'percent':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $pctOpts = is_array($options) ? $options : array();
            $decimals = isset($pctOpts['decimal_places']) ? (int) $pctOpts['decimal_places'] : 0;

            return '%' . number_format((float) $cellRow['value_number'] * 100, $decimals, ',', '.');

        case 'rating':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $ratingOpts = is_array($options) ? $options : array();
            $maxRating = isset($ratingOpts['max_rating']) ? (int) $ratingOpts['max_rating'] : 5;
            $val = max(0, min($maxRating, (int) round((float) $cellRow['value_number'])));
            return str_repeat('★', $val) . str_repeat('☆', max(0, $maxRating - $val));
        case 'checkbox':

            return ((int) $cellRow['value_number'] === 1) ? 'İşaretli' : 'İşaretsiz';
        case 'date':
            return $cellRow['value_date'] !== null ? date('d.m.Y', strtotime($cellRow['value_date'])) : '';
        case 'multiple_select':
            $choices = $cellRow['value_json'] !== null ? json_decode($cellRow['value_json'], true) : array();
            return is_array($choices) ? implode(', ', $choices) : '';
        case 'time':
            return (string) $cellRow['value_text'];
        case 'user':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $userId = (int) $cellRow['value_number'];
            return isset($usersById[$userId]) ? $usersById[$userId] : '';

        case 'created_time':
        case 'last_modified_time':
            return $cellRow['value_date'] !== null ? date('d.m.Y H:i', strtotime($cellRow['value_date'])) : '';
        case 'created_by':
        case 'last_modified_by':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $userId = (int) $cellRow['value_number'];
            return isset($usersById[$userId]) ? $usersById[$userId] : '';
        default:
            return '';
    }
}

function bcc_user_choices_from_map($usersById)
{
    $choices = array();
    foreach ($usersById as $id => $name) {
        $choices[] = array('id' => $id, 'name' => $name);
    }

    return $choices;
}

function bcc_touch_record_modified($recordId)
{
    $user = current_user();
    bcc_execute(
        'UPDATE records SET updated_at = NOW(), updated_by = :uid WHERE id = :id',
        array(':uid' => $user ? $user['id'] : null, ':id' => $recordId)
    );
}

function bcc_assign_autonumbers($tableId, $recordId)
{
    $autoFields = bcc_fetch_all(
        "SELECT id FROM fields WHERE table_id = :tid AND field_type = 'autonumber'",
        array(':tid' => $tableId)
    );

    foreach ($autoFields as $af) {
        $fieldId = (int) $af['id'];

        bcc_execute(
            'UPDATE fields SET autonumber_next = LAST_INSERT_ID(autonumber_next) + 1 WHERE id = :fid',
            array(':fid' => $fieldId)
        );
        $number = (int) bcc_fetch_column('SELECT LAST_INSERT_ID()');

        bcc_execute(
            'INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:rid, :fid, :val)',
            array(':rid' => $recordId, ':fid' => $fieldId, ':val' => $number)
        );
    }
}

function bcc_backfill_autonumber_field($fieldId, $tableId)
{
    $start = (int) bcc_fetch_column(
        'SELECT autonumber_next FROM fields WHERE id = :fid',
        array(':fid' => $fieldId)
    );

    bcc_execute(
        'INSERT INTO cell_values (record_id, field_id, value_number)
         SELECT r.id, :fid, :start + ROW_NUMBER() OVER (ORDER BY r.position, r.id) - 1
         FROM records r
         LEFT JOIN cell_values cv ON cv.record_id = r.id AND cv.field_id = :fid2
         WHERE r.table_id = :tid AND cv.value_number IS NULL
         ON DUPLICATE KEY UPDATE value_number = VALUES(value_number)',
        array(':fid' => $fieldId, ':start' => $start, ':fid2' => $fieldId, ':tid' => $tableId)
    );

    bcc_execute(
        'UPDATE fields f
         SET f.autonumber_next = GREATEST(
             f.autonumber_next,
             COALESCE((SELECT MAX(cv.value_number) FROM cell_values cv WHERE cv.field_id = f.id), 0) + 1
         )
         WHERE f.id = :fid',
        array(':fid' => $fieldId)
    );
}

function bcc_cell_row_for_field($fieldType, $record, $cellsByRecord, $fieldId)
{
    if ($fieldType === 'created_time') {
        return bcc_group_cell_row('value_date', $record['created_at']);
    }
    if ($fieldType === 'created_by') {
        return bcc_group_cell_row('value_number', $record['created_by']);
    }
    if ($fieldType === 'last_modified_time') {

        return bcc_group_cell_row('value_date', $record['updated_at']);
    }
    if ($fieldType === 'last_modified_by') {

        $value = $record['updated_by'] !== null ? $record['updated_by'] : $record['created_by'];
        return bcc_group_cell_row('value_number', $value);
    }

    return isset($cellsByRecord[$record['id']][$fieldId]) ? $cellsByRecord[$record['id']][$fieldId] : null;
}

function bcc_render_grid_row_fields_json($allFields, $record, $cellsByRecord, $usersById, $attachmentsByRecord = array())
{
    $out = array();
    foreach ($allFields as $f) {
        $cellRow = bcc_cell_row_for_field($f['field_type'], $record, $cellsByRecord, $f['id']);
        $rawValue = cell_raw_value($f['field_type'], $cellRow);

        if (is_select_field_type($f['field_type'])) {
            $choices = select_choices_from_options($f['options']);
        } elseif ($f['field_type'] === 'user') {
            $choices = bcc_user_choices_from_map($usersById);
        } elseif ($f['field_type'] === 'rating') {

            $ratingJsonOpts = is_string($f['options']) ? json_decode($f['options'], true) : null;
            $choices = array('max_rating' => (is_array($ratingJsonOpts) && isset($ratingJsonOpts['max_rating'])) ? (int) $ratingJsonOpts['max_rating'] : 5);
        } else {
            $choices = array();
        }

        $files = ($f['field_type'] === 'attachment' && isset($attachmentsByRecord[$record['id']][$f['id']]))
            ? $attachmentsByRecord[$record['id']][$f['id']]
            : null;

        $out[] = array(
            'id' => (int) $f['id'],
            'name' => $f['name'],
            'field_type' => $f['field_type'],
            'options' => $choices ? $choices : null,
            'raw' => $rawValue,
            'files' => $files,
        );
    }

    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

function bcc_render_grid_data_row($record, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $groupPath = null, $usersById = array(), $allFields = null, $attachmentsByRecord = array())
{
    if ($allFields === null) {
        $allFields = $visibleFields;
    }
    ?>
    <tr
        data-record-id="<?php echo (int) $record['id']; ?>"
        <?php echo $groupPath !== null ? 'data-group-path="' . htmlspecialchars($groupPath, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
        data-fields="<?php echo htmlspecialchars(bcc_render_grid_row_fields_json($allFields, $record, $cellsByRecord, $usersById, $attachmentsByRecord), ENT_QUOTES, 'UTF-8'); ?>"
    >
        <td class="grid-rownum">
            <div class="grid-rownum-inner">
                <span class="grid-rownum-number"><?php echo (int) $rowNum; ?></span>
                <?php if ($canEdit): ?>
                    <input type="checkbox" class="grid-row-select" aria-label="Satırı seç">
<?php endif; ?>
                <button type="button" class="grid-row-expand" aria-label="Genişlet" title="Genişlet">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M4.5 1.5h-3v3M7.5 10.5h3v-3M1.5 4.5V1.5h3M10.5 7.5v3h-3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </td>
        <?php foreach ($visibleFields as $f):
            $cellRow = bcc_cell_row_for_field($f['field_type'], $record, $cellsByRecord, $f['id']);
            $rawValue = cell_raw_value($f['field_type'], $cellRow);
            $displayText = cell_display_text($f['field_type'], $cellRow, $usersById, $f['options']);
            $isSelectType = is_select_field_type($f['field_type']);

            $isReadOnlyFieldType = in_array($f['field_type'], $GLOBALS['BCC_READONLY_FIELD_TYPES'], true);
            if ($isSelectType) {
                $choices = select_choices_from_options($f['options']);
            } elseif ($f['field_type'] === 'user') {
                $choices = bcc_user_choices_from_map($usersById);
            } elseif ($f['field_type'] === 'rating') {

                $ratingJsonOptsForTd = is_string($f['options']) ? json_decode($f['options'], true) : null;
                $choices = array('max_rating' => (is_array($ratingJsonOptsForTd) && isset($ratingJsonOptsForTd['max_rating'])) ? (int) $ratingJsonOptsForTd['max_rating'] : 5);
            } else {
                $choices = array();
            }

            $choiceColorMap = $isSelectType
                ? bcc_build_choice_color_map($choices, select_choice_colors_from_options($f['options']))
                : array();

            $isAttachmentType = ($f['field_type'] === 'attachment');
            $attachmentFiles = $isAttachmentType && isset($attachmentsByRecord[$record['id']][$f['id']])
                ? $attachmentsByRecord[$record['id']][$f['id']]
                : array();
        ?>
            <td
                class="grid-cell <?php echo ($canEdit && !$isReadOnlyFieldType) ? 'editable' : ''; ?>"
                data-field-id="<?php echo (int) $f['id']; ?>"
                data-field-type="<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"
                data-value="<?php echo htmlspecialchars($rawValue, ENT_QUOTES, 'UTF-8'); ?>"
                <?php if ($choices): ?>data-options="<?php echo htmlspecialchars(json_encode($choices, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                <?php if ($isAttachmentType): ?>data-attachments="<?php echo htmlspecialchars(json_encode($attachmentFiles, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
            >
                <?php if ($f['field_type'] === 'checkbox'): ?>
                    <input type="checkbox" class="cell-checkbox" <?php echo $rawValue === '1' ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
                <?php elseif ($f['field_type'] === 'single_select'): ?>
                    <div class="cell-view"><?php bcc_render_choice_chips($displayText !== '' ? array($displayText) : array(), $choiceColorMap); ?></div>
                <?php elseif ($f['field_type'] === 'multiple_select'): ?>
                    <?php
                        $selectedValues = array();
                        if ($cellRow !== null && $cellRow['value_json'] !== null) {
                            $decodedSelected = json_decode($cellRow['value_json'], true);
                            $selectedValues = is_array($decodedSelected) ? $decodedSelected : array();
                        }
                    ?>
                    <div class="cell-view"><?php bcc_render_choice_chips($selectedValues, $choiceColorMap); ?></div>
                <?php elseif ($f['field_type'] === 'long_text'): ?>
                    <?php   ?>
                    <?php   ?>
                    <div class="cell-view rich-text-view"><?php echo bcc_rich_text_grid_html($displayText); ?></div>
                <?php elseif ($f['field_type'] === 'rating'): ?>
                    <?php

                        $ratingDecodedOpts = is_array($f['options']) ? $f['options'] : (json_decode((string) $f['options'], true) ?: array());
                        $ratingMax = isset($ratingDecodedOpts['max_rating']) ? (int) $ratingDecodedOpts['max_rating'] : 5;
                        $ratingVal = $rawValue !== '' ? (int) $rawValue : 0;
                    ?>
                    <div class="cell-view rating-view<?php echo $canEdit ? ' rating-view-editable' : ''; ?>">
                        <?php for ($ratingI = 1; $ratingI <= $ratingMax; $ratingI++): ?>
                            <span class="rating-star<?php echo $ratingI <= $ratingVal ? ' rating-star-filled' : ''; ?>" data-rating-star="<?php echo $ratingI; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                <?php elseif ($isAttachmentType): ?>
                    <div class="cell-view attachment-cell-view">
                        <?php foreach ($attachmentFiles as $file):
                            $isImage = strpos($file['mime'], 'image/') === 0;
                        ?>
                            <a
                                class="attachment-chip"
                                href="/api/attachment_download.php?id=<?php echo (int) $file['id']; ?>"
                                target="_blank" rel="noopener noreferrer"
                                title="<?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <?php if ($isImage): ?>
                                    <img src="/api/attachment_download.php?id=<?php echo (int) $file['id']; ?>" class="attachment-thumb" alt="">
                                <?php else: ?>
                                    <span class="attachment-badge"><?php echo htmlspecialchars(bcc_attachment_type_badge($file['mime']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="attachment-name"><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (in_array($f['field_type'], $GLOBALS['BCC_LINKIFIED_FIELD_TYPES'], true)): ?>
                    <?php   ?>
                    <?php echo bcc_render_linkified_cell($f['field_type'], $displayText); ?>
                <?php elseif (bcc_is_user_value_field_type($f['field_type'])): ?>
                    <?php   ?>
                    <?php echo bcc_render_user_cell($displayText); ?>
                <?php else: ?>
                    <div class="cell-view"><?php echo htmlspecialchars($displayText, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </td>
        <?php endforeach; ?>
    </tr>
    <?php
}

function bcc_group_cell_row($column, $rawValue)
{
    $row = array('value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null);
    $row[$column] = $rawValue;

    return $row;
}

function bcc_reorder_sibling($tableName, $parentColumn, $parentId, $itemId, $direction)
{
    $allowedParents = array(
        'tables_meta' => 'base_id',
        'fields' => 'table_id',
        'views' => 'table_id',
        'slack_routing_rules' => 'table_id',
    );

    if (!isset($allowedParents[$tableName]) || $allowedParents[$tableName] !== $parentColumn) {
        throw new InvalidArgumentException('bcc_reorder_sibling: izin verilmeyen tablo/kolon.');
    }

    $siblings = bcc_fetch_all(
        "SELECT id, position FROM {$tableName} WHERE {$parentColumn} = :parent_id ORDER BY position, id",
        array('parent_id' => $parentId)
    );

    $index = null;
    foreach ($siblings as $i => $row) {
        if ((int) $row['id'] === (int) $itemId) {
            $index = $i;
            break;
        }
    }

    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

    if ($index === null || $swapWith < 0 || $swapWith >= count($siblings)) {
        return false;
    }

    $a = $siblings[$index];
    $b = $siblings[$swapWith];

    bcc_execute("UPDATE {$tableName} SET position = :pos WHERE id = :id", array('pos' => $b['position'], 'id' => $a['id']));
    bcc_execute("UPDATE {$tableName} SET position = :pos WHERE id = :id", array('pos' => $a['position'], 'id' => $b['id']));

    return true;
}

function bcc_build_safe_link($href, $labelHtml, $schemeRegex = '#^https?://#i', $extraAttrHtml = '')
{
    if (!preg_match($schemeRegex, (string) $href)) {
        return null;
    }

    return '<a ' . ($extraAttrHtml !== '' ? $extraAttrHtml . ' ' : '')
        . 'href="' . htmlspecialchars((string) $href, ENT_QUOTES, 'UTF-8') . '"'
        . ' target="_blank" rel="noopener noreferrer">' . $labelHtml . '</a>';
}

function bcc_cell_link_href($fieldType, $text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return null;
    }

    if ($fieldType === 'url') {

        return preg_match('#^https?://#i', $text) === 1 ? $text : null;
    }

    if ($fieldType === 'email') {

        return filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:' . $text : null;
    }

    if ($fieldType === 'phone') {

        $digits = preg_replace('/[^0-9]/', '', $text);
        if (strlen($digits) < 7) {
            return null;
        }

        return 'tel:' . (substr($text, 0, 1) === '+' ? '+' : '') . $digits;
    }

    return null;
}

function bcc_render_user_cell($displayText)
{
    if ($displayText === '') {
        return '<div class="cell-view"></div>';
    }

    return '<div class="cell-view cell-user-view">'
        . '<span class="ws-collab-avatar cell-user-avatar" aria-hidden="true">'
        . htmlspecialchars(bcc_name_initial($displayText), ENT_QUOTES, 'UTF-8')
        . '</span><span class="cell-user-name">'
        . htmlspecialchars($displayText, ENT_QUOTES, 'UTF-8')
        . '</span></div>';
}

function bcc_render_linkified_cell($fieldType, $displayText)
{
    $escaped = htmlspecialchars((string) $displayText, ENT_QUOTES, 'UTF-8');
    $href = bcc_cell_link_href($fieldType, $displayText);

    if ($href === null) {

        return '<div class="cell-view">' . $escaped . '</div>';
    }

    $link = bcc_build_safe_link(
        $href,
        bcc_external_link_icon_svg(),
        BCC_CELL_LINK_SCHEMES,
        'class="cell-link-icon" title="Yeni sekmede aç" aria-label="Yeni sekmede aç"'
    );

    if ($link === null) {

        return '<div class="cell-view">' . $escaped . '</div>';
    }

    return '<div class="cell-view cell-view-linkified">'
        . '<span class="cell-link-text">' . $escaped . '</span>'
        . $link
        . '</div>';
}

function bcc_external_link_icon_svg()
{
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'
        . '<polyline points="15 3 21 3 21 9"/>'
        . '<line x1="10" y1="14" x2="21" y2="3"/>'
        . '</svg>';
}

define('BCC_RICH_TEXT_MAX_CHARS', 20000);
define('BCC_RICH_TEXT_MAX_BYTES', 60000);

function bcc_sanitize_rich_text($html)
{
    $html = trim((string) $html);
    if ($html === '') {
        return null;
    }
    if (mb_strlen($html, 'UTF-8') > BCC_RICH_TEXT_MAX_CHARS) {
        $html = mb_substr($html, 0, BCC_RICH_TEXT_MAX_CHARS, 'UTF-8');
    }

    $output = bcc_sanitize_rich_text_pass($html);

    $tur = 0;
    while ($output !== null && strlen($output) > BCC_RICH_TEXT_MAX_BYTES && $tur < 8) {
        $karakter = mb_strlen($html, 'UTF-8');
        if ($karakter <= 1) {
            break;
        }
        $yeni = (int) floor($karakter * (BCC_RICH_TEXT_MAX_BYTES / strlen($output)) * 0.9);
        if ($yeni >= $karakter) {
            $yeni = $karakter - 1;
        }
        if ($yeni < 1) {
            $yeni = 1;
        }
        $html = mb_substr($html, 0, $yeni, 'UTF-8');
        $output = bcc_sanitize_rich_text_pass($html);
        $tur++;
    }

    return ($output === null || $output === '') ? null : $output;
}

function bcc_sanitize_rich_text_pass($html)
{

    $allowedTags = array(
        'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
        'br' => array(), 'a' => array('href'), 'span' => array('style'),
    );

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);

    $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
        return null;
    }

    return trim(bcc_sanitize_rich_text_children($body, $allowedTags));
}

function bcc_sanitize_rich_text_children($node, $allowedTags)
{
    $out = '';
    foreach (iterator_to_array($node->childNodes) as $child) {
        $out .= bcc_sanitize_rich_text_node($child, $allowedTags);
    }

    return $out;
}

function bcc_sanitize_rich_text_node($node, $allowedTags)
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return htmlspecialchars($node->textContent, ENT_QUOTES, 'UTF-8');
    }

    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return '';
    }

    $tag = strtolower($node->nodeName);

    if ($tag === 'script' || $tag === 'style') {
        return '';
    }

    $childrenHtml = bcc_sanitize_rich_text_children($node, $allowedTags);

    if ($tag === 'div' || $tag === 'p') {

        return $childrenHtml . '<br>';
    }

    if (!isset($allowedTags[$tag])) {
        return $childrenHtml;
    }

    if ($tag === 'br') {
        return '<br>';
    }

    if ($tag === 'a') {

        $link = bcc_build_safe_link($node->getAttribute('href'), $childrenHtml);

        return $link === null ? $childrenHtml : $link;
    }

    if ($tag === 'span') {
        $style = trim($node->getAttribute('style'));
        $sizes = implode('|', $GLOBALS['BCC_RICH_TEXT_FONT_SIZES']);
        if (!preg_match('/^font-size:(' . $sizes . ')px$/', $style)) {
            return $childrenHtml;
        }

        return '<span style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">' . $childrenHtml . '</span>';
    }

    return '<' . $tag . '>' . $childrenHtml . '</' . $tag . '>';
}

function bcc_rich_text_grid_html($html)
{
    $out = preg_replace('#<br\s*/?>#i', ' ', (string) $html);

    return $out === null ? (string) $html : $out;
}

function normalize_cell_value($fieldType, $optionsJson, $rawValue, $usersById = array())
{
    $columnMap = $GLOBALS['BCC_FIELD_VALUE_COLUMN'];

    if (!isset($columnMap[$fieldType])) {
        return array('ok' => false, 'error' => 'Bilinmeyen alan tipi.');
    }

    $column = $columnMap[$fieldType];

    switch ($fieldType) {

        case 'phone':

            $phoneNormalized = preg_replace('/\s+/u', ' ', (string) $rawValue);
            $rawValue = ($phoneNormalized === null) ? (string) $rawValue : $phoneNormalized;

        case 'single_line_text':
        case 'url':
        case 'email':
            $text = trim((string) $rawValue);

            if (strlen($text) > 65535) {
                return array('ok' => false, 'error' => 'Değer çok uzun (bu alan en fazla 65.535 bayt saklayabilir).');
            }

            return array('ok' => true, 'column' => $column, 'value' => $text === '' ? null : $text);

        case 'long_text':
            return array('ok' => true, 'column' => $column, 'value' => bcc_sanitize_rich_text($rawValue));

        case 'number':
        case 'currency':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!is_numeric($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz sayı.');
            }

            return array('ok' => true, 'column' => $column, 'value' => (float) $raw);

        case 'percent':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!is_numeric($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz sayı.');
            }

            return array('ok' => true, 'column' => $column, 'value' => ((float) $raw) / 100);

        case 'rating':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!is_numeric($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz değerlendirme.');
            }

            $maxRating = 5;
            $decodedOptions = ($optionsJson !== null && $optionsJson !== '') ? json_decode($optionsJson, true) : null;
            if (is_array($decodedOptions) && isset($decodedOptions['max_rating'])) {
                $maxRating = (int) $decodedOptions['max_rating'];
            }

            $rounded = (int) round((float) $raw);
            if ($rounded < 0 || $rounded > $maxRating) {
                return array('ok' => false, 'error' => 'Değerlendirme 0 ile ' . $maxRating . ' arasında olmalı.');
            }

            return array('ok' => true, 'column' => $column, 'value' => $rounded);

        case 'checkbox':
            return array('ok' => true, 'column' => $column, 'value' => ($rawValue === '1' || $rawValue === 1) ? 1 : 0);

        case 'date':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }

            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                return array('ok' => false, 'error' => 'Geçersiz tarih (YYYY-AA-GG).');
            }

            return array('ok' => true, 'column' => $column, 'value' => $raw . ' 00:00:00');

        case 'single_select':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }

            $choices = select_choices_from_options($optionsJson);
            if (!in_array($raw, $choices, true)) {
                return array('ok' => false, 'error' => 'Geçersiz seçenek.');
            }

            return array('ok' => true, 'column' => $column, 'value' => $raw);

        case 'multiple_select':
            $decoded = json_decode((string) $rawValue, true);
            if ($decoded === null) {
                $decoded = array();
            }
            if (!is_array($decoded)) {
                return array('ok' => false, 'error' => 'Geçersiz veri.');
            }

            $choices = select_choices_from_options($optionsJson);
            $valid = array();
            foreach ($decoded as $item) {
                if (is_string($item) && in_array($item, $choices, true) && !in_array($item, $valid, true)) {
                    $valid[] = $item;
                }
            }

            return array('ok' => true, 'column' => $column, 'value' => empty($valid) ? null : json_encode($valid, JSON_UNESCAPED_UNICODE));

        case 'time':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }

            $t = DateTime::createFromFormat('H:i', $raw);
            if (!$t || $t->format('H:i') !== $raw) {
                return array('ok' => false, 'error' => 'Geçersiz saat (SS:DD).');
            }

            return array('ok' => true, 'column' => $column, 'value' => $raw);

        case 'user':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!ctype_digit($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz kullanıcı.');
            }

            $userId = (int) $raw;
            if (!isset($usersById[$userId])) {
                return array('ok' => false, 'error' => 'Geçersiz kullanıcı (bu ekibin üyesi değil).');
            }

            return array('ok' => true, 'column' => $column, 'value' => $userId);

        case 'created_time':
        case 'created_by':
        case 'last_modified_time':
        case 'last_modified_by':
        case 'autonumber':
            return array('ok' => false, 'error' => 'Bu alan otomatik doldurulur, düzenlenemez.');

        default:
            return array('ok' => false, 'error' => 'Bilinmeyen alan tipi.');
    }
}

function parse_grid_sort_rules($params, $fieldsById)
{
    $rules = array();

    for ($i = 1; $i <= $GLOBALS['BCC_SORT_MAX_SLOTS']; $i++) {
        $fieldKey = 'sort_field_' . $i;

        if (empty($params[$fieldKey])) {
            continue;
        }

        $fieldId = (int) $params[$fieldKey];

        if (!isset($fieldsById[$fieldId])) {
            continue;
        }

        $fieldType = $fieldsById[$fieldId]['field_type'];

        if (!isset($GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType])) {
            continue;
        }

        $dirKey = 'sort_dir_' . $i;
        $dir = (isset($params[$dirKey]) && $params[$dirKey] === 'desc') ? 'DESC' : 'ASC';

        $rules[] = array(
            'slot' => $i,
            'field_id' => $fieldId,

            'field_type' => $fieldType,
            'dir' => $dir,
            'column' => $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType],
        );
    }

    return $rules;
}

function parse_grid_group_rules($params, $fieldsById)
{
    $maxLevels = 3;
    $hasNewParams = false;

    for ($i = 1; $i <= $maxLevels; $i++) {
        if (isset($params['group_field_' . $i])) {
            $hasNewParams = true;
            break;
        }
    }

    $sources = array();
    if ($hasNewParams) {
        for ($i = 1; $i <= $maxLevels; $i++) {
            $sources[] = array('field_key' => 'group_field_' . $i, 'dir_key' => 'group_dir_' . $i);
        }
    } else {
        $sources[] = array('field_key' => 'group_field', 'dir_key' => 'group_dir');
    }

    $rules = array();
    $usedFieldIds = array();

    foreach ($sources as $source) {
        if (empty($params[$source['field_key']])) {
            continue;
        }

        $fieldId = (int) $params[$source['field_key']];

        if (!isset($fieldsById[$fieldId]) || isset($usedFieldIds[$fieldId])) {
            continue;
        }

        $fieldType = $fieldsById[$fieldId]['field_type'];

        if (!isset($GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType])) {
            continue;
        }

        $dir = (isset($params[$source['dir_key']]) && $params[$source['dir_key']] === 'desc') ? 'DESC' : 'ASC';

        $rules[] = array(
            'slot' => count($rules) + 1,
            'field_id' => $fieldId,
            'field_type' => $fieldType,
            'dir' => $dir,
            'column' => $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType],

            'options' => $fieldsById[$fieldId]['options'],
        );
        $usedFieldIds[$fieldId] = true;
    }

    return $rules;
}

function parse_grid_filter_rules($params, $fieldsById)
{
    $maxSlots = $GLOBALS['BCC_FILTER_MAX_SLOTS'];
    $rules = array();

    for ($i = 1; $i <= $maxSlots; $i++) {
        $fieldKey = 'filter_field_' . $i;

        if (empty($params[$fieldKey])) {
            continue;
        }

        $fieldId = (int) $params[$fieldKey];

        if (!isset($fieldsById[$fieldId])) {
            continue;
        }

        $fieldType = $fieldsById[$fieldId]['field_type'];
        $allowedOps = isset($GLOBALS['BCC_FILTER_OPERATORS'][$fieldType]) ? $GLOBALS['BCC_FILTER_OPERATORS'][$fieldType] : array();

        $condKey = 'filter_cond_' . $i;
        $operator = isset($params[$condKey]) ? $params[$condKey] : '';

        if (!isset($allowedOps[$operator])) {
            continue;
        }

        $valueKey = 'filter_value_' . $i;

        $rules[] = array(
            'slot' => $i,
            'field_id' => $fieldId,
            'field_type' => $fieldType,
            'operator' => $operator,
            'raw_value' => isset($params[$valueKey]) ? $params[$valueKey] : '',
        );
    }

    return $rules;
}

function parse_grid_hidden_fields($params, $fieldsById, $primaryFieldId)
{
    $primaryFieldId = (int) $primaryFieldId;

    if (isset($params['visible_fields_submitted'])) {
        $visible = array();

        if (isset($params['visible_fields']) && is_array($params['visible_fields'])) {
            foreach ($params['visible_fields'] as $rawId) {
                $visible[(int) $rawId] = true;
            }
        }

        $hidden = array();
        foreach ($fieldsById as $fieldId => $field) {
            if ($fieldId !== $primaryFieldId && !isset($visible[$fieldId])) {
                $hidden[] = $fieldId;
            }
        }

        return $hidden;
    }

    if (empty($params['hidden_fields'])) {
        return array();
    }

    $hidden = array();

    foreach (explode(',', (string) $params['hidden_fields']) as $rawId) {
        $fieldId = (int) trim($rawId);

        if ($fieldId > 0 && $fieldId !== $primaryFieldId && isset($fieldsById[$fieldId]) && !in_array($fieldId, $hidden, true)) {
            $hidden[] = $fieldId;
        }
    }

    return $hidden;
}

function parse_grid_row_height($params)
{
    $value = isset($params['row_height']) ? (string) $params['row_height'] : 'short';

    return isset($GLOBALS['BCC_ROW_HEIGHT_LABELS'][$value]) ? $value : 'short';
}

function parse_grid_wrap_headers($params)
{
    return isset($params['wrap_headers']) && $params['wrap_headers'] === '1';
}

function bcc_grid_state_is_empty($params)
{
    $keys = array('hidden_fields', 'visible_fields_submitted', 'row_height', 'wrap_headers', 'filter_logic');

    for ($i = 1; $i <= $GLOBALS['BCC_SORT_MAX_SLOTS']; $i++) {
        $keys[] = 'sort_field_' . $i;
        $keys[] = 'group_field_' . $i;
    }
    for ($i = 1; $i <= $GLOBALS['BCC_FILTER_MAX_SLOTS']; $i++) {
        $keys[] = 'filter_field_' . $i;
    }

    foreach ($keys as $key) {
        if (isset($params[$key]) && $params[$key] !== '') {
            return false;
        }
    }

    return true;
}

function bcc_grid_state_to_array($sortRules, $groupRules, $filterRules, $filterLogic, $hiddenFieldIds, $rowHeight, $wrapHeaders)
{
    $state = array();

    foreach ($sortRules as $rule) {
        $state['sort_field_' . $rule['slot']] = $rule['field_id'];
        $state['sort_dir_' . $rule['slot']] = strtolower($rule['dir']);
    }

    foreach ($groupRules as $rule) {
        $state['group_field_' . $rule['slot']] = $rule['field_id'];
        $state['group_dir_' . $rule['slot']] = strtolower($rule['dir']);
    }

    foreach ($filterRules as $rule) {
        $state['filter_field_' . $rule['slot']] = $rule['field_id'];
        $state['filter_cond_' . $rule['slot']] = $rule['operator'];
        $state['filter_value_' . $rule['slot']] = $rule['raw_value'];
    }
    if (!empty($filterRules)) {
        $state['filter_logic'] = (strtolower((string) $filterLogic) === 'or') ? 'or' : 'and';
    }

    if (!empty($hiddenFieldIds)) {
        $state['hidden_fields'] = implode(',', $hiddenFieldIds);
    }

    if ($rowHeight !== 'short') {
        $state['row_height'] = $rowHeight;
    }

    if ($wrapHeaders) {
        $state['wrap_headers'] = '1';
    }

    return $state;
}

function bcc_get_view_grid_state($configJson)
{
    if ($configJson === null || $configJson === '') {
        return array();
    }

    $decoded = json_decode($configJson, true);
    if (!is_array($decoded) || !isset($decoded['grid_state']) || !is_array($decoded['grid_state'])) {
        return array();
    }

    return $decoded['grid_state'];
}

function filter_condition_sql($fieldType, $operator, $rawValue, $alias, $paramName)
{
    $allowedOps = isset($GLOBALS['BCC_FILTER_OPERATORS'][$fieldType]) ? $GLOBALS['BCC_FILTER_OPERATORS'][$fieldType] : array();
    if (!isset($allowedOps[$operator])) {
        return null;
    }

    $column = isset($GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'][$fieldType])
        ? bcc_record_column_expr($fieldType, $alias)
        : $alias . '.' . $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType];

    $isTextLike = in_array($fieldType, array('single_line_text', 'long_text', 'single_select', 'url', 'email', 'phone'), true);

    if (in_array($operator, $GLOBALS['BCC_FILTER_NO_VALUE_OPS'], true)) {
        switch ($operator) {
            case 'empty':
                if ($isTextLike) {
                    return array('sql' => "({$column} IS NULL OR {$column} = '')", 'params' => array());
                }
                return array('sql' => "{$column} IS NULL", 'params' => array());
            case 'not_empty':
                if ($isTextLike) {
                    return array('sql' => "({$column} IS NOT NULL AND {$column} <> '')", 'params' => array());
                }
                return array('sql' => "{$column} IS NOT NULL", 'params' => array());
            case 'checked':
                return array('sql' => "{$column} = 1", 'params' => array());
            case 'unchecked':
                return array('sql' => "({$column} = 0 OR {$column} IS NULL)", 'params' => array());
        }
    }

    $raw = trim((string) $rawValue);

    if ($fieldType === 'number' || $fieldType === 'currency' || $fieldType === 'percent' || $fieldType === 'rating' || $fieldType === 'autonumber') {
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $map = array('eq' => '=', 'neq' => '<>', 'gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<=');
        if (!isset($map[$operator])) {
            return null;
        }

        $value = (float) $raw;
        if ($fieldType === 'percent') {
            $value = $value / 100;
        }

        if ($operator === 'neq') {
            return array('sql' => "({$column} <> {$paramName} OR {$column} IS NULL)", 'params' => array($paramName => $value));
        }

        return array('sql' => "{$column} {$map[$operator]} {$paramName}", 'params' => array($paramName => $value));
    }

    if ($fieldType === 'user' || $fieldType === 'created_by' || $fieldType === 'last_modified_by') {
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $value = (int) $raw;

        if ($operator === 'not_equals') {
            return array('sql' => "({$column} <> {$paramName} OR {$column} IS NULL)", 'params' => array($paramName => $value));
        }
        if ($operator === 'equals') {
            return array('sql' => "{$column} = {$paramName}", 'params' => array($paramName => $value));
        }

        return null;
    }

    if ($fieldType === 'time') {
        $t = DateTime::createFromFormat('H:i', $raw);
        if (!$t || $t->format('H:i') !== $raw) {
            return null;
        }

        $map = array('before' => '<', 'after' => '>', 'equals' => '=');
        if (!isset($map[$operator])) {
            return null;
        }

        return array('sql' => "{$column} {$map[$operator]} {$paramName}", 'params' => array($paramName => $raw));
    }

    if ($fieldType === 'date' || $fieldType === 'created_time' || $fieldType === 'last_modified_time') {
        $d = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) {
            return null;
        }

        if ($operator === 'before') {
            return array('sql' => "{$column} < {$paramName}", 'params' => array($paramName => $raw . ' 00:00:00'));
        }
        if ($operator === 'after') {
            return array('sql' => "{$column} > {$paramName}", 'params' => array($paramName => $raw . ' 23:59:59'));
        }
        if ($operator === 'equals') {
            return array('sql' => "DATE({$column}) = {$paramName}", 'params' => array($paramName => $raw));
        }

        return null;
    }

    if ($fieldType === 'multiple_select') {
        if ($raw === '') {
            return null;
        }

        if ($operator === 'contains') {
            return array('sql' => "JSON_CONTAINS({$column}, JSON_QUOTE({$paramName}))", 'params' => array($paramName => $raw));
        }
        if ($operator === 'not_contains') {
            return array('sql' => "(NOT JSON_CONTAINS({$column}, JSON_QUOTE({$paramName})) OR {$column} IS NULL)", 'params' => array($paramName => $raw));
        }

        return null;
    }

    if ($raw === '' && $operator !== 'equals' && $operator !== 'not_equals') {
        return null;
    }

    switch ($operator) {
        case 'contains':
            return array(
                'sql' => "{$column} LIKE {$paramName} ESCAPE '\\\\'",
                'params' => array($paramName => '%' . bcc_like_escape($raw) . '%'),
            );
        case 'not_contains':
            return array(
                'sql' => "({$column} NOT LIKE {$paramName} ESCAPE '\\\\' OR {$column} IS NULL)",
                'params' => array($paramName => '%' . bcc_like_escape($raw) . '%'),
            );
        case 'equals':
            return array('sql' => "{$column} = {$paramName}", 'params' => array($paramName => $raw));
        case 'not_equals':
            return array('sql' => "({$column} <> {$paramName} OR {$column} IS NULL)", 'params' => array($paramName => $raw));
    }

    return null;
}

function bcc_like_escape($text)
{
    return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string) $text);
}

function bcc_build_grid_records_query($tableId, $groupRules, $sortRules, $filterRules, $filterLogic)
{

    $recordColumnTypes = $GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'];

    $groupSelectExtra = '';
    foreach ($groupRules as $gIdx => $gRule) {
        if (isset($recordColumnTypes[$gRule['field_type']])) {
            $groupSelectExtra .= ', ' . bcc_record_column_expr($gRule['field_type'], 'r') . " AS group_raw_value_{$gIdx}";
        } else {
            $groupSelectExtra .= ", gv{$gIdx}.{$gRule['column']} AS group_raw_value_{$gIdx}";
        }
    }

    $recordsSql = "SELECT r.id, r.position, r.created_at, r.created_by, r.updated_at, r.updated_by{$groupSelectExtra} FROM records r";
    $recordsParams = array(':table_id' => $tableId);
    $orderParts = array();

    foreach ($groupRules as $gIdx => $gRule) {
        if (isset($recordColumnTypes[$gRule['field_type']])) {

            $col = bcc_record_column_expr($gRule['field_type'], 'r');
            $orderParts[] = "({$col} IS NULL) DESC";
            $orderParts[] = "{$col} {$gRule['dir']}";
            continue;
        }
        $alias = 'gv' . $gIdx;
        $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :gfid{$gIdx}";
        $recordsParams[':gfid' . $gIdx] = $gRule['field_id'];
        $orderParts[] = "({$alias}.{$gRule['column']} IS NULL) DESC";
        $orderParts[] = "{$alias}.{$gRule['column']} {$gRule['dir']}";
    }

    foreach ($sortRules as $idx => $rule) {
        if (isset($recordColumnTypes[$rule['field_type']])) {
            $orderParts[] = bcc_record_column_expr($rule['field_type'], 'r') . ' ' . $rule['dir'];
            continue;
        }
        $alias = 'sv' . $idx;
        $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :sfid{$idx}";
        $recordsParams[':sfid' . $idx] = $rule['field_id'];
        $orderParts[] = "{$alias}.{$rule['column']} {$rule['dir']}";
    }

    $filterConds = array();
    foreach ($filterRules as $idx => $rule) {
        $paramName = ':fval' . $idx;

        if (isset($recordColumnTypes[$rule['field_type']])) {
            $frag = filter_condition_sql($rule['field_type'], $rule['operator'], $rule['raw_value'], 'r', $paramName);
            if ($frag === null) {
                continue;
            }
            foreach ($frag['params'] as $pName => $pValue) {
                $recordsParams[$pName] = $pValue;
            }
            $filterConds[] = $frag['sql'];
            continue;
        }

        $alias = 'fv' . $idx;
        $frag = filter_condition_sql($rule['field_type'], $rule['operator'], $rule['raw_value'], $alias, $paramName);

        if ($frag === null) {
            continue;
        }

        $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :ffid{$idx}";
        $recordsParams[':ffid' . $idx] = $rule['field_id'];
        foreach ($frag['params'] as $pName => $pValue) {
            $recordsParams[$pName] = $pValue;
        }
        $filterConds[] = $frag['sql'];
    }

    $orderParts[] = 'r.position ASC';
    $orderParts[] = 'r.id ASC';

    $recordsSql .= ' WHERE r.table_id = :table_id AND r.deleted_at IS NULL';
    if (!empty($filterConds)) {
        $joinWord = ($filterLogic === 'OR') ? ' OR ' : ' AND ';
        $recordsSql .= ' AND (' . implode($joinWord, $filterConds) . ')';
    }
    $recordsSql .= ' ORDER BY ' . implode(', ', $orderParts);

    return array($recordsSql, $recordsParams);
}

$GLOBALS['BCC_NAME_SCOPES'] = array(
    'bases'       => array('scope' => 'team_id',  'soft_delete' => true,  'label' => 'çalışma alanında'),
    'tables_meta' => array('scope' => 'base_id',  'soft_delete' => false, 'label' => "base'de"),
    'fields'      => array('scope' => 'table_id', 'soft_delete' => false, 'label' => 'tabloda'),
    'views'       => array('scope' => 'table_id', 'soft_delete' => false, 'label' => 'tabloda'),
);

function bcc_name_taken($entity, $scopeId, $name, $excludeId = null)
{
    if (!isset($GLOBALS['BCC_NAME_SCOPES'][$entity])) {

        throw new InvalidArgumentException('Bilinmeyen isim scope\'u: ' . $entity);
    }

    $cfg = $GLOBALS['BCC_NAME_SCOPES'][$entity];

    $sql = 'SELECT id FROM ' . $entity . ' WHERE ' . $cfg['scope'] . ' = :scope_id AND name = :name';
    $params = array('scope_id' => $scopeId, 'name' => trim((string) $name));

    if ($cfg['soft_delete']) {
        $sql .= ' AND deleted_at IS NULL';
    }

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = (int) $excludeId;
    }

    return bcc_fetch_one($sql . ' LIMIT 1', $params) !== false;
}

function bcc_name_taken_error($entity, $what)
{
    $label = isset($GLOBALS['BCC_NAME_SCOPES'][$entity]) ? $GLOBALS['BCC_NAME_SCOPES'][$entity]['label'] : 'kapsamda';

    return 'Bu ' . $what . ' adı aynı ' . $label . ' zaten kullanılıyor.';
}

function bcc_create_base($teamId, $name, $description, $userId, $icon = null, $iconColor = null)
{
    $name = trim((string) $name);
    $description = trim((string) $description);
    $icon = bcc_base_icon_key_or_null($icon);
    $iconColor = bcc_base_icon_color_index_or_null($iconColor);

    if ($name === '') {
        return array('ok' => false, 'error' => 'Base adı boş olamaz.', 'id' => null);
    }

    if (mb_strlen($name, 'UTF-8') > 150) {
        return array('ok' => false, 'error' => 'Base adı en fazla 150 karakter olabilir.', 'id' => null);
    }

    if (mb_strlen($description, 'UTF-8') > 500) {
        return array('ok' => false, 'error' => 'Açıklama en fazla 500 karakter olabilir.', 'id' => null);
    }

    if (bcc_name_taken('bases', $teamId, $name)) {
        return array('ok' => false, 'error' => bcc_name_taken_error('bases', 'base'), 'id' => null);
    }

    bcc_execute(
        'INSERT INTO bases (team_id, name, description, icon, icon_color, created_by)
         VALUES (:team_id, :name, :description, :icon, :icon_color, :created_by)',
        array(
            'team_id' => $teamId,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'icon' => $icon,
            'icon_color' => $iconColor,
            'created_by' => $userId,
        )
    );

    $newId = (int) bcc_last_insert_id();
    log_audit('base.create', 'base', $newId, array('name' => $name), $teamId);

    return array('ok' => true, 'error' => null, 'id' => $newId);
}

function bcc_team_memberships_for_current_user()
{
    $user = current_user();
    if ($user === null) {
        return array();
    }

    return bcc_fetch_all(
        'SELECT t.id, t.name, m.role
         FROM team_members m
         INNER JOIN teams t ON t.id = m.team_id
         WHERE m.user_id = :uid
         ORDER BY t.name',
        array('uid' => $user['id'])
    );
}

function bcc_teams_for_current_user()
{
    if (current_user() === null) {
        return array();
    }

    if (is_platform_admin()) {
        return bcc_fetch_all("SELECT t.id, t.name, 'owner' AS role FROM teams t ORDER BY t.name");
    }

    return bcc_team_memberships_for_current_user();
}

function bcc_remap_view_config_fields($configJson, $fieldMap)
{
    if ($configJson === null || $configJson === '') {
        return $configJson;
    }

    $config = json_decode((string) $configJson, true);
    if (!is_array($config)) {
        return $configJson;
    }

    $mapId = function ($old) use ($fieldMap) {
        $old = (int) $old;
        return isset($fieldMap[$old]) ? $fieldMap[$old] : null;
    };

    if (isset($config['column_widths']) && is_array($config['column_widths'])) {
        $out = array();
        foreach ($config['column_widths'] as $key => $width) {
            if ($key === 'row') {
                $out['row'] = $width;
                continue;
            }
            if (preg_match('/^f(\d+)$/', (string) $key, $m)) {
                $new = $mapId($m[1]);
                if ($new !== null) {
                    $out['f' . $new] = $width;
                }
            }
        }
        $config['column_widths'] = $out;
    }

    if (isset($config['grid_state']) && is_array($config['grid_state'])) {
        $gs = $config['grid_state'];
        foreach (array_keys($gs) as $key) {
            if (preg_match('/^(sort|group|filter)_field_\d+$/', (string) $key)) {
                $new = $mapId($gs[$key]);
                if ($new === null) {
                    unset($gs[$key]);
                } else {
                    $gs[$key] = $new;
                }
            }
        }
        if (isset($gs['hidden_fields'])) {
            $ids = array();
            foreach (explode(',', (string) $gs['hidden_fields']) as $old) {
                $new = $mapId($old);
                if ($new !== null) { $ids[] = $new; }
            }
            if ($ids) {
                $gs['hidden_fields'] = implode(',', $ids);
            } else {
                unset($gs['hidden_fields']);
            }
        }
        $config['grid_state'] = $gs;
    }

    if (isset($config['kanban_field_id'])) {
        $new = $mapId($config['kanban_field_id']);
        if ($new === null) { unset($config['kanban_field_id']); } else { $config['kanban_field_id'] = $new; }
    }

    foreach (array('kanban_card_fields', 'form_fields') as $listKey) {
        if (isset($config[$listKey]) && is_array($config[$listKey])) {
            $ids = array();
            foreach ($config[$listKey] as $old) {
                $new = $mapId($old);
                if ($new !== null) { $ids[] = $new; }
            }
            $config[$listKey] = $ids;
        }
    }

    return json_encode($config, JSON_UNESCAPED_UNICODE);
}

function bcc_duplicate_table($tableId, $newName, $withRecords, $userId)
{

    $src = bcc_fetch_one(
        'SELECT t.id, t.base_id, t.name, t.description, b.team_id
         FROM tables_meta t INNER JOIN bases b ON b.id = t.base_id
         WHERE t.id = :id',
        array('id' => (int) $tableId)
    );
    if (!$src) {
        return array('ok' => false, 'error' => 'Tablo bulunamadı.', 'id' => null);
    }

    $newName = trim((string) $newName);
    if ($newName === '') {
        return array('ok' => false, 'error' => 'Tablo adı boş olamaz.', 'id' => null);
    }
    if (mb_strlen($newName, 'UTF-8') > 150) {
        return array('ok' => false, 'error' => 'Tablo adı en fazla 150 karakter olabilir.', 'id' => null);
    }
    if (bcc_name_taken('tables_meta', $src['base_id'], $newName)) {
        return array('ok' => false, 'error' => bcc_name_taken_error('tables_meta', 'tablo'), 'id' => null);
    }

    bcc_begin_transaction();
    try {
        $nextPos = (int) bcc_fetch_column(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM tables_meta WHERE base_id = :b',
            array('b' => $src['base_id'])
        );
        bcc_execute(
            'INSERT INTO tables_meta (base_id, name, description, position) VALUES (:b, :n, :d, :p)',
            array('b' => $src['base_id'], 'n' => $newName, 'd' => $src['description'], 'p' => $nextPos)
        );
        $newTableId = (int) bcc_last_insert_id();

        $fieldMap = array();
        foreach (bcc_fetch_all(
            'SELECT id, name, field_type, options, position, is_required, autonumber_next
             FROM fields WHERE table_id = :t ORDER BY position, id',
            array('t' => $src['id'])
        ) as $f) {
            bcc_execute(
                'INSERT INTO fields (table_id, name, field_type, options, position, is_required, autonumber_next)
                 VALUES (:t, :n, :ft, :o, :p, :r, :a)',
                array(
                    't' => $newTableId, 'n' => $f['name'], 'ft' => $f['field_type'],
                    'o' => $f['options'], 'p' => $f['position'],
                    'r' => (int) $f['is_required'],
                    'a' => $withRecords ? (int) $f['autonumber_next'] : 1,
                )
            );
            $fieldMap[(int) $f['id']] = (int) bcc_last_insert_id();
        }

        foreach (bcc_fetch_all(
            'SELECT name, description, view_type, position, config
             FROM views WHERE table_id = :t ORDER BY position, id',
            array('t' => $src['id'])
        ) as $v) {

            bcc_execute(
                'INSERT INTO views (table_id, name, description, view_type, position, config, created_by)
                 VALUES (:t, :n, :d, :vt, :p, :c, :u)',
                array(
                    't' => $newTableId, 'n' => $v['name'], 'd' => $v['description'],
                    'vt' => $v['view_type'], 'p' => $v['position'],
                    'c' => bcc_remap_view_config_fields($v['config'], $fieldMap),
                    'u' => $userId ? (int) $userId : null,
                )
            );
        }

        $recordCount = 0;
        $attachmentCount = 0;

        if ($withRecords) {

            $recordMap = array();
            foreach (bcc_fetch_all(
                'SELECT id, position, created_by FROM records
                 WHERE table_id = :t AND deleted_at IS NULL ORDER BY position, id',
                array('t' => $src['id'])
            ) as $rec) {
                bcc_execute(
                    'INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
                    array('t' => $newTableId, 'p' => $rec['position'], 'u' => $rec['created_by'])
                );
                $recordMap[(int) $rec['id']] = (int) bcc_last_insert_id();
                $recordCount++;
            }

            if ($recordMap) {
                $oldIds = array_keys($recordMap);
                $ph = implode(',', array_fill(0, count($oldIds), '?'));
                foreach (bcc_fetch_all(
                    "SELECT record_id, field_id, value_text, value_number, value_date, value_json
                     FROM cell_values WHERE record_id IN ($ph)",
                    $oldIds
                ) as $cv) {
                    $newRid = isset($recordMap[(int) $cv['record_id']]) ? $recordMap[(int) $cv['record_id']] : null;
                    $newFid = isset($fieldMap[(int) $cv['field_id']]) ? $fieldMap[(int) $cv['field_id']] : null;
                    if ($newRid === null || $newFid === null) {
                        continue;
                    }
                    bcc_execute(
                        'INSERT INTO cell_values (record_id, field_id, value_text, value_number, value_date, value_json)
                         VALUES (:r, :f, :t, :n, :d, :j)',
                        array(
                            'r' => $newRid, 'f' => $newFid,
                            't' => $cv['value_text'], 'n' => $cv['value_number'],
                            'd' => $cv['value_date'], 'j' => $cv['value_json'],
                        )
                    );
                }

                $attDir = bcc_attachment_storage_dir();
                foreach (bcc_fetch_all(
                    "SELECT record_id, field_id, original_name, stored_name, mime_type, file_size, uploaded_by
                     FROM attachments WHERE record_id IN ($ph)",
                    $oldIds
                ) as $att) {
                    $newRid = isset($recordMap[(int) $att['record_id']]) ? $recordMap[(int) $att['record_id']] : null;
                    $newFid = isset($fieldMap[(int) $att['field_id']]) ? $fieldMap[(int) $att['field_id']] : null;
                    if ($newRid === null || $newFid === null) {
                        continue;
                    }

                    $srcPath = bcc_attachment_storage_path($att['stored_name']);

                    if (!is_file($srcPath)) {
                        continue;
                    }

                    $ext = pathinfo($att['stored_name'], PATHINFO_EXTENSION);
                    $newStored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
                    if (!@copy($srcPath, $attDir . '/' . $newStored)) {
                        continue;
                    }

                    bcc_execute(
                        'INSERT INTO attachments (field_id, record_id, original_name, stored_name, mime_type, file_size, uploaded_by)
                         VALUES (:f, :r, :on, :sn, :mt, :fs, :u)',
                        array(
                            'f' => $newFid, 'r' => $newRid,
                            'on' => $att['original_name'], 'sn' => $newStored,
                            'mt' => $att['mime_type'], 'fs' => $att['file_size'],
                            'u' => $att['uploaded_by'],
                        )
                    );
                    $attachmentCount++;
                }
            }
        }

        log_audit('table.duplicate', 'table', $newTableId, array(
            'source_table_id' => (int) $src['id'],
            'name' => $newName,
            'with_records' => $withRecords ? 1 : 0,
            'record_count' => $recordCount,
            'attachment_count' => $attachmentCount,
        ), (int) $src['team_id']);

        bcc_commit();
    } catch (Throwable $e) {
        bcc_rollback();
        throw $e;
    }

    return array(
        'ok' => true, 'error' => null, 'id' => $newTableId,
        'record_count' => $recordCount, 'attachment_count' => $attachmentCount,
    );
}

function bcc_create_team($name, $creatorUserId)
{
    $name = trim((string) $name);

    if ($name === '') {
        return array('ok' => false, 'error' => 'Ekip adı boş olamaz.', 'id' => null);
    }

    if (mb_strlen($name, 'UTF-8') > 100) {
        return array('ok' => false, 'error' => 'Ekip adı en fazla 100 karakter olabilir.', 'id' => null);
    }

    $existing = bcc_fetch_one('SELECT id FROM teams WHERE name = :name', array('name' => $name));
    if ($existing) {
        return array('ok' => false, 'error' => 'Bu isimde bir ekip zaten var.', 'id' => null);
    }

    bcc_execute('INSERT INTO teams (name) VALUES (:name)', array('name' => $name));
    $newId = (int) bcc_last_insert_id();

    log_audit('team.create', 'team', $newId, array('name' => $name, 'created_by_user_id' => (int) $creatorUserId), $newId);

    return array('ok' => true, 'error' => null, 'id' => $newId);
}

function bcc_home_relative_date($datetimeStr)
{
    $ts = strtotime((string) $datetimeStr);
    if ($ts === false) {
        return '';
    }

    $days = intdiv(time() - $ts, 86400);

    if ($days <= 0) {
        return 'Bugün';
    }
    if ($days === 1) {
        return 'Dün';
    }
    if ($days < 30) {
        return $days . ' gün önce';
    }

    $months = intdiv($days, 30);
    if ($months < 12) {
        return $months . ' ay önce';
    }

    return intdiv($months, 12) . ' yıl önce';
}

function bcc_notification_time_text($datetimeStr)
{
    $ts = strtotime((string) $datetimeStr);
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d', $ts) === date('Y-m-d')
        ? date('H:i', $ts)
        : date('d.m.Y H:i', $ts);
}

$GLOBALS['BCC_BASE_ICON_THEMES'] = array(
    array('solid' => '#2D7FF9', 'bg' => '#e8f0fe', 'fg' => '#1a56db', 'bgDark' => '#1b2a4a', 'fgDark' => '#8ab4f8'),
    array('solid' => '#8b5cf6', 'bg' => '#f3ebfd', 'fg' => '#6b3fa0', 'bgDark' => '#2b2140', 'fgDark' => '#c4a7ec'),
    array('solid' => '#f59e0b', 'bg' => '#fff4e0', 'fg' => '#a35c00', 'bgDark' => '#3a2c14', 'fgDark' => '#e8b96a'),
    array('solid' => '#10b981', 'bg' => '#e4f6ec', 'fg' => '#1b7e3c', 'bgDark' => '#18321f', 'fgDark' => '#7ac98d'),
    array('solid' => '#ef4444', 'bg' => '#fdeaea', 'fg' => '#c62828', 'bgDark' => '#3a1e1e', 'fgDark' => '#ef9a9a'),
    array('solid' => '#06b6d4', 'bg' => '#e2f4f8', 'fg' => '#0e6c80', 'bgDark' => '#14313a', 'fgDark' => '#7fd0e0'),
);

function bcc_base_icon_theme($baseId, $iconColor = null)
{
    $themes = $GLOBALS['BCC_BASE_ICON_THEMES'];

    if ($iconColor !== null && $iconColor !== '' && isset($themes[(int) $iconColor])) {
        return $themes[(int) $iconColor];
    }

    return $themes[(int) $baseId % count($themes)];
}

function bcc_base_icon_key_or_null($icon)
{
    $icon = is_string($icon) ? trim($icon) : '';

    return isset($GLOBALS['BCC_BASE_ICON_PATHS'][$icon]) ? $icon : null;
}

function bcc_base_icon_color_index_or_null($index)
{
    if ($index === null || $index === '' || !is_numeric($index)) {
        return null;
    }

    $index = (int) $index;

    return isset($GLOBALS['BCC_BASE_ICON_THEMES'][$index]) ? $index : null;
}

function bcc_base_icon_color($baseId, $iconColor = null)
{
    $theme = bcc_base_icon_theme($baseId, $iconColor);
    return $theme['solid'];
}

function bcc_base_icon_style_attr($baseId, $iconColor = null)
{
    $t = bcc_base_icon_theme($baseId, $iconColor);

    return '--bi-solid: ' . $t['solid']
        . '; --bi-bg: ' . $t['bg'] . '; --bi-fg: ' . $t['fg']
        . '; --bi-bg-dark: ' . $t['bgDark'] . '; --bi-fg-dark: ' . $t['fgDark'] . ';';
}

function bcc_base_icon_category($baseName)
{
    $name = mb_strtolower((string) $baseName, 'UTF-8');

    $rules = array(
        'shield' => array('rol', 'role', 'yetki', 'izin', 'permission', 'admin', 'personel', 'calisan', 'çalışan'),
        'export' => array('export', 'import', 'aktar', 'csv', 'xlsx', 'yedek'),
        'users' => array('crm', 'musteri', 'müşteri', 'satis', 'satış', 'sales', 'customer', 'lead', 'uye', 'üye'),
        'receipt' => array('finans', 'fatura', 'muhasebe', 'butce', 'bütçe', 'budget', 'invoice', 'gider', 'masraf', 'odeme', 'ödeme'),
        'package' => array('stok', 'envanter', 'inventory', 'urun', 'ürün', 'product', 'depo', 'katalog'),
        'calendar' => array('takvim', 'calendar', 'etkinlik', 'event', 'toplanti', 'toplantı', 'randevu', 'ajanda'),
        'layout' => array('proje', 'project', 'gorev', 'görev', 'task', 'sprint', 'roadmap', 'plan'),
        'flask' => array('test', 'deneme', 'demo', 'sandbox', 'qa'),
    );

    foreach ($rules as $category => $needles) {
        foreach ($needles as $needle) {
            if (mb_strpos($name, $needle, 0, 'UTF-8') !== false) {
                return $category;
            }
        }
    }

    return 'database';
}

$GLOBALS['BCC_BASE_ICON_PATHS'] = array(
    'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'export' => '<path d="M12 17V3"/><path d="m6 11 6 6 6-6"/><path d="M19 21H5"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
    'package' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'layout' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
    'flask' => '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
);

$GLOBALS['BCC_BASE_ICON_LABELS'] = array(
    'database' => 'Veritabanı',
    'users' => 'Müşteri / Kişiler',
    'layout' => 'Proje / Görev',
    'receipt' => 'Fatura / Finans',
    'package' => 'Stok / Ürün',
    'calendar' => 'Takvim / Etkinlik',
    'shield' => 'Yetki / Personel',
    'export' => 'İçe / Dışa aktarım',
    'flask' => 'Deneme / Test',
);

function bcc_base_icon_svg($size = 20, $baseName = null, $icon = null)
{
    $chosen = bcc_base_icon_key_or_null($icon);
    $category = $chosen !== null
        ? $chosen
        : ($baseName === null ? 'database' : bcc_base_icon_category($baseName));
    $paths = $GLOBALS['BCC_BASE_ICON_PATHS'];
    $d = isset($paths[$category]) ? $paths[$category] : $paths['database'];

    return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true">' . $d . '</svg>';
}

function bcc_base_icon_paths($baseName = null, $icon = null)
{
    $chosen = bcc_base_icon_key_or_null($icon);
    $category = $chosen !== null
        ? $chosen
        : ($baseName === null ? 'database' : bcc_base_icon_category($baseName));
    $paths = $GLOBALS['BCC_BASE_ICON_PATHS'];

    return isset($paths[$category]) ? $paths[$category] : $paths['database'];
}

function bcc_tab_title($pageName)
{
    $pageName = trim((string) $pageName);
    $brand = bcc_brand_name();

    return $pageName !== '' ? $pageName . ' - ' . $brand : $brand;
}

function bcc_page_title($baseName, $contextName = null)
{
    $base = trim((string) $baseName);
    $ctx = trim((string) $contextName);

    if ($base === '') {
        return bcc_tab_title($ctx);
    }

    return bcc_tab_title($ctx !== '' ? $base . ': ' . $ctx : $base);
}

function bcc_page_identity_meta($baseId, $baseName, $contextName = null, $icon = null, $iconColor = null)
{
    $esc = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };

    return '<meta name="bcc-brand" content="' . $esc(bcc_brand_name()) . '">' . "\n"
        . '<meta name="bcc-base-name" content="' . $esc($baseName) . '">' . "\n"
        . '<meta name="bcc-context-name" content="' . $esc($contextName) . '">' . "\n"
        . '<meta name="bcc-base-color" content="' . $esc(bcc_base_icon_color($baseId, $iconColor)) . '">' . "\n"
        . '<meta name="bcc-base-icon" content="' . $esc(bcc_base_icon_paths($baseName, $icon)) . '">';
}

function bcc_starred_bases_for_current_user($forceReload = false)
{
    static $cache = array();

    $user = current_user();
    $uid = $user !== null ? (int) $user['id'] : 0;

    if (isset($cache[$uid]) && !$forceReload) {
        return $cache[$uid];
    }

    $teamIds = current_user_team_ids();

    if ($user === null || empty($teamIds)) {
        $cache[$uid] = array();

        return $cache[$uid];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $cache[$uid] = bcc_fetch_all(
        "SELECT b.id, b.name, b.team_id, b.icon, b.icon_color, t.name AS team_name
         FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($placeholders) AND b.deleted_at IS NULL
         INNER JOIN teams t ON t.id = b.team_id
         WHERE usb.user_id = ?
         ORDER BY t.name, b.name",
        array_merge($teamIds, array((int) $user['id']))
    );

    return $cache[$uid];
}

function bcc_group_starred_bases_by_team($starredBases)
{
    $groups = array();

    foreach ($starredBases as $row) {
        $teamId = isset($row['team_id']) ? (int) $row['team_id'] : 0;

        if (!isset($groups[$teamId])) {
            $name = isset($row['team_name']) ? trim((string) $row['team_name']) : '';
            $groups[$teamId] = array(
                'team_id' => $teamId,
                'team_name' => $name !== '' ? $name : ('Çalışma alanı #' . $teamId),
                'bases' => array(),
            );
        }

        $groups[$teamId]['bases'][] = $row;
    }

    usort($groups, function ($a, $b) {
        return strcasecmp($a['team_name'], $b['team_name']);
    });

    return $groups;
}

function bcc_starred_base_ids_for_current_user()
{
    $ids = array();
    foreach (bcc_starred_bases_for_current_user() as $row) {
        $ids[(int) $row['id']] = true;
    }

    return $ids;
}

function bcc_base_table_counts($baseIds)
{
    if (empty($baseIds)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($baseIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT base_id, COUNT(*) AS c FROM tables_meta WHERE base_id IN ($placeholders) GROUP BY base_id",
        array_map('intval', $baseIds)
    );

    $out = array();
    foreach ($baseIds as $id) {
        $out[(int) $id] = 0;
    }
    foreach ($rows as $r) {
        $out[(int) $r['base_id']] = (int) $r['c'];
    }

    return $out;
}

function bcc_render_home_base_card($base, $iconColor, $isStarred, $workspaceName, $canDelete = false, $role = null, $variant = 'standard', $tableCount = null)
{

    unset($iconColor);
    $isFeature = ($variant === 'feature');
    $description = isset($base['description']) ? trim((string) $base['description']) : '';
    ?>
    <?php

    ?>
    <a class="home-base-card<?php echo $isStarred ? ' is-starred' : ''; ?><?php echo $isFeature ? ' home-base-card--feature' : ''; ?>" href="/base.php?base_id=<?php echo (int) $base['id']; ?>" data-base-id="<?php echo (int) $base['id']; ?>"<?php echo isset($base['team_id']) ? ' data-team-id="' . (int) $base['team_id'] . '"' : ''; ?> style="<?php echo htmlspecialchars(bcc_base_icon_style_attr($base['id'], isset($base['icon_color']) ? $base['icon_color'] : null), ENT_QUOTES, 'UTF-8'); ?>">
        <?php

        ?>
        <span class="home-base-cover" aria-hidden="true">
            <span class="home-base-cover-glyph"><?php echo bcc_base_icon_svg($isFeature ? 64 : 34, $base['name'], isset($base['icon']) ? $base['icon'] : null); ?></span>
        </span>
        <div class="home-base-icon">
            <?php echo bcc_base_icon_svg(20, $base['name'], isset($base['icon']) ? $base['icon'] : null); ?>
        </div>
        <div class="home-base-info">
            <div class="home-base-name"><?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($isFeature && $description !== ''): ?>
                <?php ?>
                <div class="home-base-desc"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($tableCount !== null): ?>
                <div class="home-base-stats"><span class="home-base-stat"><?php echo (int) $tableCount; ?> tablo</span></div>
            <?php endif; ?>
            <div class="home-base-meta">
                <span class="home-base-meta-star" aria-hidden="true">
                    <svg width="11" height="11" viewBox="0 0 20 20"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" fill="#f5b400"/></svg>
                </span>
                <?php

                $lastOpenedDisplay = !empty($base['last_opened']) ? $base['last_opened'] : $base['created_at'];
                ?>
                Açıldı: <?php echo htmlspecialchars(bcc_home_relative_date($lastOpenedDisplay), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div class="home-base-workspace"><?php echo htmlspecialchars($workspaceName, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($role !== null && isset($GLOBALS['BCC_ROLE_LABELS'][$role])): ?>
            <?php

            ?>
            <span class="home-base-role home-base-role--<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$role], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        <?php endif; ?>
        <div class="home-base-card-actions">
            <?php

            ?>
            <button type="button" class="home-base-data-btn" data-nav-href="/base.php?base_id=<?php echo (int) $base['id']; ?>" aria-label="Tabloya git">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <ellipse cx="10" cy="5.2" rx="5.8" ry="2.4" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M4.2 5.2v9.6c0 1.33 2.6 2.4 5.8 2.4s5.8-1.07 5.8-2.4V5.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                    <path d="M4.2 10c0 1.33 2.6 2.4 5.8 2.4s5.8-1.07 5.8-2.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                </svg>
                <span class="home-base-btn-tip" aria-hidden="true">Tabloya git</span>
            </button>
            <button type="button" class="home-base-star-btn" aria-label="Favorilere ekle/çıkar" aria-pressed="<?php echo $isStarred ? 'true' : 'false'; ?>">
                <svg width="16" height="16" viewBox="0 0 20 20" class="home-base-star-icon"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" stroke-width="1.4" stroke-linejoin="round"/></svg>
            </button>
            <details class="home-base-more-menu">
                <summary class="home-base-more-btn" aria-label="Diğer aksiyonlar">
                    <?php 
                          ?>
                    <svg width="16" height="16" viewBox="0 0 20 20"><circle cx="4" cy="10" r="1.6" fill="currentColor"/><circle cx="10" cy="10" r="1.6" fill="currentColor"/><circle cx="16" cy="10" r="1.6" fill="currentColor"/></svg>
                </summary>
                <div class="home-base-more-panel">
                    <details class="home-base-more-submenu">
                        <summary class="home-base-more-item home-base-more-item-parent">
                            <span>Aç</span>
                            <svg class="home-base-more-caret" width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M4.5 2.5l3.5 3.5-3.5 3.5" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="home-base-more-submenu-panel">
                            <button type="button" class="home-base-more-item" data-nav-href="/interface.php?base_id=<?php echo (int) $base['id']; ?>">Duyuru</button>
                        </div>
                    </details>
                    <?php 
                          ?>
                    <?php if ($canDelete): ?>
                    <div class="home-base-more-divider"></div>
                    <button type="button" class="home-base-more-item home-base-more-item-danger" data-base-delete="<?php echo (int) $base['id']; ?>">Sil</button>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </a>
    <?php
}

function bcc_render_home_create_base_tile()
{
    ?>
    <?php 
          ?>
    <button type="button" class="home-base-card home-base-create" id="home-create-base-btn" data-create-base-open>
        <span class="home-base-icon home-base-create-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
        </span>
        <span class="home-base-info">
            <span class="home-base-name">Yeni Base Oluştur</span>
            <span class="home-base-meta">Boş bir base ile başlayın</span>
        </span>
    </button>
    <?php
}

function bcc_render_home_base_grid($bases, $starredBaseIds, $teamNamesById, $emptyMessage, $roleByTeamId = array(), $canCreateBase = false, $bento = false, $tableCounts = array(), $groupByWorkspace = false)
{
    if (!empty($bases) && $groupByWorkspace) {
        $byTeam = array();
        foreach ($bases as $b) {
            $byTeam[(int) $b['team_id']][] = $b;
        }

        if ($canCreateBase) {
            ?>
            <?php

            ?>
            <div class="home-base-grid home-base-grid--lead" id="home-base-grid-lead">
                <?php bcc_render_home_create_base_tile(); ?>
            </div>
            <?php
        }

        foreach ($teamNamesById as $tid => $tname) {
            $tid = (int) $tid;
            if (empty($byTeam[$tid])) {
                continue;
            }

            $groupRole = isset($roleByTeamId[$tid]) ? $roleByTeamId[$tid] : null;
            ?>
            <div class="home-section-head home-ws-head">
                <h2 class="home-section-title"><?php echo htmlspecialchars($tname, ENT_QUOTES, 'UTF-8'); ?></h2>
                <?php if ($groupRole !== null && isset($GLOBALS['BCC_ROLE_LABELS'][$groupRole])): ?>
                    <?php 
                          ?>
                    <span class="home-base-role home-base-role--<?php echo htmlspecialchars($groupRole, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$groupRole], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php

                ?>
                <?php if ($groupRole !== null && bcc_can_manage_members($groupRole)): ?>
                    <a class="home-ws-members" href="/team_members.php?team_id=<?php echo (int) $tid; ?>">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8" cy="7" r="2.8" stroke="currentColor" stroke-width="1.4"/><path d="M3 16c0-2.5 2.2-4 5-4s5 1.5 5 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M14.5 7.5h3M16 6v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        Katılımcılar
                    </a>
                <?php endif; ?>
                <span class="home-section-meta"><?php echo count($byTeam[$tid]); ?> base</span>
            </div>
            <?php

            bcc_render_home_base_grid_block($byTeam[$tid], $starredBaseIds, $teamNamesById, $roleByTeamId, false, false, $tableCounts, true);
        }

        return;
    }

    if (empty($bases)) {
        ?>
        <div class="home-empty">
            <p><?php echo htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($canCreateBase): ?>
                <?php

                ?>
                <button type="button" class="home-empty-create-btn" id="home-create-base-btn" data-create-base-open>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    Yeni Base Oluştur
                </button>
            <?php endif; ?>
        </div>
        <?php
        return;
    }

    bcc_render_home_base_grid_block($bases, $starredBaseIds, $teamNamesById, $roleByTeamId, $canCreateBase, $bento, $tableCounts, false);
}

function bcc_render_home_base_grid_block($bases, $starredBaseIds, $teamNamesById, $roleByTeamId, $canCreateBase, $bento, $tableCounts, $hideRole)
{
    ?>
    <div class="home-base-grid<?php echo $bento ? ' home-base-grid--bento' : ''; ?>">
        <div class="home-list-header" aria-hidden="true">
            <div class="home-list-header-icon"></div>
            <div class="home-list-header-info">
                <div class="home-list-header-name">Ad</div>
                <div class="home-list-header-meta">Son açılma</div>
            </div>
            <div class="home-list-header-workspace">Çalışma alanı</div>
        </div>
        <?php foreach ($bases as $bIdx => $b):
            $isStarred = isset($starredBaseIds[(int) $b['id']]);
            $workspaceName = isset($teamNamesById[(int) $b['team_id']]) ? $teamNamesById[(int) $b['team_id']] : '';
            $iconColor = bcc_base_icon_color($b['id']);
            $role = isset($roleByTeamId[(int) $b['team_id']]) ? $roleByTeamId[(int) $b['team_id']] : null;

            $canDelete = $role !== null && bcc_can_manage_bases($role);
            $variant = ($bento && $bIdx === 0) ? 'feature' : 'standard';
            $tc = isset($tableCounts[(int) $b['id']]) ? (int) $tableCounts[(int) $b['id']] : null;
            bcc_render_home_base_card($b, $iconColor, $isStarred, $workspaceName, $canDelete, $hideRole ? null : $role, $variant, $tc);
        endforeach; ?>
        <?php if ($canCreateBase) { bcc_render_home_create_base_tile(); } ?>
    </div>
    <?php
}

function bcc_interface_summary_field($fields)
{
    foreach ($fields as $f) {
        if ($f['field_type'] === 'long_text') {
            return $f;
        }
    }
    return null;
}

function bcc_fetch_cells_by_record($recordIds)
{
    if (empty($recordIds)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $cellRows = bcc_fetch_all(
        "SELECT record_id, field_id, value_text, value_number, value_date, value_json FROM cell_values WHERE record_id IN ($placeholders)",
        $recordIds
    );

    $cellsByRecord = array();
    foreach ($cellRows as $cell) {
        $cellsByRecord[$cell['record_id']][$cell['field_id']] = $cell;
    }

    return $cellsByRecord;
}

function bcc_fetch_attachments_by_record($recordIds)
{
    if (empty($recordIds)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT id, record_id, field_id, original_name, mime_type, file_size
         FROM attachments WHERE record_id IN ($placeholders) ORDER BY id",
        $recordIds
    );

    $byRecord = array();
    foreach ($rows as $row) {
        $byRecord[$row['record_id']][$row['field_id']][] = array(
            'id' => (int) $row['id'],
            'name' => $row['original_name'],
            'mime' => $row['mime_type'],
            'size' => (int) $row['file_size'],
        );
    }

    return $byRecord;
}

function bcc_build_grouped_tree($records, $groupRules, $usersById = array())
{
    $levelCount = count($groupRules);
    $tree = array();

    if ($levelCount === 0) {
        return $tree;
    }

    $openNodes = array();
    $counters = array_fill(0, $levelCount, -1);
    $prevKeys = null;

    foreach ($records as $record) {
        $keys = array();
        for ($lvl = 0; $lvl < $levelCount; $lvl++) {
            $keys[$lvl] = $record['group_raw_value_' . $lvl];
        }

        $divergeLevel = 0;
        if ($prevKeys !== null) {
            $divergeLevel = $levelCount;
            for ($lvl = 0; $lvl < $levelCount; $lvl++) {
                if ($keys[$lvl] !== $prevKeys[$lvl]) {
                    $divergeLevel = $lvl;
                    break;
                }
            }
        }

        for ($lvl = $divergeLevel; $lvl < $levelCount; $lvl++) {
            $counters[$lvl] = ($lvl === $divergeLevel) ? $counters[$lvl] + 1 : 0;

            $rule = $groupRules[$lvl];
            $rawValue = $keys[$lvl];

            if ($rawValue === null) {
                $display = '(Boş)';
            } else {
                $display = cell_display_text($rule['field_type'], bcc_group_cell_row($rule['column'], $rawValue), $usersById, $rule['options']);
            }

            $isLeaf = ($lvl === $levelCount - 1);
            $node = array(
                'level' => $lvl,
                'path' => implode('-', array_slice($counters, 0, $lvl + 1)),
                'display' => $display,
                'count' => 0,
                'is_leaf' => $isLeaf,
                'children' => $isLeaf ? null : array(),
                'records' => $isLeaf ? array() : null,
            );

            if ($lvl === 0) {
                $tree[] = $node;
                $openNodes[0] = &$tree[count($tree) - 1];
            } else {
                $openNodes[$lvl - 1]['children'][] = $node;
                $openNodes[$lvl] = &$openNodes[$lvl - 1]['children'][count($openNodes[$lvl - 1]['children']) - 1];
            }
        }

        $openNodes[$levelCount - 1]['records'][] = $record;

        for ($lvl = 0; $lvl < $levelCount; $lvl++) {
            $openNodes[$lvl]['count']++;
        }

        $prevKeys = $keys;
    }

    unset($openNodes);

    return $tree;
}

function bcc_interface_fetch_records($tableId, $primaryFieldId, $summaryFieldId, $searchTerm = null)
{
    $sql = "SELECT r.id, r.created_at,
                   COALESCE((SELECT MAX(cv2.updated_at) FROM cell_values cv2 WHERE cv2.record_id = r.id), r.created_at) AS last_update
            FROM records r
            WHERE r.table_id = ? AND r.deleted_at IS NULL";
    $params = array($tableId);

    if ($searchTerm !== null && $searchTerm !== '') {

        $fieldIds = array_values(array_filter(array($primaryFieldId, $summaryFieldId)));
        if (empty($fieldIds)) {
            return array();
        }
        $fieldPlaceholders = implode(',', array_fill(0, count($fieldIds), '?'));

        $escapedTerm = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $searchTerm);
        $sql .= " AND EXISTS (
                SELECT 1 FROM cell_values cv
                WHERE cv.record_id = r.id
                  AND cv.field_id IN ($fieldPlaceholders)
                  AND cv.value_text LIKE ? ESCAPE '\\\\'
            )";
        $params = array_merge($params, $fieldIds, array('%' . $escapedTerm . '%'));
    }

    $sql .= ' ORDER BY last_update DESC, r.id DESC';

    return bcc_fetch_all($sql, $params);
}

$GLOBALS['BCC_WORKSPACE_SOFT_LIMITS'] = array(
    'records' => 50000,
    'storage_bytes' => 2 * 1024 * 1024 * 1024,
    'bases' => 25,
);

$GLOBALS['BCC_USER_WORKSPACE_SOFT_LIMIT'] = 5;

function bcc_format_bytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = array('KB', 'MB', 'GB', 'TB');
    $value = $bytes / 1024;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return number_format($value, $value >= 10 ? 0 : 1, ',', '.') . ' ' . $units[$i];
}

function bcc_db_now()
{
    static $now = null;

    if ($now === null) {
        $raw = bcc_fetch_column('SELECT NOW()');
        $now = $raw !== null && $raw !== false ? strtotime((string) $raw) : time();
    }

    return $now;
}

function bcc_time_ago($datetime, $now = null)
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }

    $ts = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
    if ($ts === false || $ts <= 0) {
        return '—';
    }

    $diff = ($now === null ? bcc_db_now() : (int) $now) - $ts;

    if ($diff < 0) {
        return date('d.m.Y', $ts);
    }
    if ($diff < 60) {
        return 'az önce';
    }
    if ($diff < 3600) {
        return ((int) floor($diff / 60)) . ' dakika önce';
    }
    if ($diff < 86400) {
        return ((int) floor($diff / 3600)) . ' saat önce';
    }
    if ($diff < 2592000) {
        return ((int) floor($diff / 86400)) . ' gün önce';
    }

    return date('d.m.Y', $ts);
}

function bcc_workspace_bases($teamId, $userId = null)
{
    $teamId = (int) $teamId;

    return bcc_fetch_all(
        'SELECT
             b.id,
             b.name,
             b.description,
             b.created_at,
             b.icon,
             b.icon_color,
             (SELECT COUNT(*) FROM tables_meta tm WHERE tm.base_id = b.id) AS table_count,
             (SELECT COUNT(*)
                FROM records r
                INNER JOIN tables_meta tm2 ON tm2.id = r.table_id
               WHERE tm2.base_id = b.id AND r.deleted_at IS NULL) AS record_count,
             (SELECT MAX(cv.updated_at)
                FROM cell_values cv
                INNER JOIN records r2 ON r2.id = cv.record_id AND r2.deleted_at IS NULL
                INNER JOIN tables_meta tm3 ON tm3.id = r2.table_id
               WHERE tm3.base_id = b.id) AS last_edit_at,
             (SELECT COUNT(*) FROM user_starred_bases usb
               WHERE usb.base_id = b.id AND usb.user_id = :uid) AS is_starred
         FROM bases b
         WHERE b.team_id = :team_id AND b.deleted_at IS NULL
         ORDER BY b.name',
        array('team_id' => $teamId, 'uid' => (int) $userId)
    );
}

function bcc_workspace_usage($teamId)
{
    $teamId = (int) $teamId;
    $params = array('team_id' => $teamId);

    return array(
        'base_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM bases WHERE team_id = :team_id AND deleted_at IS NULL',
            $params
        ),
        'table_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM tables_meta tm
             INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
             WHERE b.team_id = :team_id',
            $params
        ),
        'record_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM records r
             INNER JOIN tables_meta tm ON tm.id = r.table_id
             INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
             WHERE b.team_id = :team_id AND r.deleted_at IS NULL',
            $params
        ),
        'storage_bytes' => (int) bcc_fetch_column(
            'SELECT COALESCE(SUM(a.file_size), 0) FROM attachments a
             INNER JOIN records r ON r.id = a.record_id AND r.deleted_at IS NULL
             INNER JOIN tables_meta tm ON tm.id = r.table_id
             INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
             WHERE b.team_id = :team_id',
            $params
        ),
        'slack_webhook_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM slack_webhooks WHERE team_id = :team_id AND is_active = 1',
            $params
        ),
    );
}

function bcc_workspace_activity($teamId, $limit = 12)
{
    $teamId = (int) $teamId;

    $limit = max(1, min(50, (int) $limit));

    $rows = bcc_fetch_all(
        "SELECT al.id, al.action, al.entity_type, al.entity_id, al.details, al.created_at,
                u.full_name AS actor_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.team_id = :team_id
           AND al.action NOT IN ('base.open', 'user.login', 'user.logout')
           AND al.action NOT LIKE '%.export/_%' ESCAPE '/'
         ORDER BY al.id DESC
         LIMIT " . $limit,
        array('team_id' => $teamId)
    );

    if (empty($rows)) {
        return array();
    }

    $baseIds = array();
    $tableIds = array();
    $userIds = array();

    foreach ($rows as $r) {
        $d = bcc_audit_details($r['details']);
        if ($r['entity_type'] === 'base' && $r['entity_id']) {
            $baseIds[(int) $r['entity_id']] = true;
        }
        if (isset($d['base_id'])) {
            $baseIds[(int) $d['base_id']] = true;
        }
        if (isset($d['table_id'])) {
            $tableIds[(int) $d['table_id']] = true;
        }
        if ($r['entity_type'] === 'table' && $r['entity_id']) {
            $tableIds[(int) $r['entity_id']] = true;
        }
        if (isset($d['user_id'])) {
            $userIds[(int) $d['user_id']] = true;
        }
    }

    $baseNames = bcc_ids_to_names('bases', array_keys($baseIds));
    $tableNames = bcc_ids_to_names('tables_meta', array_keys($tableIds));
    $userNames = bcc_ids_to_names('users', array_keys($userIds), 'full_name');

    $out = array();
    foreach ($rows as $r) {
        $d = bcc_audit_details($r['details']);

        $target = null;
        if (isset($d['name']) && is_string($d['name']) && $d['name'] !== '') {
            $target = $d['name'];
        } elseif (isset($d['field_name']) && is_string($d['field_name']) && $d['field_name'] !== '') {
            $target = $d['field_name'];
        } elseif (isset($d['table_id']) && isset($tableNames[(int) $d['table_id']])) {
            $target = $tableNames[(int) $d['table_id']];
        } elseif ($r['entity_type'] === 'table' && isset($tableNames[(int) $r['entity_id']])) {
            $target = $tableNames[(int) $r['entity_id']];
        } elseif ($r['entity_type'] === 'base' && isset($baseNames[(int) $r['entity_id']])) {
            $target = $baseNames[(int) $r['entity_id']];
        } elseif (isset($d['base_id']) && isset($baseNames[(int) $d['base_id']])) {
            $target = $baseNames[(int) $d['base_id']];
        } elseif (isset($d['user_id']) && isset($userNames[(int) $d['user_id']])) {
            $target = $userNames[(int) $d['user_id']];
        }

        $out[] = array(
            'id' => (int) $r['id'],
            'action' => $r['action'],
            'actor' => ($r['actor_name'] !== null && $r['actor_name'] !== '') ? $r['actor_name'] : 'Sistem',
            'label' => bcc_audit_action_label($r['action']),
            'kind' => bcc_audit_action_kind($r['action']),
            'target' => $target,
            'created_at' => $r['created_at'],
            'ago' => bcc_time_ago($r['created_at']),
        );
    }

    return $out;
}

function bcc_audit_details($raw)
{
    if ($raw === null || $raw === '') {
        return array();
    }
    $decoded = json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : array();
}

function bcc_ids_to_names($table, array $ids, $nameColumn = 'name')
{

    $allowed = array(
        'bases' => 'name',
        'tables_meta' => 'name',
        'users' => 'full_name',
    );
    if (!isset($allowed[$table]) || $allowed[$table] !== $nameColumn) {
        throw new InvalidArgumentException('bcc_ids_to_names: izin verilmeyen tablo/kolon.');
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        return array();
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = bcc_fetch_all("SELECT id, {$nameColumn} AS nm FROM {$table} WHERE id IN ($ph)", $ids);

    $map = array();
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row['nm'];
    }

    return $map;
}

function bcc_audit_action_label($action)
{
    $labels = array(
        'base.create' => 'yeni base oluşturdu',
        'base.delete' => 'base\'i çöpe taşıdı',
        'base.restore' => 'base\'i geri yükledi',
        'base.update' => 'base\'i güncelledi',
        'table.create' => 'yeni tablo oluşturdu',
        'table.update' => 'tabloyu güncelledi',
        'table.delete' => 'tabloyu sildi',
        'table.reorder' => 'tabloları yeniden sıraladı',
        'table.import_xlsx' => 'Excel\'den veri aktardı',
        'table.import_csv' => 'CSV\'den veri aktardı',
        'table.clear_data' => 'tablo verilerini temizledi',
        'table.rename' => 'tabloyu yeniden adlandırdı',
        'table.duplicate' => 'tabloyu çoğalttı',
        'field.create' => 'yeni alan ekledi',
        'field.update' => 'alanı güncelledi',
        'field.delete' => 'alanı sildi',
        'field.reorder' => 'alanları yeniden sıraladı',
        'record.create' => 'kayıt ekledi',
        'record.duplicate' => 'kaydı çoğalttı',
        'record.create_bulk' => 'toplu kayıt ekledi',
        'record.purge' => 'kaydı çöp kutusundan kalıcı sildi',
        'note_view.export_xlsx' => 'inceleme raporunu Excel indirdi',
        'view.export_csv' => 'görünümü CSV indirdi',
        'team_member.export_csv' => 'katılımcı listesini CSV indirdi',
        'record.delete_soft' => 'kaydı çöpe taşıdı',
        'record.restore' => 'kaydı geri yükledi',
        'record.delete' => 'kaydı kalıcı sildi',
        'record.form_submit' => 'form üzerinden kayıt geldi',
        'record.send' => 'kaydı e-posta ile gönderdi',
        'cell.update' => 'hücre güncelledi',
        'cell.bulk_paste' => 'toplu hücre yapıştırdı',
        'comment.add' => 'yorum ekledi',
        'comment.update' => 'yorumu düzenledi',
        'comment.delete' => 'yorumu sildi',
        'view.create' => 'yeni görünüm oluşturdu',
        'view.delete' => 'görünümü sildi',
        'view.rename' => 'görünümü yeniden adlandırdı',
        'view.duplicate' => 'görünümü çoğalttı',
        'view.reorder' => 'görünümleri yeniden sıraladı',
        'view.config_update' => 'görünüm düzenini değiştirdi',
        'view.kanban_config' => 'kanban ayarını değiştirdi',
        'view.form_config' => 'form ayarını değiştirdi',
        'view.description_update' => 'görünüm açıklamasını değiştirdi',
        'view.favorite_toggle' => 'görünümü favoriledi',
        'view.save_state' => 'görünüm durumunu kaydetti',
        'team_member.assign' => 'çalışma alanına katılımcı ekledi',
        'team_member.role_change' => 'katılımcı rolünü değiştirdi',
        'team_member.remove' => 'katılımcıyı çıkardı',
        'team.create' => 'yeni çalışma alanı oluşturdu',
        'attachment.upload' => 'dosya ekledi',
        'attachment.delete' => 'dosya ekini sildi',
        'slack.notify_sent' => 'Slack bildirimi gönderdi',
        'slack.notify_failed' => 'Slack bildirimi başarısız oldu',
        'slack.webhook_create' => 'Slack entegrasyonu ekledi',
        'slack.webhook_delete' => 'Slack entegrasyonunu kaldırdı',
        'slack.webhook_update' => 'Slack entegrasyonunu güncelledi',
        'slack.routing_rule_create' => 'Slack yönlendirme kuralı ekledi',
        'slack.routing_rule_delete' => 'Slack yönlendirme kuralını sildi',
        'slack.routing_rule_toggle' => 'Slack yönlendirme kuralını açtı/kapattı',
        'slack.watched_fields_update' => 'Slack izlenen alanlarını değiştirdi',
        'slack.test_sent' => 'Slack test bildirimi gönderdi',
        'slack.test_failed' => 'Slack test bildirimi başarısız oldu',
        'slack.routing_rule_reorder' => 'Slack kurallarını sıraladı',
        'user.account_updated' => 'hesap bilgilerini güncelledi',
    );

    return isset($labels[$action]) ? $labels[$action] : $action;
}

function bcc_audit_action_kind($action)
{
    $byPrefix = array(
        'base' => 'base',
        'table' => 'table',
        'field' => 'field',
        'record' => 'record',
        'cell' => 'record',
        'comment' => 'comment',
        'view' => 'view',
        'team_member' => 'member',
        'attachment' => 'file',
        'slack' => 'slack',
    );

    $prefix = strtok((string) $action, '.');

    return isset($byPrefix[$prefix]) ? $byPrefix[$prefix] : 'other';
}
