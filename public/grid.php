<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$pdo = bcc_get_pdo();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

// Her erişimde KVKK ekip izolasyonu: bu tablonun ekibine üye olmayan hiçbir şey göremez.
require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = in_array($role, array('editor', 'owner'), true);

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    // Kayıt ekleme/silme yalnızca editor+ rolünde açık.
    require_role($table['team_id'], 'editor');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_record') {
        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM records WHERE table_id = :table_id');
        $posStmt->execute(array(':table_id' => $table['id']));
        $nextPos = (int) $posStmt->fetch()['next_pos'];

        $user = current_user();
        $stmt = $pdo->prepare('INSERT INTO records (table_id, position, created_by) VALUES (:table_id, :position, :created_by)');
        $stmt->execute(array(':table_id' => $table['id'], ':position' => $nextPos, ':created_by' => $user['id']));
        $newId = $pdo->lastInsertId();
        log_audit('record.create', 'record', $newId, array('table_id' => $table['id']), $table['team_id']);
        $success = 'Kayıt eklendi.';
    } elseif ($action === 'delete_record') {
        $recordId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;

        $checkStmt = $pdo->prepare('SELECT id FROM records WHERE id = :id AND table_id = :table_id LIMIT 1');
        $checkStmt->execute(array(':id' => $recordId, ':table_id' => $table['id']));

        if (!$checkStmt->fetch()) {
            http_response_code(403);
            die('Bu kayıt bu tabloya ait değil.');
        }

        $stmt = $pdo->prepare('DELETE FROM records WHERE id = :id');
        $stmt->execute(array(':id' => $recordId));
        log_audit('record.delete', 'record', $recordId, array('table_id' => $table['id']), $table['team_id']);
        $success = 'Kayıt silindi.';
    }
}

$stmt = $pdo->prepare('SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id');
$stmt->execute(array(':table_id' => $table['id']));
$fields = $stmt->fetchAll();

$fieldsById = array();
foreach ($fields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}

// Sıralama (Faz 4): sort_field_1..3 / sort_dir_1..3 GET parametreleri, yalnızca bu
// tabloya ait alanlar kabul edilir. Kalıcılık henüz yok — durum URL'de taşınıyor.
$sortRules = parse_grid_sort_rules($_GET, $fieldsById);

// Filtreleme (Faz 4): filter_field_1..5 / filter_cond_1..5 / filter_value_1..5 +
// filter_logic (and/or). Alan id'si VE operatör whitelist'te değilse kural
// sessizce yok sayılır (parse_grid_filter_rules). Değerler prepared statement ile
// bağlanır (filter_condition_sql), sunucu tarafında gerçek SQL sorgusu ile filtrelenir.
$filterRules = parse_grid_filter_rules($_GET, $fieldsById);
$filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';

$recordsSql = 'SELECT r.id, r.position, r.created_at FROM records r';
$recordsParams = array(':table_id' => $table['id']);
$orderParts = array();

foreach ($sortRules as $idx => $rule) {
    $alias = 'sv' . $idx;
    $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :sfid{$idx}";
    $recordsParams[':sfid' . $idx] = $rule['field_id'];
    $orderParts[] = "{$alias}.{$rule['column']} {$rule['dir']}";
}

$filterConds = array();
foreach ($filterRules as $idx => $rule) {
    $alias = 'fv' . $idx;
    $paramName = ':fval' . $idx;
    $frag = filter_condition_sql($rule['field_type'], $rule['operator'], $rule['raw_value'], $alias, $paramName);

    if ($frag === null) {
        continue; // deger gecersiz/eksik (ör. sayi alaninda sayi olmayan girdi) -> kural atlanir
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

$recordsSql .= ' WHERE r.table_id = :table_id';
if (!empty($filterConds)) {
    $joinWord = ($filterLogic === 'OR') ? ' OR ' : ' AND ';
    $recordsSql .= ' AND (' . implode($joinWord, $filterConds) . ')';
}
$recordsSql .= ' ORDER BY ' . implode(', ', $orderParts);

$stmt = $pdo->prepare($recordsSql);
$stmt->execute($recordsParams);
$records = $stmt->fetchAll();

// Kayıt ekleme/silme formlarının ve "temizle" linklerinin geçerli sort/filter
// durumunu koruması için ortak query string parçaları.
$sortState = array();
foreach ($sortRules as $rule) {
    $sortState['sort_field_' . $rule['slot']] = $rule['field_id'];
    $sortState['sort_dir_' . $rule['slot']] = strtolower($rule['dir']);
}

$filterState = array();
foreach ($filterRules as $rule) {
    $filterState['filter_field_' . $rule['slot']] = $rule['field_id'];
    $filterState['filter_cond_' . $rule['slot']] = $rule['operator'];
    $filterState['filter_value_' . $rule['slot']] = $rule['raw_value'];
}
if (!empty($filterRules)) {
    $filterState['filter_logic'] = strtolower($filterLogic);
}

$baseState = array('table_id' => $table['id']);
$stateQueryString = http_build_query($baseState + $sortState + $filterState);
$clearSortQueryString = http_build_query($baseState + $filterState);
$clearFilterQueryString = http_build_query($baseState + $sortState);

$cellsByRecord = array();
if (!empty($records) && !empty($fields)) {
    $recordIds = array_column($records, 'id');
    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $stmt = $pdo->prepare("SELECT record_id, field_id, value_text, value_number, value_date, value_json FROM cell_values WHERE record_id IN ($placeholders)");
    $stmt->execute($recordIds);

    foreach ($stmt->fetchAll() as $cell) {
        $cellsByRecord[$cell['record_id']][$cell['field_id']] = $cell;
    }
}

$typeBadges = $GLOBALS['BCC_FIELD_TYPE_BADGE'];
$typeLabels = $GLOBALS['BCC_FIELD_TYPES'];

// Tablo sekme şeridi için: aynı base'in diğer tabloları (görünüm amaçlı, salt-okunur).
// base_id zaten yukarıda require_team_access($table['team_id']) ile doğrulandı,
// bu yüzden aynı base_id'ye ait kardeş tablolar da güvenle listelenebilir.
$siblingTablesStmt = $pdo->prepare('SELECT id, name FROM tables_meta WHERE base_id = :base_id ORDER BY position, id');
$siblingTablesStmt->execute(array(':base_id' => $table['base_id']));
$siblingTables = $siblingTablesStmt->fetchAll();

$gridUser = current_user();
$gridUserInitial = mb_strtoupper(mb_substr((string) $gridUser['full_name'], 0, 1, 'UTF-8'), 'UTF-8');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<title>BCC-Core — <?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/grid-shell.css">
</head>
<body class="gs-body">

<aside class="gs-rail">
    <a href="/dashboard.php" class="gs-rail-logo" title="Home'a dön">
        <img src="/assets/bcc-logo.svg" alt="BCC-Core" class="gs-rail-logo-img">
        <svg class="gs-rail-back-icon" width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M12.5 4.5L6 10l6.5 5.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <div class="gs-rail-bottom">
        <button type="button" class="gs-rail-icon-btn" aria-label="Bildirimler">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M10 2.5c-2.4 0-4.2 1.9-4.2 4.3v2.6c0 .5-.2 1.3-.5 1.7L4.4 12.5c-.6.8-.2 1.9.8 2.2 3.3 1 6.9 1 10.2 0 .9-.3 1.3-1.4.7-2.2l-.9-1.4c-.3-.4-.5-1.2-.5-1.7V6.8c0-2.4-1.9-4.3-4.2-4.3z" stroke="#ccc" stroke-width="1.3" stroke-linejoin="round"/><path d="M8.2 16.5a1.8 1.8 0 003.6 0" stroke="#ccc" stroke-width="1.3" stroke-linecap="round"/></svg>
        </button>
        <div class="gs-account">
            <button type="button" class="gs-avatar" id="gs-account-toggle"><?php echo htmlspecialchars($gridUserInitial, ENT_QUOTES, 'UTF-8'); ?></button>
            <div class="gs-account-menu" id="gs-account-menu">
                <div class="gs-account-info">
                    <div class="gs-account-name"><?php echo htmlspecialchars($gridUser['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="gs-account-email"><?php echo htmlspecialchars($gridUser['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <form method="post" action="/logout.php" class="gs-account-logout">
                    <?php echo csrf_field(); ?>
                    <button type="submit">Çıkış</button>
                </form>
            </div>
        </div>
    </div>
</aside>

<div class="gs-main-col">
    <header class="gs-topbar">
        <a href="/base_tables.php?base_id=<?php echo (int) $table['base_id']; ?>" class="gs-topbar-left" title="Base tablolarına dön">
            <span class="gs-base-icon">▤</span>
            <span class="gs-base-name"><?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <nav class="gs-topbar-tabs">
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>" class="gs-topbar-tab is-active">Data</a>
            <button type="button" class="gs-topbar-tab">Automations</button>
            <button type="button" class="gs-topbar-tab">Interfaces</button>
            <button type="button" class="gs-topbar-tab">Forms</button>
        </nav>
        <div class="gs-topbar-right">
            <button type="button" class="gs-rail-icon-btn gs-icon-btn-dark" aria-label="Geçmiş">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 5.5V10l3 2" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.3 6.5A6.5 6.5 0 1110 16.5" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/><path d="M3 3.5v3.3h3.3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="gs-btn-ghost">Launch</button>
            <button type="button" class="gs-btn-primary">Share</button>
        </div>
    </header>

    <div class="gs-table-tabs">
        <div class="gs-table-tabs-scroll">
            <?php foreach ($siblingTables as $st): ?>
                <a
                    href="/grid.php?table_id=<?php echo (int) $st['id']; ?>"
                    class="gs-table-tab <?php echo (int) $st['id'] === (int) $table['id'] ? 'is-active' : ''; ?>"
                ><?php echo htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
        </div>
        <a href="/base_tables.php?base_id=<?php echo (int) $table['base_id']; ?>" class="gs-table-tab-add" title="Yeni tablo">+</a>
    </div>

    <div class="gs-view-toolbar">
        <div class="gs-view-toolbar-left">
            <button type="button" class="gs-icon-btn" id="gs-view-panel-toggle" aria-label="Görünüm panelini aç/kapat">
                <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M2.5 5.5h15M2.5 10h15M2.5 14.5h15" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
            </button>
            <span class="gs-view-label">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="2" stroke="#1a73e8" stroke-width="1.4"/><path d="M3 8h14M8 3v14" stroke="#1a73e8" stroke-width="1.2"/></svg>
                Grid view
            </span>
        </div>
        <div class="gs-view-toolbar-right">
            <button type="button" class="gs-tool-btn">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M2.5 6h15M2.5 10h10M2.5 14h6" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                Hide fields
            </button>

            <?php if (!empty($fields)): ?>
            <details class="filter-panel gs-tool-details">
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M3 4h14l-5.5 6.5V16l-3-1.5v-4L3 4z" stroke="#5f6368" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    Filter<?php echo !empty($filterRules) ? ' (' . count($filterRules) . ')' : ''; ?>
                </summary>
                <form method="get" action="/grid.php" class="filter-form">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <?php for ($slot = 1; $slot <= 5; $slot++):
                        $currentRule = null;
                        foreach ($filterRules as $rule) {
                            if ($rule['slot'] === $slot) {
                                $currentRule = $rule;
                                break;
                            }
                        }
                        $currentFieldId = $currentRule ? $currentRule['field_id'] : 0;
                        $currentFieldType = $currentRule ? $currentRule['field_type'] : null;
                        $currentOp = $currentRule ? $currentRule['operator'] : '';
                        $currentValue = $currentRule ? $currentRule['raw_value'] : '';
                        $opsForField = $currentFieldType ? $GLOBALS['BCC_FILTER_OPERATORS'][$currentFieldType] : array();
                        $valueHidden = in_array($currentOp, $GLOBALS['BCC_FILTER_NO_VALUE_OPS'], true);
                        $valueInputType = 'text';
                        if ($currentFieldType === 'number') {
                            $valueInputType = 'number';
                        } elseif ($currentFieldType === 'date') {
                            $valueInputType = 'date';
                        }
                    ?>
                        <div class="filter-row">
                            <select name="filter_field_<?php echo $slot; ?>" class="filter-field-select">
                                <option value="">— yok —</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?php echo (int) $f['id']; ?>" <?php echo $currentFieldId === (int) $f['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="filter_cond_<?php echo $slot; ?>" class="filter-cond-select" <?php echo $opsForField ? '' : 'disabled'; ?>>
                                <?php if (empty($opsForField)): ?>
                                    <option value="">— önce alan seçin —</option>
                                <?php else: ?>
                                    <?php foreach ($opsForField as $opKey => $opLabel): ?>
                                        <option value="<?php echo htmlspecialchars($opKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentOp === $opKey ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($opLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <input
                                type="<?php echo $valueInputType; ?>"
                                name="filter_value_<?php echo $slot; ?>"
                                class="filter-value-input"
                                value="<?php echo htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="değer"
                                <?php echo $valueHidden ? 'style="display:none"' : ''; ?>
                            >
                        </div>
                    <?php endfor; ?>
                    <div class="filter-logic-row">
                        <label><input type="radio" name="filter_logic" value="and" <?php echo $filterLogic === 'AND' ? 'checked' : ''; ?>> VE (tüm kurallar)</label>
                        <label><input type="radio" name="filter_logic" value="or" <?php echo $filterLogic === 'OR' ? 'checked' : ''; ?>> VEYA (herhangi biri)</label>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-sm">Uygula</button>
                        <?php if (!empty($filterRules)): ?>
                            <a class="btn-sm" href="/grid.php?<?php echo htmlspecialchars($clearFilterQueryString, ENT_QUOTES, 'UTF-8'); ?>">Temizle</a>
                        <?php endif; ?>
                    </div>
                </form>
            </details>
            <?php endif; ?>

            <button type="button" class="gs-tool-btn">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="6" cy="6" r="2" stroke="#5f6368" stroke-width="1.3"/><circle cx="14" cy="14" r="2" stroke="#5f6368" stroke-width="1.3"/><path d="M8 6h9M3 14h3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                Group
            </button>

            <?php if (!empty($fields)): ?>
            <details class="sort-panel gs-tool-details">
                <summary class="gs-tool-btn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 5h9M4 10h6M4 15h3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/><path d="M15 4v11m0 0l-2.5-2.5M15 15l2.5-2.5" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Sort<?php echo !empty($sortRules) ? ' (' . count($sortRules) . ')' : ''; ?>
                </summary>
                <form method="get" action="/grid.php" class="sort-form">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <?php for ($slot = 1; $slot <= 3; $slot++):
                        $currentFieldId = 0;
                        $currentDir = 'asc';
                        foreach ($sortRules as $rule) {
                            if ($rule['slot'] === $slot) {
                                $currentFieldId = $rule['field_id'];
                                $currentDir = strtolower($rule['dir']);
                                break;
                            }
                        }
                    ?>
                        <div class="sort-row">
                            <select name="sort_field_<?php echo $slot; ?>">
                                <option value="">— yok —</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?php echo (int) $f['id']; ?>" <?php echo $currentFieldId === (int) $f['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="sort_dir_<?php echo $slot; ?>">
                                <option value="asc" <?php echo $currentDir === 'asc' ? 'selected' : ''; ?>>artan</option>
                                <option value="desc" <?php echo $currentDir === 'desc' ? 'selected' : ''; ?>>azalan</option>
                            </select>
                        </div>
                    <?php endfor; ?>
                    <div class="sort-actions">
                        <button type="submit" class="btn-sm">Uygula</button>
                        <?php if (!empty($sortRules)): ?>
                            <a class="btn-sm" href="/grid.php?<?php echo htmlspecialchars($clearSortQueryString, ENT_QUOTES, 'UTF-8'); ?>">Temizle</a>
                        <?php endif; ?>
                    </div>
                </form>
            </details>
            <?php endif; ?>

            <button type="button" class="gs-tool-btn">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="6.5" stroke="#5f6368" stroke-width="1.4"/><path d="M10 3.5a6.5 6.5 0 010 13" fill="#5f6368"/></svg>
                Color
            </button>
            <button type="button" class="gs-tool-btn">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="4" rx="1" stroke="#5f6368" stroke-width="1.3"/><rect x="3" y="12" width="14" height="4" rx="1" stroke="#5f6368" stroke-width="1.3"/></svg>
                Row height
            </button>
            <button type="button" class="gs-tool-btn">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M14 6.5a2 2 0 10-1.9-2.6M6 10a2 2 0 10-1.9 2.6M14 13.5a2 2 0 10-1.9 2.6M5.8 9l6.4-3.4M5.8 11l6.4 3.4" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round"/></svg>
                Share and sync
            </button>

            <?php if (!empty($fields)): ?>
            <div class="gs-search">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#5f6368" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                <input type="text" id="grid-search" placeholder="Ara…">
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="gs-view-drawer" id="gs-view-drawer">
        <button type="button" class="gs-view-drawer-create">+ Create new...</button>
        <div class="gs-view-drawer-search">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="#8a8a8e" stroke-width="1.4"/><path d="M12.7 12.7L17 17" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" placeholder="Find a view">
        </div>
        <div class="gs-view-drawer-list">
            <div class="gs-view-drawer-view is-selected">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="2" stroke="#1a73e8" stroke-width="1.4"/><path d="M3 8h14M8 3v14" stroke="#1a73e8" stroke-width="1.2"/></svg>
                Grid view
            </div>
        </div>
    </div>

    <main class="gs-main">
        <p class="gs-fields-link">
            <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">Alanları yönet</a>
        </p>

        <?php if ($error !== null): ?>
            <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($success !== null): ?>
            <p class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if (empty($fields)): ?>
            <div class="card">
                <p>Bu tabloda henüz alan yok. Önce <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">alan ekleyin</a>.</p>
            </div>
        <?php else: ?>
            <div class="grid-wrap">
                <table class="grid">
                    <thead>
                        <tr>
                            <th class="grid-rownum">#</th>
                            <?php foreach ($fields as $f): ?>
                                <th>
                                    <span class="field-badge" title="<?php echo htmlspecialchars($typeLabels[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($typeBadges[$f['field_type']], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) $f['is_required'] === 1): ?><span class="req-mark" title="Zorunlu">*</span><?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <?php if ($canEdit): ?><th class="grid-actions-col">İşlemler</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td class="grid-empty" colspan="<?php echo count($fields) + 1 + ($canEdit ? 1 : 0); ?>">Bu tabloda henüz kayıt yok.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $i => $record): ?>
                                <tr data-record-id="<?php echo (int) $record['id']; ?>">
                                    <td class="grid-rownum"><?php echo (int) $i + 1; ?></td>
                                    <?php foreach ($fields as $f):
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
                                                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                                <input type="hidden" name="record_id" value="<?php echo (int) $record['id']; ?>">
                                                <button type="submit" class="btn-sm btn-danger">Sil</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canEdit): ?>
                <form method="post" action="/grid.php?<?php echo htmlspecialchars($stateQueryString, ENT_QUOTES, 'UTF-8'); ?>" class="grid-add-record">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_record">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <button type="submit">+ Kayıt Ekle</button>
                </form>
            <?php else: ?>
                <p class="hint">Bu ekipte kayıt eklemek/düzenlemek için editor veya owner rolü gerekir.</p>
            <?php endif; ?>

            <div class="gs-grid-footer">
                <span class="grid-row-count" id="grid-row-count"></span>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if (!empty($fields)): ?>
<script>
    var BCC_FIELD_TYPES_BY_ID = <?php
        $typesById = array();
        foreach ($fields as $f) {
            $typesById[(int) $f['id']] = $f['field_type'];
        }
        echo json_encode($typesById, JSON_UNESCAPED_UNICODE);
    ?>;
    var BCC_FILTER_OPS = <?php echo json_encode($GLOBALS['BCC_FILTER_OPERATORS'], JSON_UNESCAPED_UNICODE); ?>;
    var BCC_FILTER_NO_VALUE_OPS = <?php echo json_encode($GLOBALS['BCC_FILTER_NO_VALUE_OPS'], JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="/assets/grid-toolbar.js" defer></script>
<script src="/assets/grid-filter.js" defer></script>
<?php endif; ?>
<?php if ($canEdit && !empty($fields)): ?>
<script src="/assets/grid.js" defer></script>
<?php endif; ?>
<script>
(function () {
    var accountToggle = document.getElementById('gs-account-toggle');
    var accountMenu = document.getElementById('gs-account-menu');
    if (accountToggle && accountMenu) {
        accountToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            accountMenu.classList.toggle('is-open');
        });
    }

    var drawerToggle = document.getElementById('gs-view-panel-toggle');
    var drawer = document.getElementById('gs-view-drawer');
    if (drawerToggle && drawer) {
        drawerToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            drawer.classList.toggle('is-open');
        });
    }

    document.addEventListener('click', function (e) {
        if (accountMenu && !accountMenu.contains(e.target)) {
            accountMenu.classList.remove('is-open');
        }
        if (drawer && !drawer.contains(e.target) && e.target !== drawerToggle) {
            drawer.classList.remove('is-open');
        }
    });
})();
</script>
</body>
</html>
