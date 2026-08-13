<?php
// AJAX uçnoktası: Duyuru arayüzünün (public/interface.php) kayıt listesini
// filtre / sıralama / gruplama uygulanmış hâliyle döndürür.
//
// SIFIR YENİ SORGU MANTIĞI: kural ayrıştırma (parse_grid_filter_rules /
// parse_grid_sort_rules / parse_grid_group_rules), SQL kurma
// (bcc_build_grid_records_query) ve grup ağacı (bcc_build_grouped_tree)
// grid.php'nin kullandığı AYNI fonksiyonlar — bu dosya yalnızca onları çağırıp
// sonucu istemcinin anlayacağı düz bir listeye çeviriyor. Parametre adları da
// grid.php ile birebir aynı (filter_field_N / filter_cond_N / filter_value_N /
// filter_logic, sort_field_N / sort_dir_N, group_field_N / group_dir_N), yani
// aynı URL iki ekranda da aynı sonucu verir.
//
// interface_search.php gibi SALT-OKUNUR (SELECT) — mutasyon yok, CSRF gerekmez;
// require_team_access() ise her uçnoktadaki gibi zorunlu.
//
// Kayıtları YENİDEN RENDER ETMEZ: interface.php satırları zaten basmış durumda,
// burada yalnızca "hangi kayıtlar, hangi sırada, hangi grup başlığının altında"
// bilgisi dönüyor; istemci mevcut DOM düğümlerini sıralayıp gösterip gizliyor
// (ikinci bir HTML üretim yolu yok — interface_search.php ile AYNI desen).

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_login();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : 0;
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $table = find_table_or_404($tableId);
    require_team_access($table['team_id']);

    $fields = bcc_fetch_all(
        'SELECT id, name, field_type, options FROM fields WHERE table_id = :table_id ORDER BY position, id',
        array('table_id' => $tableId)
    );

    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }

    // grid.php:223 ile AYNI kural: 'or' dışındaki her şey AND.
    $filterLogic = (isset($_GET['filter_logic']) && $_GET['filter_logic'] === 'or') ? 'OR' : 'AND';

    $filterRules = parse_grid_filter_rules($_GET, $fieldsById);
    $sortRules = parse_grid_sort_rules($_GET, $fieldsById);
    $groupRules = parse_grid_group_rules($_GET, $fieldsById);

    list($sql, $params) = bcc_build_grid_records_query($tableId, $groupRules, $sortRules, $filterRules, $filterLogic);
    $records = bcc_fetch_all($sql, $params);

    // Arama, filtreyle BİRLİKTE çalışır (biri diğerini ezmez): arama zaten
    // kendi uç noktasında (interface_search.php) tam-içerik LIKE'ı yapıyor,
    // burada onu tekrarlamak yerine aynı fonksiyondan gelen id kümesiyle
    // KESİŞİM alınıyor — tek bir arama mantığı var.
    if ($query !== '') {
        $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : null;
        $summaryField = bcc_interface_summary_field($fields);
        $summaryFieldId = $summaryField ? (int) $summaryField['id'] : null;

        $matched = bcc_interface_fetch_records($tableId, $primaryFieldId, $summaryFieldId, $query);
        $allowed = array_flip(array_map('intval', array_column($matched, 'id')));

        $records = array_values(array_filter($records, function ($r) use ($allowed) {
            return isset($allowed[(int) $r['id']]);
        }));
    }

    // Grup başlığı etiketleri kullanıcı adlarını çözebilmeli ('user' tipli bir
    // alana göre gruplandığında) — grid.php'deki AYNI harita.
    $usersById = bcc_team_users_by_id($table['team_id']);

    $items = array();
    if (!empty($groupRules)) {
        // Ağaç grid.php'nin kullandığı fonksiyondan geliyor; burada yalnızca
        // kenar çubuğunun düz listesine uygun biçimde DÜZLEŞTİRİLİYOR.
        $tree = bcc_build_grouped_tree($records, $groupRules, $usersById);
        $flatten = function ($nodes) use (&$flatten, &$items) {
            foreach ($nodes as $node) {
                $items[] = array(
                    't' => 'g',
                    'level' => (int) $node['level'],
                    'label' => (string) $node['display'],
                    'count' => (int) $node['count'],
                );
                if ($node['is_leaf']) {
                    foreach ($node['records'] as $rec) {
                        $items[] = array('t' => 'r', 'id' => (int) $rec['id']);
                    }
                } else {
                    $flatten($node['children']);
                }
            }
        };
        $flatten($tree);
    } else {
        foreach ($records as $rec) {
            $items[] = array('t' => 'r', 'id' => (int) $rec['id']);
        }
    }
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array(
    'ok' => true,
    'items' => $items,
    'record_ids' => array_map('intval', array_column($records, 'id')),
    'counts' => array(
        'filters' => count($filterRules),
        'sorts' => count($sortRules),
        'groups' => count($groupRules),
    ),
), JSON_UNESCAPED_UNICODE);
