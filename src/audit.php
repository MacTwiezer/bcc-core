<?php

function log_audit($action, $entityType = null, $entityId = null, $details = null, $teamId = null)
{
    $user = current_user();

    bcc_execute(
        'INSERT INTO audit_log (team_id, user_id, action, entity_type, entity_id, details)
         VALUES (:team_id, :user_id, :action, :entity_type, :entity_id, :details)',
        array(
            'team_id' => $teamId,
            'user_id' => $user ? $user['id'] : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        )
    );
}

// "Son açılma" tarih filtresi (dashboard.php) audit_log'daki base.open olaylarından
// türetilir. Her F5'te yeni satır oluşmasını önlemek için: aynı kullanıcı+base için
// son 5 dakika içinde zaten bir base.open kaydı varsa onu (created_at'ini şimdiye
// çekerek) GÜNCELLER, yoksa yeni satır ekler. audit_log'da bunu garanti eden bir
// UNIQUE kısıt yok (DDL uygulanmıyor) — iki isteğin aynı anda ikisinin de INSERT
// denemesi teorik olarak mümkün, ama sonucu en fazla bir fazla satır, işlevsel bir
// hata değil (views.created_by'daki tekillik garantisi kadar kritik değil).
function log_base_open($baseId, $teamId)
{
    $user = current_user();
    $userId = $user ? $user['id'] : null;

    if ($userId === null) {
        return;
    }

    $recent = bcc_fetch_one(
        "SELECT id FROM audit_log
         WHERE action = 'base.open' AND entity_type = 'base' AND entity_id = :entity_id
           AND user_id = :user_id AND created_at > (NOW() - INTERVAL 5 MINUTE)
         ORDER BY id DESC LIMIT 1",
        array('entity_id' => $baseId, 'user_id' => $userId)
    );

    if ($recent) {
        bcc_execute('UPDATE audit_log SET created_at = NOW() WHERE id = :id', array('id' => $recent['id']));
        return;
    }

    bcc_execute(
        'INSERT INTO audit_log (team_id, user_id, action, entity_type, entity_id) VALUES (:team_id, :user_id, :action, :entity_type, :entity_id)',
        array('team_id' => $teamId, 'user_id' => $userId, 'action' => 'base.open', 'entity_type' => 'base', 'entity_id' => $baseId)
    );
}

// Bildirim paneli (zil ikonu) — YENİ bir notifications tablosu YOK (onaylanan
// "basit" model): audit_log salt-okunur gösterilir, KVKK team_id IN (...) ile
// filtrelenir, yalnızca bu whitelist'teki action'lar bildirim sayılır — user.login
// (368 satırın %66'sı) ve cell.update (%7'si) gibi gürültülü/kişisel olaylar
// KASITLI olarak DIŞARIDA (bkz. PROJE-DURUM.md analiz notu).
// Değer = bildirimin İZLEYİCİ KİTLESİ (aşağıdaki bcc_notification_audience_*
// kapıları). Eskiden düz bir action listesiydi ve ekipteki HERKES hepsini
// görüyordu; bulunan gerçek sorun buydu — viewer rolündeki bir kullanıcıya
// "Slack bildirimi gönderilemedi" (yalnızca owner'ın açabildiği bir entegrasyon
// ayarının hatası) ve "ekibe yeni bir üye ekledi" (yalnızca owner'ın
// yapabildiği işlem) düşüyordu. İkisi de o kullanıcının ne görebildiği ne de
// hakkında bir şey yapabildiği olaylar.
$GLOBALS['BCC_NOTIFICATION_ACTIONS'] = array(
    // VERİ olayları: dört rol de bu verinin kendisini zaten görüyor
    // (require_team_access üyelikle geçer), dolayısıyla değiştiğini bilmek de
    // dört rolün hakkı.
    'record.create' => 'data',
    'view.rename' => 'data',
    // ENTEGRASYON: Slack ayarları owner-only (public/slack_settings.php ->
    // require_role('owner') + bcc_can_manage_schema). "Gönderildi/gönderilemedi"
    // yalnızca o ayarı açıp düzeltebilen kişiye anlamlı.
    'slack.notify_sent' => 'integration',
    'slack.notify_failed' => 'integration',
    // ÜYELİK: ekleme/rol değiştirme owner-only (bcc_can_manage_members).
    'team_member.assign' => 'members',
    'team_member.role_change' => 'members',
);

// Bir rolün göreceği action'lar. EŞİKLER BURADA YENİDEN YAZILMAZ: her kitle,
// ilgili işlemi yapmaya yetkili kılan src/auth.php yeteneğinin TA KENDİSİNE
// sorulur. Böylece "üye yönetimi editor'a da açılsın" gibi bir karar tek yerde
// (bcc_can_manage_members) verilince bildirim görünürlüğü de kendiliğinden
// onunla birlikte kayar — panelin ayrı bir rol listesi tutmasına gerek yok.
function bcc_notification_actions_for_role($role)
{
    $actions = array();

    foreach ($GLOBALS['BCC_NOTIFICATION_ACTIONS'] as $action => $audience) {
        $visible = false;

        switch ($audience) {
            case 'data':
                // Ekibin üyesi olmak yeter — rol farkı gözetilmez.
                $visible = $role !== null;
                break;
            case 'integration':
                $visible = bcc_can_manage_schema($role);
                break;
            case 'members':
                $visible = bcc_can_manage_members($role);
                break;
        }

        if ($visible) {
            $actions[] = $action;
        }
    }

    return $actions;
}

// Bildirim GÖRÜNÜRLÜK koşulu — audit_log satırlarını hem panelin listesi hem de
// "tek tek okundu" uçnoktasının yetki kontrolü bu TEK ifadeyle süzer
// (public/api/notification_mark_one_read.php). Kural iki yere kopyalanırsa
// biri değişince diğeri sessizce eski davranışta kalırdı — nitekim rol süzgeci
// eklenmeden önceki hâlde ikisi de aynı düz listeyi ayrı ayrı kuruyordu.
//
// ⚠️ ROL EKİP BAŞINA DEĞİŞİR: aynı kullanıcı A ekibinde owner, B ekibinde viewer
// olabilir. Bu yüzden tek bir "team_id IN (...) AND action IN (...)" YETMEZ —
// her ekip kendi rolünün action kümesiyle ayrı bir OR grubu olur.
//
// Dönüş: array('sql' => '(...)', 'params' => array(...)) veya görünür hiçbir
// şey yoksa null.
function bcc_notification_scope_clause()
{
    $teamRoles = current_user_team_roles();
    if (empty($teamRoles)) {
        return null;
    }

    $groups = array();
    $params = array();

    foreach ($teamRoles as $teamId => $role) {
        $actions = bcc_notification_actions_for_role($role);
        if (empty($actions)) {
            continue;
        }

        $actionPlaceholders = implode(',', array_fill(0, count($actions), '?'));
        $groups[] = "(al.team_id = ? AND al.action IN ($actionPlaceholders))";
        $params[] = (int) $teamId;
        foreach ($actions as $action) {
            $params[] = $action;
        }
    }

    if (empty($groups)) {
        return null;
    }

    return array('sql' => '(' . implode(' OR ', $groups) . ')', 'params' => $params);
}

// current_user_team_roles() (src/auth.php) ile AYNI kaynaktan — ikinci bir
// "kullanıcının takımları" sorgusu YAZILMADI.
function bcc_fetch_notifications($limit = 30)
{
    $scope = bcc_notification_scope_clause();
    if ($scope === null) {
        return array();
    }

    $sql = "SELECT al.id, al.action, al.entity_type, al.entity_id, al.details, al.created_at, u.full_name AS actor_name
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE {$scope['sql']}
            ORDER BY al.created_at DESC
            LIMIT " . (int) $limit;

    return bcc_fetch_all($sql, $scope['params']);
}

// TEK TEK "okundu" işaretlenmiş bildirimlerin id kümesi (migrations/021).
// Dönüş: audit_log.id => true haritası — panel "bu satır okundu mu?" sorusunu
// O(1) sorar, bildirim başına ayrı sorgu AÇILMAZ.
//
// ⚠️ SORGU BİLDİRİM LİSTESİYLE SINIRLI: kullanıcının tüm okundu geçmişi
// (zamanla binlerce satır) çekilmez, yalnızca ekranda basılacak id'ler
// sorulur. $auditIds boşsa hiç sorgu açılmaz.
function bcc_read_notification_ids($auditIds)
{
    $user = current_user();
    if ($user === null || empty($auditIds)) {
        return array();
    }

    $auditIds = array_map('intval', $auditIds);
    $placeholders = implode(',', array_fill(0, count($auditIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT audit_log_id FROM user_read_notifications
         WHERE user_id = ? AND audit_log_id IN ($placeholders)",
        array_merge(array((int) $user['id']), $auditIds)
    );

    $map = array();
    foreach ($rows as $r) {
        $map[(int) $r['audit_log_id']] = true;
    }

    return $map;
}

// Her action için okunabilir tek cümle — details JSON'undaki alanlar action'a
// göre değişir (bkz. log_audit() çağrı noktaları), bu yüzden switch/case.
function bcc_notification_message($row)
{
    $actor = ($row['actor_name'] !== null && $row['actor_name'] !== '') ? $row['actor_name'] : 'Bir kullanıcı';

    $details = array();
    if ($row['details'] !== null) {
        $decoded = json_decode($row['details'], true);
        if (is_array($decoded)) {
            $details = $decoded;
        }
    }

    switch ($row['action']) {
        case 'record.create':
            return $actor . ' yeni bir kayıt ekledi.';
        case 'view.rename':
            $name = isset($details['name']) ? (string) $details['name'] : '';
            return $actor . ' bir görünümü yeniden adlandırdı' . ($name !== '' ? ': "' . $name . '"' : '') . '.';
        case 'slack.notify_sent':
            return $actor . '\'in eklediği kayıt için Slack bildirimi gönderildi.';
        case 'slack.notify_failed':
            return $actor . '\'in eklediği kayıt için Slack bildirimi gönderilemedi.';
        case 'team_member.assign':
            return $actor . ' ekibe yeni bir üye ekledi.';
        case 'team_member.role_change':
            return $actor . ' bir ekip üyesinin rolünü güncelledi.';
        default:
            return $actor . ' bir işlem yaptı.';
    }
}
