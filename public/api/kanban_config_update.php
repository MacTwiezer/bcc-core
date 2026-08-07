<?php
// AJAX uçnoktası: Kanban "Sütunlama" panelinin ayarlarını kaydeder —
// kanban_field_id (hangi single_select alanına göre sütunlanacak) ve
// kanban_card_fields (kartta birincil alanın altında görünecek ek alanlar).
//
// Güvenlik deseni view_config_update.php ile BİREBİR AYNI: CSRF + login +
// require_role('editor') + team_id'nin DB satırından gelmesi (istekten DEĞİL).
// Oku-değiştir-yaz bcc_update_view_config()'te — diğer config anahtarları
// (frozen_column_count, grid_state, form_*) EZİLMEZ.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$viewId = isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0;

try {
    $view = bcc_fetch_one(
        'SELECT v.id, v.table_id, v.view_type, v.config, b.team_id
         FROM views v
         INNER JOIN tables_meta tm ON tm.id = v.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE v.id = :id LIMIT 1',
        array(':id' => $viewId)
    );

    if (!$view) {
        json_fail(404, 'Görünüm bulunamadı.');
    }

    // ⚠️ SIRA ÖNEMLİ — yetki kontrolü tür kontrolünden ÖNCE.
    // Ters sırada, BAŞKA BİR TAKIMDAKİ oturumlu bir kullanıcı yanıt koduna
    // bakarak "bu view_id var mı ve Kanban mı" bilgisini öğrenebilirdi
    // (422 = var ve Kanban değil, 403 = var ve Kanban). Önce yetkiyi
    // kapatınca ekibe üye olmayan herkes tür fark etmeksizin 403 alır.
    require_role($view['team_id'], 'editor');

    // Tür kontrolü: bu uçnokta yalnızca Kanban görünümlerini yapılandırır.
    // Olmasaydı bir grid görünümüne kanban_* anahtarları yazılabilirdi —
    // zararsız görünür ama config'i kirletir ve "bu görünüm ne?" sorusunu
    // belirsizleştirirdi.
    if ($view['view_type'] !== 'kanban') {
        json_fail(422, 'Bu görünüm bir Kanban görünümü değil.');
    }

    $fields = bcc_fetch_all(
        'SELECT id, field_type FROM fields WHERE table_id = :tid ORDER BY position, id',
        array(':tid' => $view['table_id'])
    );
    $fieldsById = array();
    foreach ($fields as $f) {
        $fieldsById[(int) $f['id']] = $f;
    }
    // Birincil alan kartta HER ZAMAN basılır, ek alan listesine giremez
    // (grid'in $fields[0] kuralı — parse_grid_hidden_fields'in birincil alanı
    // gizlenmekten koruması ile AYNI mantık).
    $primaryFieldId = !empty($fields) ? (int) $fields[0]['id'] : 0;

    // --- kanban_field_id -----------------------------------------------------
    // 0 = "seçilmemiş" (boş durum). Sıfırdan farklıysa alan GERÇEKTEN bu tabloda
    // olmalı VE sütunlamaya uygun tip olmalı — istemcinin gönderdiği id'ye
    // güvenilmez.
    $changes = array();

    if (array_key_exists('kanban_field_id', $_POST)) {
        $requested = (int) $_POST['kanban_field_id'];

        if ($requested === 0) {
            $changes['kanban_field_id'] = 0;
        } elseif (!isset($fieldsById[$requested])) {
            json_fail(422, 'Alan bu tabloya ait değil.');
        } elseif (!bcc_field_allowed_for_kanban($fieldsById[$requested]['field_type'])) {
            json_fail(422, 'Bu alan tipine göre sütunlanamaz (yalnızca Tekli seçim).');
        } else {
            $changes['kanban_field_id'] = $requested;
        }
    }

    // --- kanban_card_fields --------------------------------------------------
    // Gelen id listesi tabloya AİT olanlara süzülür; birincil alan ve sütunlama
    // alanı listeden çıkarılır (ikisi de kartta zaten var / rozet olarak var).
    if (array_key_exists('kanban_card_fields', $_POST)) {
        $posted = is_array($_POST['kanban_card_fields']) ? $_POST['kanban_card_fields'] : array();
        $columnFieldId = isset($changes['kanban_field_id'])
            ? (int) $changes['kanban_field_id']
            : bcc_kanban_config_from_view($view)['kanban_field_id'];

        $selected = array();
        foreach ($posted as $rawId) {
            if (!is_scalar($rawId)) {
                continue;
            }
            $fid = (int) $rawId;
            if ($fid > 0 && $fid !== $primaryFieldId && $fid !== $columnFieldId
                && isset($fieldsById[$fid]) && !in_array($fid, $selected, true)) {
                $selected[] = $fid;
            }
        }

        $changes['kanban_card_fields'] = $selected;
    }

    if (empty($changes)) {
        json_fail(422, 'Değiştirilecek bir ayar gönderilmedi.');
    }

    bcc_update_view_config($view['id'], $changes);

    log_audit('view.kanban_config', 'view', $view['id'], $changes, $view['team_id']);
} catch (Throwable $e) {
    json_fail(500, 'Veritabanı hatası.');
}

echo json_encode(array('ok' => true), JSON_UNESCAPED_UNICODE);
