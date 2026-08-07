<?php
// AJAX uçnoktası: "+ Yeni oluştur..." — sol Görünüm panelinde boş (config=NULL)
// yeni bir view oluşturur. view_duplicate.php ile AYNI ekleme deseni (tablonun
// EN SONUNA, position = mevcut MAX + 1) — yalnızca config'i kopyalamak yerine
// boş bırakır. Güvenlik deseni diğer view_*.php uçnoktalarıyla AYNI.

require __DIR__ . '/../../src/api_bootstrap.php';

api_require_post();
api_require_login();
api_require_csrf();

$tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
$user = current_user();

try {
    $table = find_table_or_404($tableId);
    require_role($table['team_id'], 'editor');

    // Görünüm TÜRÜ artık istemciden geliyor ("+ Yeni oluştur..." tip seçici) —
    // eskiden sabit 'grid' yazılıyordu. Whitelist'e karşı doğrulanır; uymayan
    // her değer reddedilir (SQL'e ASLA doğrudan gömülmez, prepared statement'a
    // yalnızca whitelist'ten GEÇMİŞ bir anahtar bağlanır).
    $viewType = isset($_POST['view_type']) ? (string) $_POST['view_type'] : 'grid';
    if (!isset($GLOBALS['BCC_VIEW_TYPES'][$viewType])) {
        json_fail(422, 'Geçersiz görünüm türü.');
    }

    // Ad, TÜRE göre numaralanır ("Form 1", "Tablo 2") — eskiden tüm görünümler
    // tek sayaçtan "Görünüm N" alıyordu. Tür etiketi BCC_VIEW_TYPES'tan gelir,
    // elle yazılmaz.
    $sameTypeCount = (int) bcc_fetch_column(
        'SELECT COUNT(*) FROM views WHERE table_id = :table_id AND view_type = :vt',
        array(':table_id' => $table['id'], ':vt' => $viewType)
    );
    $newName = $GLOBALS['BCC_VIEW_TYPES'][$viewType] . ' ' . ($sameTypeCount + 1);
    $nextPosition = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 FROM views WHERE table_id = :table_id',
        array(':table_id' => $table['id'])
    );

    // INSERT + log_audit AYNI transaction'da — record_add.php/table_clear_data.php'de
    // bulunan AYNI sınıf bug: ikisi ayrı olsaydı, log_audit() istisna atarsa
    // (nadir ama mümkün) INSERT zaten commit edilmiş olurdu, istemci yine de
    // "Veritabanı hatası" görürdü.
    bcc_begin_transaction();

    bcc_execute(
        'INSERT INTO views (table_id, name, view_type, position, created_by)
         VALUES (:table_id, :name, :view_type, :position, :created_by)',
        array(
            ':table_id' => $table['id'],
            ':name' => $newName,
            ':view_type' => $viewType,
            ':position' => $nextPosition,
            ':created_by' => $user ? $user['id'] : null,
        )
    );

    $newViewId = bcc_last_insert_id();

    // Form görünümü: herkese açık linkin SIRRI burada üretilir (migrations/015).
    // AYNI transaction'da — ayrı commit edilseydi araya düşen bir hata
    // "form görünümü var ama token'ı yok, linki hiç üretilemez" durumu bırakırdı.
    // random_bytes: CSPRNG (csrf_token() ile AYNI kaynak). 16 bayt = 32 hex.
    // form_enabled = 1: yeni form doğrudan açık gelir (Airtable davranışı);
    // kolonun DEFAULT'u 0 (fail-closed), burada AÇIKÇA 1 yapılıyor.
    if ($viewType === 'form') {
        bcc_execute(
            'UPDATE views SET form_token = :token, form_enabled = 1 WHERE id = :id',
            array(':token' => bin2hex(random_bytes(16)), ':id' => $newViewId)
        );
    }

    // Kanban: tablodaki İLK single_select alanı varsayılan sütunlama alanı olur —
    // kullanıcı görünümü açar açmaz çalışan bir tahta görsün, önce ayar paneline
    // gitmek zorunda kalmasın (Airtable'ın "hemen kullanılabilir" hissi).
    //
    // Hiç single_select yoksa kanban_field_id HİÇ yazılmaz (bcc_kanban_config_from_view
    // 0 döndürür = "seçilmemiş") — bu HATA DEĞİL, tasarlanmış bir boş durum:
    // kanban.php yönlendirici bir ekran gösterip alan oluşturmaya çağırır.
    // Görünümün oluşturulmasını ENGELLEMİYORUZ; tip seçiciyi tablo durumuna göre
    // gri yapmak menüye tablo bilgisi taşımak demek olurdu ve kullanıcıyı
    // "Kanban istiyorum ama neden yok?" çıkmazına sokardı.
    if ($viewType === 'kanban') {
        $firstSelect = bcc_fetch_one(
            "SELECT id FROM fields WHERE table_id = :tid AND field_type = 'single_select'
             ORDER BY position, id LIMIT 1",
            array(':tid' => $table['id'])
        );

        if ($firstSelect) {
            bcc_execute(
                'UPDATE views SET config = :config WHERE id = :id',
                array(
                    // Yeni görünüm, config'i NULL — read-modify-write gerekmez,
                    // ezilecek başka anahtar yok.
                    ':config' => json_encode(array('kanban_field_id' => (int) $firstSelect['id']), JSON_UNESCAPED_UNICODE),
                    ':id' => $newViewId,
                )
            );
        }
    }

    log_audit('view.create', 'view', $newViewId, array('table_id' => $table['id'], 'name' => $newName, 'view_type' => $viewType), $table['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Veritabanı hatası.');
}

// redirect_url SUNUCUDAN gelir — istemci artık '/grid.php?...' dizgisini kendi
// birleştirmiyor. Yeni bir görünüm türü eklendiğinde JS'e dokunmak gerekmesin
// diye tek yönlendirme noktası (bcc_view_route_for) kullanılıyor.
echo json_encode(array(
    'ok' => true,
    'view_id' => $newViewId,
    'name' => $newName,
    'view_type' => $viewType,
    'redirect_url' => bcc_view_route_for($viewType, $table['id'], $newViewId),
), JSON_UNESCAPED_UNICODE);
