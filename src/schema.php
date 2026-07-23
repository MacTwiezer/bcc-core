<?php
// Base / tablo / alan şeması sayfalarının ortak yardımcıları (Faz 2).

require_once __DIR__ . '/auth.php';

$GLOBALS['BCC_FIELD_TYPES'] = array(
    'single_line_text' => 'Tek satır metin',
    'long_text' => 'Uzun metin',
    'number' => 'Sayı',
    'checkbox' => 'Onay kutusu',
    'date' => 'Tarih',
    'single_select' => 'Tekli seçim',
    'multiple_select' => 'Çoklu seçim',
);

$GLOBALS['BCC_SELECT_FIELD_TYPES'] = array('single_select', 'multiple_select');

// Bir alan tipinin değeri cell_values'ta hangi kolonda saklanır (Faz 3).
$GLOBALS['BCC_FIELD_VALUE_COLUMN'] = array(
    'single_line_text' => 'value_text',
    'long_text' => 'value_text',
    'number' => 'value_number',
    'checkbox' => 'value_number',
    'date' => 'value_date',
    'single_select' => 'value_text',
    'multiple_select' => 'value_json',
);

// Grid sütun başlığında gösterilen kısa tip rozeti.
$GLOBALS['BCC_FIELD_TYPE_BADGE'] = array(
    'single_line_text' => 'Aa',
    'long_text' => '¶',
    'number' => '#',
    'checkbox' => '☑',
    'date' => '📅',
    'single_select' => '▾',
    'multiple_select' => '☰',
);

// Grid filtresi (Faz 4): alan tipine göre izin verilen koşullar (whitelist).
// Anahtarlar SQL'e gömülmez — filter_condition_sql() içinde sabit switch/case ile eşlenir.
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
);

// Değer girdisi gerektirmeyen operatörler (input UI'da gizlenir).
$GLOBALS['BCC_FILTER_NO_VALUE_OPS'] = array('empty', 'not_empty', 'checked', 'unchecked');

// Grid gruplama (Grid araçları Adım 2a): alan tipine göre yön dropdown etiketleri
// (mantık her zaman artan/azalan — yalnızca metin değişir, Airtable'daki gibi).
$GLOBALS['BCC_GROUP_DIR_LABELS'] = array(
    'single_line_text' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'long_text' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'single_select' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'multiple_select' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'number' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    'date' => array('asc' => 'Earliest → Latest', 'desc' => 'Latest → Earliest'),
    'checkbox' => array('asc' => 'Unchecked → Checked', 'desc' => 'Checked → Unchecked'),
);

// Grid satır yüksekliği (Grid araçları Adım 3): whitelist + kaçta kaç satırın
// gösterileceği (line-clamp) etiketleri. Sıra panel render'ında da kullanılır.
$GLOBALS['BCC_ROW_HEIGHT_LABELS'] = array(
    'short' => 'Short',
    'medium' => 'Medium',
    'tall' => 'Tall',
    'extra' => 'Extra Tall',
);

// team_id, bases üzerinden gelir; bir base'in verisine erişen her sayfa bunu kullanmalı.
function find_base_or_404($baseId)
{
    $base = bcc_fetch_one(
        'SELECT id, team_id, name, description FROM bases WHERE id = :id LIMIT 1',
        array('id' => $baseId)
    );

    if (!$base) {
        http_response_code(404);
        die('Base bulunamadı.');
    }

    return $base;
}

// team_id, tables_meta -> bases üzerinden gelir; bir tablonun verisine erişen her sayfa bunu kullanmalı.
function find_table_or_404($tableId)
{
    $table = bcc_fetch_one(
        'SELECT tm.id, tm.base_id, tm.name, tm.description, tm.position, b.team_id, b.name AS base_name
         FROM tables_meta tm
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE tm.id = :id LIMIT 1',
        array('id' => $tableId)
    );

    if (!$table) {
        http_response_code(404);
        die('Tablo bulunamadı.');
    }

    return $table;
}

// Bir tabloya ait TEK varsayılan görünüm satırını (id + name) döndürür; yoksa
// oluşturur. grid.php şu ana kadar "Grid view" adını sabit basıyordu — görünüm
// adını satır içi yeniden adlandırma özelliği kalıcı bir view_id gerektirdiği
// için bu fonksiyon her table_id'nin en az bir views satırına sahip olmasını
// garanti eder. Şemaya DOKUNMAZ (views tablosu zaten schema.sql'de var), yalnızca
// satır okur/yazar.
// Yarış koşulu: iki istek aynı anda ilk kez buraya gelirse ikisi de INSERT
// deneyebilir (views.table_id üzerinde UNIQUE kısıt yok, DDL uygulanmıyor).
// Bunu tamamen engellemek yerine ZARARSIZ hale getiriyoruz: INSERT'ten SONRA
// satır her zaman TEKRAR "id ASC LIMIT 1" ile okunur — olası bir kısa süreli
// çift satır oluşsa bile tüm çağıranlar hep AYNI (en eski) satırda buluşur,
// hiçbir çağıran "az önce ben oluşturdum" varsayımıyla ikinci bir satır üretmez.
function bcc_get_or_create_default_view($tableId)
{
    $view = bcc_fetch_one(
        'SELECT id, name, config FROM views WHERE table_id = :table_id ORDER BY id ASC LIMIT 1',
        array('table_id' => $tableId)
    );

    if ($view) {
        return $view;
    }

    bcc_execute(
        'INSERT INTO views (table_id, name, view_type)
         SELECT :table_id, :name, :view_type
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM views WHERE table_id = :table_id)',
        array('table_id' => $tableId, 'name' => 'Grid view', 'view_type' => 'grid')
    );

    return bcc_fetch_one(
        'SELECT id, name, config FROM views WHERE table_id = :table_id ORDER BY id ASC LIMIT 1',
        array('table_id' => $tableId)
    );
}

// Dondurulabilecek en fazla sütun sayısı (satır no dahil) — görünür alan sayısının
// yaklaşık yarısı, en az 1. grid.php (ilk render) ve view_config_update.php
// (sürükleme sonrası doğrulama) AYNI formülü paylaşır, ikisi ayrı ayrı hesaplamaz.
function bcc_max_frozen_columns($visibleFieldCount)
{
    $total = $visibleFieldCount + 1; // +1: satır no kolonu her zaman sayılır

    return max(1, (int) ceil($total / 2));
}

// views.config JSON'ından dondurulmuş sütun sayısını SAVUNMACI biçimde okur:
// NULL, bozuk JSON, eksik anahtar veya beklenmedik tip (ör. string/float) gelirse
// sessizce varsayılana (1 — yalnızca satır no) düşer, hata fırlatmaz. $maxAllowed
// verilirse üst sınıra da kırpılır (config'teki eski bir değer, sonradan alan
// gizlenip görünür sütun sayısı azalınca render'ı bozmasın diye).
function bcc_get_frozen_column_count($configJson, $maxAllowed = null)
{
    $count = 1;

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

// Bir base'e ait tüm tabloları (id + name) position,id sırasına göre döndürür.
// Sekme şeridi (grid.php) ve base.php köprü sayfası (ilk tabloyu bulmak için) aynı
// sorguyu paylaşır — iki yerde ayrı ayrı yazılmaz.
function bcc_list_base_tables($baseId)
{
    return bcc_fetch_all(
        'SELECT id, name FROM tables_meta WHERE base_id = :base_id ORDER BY position, id',
        array('base_id' => $baseId)
    );
}

function is_select_field_type($fieldType)
{
    return in_array($fieldType, $GLOBALS['BCC_SELECT_FIELD_TYPES'], true);
}

// "Her satırda bir seçenek" metnini fields.options için JSON'a çevirir.
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

// team_id, fields -> tables_meta -> bases üzerinden gelir; bir alanın hücre verisine
// erişen her sayfa/uçnokta bunu kullanmalı. Bulunamazsa null döner (404/die yapmaz) —
// çağıran taraf kendi hata davranışını (die ile HTML ya da JSON) seçer.
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

function find_field_or_404($fieldId)
{
    $field = bcc_find_field($fieldId);

    if (!$field) {
        http_response_code(404);
        die('Alan bulunamadı.');
    }

    return $field;
}

// Bir cell_values satırından (veya kayıt yoksa null'dan), o hücrenin edit alanına
// dolduracağımız "ham" değeri çıkarır (input/select doldurmak için).
function cell_raw_value($fieldType, $cellRow)
{
    if ($cellRow === null) {
        return $fieldType === 'multiple_select' ? '[]' : '';
    }

    switch ($fieldType) {
        case 'single_line_text':
        case 'long_text':
        case 'single_select':
            return (string) $cellRow['value_text'];
        case 'number':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';
        case 'checkbox':
            return ((int) $cellRow['value_number'] === 1) ? '1' : '0';
        case 'date':
            return $cellRow['value_date'] !== null ? substr($cellRow['value_date'], 0, 10) : '';
        case 'multiple_select':
            return $cellRow['value_json'] !== null ? $cellRow['value_json'] : '[]';
        default:
            return '';
    }
}

// Grid hücresinde salt-okunur görüntülenecek metni üretir (htmlspecialchars çağıran taraf yapar).
function cell_display_text($fieldType, $cellRow)
{
    if ($cellRow === null) {
        return '';
    }

    switch ($fieldType) {
        case 'single_line_text':
        case 'long_text':
        case 'single_select':
            return (string) $cellRow['value_text'];
        case 'number':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';
        case 'date':
            return $cellRow['value_date'] !== null ? date('d.m.Y', strtotime($cellRow['value_date'])) : '';
        case 'multiple_select':
            $choices = $cellRow['value_json'] !== null ? json_decode($cellRow['value_json'], true) : array();
            return is_array($choices) ? implode(', ', $choices) : '';
        default:
            return '';
    }
}

// Bir kayıt satırını (hücreler + varsa "Sil" formu) basar. Gruplu ve düz (grupsuz)
// tbody render'ı arasında paylaşılır; $groupPath verilirse satıra
// data-group-path eklenir (grid-group.js aç/kapa bunu prefix eşleşmesiyle kullanır).
// grid.php'nin ilk sayfa render'ı VE public/api/record_add.php (AJAX ile eklenen
// tek bir satırın HTML'ini üretmek için) aynı fonksiyonu paylaşır — iki yerde
// ayrı ayrı yazılmaz.
function bcc_render_grid_data_row($record, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $groupPath = null)
{
    ?>
    <tr data-record-id="<?php echo (int) $record['id']; ?>" <?php echo $groupPath !== null ? 'data-group-path="' . htmlspecialchars($groupPath, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
        <td class="grid-rownum"><?php echo (int) $rowNum; ?></td>
        <?php foreach ($visibleFields as $f):
            $cellRow = isset($cellsByRecord[$record['id']][$f['id']]) ? $cellsByRecord[$record['id']][$f['id']] : null;
            $rawValue = cell_raw_value($f['field_type'], $cellRow);
            $displayText = cell_display_text($f['field_type'], $cellRow);
            $choices = is_select_field_type($f['field_type']) ? select_choices_from_options($f['options']) : array();
        ?>
            <td
                class="grid-cell <?php echo $canEdit ? 'editable' : ''; ?>"
                data-field-id="<?php echo (int) $f['id']; ?>"
                data-field-type="<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"
                data-value="<?php echo htmlspecialchars($rawValue, ENT_QUOTES, 'UTF-8'); ?>"
                <?php if ($choices): ?>data-options="<?php echo htmlspecialchars(json_encode($choices, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
            >
                <?php if ($f['field_type'] === 'checkbox'): ?>
                    <input type="checkbox" class="cell-checkbox" <?php echo $rawValue === '1' ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
                <?php else: ?>
                    <div class="cell-view"><?php echo htmlspecialchars($displayText, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </td>
        <?php endforeach; ?>
        <?php if ($canEdit): ?>
            <td class="grid-actions-col">
                <form method="post" action="/grid.php?<?php echo htmlspecialchars($stateQueryString, ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Bu kaydı silmek istediğinize emin misiniz?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_record">
                    <input type="hidden" name="table_id" value="<?php echo (int) $tableId; ?>">
                    <input type="hidden" name="record_id" value="<?php echo (int) $record['id']; ?>">
                    <button type="submit" class="btn-sm btn-danger">Sil</button>
                </form>
            </td>
        <?php endif; ?>
    </tr>
    <?php
}

// Grid gruplama (Grid araçları Adım 2a): bir grup başlığının ham hücre değerini
// cell_display_text() ile biçimlendirebilmek için, o fonksiyonun beklediği
// cell_values satırı şeklinde sahte bir dizi üretir (GROUP BY sorgusu tek bir kolon
// SELECT ettiği için diğer üç kolon her zaman null'dur) — cell_update.php'deki
// aynı desenin tekrarı.
function bcc_group_cell_row($column, $rawValue)
{
    $row = array('value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null);
    $row[$column] = $rawValue;

    return $row;
}

// Kardeş kayıtlar arasında sıra değiştirme (yukarı/aşağı taşı) — base_tables.php
// (move_table) ve table_fields.php (move_field) tarafından paylaşılır.
// GÜVENLİK: $tableName ve $parentColumn prepared statement ile bağlanamaz, doğrudan
// SQL'e gömülür — bu yüzden KESİNLİKLE aşağıdaki sabit whitelist'ten gelmeli, asla
// kullanıcı girdisinden (ör. $_POST) türememeli. Uyuşmayan bir çift verilirse (kod
// hatası anlamına gelir) istisna fırlatılır.
// Dönüş: takas yapıldıysa true; ilk/son eleman, geçersiz yön ya da öge bulunamadıysa false.
function bcc_reorder_sibling($tableName, $parentColumn, $parentId, $itemId, $direction)
{
    $allowedParents = array(
        'tables_meta' => 'base_id',
        'fields' => 'table_id',
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

    bcc_begin_transaction();
    bcc_execute("UPDATE {$tableName} SET position = :pos WHERE id = :id", array('pos' => $b['position'], 'id' => $a['id']));
    bcc_execute("UPDATE {$tableName} SET position = :pos WHERE id = :id", array('pos' => $a['position'], 'id' => $b['id']));
    bcc_commit();

    return true;
}

// Kullanıcıdan gelen ham değeri (POST'tan) fields.field_type'a göre doğrular ve
// cell_values'a yazılacak kolon + normalize edilmiş değeri döndürür.
// Dönüş: array('ok' => bool, 'error' => string|null, 'column' => string|null, 'value' => mixed)
function normalize_cell_value($fieldType, $optionsJson, $rawValue)
{
    $columnMap = $GLOBALS['BCC_FIELD_VALUE_COLUMN'];

    if (!isset($columnMap[$fieldType])) {
        return array('ok' => false, 'error' => 'Bilinmeyen alan tipi.');
    }

    $column = $columnMap[$fieldType];

    switch ($fieldType) {
        case 'single_line_text':
        case 'long_text':
            $text = trim((string) $rawValue);

            return array('ok' => true, 'column' => $column, 'value' => $text === '' ? null : $text);

        case 'number':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!is_numeric($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz sayı.');
            }

            return array('ok' => true, 'column' => $column, 'value' => (float) $raw);

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

        default:
            return array('ok' => false, 'error' => 'Bilinmeyen alan tipi.');
    }
}

// Grid'in çoklu sıralama panelinden gelen sort_field_N / sort_dir_N (N=1..3) GET
// parametrelerini doğrular. Yalnızca $fieldsById içindeki (yani bu tabloya ait)
// alan id'lerini kabul eder — team_id/tablo her zaman DB satırından gelir.
function parse_grid_sort_rules($params, $fieldsById)
{
    $rules = array();

    for ($i = 1; $i <= 3; $i++) {
        $fieldKey = 'sort_field_' . $i;

        if (empty($params[$fieldKey])) {
            continue;
        }

        $fieldId = (int) $params[$fieldKey];

        if (!isset($fieldsById[$fieldId])) {
            continue;
        }

        $dirKey = 'sort_dir_' . $i;
        $dir = (isset($params[$dirKey]) && $params[$dirKey] === 'desc') ? 'DESC' : 'ASC';
        $fieldType = $fieldsById[$fieldId]['field_type'];

        $rules[] = array(
            'slot' => $i,
            'field_id' => $fieldId,
            'dir' => $dir,
            'column' => $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType],
        );
    }

    return $rules;
}

// Grid'in Group panelinden gelen group_field_1..3 / group_dir_1..3 GET
// parametrelerini doğrular (çok seviyeli gruplama, en fazla 3 kural). Yalnızca
// $fieldsById'e ait (bu tabloya ait) bir alan id'si kabul edilir — gizli (Hide
// fields ile kapatılmış) bir alan da gruplama için geçerlidir, whitelist kaynağı
// her zaman $fieldsById'in tamamıdır. Yön parse_grid_sort_rules ile aynı şekilde
// ele alınır ve aynı biçimde döner: yalnızca tam olarak "desc" DESC'e karşılık
// gelir, başka her şey (eksik dahil) ASC sayılır; dönüş değerindeki 'dir' de
// parse_grid_sort_rules ile birebir aynı biçimde büyük harf 'ASC'/'DESC' olur
// (ORDER BY'a doğrudan gömülür, ayrıca panel <select>'lerindeki karşılaştırmalar
// da bu biçimi bekler — grid.php URL state'ine yazarken strtolower() ile küçük
// harfe çevirir, tıpkı sort kurallarında olduğu gibi).
//
// Geriye dönük uyum: yeni group_field_1..3 parametrelerinden hiçbiri istekte
// YOKSA (isset ile kontrol edilir — boş gönderilmiş olması "yeni format
// kullanılıyor" sayılır), eski tekil group_field / group_dir parametreleri
// varsa 1. seviye olarak okunur. Eski parametre adları hiçbir zaman üretilmez,
// yalnızca okunur (bkz. grid.php'deki $groupState).
//
// Geçersiz/silinmiş/whitelist dışı alan id'leri o slotu sessizce eler; sonuç
// dizisi yalnızca geçerli kuralları, orijinal slot sırasına göre, BOŞLUK
// BIRAKMADAN içerir — yani 2. seviye silinip 3. seviye kalırsa, 3. seviyenin
// kuralı dizide 2. sıraya (index 1) düşer. Bu sıkıştırma ayrı bir adım değil,
// doğrudan 1..3 taramasının bir sonucudur.
//
// Aynı alan iki seviyede birden seçilemez (Airtable davranışı): FAZ 4'teki
// panel zaten kullanılmış alanları dropdown'dan düşürecek, ama URL elle
// değiştirilebildiği için burada da bir güvenlik ağı var — bir field_id daha
// önceki (daha düşük) bir seviyede zaten kullanıldıysa, sonraki tekrarı
// sessizce elenir (o slot dizide yer almaz, altındaki seviyeler yine kayar).
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

        $dir = (isset($params[$source['dir_key']]) && $params[$source['dir_key']] === 'desc') ? 'DESC' : 'ASC';
        $fieldType = $fieldsById[$fieldId]['field_type'];

        $rules[] = array(
            'slot' => count($rules) + 1,
            'field_id' => $fieldId,
            'field_type' => $fieldType,
            'dir' => $dir,
            'column' => $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType],
        );
        $usedFieldIds[$fieldId] = true;
    }

    return $rules;
}

// Grid'in filtre panelinden gelen filter_field_N / filter_cond_N / filter_value_N
// (N=1..5) GET parametrelerini doğrular. Yalnızca $fieldsById'e ait alan id'leri VE
// o alan tipi için whitelist'te tanımlı operatörler kabul edilir; geri kalanı
// sessizce yok sayılır. Değerin (sayı/tarih formatı vb.) doğrulanması
// filter_condition_sql() içinde, SQL'e bağlanma anında yapılır.
function parse_grid_filter_rules($params, $fieldsById)
{
    $maxSlots = 5;
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

// Grid'in "Hide fields" panelinden gelen görünürlük tercihini doğrular ve gizlenecek
// alan id'lerini döndürür. Birincil alan ($primaryFieldId — position/id'ye göre bu
// tablonun ilk alanı) HİÇBİR ZAMAN gizlenemez, URL'e elle yazılsa bile (Airtable'daki
// gibi) — bu fonksiyon onu iki yolda da sonuçtan düşürür.
// İki girdi şekli kabul edilir:
//  - visible_fields[]=ID&visible_fields[]=ID...: panelin kendi formu (toggle'lar
//    "işaretli = görünür") tarayıcı tarafından böyle gönderilir; işaretli olmayan
//    alanlar gizli sayılır. visible_fields_submitted=1 imleyicisi, "form gönderildi
//    ama hiçbir kutu işaretli değil" durumunu "bu istek panel formundan hiç gelmedi"
//    durumundan ayırt etmek için gerekli (aksi halde ikisi de "parametre yok" gibi görünür).
//  - hidden_fields=ID,ID,...: diğer bağlantılar/formlar (Tümünü gizle kısayolu,
//    Filter/Sort formlarındaki durum input'u) doğrudan bu biçimi üretir.
// Yalnızca $fieldsById'e ait (bu tabloya ait) alan id'leri kabul edilir, sahte/yabancı
// id'ler sessizce yok sayılır — parse_grid_sort_rules / parse_grid_filter_rules ile
// aynı yaklaşım. Gizli alan veriden çıkmaz; yalnızca grid.php'nin render ettiği sütun
// listesinden düşer (filtre/sıralama etkilenmez).
// Dönüş: gizlenecek alan id'lerinin (int) dizisi.
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

// Grid'in Row height panelinden gelen row_height GET parametresini doğrular
// (whitelist, BCC_ROW_HEIGHT_LABELS'in anahtarları). Geçersiz/eksikse 'short' döner.
function parse_grid_row_height($params)
{
    $value = isset($params['row_height']) ? (string) $params['row_height'] : 'short';

    return isset($GLOBALS['BCC_ROW_HEIGHT_LABELS'][$value]) ? $value : 'short';
}

// Grid'in Row height panelinden gelen wrap_headers GET parametresini doğrular.
// Yalnızca tam olarak "1" açık sayılır — eksik ya da başka her şey kapalı demektir.
function parse_grid_wrap_headers($params)
{
    return isset($params['wrap_headers']) && $params['wrap_headers'] === '1';
}

// Doğrulanmış tek bir filtre kuralını SQL WHERE parçasına çevirir.
// $alias: bu kural için LEFT JOIN edilmiş cell_values takma adı (ör. "fv0").
// $paramName: SQL'de kullanılacak bind parametre adı (ör. ":fval0"), kolonu içerir.
// Dönüş: array('sql' => string, 'params' => array($paramName => $value)) veya
// değer geçersiz/eksikse null (bu durumda kural sessizce filtreden düşer).
function filter_condition_sql($fieldType, $operator, $rawValue, $alias, $paramName)
{
    $allowedOps = isset($GLOBALS['BCC_FILTER_OPERATORS'][$fieldType]) ? $GLOBALS['BCC_FILTER_OPERATORS'][$fieldType] : array();
    if (!isset($allowedOps[$operator])) {
        return null;
    }

    $column = $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType];
    $isTextLike = in_array($fieldType, array('single_line_text', 'long_text', 'single_select'), true);

    if (in_array($operator, $GLOBALS['BCC_FILTER_NO_VALUE_OPS'], true)) {
        switch ($operator) {
            case 'empty':
                if ($isTextLike) {
                    return array('sql' => "({$alias}.{$column} IS NULL OR {$alias}.{$column} = '')", 'params' => array());
                }
                return array('sql' => "{$alias}.{$column} IS NULL", 'params' => array());
            case 'not_empty':
                if ($isTextLike) {
                    return array('sql' => "({$alias}.{$column} IS NOT NULL AND {$alias}.{$column} <> '')", 'params' => array());
                }
                return array('sql' => "{$alias}.{$column} IS NOT NULL", 'params' => array());
            case 'checked':
                return array('sql' => "{$alias}.{$column} = 1", 'params' => array());
            case 'unchecked':
                return array('sql' => "({$alias}.{$column} = 0 OR {$alias}.{$column} IS NULL)", 'params' => array());
        }
    }

    $raw = trim((string) $rawValue);

    if ($fieldType === 'number') {
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $map = array('eq' => '=', 'neq' => '<>', 'gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<=');
        if (!isset($map[$operator])) {
            return null;
        }

        $value = (float) $raw;

        if ($operator === 'neq') {
            return array('sql' => "({$alias}.{$column} <> {$paramName} OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => $value));
        }

        return array('sql' => "{$alias}.{$column} {$map[$operator]} {$paramName}", 'params' => array($paramName => $value));
    }

    if ($fieldType === 'date') {
        $d = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) {
            return null;
        }

        if ($operator === 'before') {
            return array('sql' => "{$alias}.{$column} < {$paramName}", 'params' => array($paramName => $raw . ' 00:00:00'));
        }
        if ($operator === 'after') {
            return array('sql' => "{$alias}.{$column} > {$paramName}", 'params' => array($paramName => $raw . ' 23:59:59'));
        }
        if ($operator === 'equals') {
            return array('sql' => "DATE({$alias}.{$column}) = {$paramName}", 'params' => array($paramName => $raw));
        }

        return null;
    }

    if ($fieldType === 'multiple_select') {
        if ($raw === '') {
            return null;
        }

        if ($operator === 'contains') {
            return array('sql' => "JSON_CONTAINS({$alias}.{$column}, JSON_QUOTE({$paramName}))", 'params' => array($paramName => $raw));
        }
        if ($operator === 'not_contains') {
            return array('sql' => "(NOT JSON_CONTAINS({$alias}.{$column}, JSON_QUOTE({$paramName})) OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => $raw));
        }

        return null;
    }

    // Metin benzeri: single_line_text, long_text, single_select
    if ($raw === '' && $operator !== 'equals' && $operator !== 'not_equals') {
        return null;
    }

    switch ($operator) {
        case 'contains':
            return array('sql' => "{$alias}.{$column} LIKE {$paramName}", 'params' => array($paramName => '%' . $raw . '%'));
        case 'not_contains':
            return array('sql' => "({$alias}.{$column} NOT LIKE {$paramName} OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => '%' . $raw . '%'));
        case 'equals':
            return array('sql' => "{$alias}.{$column} = {$paramName}", 'params' => array($paramName => $raw));
        case 'not_equals':
            return array('sql' => "({$alias}.{$column} <> {$paramName} OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => $raw));
    }

    return null;
}
