<?php
// Slack otomasyonu ayar sayfası. Üç bağımsız bölüm yönetilir:
// - Tablo-özel webhook'lar (table_id = bu tablo) — ARTIK BİR LİSTE: aynı
//   tabloda birden fazla webhook olabilir (marka/operasyon başına bir tane,
//   ör. "Trendyol kanalı", "Yves Rocher kanalı") — koşullu yönlendirme
//   kuralları bunlardan birine işaret eder.
// - Takım-geneli webhook (table_id NULL, team_id = bu takım) — takımın TÜM
//   tablolarında tetiklenir, tek satır (OpsFlow'daki tek "Otherwise" yedek
//   kanalı gibi) — DEĞİŞMEDİ.
// - Koşullu yönlendirme kuralları (slack_routing_rules) — bir alanın (yalnızca
//   tekli seçim tipi) DEĞERİNE göre hangi webhook'a gidileceğini belirler.
//   İlk eşleşen kural (sıraya göre) kazanır — bkz. bcc_find_slack_webhook()
//   (src/slack.php). Hiç kural yoksa/eşleşmezse ESKİ tablo-özel/ekip-geneli
//   davranışa aynen düşülür.
// GÜVENLİK: webhook_url kaydedildikten sonra HİÇBİR ZAMAN tam olarak geri
// gösterilmez (yalnızca son 4 karakter) — form her zaman boş başlar, dolu
// bırakılırsa "değiştirme" anlamına gelir, boş bırakılırsa mevcut URL korunur.

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = bcc_can_manage_schema($role);  // entegrasyon ayarı — src/auth.php

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    require_role($table['team_id'], 'owner');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save_webhook') {
        $scope = (isset($_POST['scope']) && $_POST['scope'] === 'team') ? 'team' : 'table';
        $webhookIdRaw = isset($_POST['webhook_id']) ? (int) $_POST['webhook_id'] : 0;
        $webhookUrlRaw = isset($_POST['webhook_url']) ? trim($_POST['webhook_url']) : '';
        $channelNameRaw = isset($_POST['channel_name']) ? trim($_POST['channel_name']) : '';
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        // Var olan satırı düzenliyorsak, gerçekten bu tabloya/ekibe ait mi doğrula
        // (base_tables.php'deki "bu tablo bu base'e ait değil" ile AYNI desen).
        $existing = null;
        if ($webhookIdRaw > 0) {
            $existing = ($scope === 'team')
                ? bcc_fetch_one('SELECT id, webhook_url FROM slack_webhooks WHERE id = :id AND team_id = :team_id AND table_id IS NULL LIMIT 1', array('id' => $webhookIdRaw, 'team_id' => $table['team_id']))
                : bcc_fetch_one('SELECT id, webhook_url FROM slack_webhooks WHERE id = :id AND table_id = :table_id LIMIT 1', array('id' => $webhookIdRaw, 'table_id' => $table['id']));
            $existing = $existing !== false ? $existing : null;

            if (!$existing) {
                http_response_code(403);
                die('Bu webhook bu tabloya/ekibe ait değil.');
            }
        } elseif ($scope === 'team') {
            // Bulunan gerçek bug: webhook_id boş/0 gönderilirse (form state'i bayatlamış
            // ya da elle POST edilmiş — normal UI akışı zaten var olan satırın id'sini
            // her zaman gönderir) ve bu ekip için ZATEN bir takım-geneli webhook
            // (table_id IS NULL) varsa, aşağıdaki "yeni ekle" dalı DB'de bunu engelleyen
            // bir UNIQUE kısıt olmadığı için ikinci bir takım-geneli satır oluşturuyordu
            // (canlı test ile doğrulandı). Sonuç: hangisinin "asıl" olduğu belirsizleşir
            // — ayarlar sayfası LIMIT 1 ile arbitrer birini gösterir, bildirim gönderen
            // bcc_find_slack_webhook() farklı birini seçebilir. "Ekip-geneli için her
            // zaman tek satır" invariant'ı burada da (yalnızca UI'da değil) uygulanır:
            // var olan satır varsa INSERT yerine ONA güncelleme uygulanır.
            $existing = bcc_fetch_one('SELECT id, webhook_url FROM slack_webhooks WHERE team_id = :team_id AND table_id IS NULL LIMIT 1', array('team_id' => $table['team_id']));
            $existing = $existing !== false ? $existing : null;
        }

        if ($webhookUrlRaw === '' && !$existing) {
            $error = 'Webhook URL gerekli.';
        } elseif ($webhookUrlRaw !== '' && strpos($webhookUrlRaw, 'https://hooks.slack.com/') !== 0) {
            $error = 'Geçersiz webhook URL — https://hooks.slack.com/ ile başlamalı.';
        } elseif (mb_strlen($channelNameRaw, 'UTF-8') > 150) {
            $error = 'Kanal adı en fazla 150 karakter olabilir.';
        } else {
            $channelName = $channelNameRaw !== '' ? $channelNameRaw : null;

            if ($existing) {
                // Boş bırakılan webhook_url = "mevcut URL'i koru" (değiştirme).
                $urlToSave = $webhookUrlRaw !== '' ? $webhookUrlRaw : $existing['webhook_url'];

                bcc_execute(
                    'UPDATE slack_webhooks SET webhook_url = :url, channel_name = :channel, is_active = :active WHERE id = :id',
                    array('url' => $urlToSave, 'channel' => $channelName, 'active' => $isActive, 'id' => $existing['id'])
                );
                log_audit('slack.webhook_update', 'table', $table['id'], array('scope' => $scope, 'channel_name' => $channelName, 'is_active' => $isActive), $table['team_id']);
                $success = 'Webhook güncellendi.';
            } else {
                // Tablo-özel kapsamda HER ZAMAN yeni satır (bu tabloda birden fazla
                // webhook olabilir); ekip-geneli kapsamda tek satır kuralı (uygulama
                // katmanında — bu form zaten yalnızca hiç yokken "yeni ekle" gösterir).
                bcc_execute(
                    'INSERT INTO slack_webhooks (team_id, table_id, webhook_url, channel_name, is_active) VALUES (:team_id, :table_id, :url, :channel, :active)',
                    array(
                        'team_id' => $table['team_id'],
                        'table_id' => $scope === 'team' ? null : $table['id'],
                        'url' => $webhookUrlRaw,
                        'channel' => $channelName,
                        'active' => $isActive,
                    )
                );
                log_audit('slack.webhook_create', 'table', $table['id'], array('scope' => $scope, 'channel_name' => $channelName), $table['team_id']);
                $success = 'Webhook kaydedildi.';
            }
        }
    } elseif ($action === 'test_webhook') {
        // "Bağlantıyı test et" — kayıtlı bir webhook satırına deneme mesajı
        // gönderir ve SONUCU kullanıcıya bildirir. Kaydetme akışının bir parçası
        // DEĞİL, ayrı bir aksiyon: kaydetmenin kendisi otomatik mesaj atsaydı
        // her küçük düzenleme (ör. yalnızca kanal adını değiştirmek) kanala
        // gürültü basardı. Yetki: yukarıdaki require_role('owner') bu POST'un
        // TAMAMINI zaten kapsıyor.
        $webhookIdRaw = isset($_POST['webhook_id']) ? (int) $_POST['webhook_id'] : 0;

        $testResult = bcc_slack_send_test($webhookIdRaw, $table['team_id'], $user['full_name']);

        if ($testResult['ok']) {
            $success = 'Test mesajı gönderildi — Slack kanalınızı kontrol edin ("Slack Integration Connected Successfully").';
        } else {
            $error = $testResult['error'];
        }
    } elseif ($action === 'delete_webhook') {
        $webhookIdRaw = isset($_POST['webhook_id']) ? (int) $_POST['webhook_id'] : 0;

        $webhook = bcc_fetch_one('SELECT id FROM slack_webhooks WHERE id = :id AND team_id = :team_id LIMIT 1', array('id' => $webhookIdRaw, 'team_id' => $table['team_id']));

        if (!$webhook) {
            http_response_code(403);
            die('Bu webhook bu ekibe ait değil.');
        }

        // Bu webhook'a bağlı bir yönlendirme kuralı varsa silme ENGELLENİR (sessiz
        // cascade yerine açık hata) — bir kuralın hedefi aniden kaybolmasın.
        $ruleCount = (int) bcc_fetch_column('SELECT COUNT(*) AS c FROM slack_routing_rules WHERE webhook_id = :id', array('id' => $webhookIdRaw));

        if ($ruleCount > 0) {
            $error = 'Bu webhook ' . $ruleCount . ' yönlendirme kuralında kullanılıyor. Önce o kuralları silin ya da başka bir webhook\'a taşıyın.';
        } else {
            bcc_execute('DELETE FROM slack_webhooks WHERE id = :id', array('id' => $webhookIdRaw));
            log_audit('slack.webhook_delete', 'table', $table['id'], array('webhook_id' => $webhookIdRaw), $table['team_id']);
            $success = 'Webhook silindi.';
        }
    } elseif ($action === 'add_routing_rule') {
        $fieldIdRaw = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
        $operatorRaw = isset($_POST['operator']) ? $_POST['operator'] : '';
        $valueRaw = isset($_POST['value']) ? trim($_POST['value']) : '';
        $webhookIdRaw = isset($_POST['webhook_id']) ? (int) $_POST['webhook_id'] : 0;

        $field = bcc_fetch_one('SELECT id, options FROM fields WHERE id = :id AND table_id = :table_id AND field_type = \'single_select\' LIMIT 1', array('id' => $fieldIdRaw, 'table_id' => $table['id']));

        // Webhook bu ekibe ait olmalı (tablo-özel VEYA ekip-geneli — ikisi de aynı team_id'ye bağlı).
        $webhook = bcc_fetch_one('SELECT id FROM slack_webhooks WHERE id = :id AND team_id = :team_id LIMIT 1', array('id' => $webhookIdRaw, 'team_id' => $table['team_id']));

        if (!$field || !$webhook) {
            http_response_code(403);
            die('Bu alan ya da webhook bu tabloya/ekibe ait değil.');
        }

        $choices = select_choices_from_options($field['options']);

        if (!isset($GLOBALS['BCC_SLACK_ROUTING_OPERATORS'][$operatorRaw])) {
            $error = 'Geçersiz operatör.';
        } elseif (!in_array($valueRaw, $choices, true)) {
            $error = 'Geçersiz değer — alanın seçeneklerinden biri olmalı.';
        } else {
            $nextPos = (int) bcc_fetch_column('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM slack_routing_rules WHERE table_id = :table_id', array('table_id' => $table['id']));

            bcc_execute(
                'INSERT INTO slack_routing_rules (team_id, table_id, field_id, operator, value, webhook_id, position) VALUES (:team_id, :table_id, :field_id, :operator, :value, :webhook_id, :position)',
                array(
                    'team_id' => $table['team_id'],
                    'table_id' => $table['id'],
                    'field_id' => $fieldIdRaw,
                    'operator' => $operatorRaw,
                    'value' => $valueRaw,
                    'webhook_id' => $webhookIdRaw,
                    'position' => $nextPos,
                )
            );
            log_audit('slack.routing_rule_create', 'table', $table['id'], array('field_id' => $fieldIdRaw, 'operator' => $operatorRaw, 'value' => $valueRaw), $table['team_id']);
            $success = 'Yönlendirme kuralı eklendi.';
        }
    } elseif ($action === 'delete_routing_rule' || $action === 'move_routing_rule' || $action === 'toggle_routing_rule') {
        $ruleIdRaw = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;

        $rule = bcc_fetch_one('SELECT id, is_active FROM slack_routing_rules WHERE id = :id AND table_id = :table_id LIMIT 1', array('id' => $ruleIdRaw, 'table_id' => $table['id']));

        if (!$rule) {
            http_response_code(403);
            die('Bu kural bu tabloya ait değil.');
        }

        if ($action === 'delete_routing_rule') {
            bcc_execute('DELETE FROM slack_routing_rules WHERE id = :id', array('id' => $rule['id']));
            log_audit('slack.routing_rule_delete', 'table', $table['id'], array('rule_id' => $rule['id']), $table['team_id']);
            $success = 'Yönlendirme kuralı silindi.';
        } elseif ($action === 'toggle_routing_rule') {
            $newActive = ((int) $rule['is_active'] === 1) ? 0 : 1;
            bcc_execute('UPDATE slack_routing_rules SET is_active = :active WHERE id = :id', array('active' => $newActive, 'id' => $rule['id']));
            log_audit('slack.routing_rule_toggle', 'table', $table['id'], array('rule_id' => $rule['id'], 'is_active' => $newActive), $table['team_id']);
        } else {
            $direction = isset($_POST['direction']) ? $_POST['direction'] : '';

            // İKİ UPDATE + log_audit TEK transaction'da — bcc_reorder_sibling()
            // artık transaction'ı ÇAĞIRANDAN bekliyor (bkz. o fonksiyonun
            // sözleşmesi; iç içe transaction mysqli'de desteklenmiyor).
            try {
                bcc_begin_transaction();

                $moved = bcc_reorder_sibling('slack_routing_rules', 'table_id', $table['id'], $rule['id'], $direction);

                if ($moved) {
                    log_audit('slack.routing_rule_reorder', 'table', $table['id'], array('rule_id' => $rule['id'], 'direction' => $direction), $table['team_id']);
                }

                bcc_commit();
            } catch (Throwable $e) {
                bcc_rollback();
                $error = 'Kural taşınamadı (veritabanı hatası).';
            }
        }
    }
}

// Tablo-özel: artık LİSTE — bu tabloya ait tüm webhook satırları (marka
// başına bir tane olabilir), en eski önce.
$tableWebhooks = bcc_fetch_all(
    'SELECT id, channel_name, is_active, webhook_url FROM slack_webhooks WHERE table_id = :table_id ORDER BY id',
    array('table_id' => $table['id'])
);

// Ekip-geneli: hâlâ tek satır (DEĞİŞMEDİ).
$teamWebhook = bcc_fetch_one('SELECT id, channel_name, is_active, webhook_url FROM slack_webhooks WHERE team_id = :team_id AND table_id IS NULL LIMIT 1', array('team_id' => $table['team_id']));
$teamWebhook = $teamWebhook !== false ? $teamWebhook : null;

// Düzenlenen tablo-özel webhook (varsa) — table_fields.php'deki "?edit=" deseniyle AYNI.
$editWebhookId = isset($_GET['edit_webhook']) ? (int) $_GET['edit_webhook'] : 0;
$editWebhook = null;
foreach ($tableWebhooks as $w) {
    if ((int) $w['id'] === $editWebhookId) {
        $editWebhook = $w;
        break;
    }
}

// Yönlendirme kuralı formunun "hedef webhook" seçeneği: bu tabloya özel VEYA
// ekip-geneli — hangisi olursa olsun aynı ekibe ait, ikisi de geçerli hedef.
$availableWebhooksForRules = $tableWebhooks;
if ($teamWebhook) {
    $availableWebhooksForRules[] = $teamWebhook;
}

// Yönlendirme kuralı formunun "alan seç" seçeneği: yalnızca bu tablonun tekli
// seçim alanları (koşul yalnızca ayrık değerlerde anlamlı).
$fields = bcc_fetch_all('SELECT id, name, field_type, options FROM fields WHERE table_id = :table_id ORDER BY position, id', array('table_id' => $table['id']));
$singleSelectFields = array();
foreach ($fields as $f) {
    if ($f['field_type'] === 'single_select') {
        $singleSelectFields[] = $f;
    }
}

$routingRules = bcc_fetch_all(
    'SELECT rr.id, rr.field_id, rr.operator, rr.value, rr.webhook_id, rr.is_active,
            f.name AS field_name, sw.channel_name AS webhook_channel_name, sw.webhook_url AS webhook_url
     FROM slack_routing_rules rr
     INNER JOIN fields f ON f.id = rr.field_id
     INNER JOIN slack_webhooks sw ON sw.id = rr.webhook_id
     WHERE rr.table_id = :table_id
     ORDER BY rr.position, rr.id',
    array('table_id' => $table['id'])
);

function bcc_slack_masked_url($webhook)
{
    return $webhook ? ('••••••••' . substr($webhook['webhook_url'], -4)) : null;
}

// Sol panelin "Yıldızlılar" listesi ARTIK BURADA ÇEKİLMİYOR: kabuk
// (src/partials/home_shell_top.php) bcc_starred_bases_for_current_user()'ı
// kendisi çağırıyor — bkz. src/schema.php'deki tek kaynak notu.

$homeActiveNav = 'fields';
$homePageTitle = bcc_brand_domain() . ' — ' . $table['name'] . ' — Slack';
// Ortak tasarım sistemi (table_fields / base_tables / account ile PAYLAŞILAN) +
// yalnızca bu sayfaya ait yerleşim. Durum hapı, toggle, bilgi kutusu ve
// maskeli-sır rozeti ORTAK dosyada (settings-page.css) — burada kopyası yok.
$homeExtraCss = array('settings-page.css', 'slack-settings.css');
require __DIR__ . '/../src/partials/home_shell_top.php';

// Küçük yardımcı: "aktif"/"pasif" durum hapı. Üç yerde (tablo webhook'ları,
// takım webhook'u, yönlendirme kuralları) kullanılıyor — HTML üç kez yazılmıyor.
function bcc_slack_status_pill($isActive)
{
    $on = ((int) $isActive === 1);
    ?><span class="sp-status <?php echo $on ? 'sp-status-on' : ''; ?>"><?php echo $on ? 'aktif' : 'pasif'; ?></span><?php
}

// Bir webhook için tam form (yeni ekleme VEYA düzenleme — $webhook null ise yeni).
// Tablo-özel VE ekip-geneli kapsam AYNI bu fonksiyonu kullanır, HTML iki kez yazılmaz.
function bcc_render_slack_webhook_form($scope, $webhook, $table, $submitLabel)
{
    $masked = bcc_slack_masked_url($webhook);
    ?>
    <form class="settings-form sl-webhook-form" method="post" action="/slack_settings.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_webhook">
        <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
        <input type="hidden" name="webhook_id" value="<?php echo $webhook ? (int) $webhook['id'] : ''; ?>">
        <label class="settings-field">Webhook URL
            <?php if ($webhook): ?>
                <span class="sl-current-url">Mevcut: <span class="sp-code"><?php echo htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?></span> — boş bırakırsanız korunur.</span>
            <?php endif; ?>
            <input type="url" name="webhook_url" placeholder="https://hooks.slack.com/services/...">
        </label>
        <label class="settings-field">Kanal adı <span class="sl-current-url">opsiyonel, yalnızca gösterim</span>
            <input type="text" name="channel_name" value="<?php echo $webhook ? htmlspecialchars((string) $webhook['channel_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="#trendyol-siparis">
        </label>
        <div class="sl-form-footer">
            <?php // Ham checkbox yerine tasarım sisteminin toggle'ı. name/value
                  // AYNEN korundu (is_active=1) — sunucu tarafı değişmedi. ?>
            <label class="sp-toggle">
                <input type="checkbox" name="is_active" value="1" <?php echo (!$webhook || (int) $webhook['is_active'] === 1) ? 'checked' : ''; ?>>
                <span class="sp-toggle-track"></span>
                <span>Aktif</span>
            </label>
            <button type="submit" class="settings-btn settings-btn-primary"><?php echo htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
    </form>
    <?php
}
?>
<div class="sp-page">
        <div class="settings-breadcrumb">
            <a href="/base_tables.php?base_id=<?php echo (int) $table['base_id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?> tabloları
            </a>
            <span>·</span>
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M3 8h14M8 8v8" stroke="currentColor" stroke-width="1.4"/></svg>
                Grid'i görüntüle
            </a>
            <span>·</span>
            <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M4 10h12M4 14h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                Alanları yönet
            </a>
        </div>
        <div class="home-main-header">
            <h1><?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?> — Slack bildirimleri</h1>
            <p class="settings-hint">Bu tabloya yeni kayıt eklendiğinde hangi Slack kanalına bildirim gideceğini yönetin.</p>
        </div>

        <?php require __DIR__ . '/../src/partials/flash.php'; ?>

        <?php if (!$canEdit): ?>
            <div class="sp-note">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.5" r="0.9" fill="currentColor"/></svg>
                <span>Slack bildirimlerini ayarlamak için <strong>owner</strong> rolü gerekir. Bu sayfayı salt-okunur görüyorsunuz.</span>
            </div>
        <?php endif; ?>

        <div class="settings-card">
            <h2>Bu tabloya özel webhook'lar <span class="sp-count"><?php echo count($tableWebhooks); ?></span></h2>
            <div class="sp-note">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.5" r="0.9" fill="currentColor"/></svg>
                <span>Yalnızca <strong>&ldquo;<?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?>&rdquo;</strong> tablosuna yeni kayıt eklendiğinde tetiklenir. Aynı tabloda birden fazla webhook olabilir (ör. marka başına bir kanal) — hangisinin kullanılacağı aşağıdaki <em>Koşullu yönlendirme kuralları</em> ile belirlenir; hiç kural yoksa listedeki <strong>ilk aktif</strong> webhook kullanılır.</span>
            </div>

            <?php if (empty($tableWebhooks)): ?>
                <p class="settings-empty">
                    <strong>Bu tabloya özel webhook yok.</strong>
                    <span class="sp-muted">Aşağıdan bir Slack webhook URL'i ekleyin.</span>
                </p>
            <?php else: ?>
                <div class="settings-table-wrap">
                    <table class="settings-table">
                        <thead><tr><th>Kanal</th><th>Webhook</th><th>Durum</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($tableWebhooks as $w): ?>
                            <tr>
                                <td class="sl-channel <?php echo ((string) $w['channel_name'] === '') ? 'sl-channel-empty' : ''; ?>"><?php echo htmlspecialchars((string) $w['channel_name'] ?: 'kanal adı belirtilmemiş', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="sp-code"><?php echo htmlspecialchars(bcc_slack_masked_url($w), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php bcc_slack_status_pill($w['is_active']); ?></td>
                                <?php if ($canEdit): ?>
                                <?php // Aksiyonlar: dolu zeminli metin butonları yerine ortak
                                      // .sp-icon-btn hayalet ikon butonları. POST/CSRF formları
                                      // AYNEN korundu — yalnızca görünüm + aria-label değişti. ?>
                                <td class="settings-row-actions">
                                    <?php // "Test et" — kaydedilmiş URL'e gerçek bir deneme
                                          // mesajı atar ("Slack Integration Connected
                                          // Successfully"). Kaydetme akışından AYRI: kaydetmek
                                          // otomatik mesaj atsaydı her küçük düzenleme kanala
                                          // gürültü basardı. ?>
                                    <form method="post" action="/slack_settings.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="test_webhook">
                                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                        <input type="hidden" name="webhook_id" value="<?php echo (int) $w['id']; ?>">
                                        <button type="submit" class="sp-icon-btn" title="Test mesajı gönder" aria-label="Bu webhook'a test mesajı gönder">
                                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M17 3L9 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M17 3l-5.5 14-3-6-6-3L17 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                                        </button>
                                    </form>
                                    <a class="sp-icon-btn" title="Düzenle" aria-label="Webhook'u düzenle" href="/slack_settings.php?table_id=<?php echo (int) $table['id']; ?>&edit_webhook=<?php echo (int) $w['id']; ?>">
                                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13.2 3.8l3 3L7.5 15.5l-3.7.7.7-3.7 8.7-8.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                                    </a>
                                    <form method="post" action="/slack_settings.php" onsubmit="return confirm('Bu webhook\'u silmek istediğinize emin misiniz?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_webhook">
                                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                        <input type="hidden" name="webhook_id" value="<?php echo (int) $w['id']; ?>">
                                        <button type="submit" class="sp-icon-btn sp-icon-btn--danger" title="Sil" aria-label="Webhook'u sil">
                                            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($canEdit && $editWebhook): ?>
                <h3 class="sl-subhead">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13.2 3.8l3 3L7.5 15.5l-3.7.7.7-3.7 8.7-8.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                    Webhook'u düzenle
                </h3>
                <?php bcc_render_slack_webhook_form('table', $editWebhook, $table, 'Kaydet'); ?>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <h3 class="sl-subhead">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    Yeni webhook ekle
                </h3>
                <?php bcc_render_slack_webhook_form('table', null, $table, 'Ekle'); ?>
            <?php endif; ?>
        </div>

        <div class="settings-card">
            <h2>Takım-geneli webhook</h2>
            <div class="sp-note">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.5" r="0.9" fill="currentColor"/></svg>
                <span>Yedek kanal: bu takımın <strong>tüm</strong> tablolarında (bu tablo dahil), tablo-özel bir webhook veya kural eşleşmemişse tetiklenir.</span>
            </div>

            <?php if (!$canEdit): ?>
                <?php if ($teamWebhook): ?>
                    <div class="sl-readonly-row">
                        <span class="sl-channel <?php echo ((string) $teamWebhook['channel_name'] === '') ? 'sl-channel-empty' : ''; ?>"><?php echo htmlspecialchars((string) $teamWebhook['channel_name'] ?: 'kanal adı belirtilmemiş', ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php bcc_slack_status_pill($teamWebhook['is_active']); ?>
                    </div>
                <?php else: ?>
                    <p class="settings-empty"><strong>Ayarlanmamış.</strong></p>
                <?php endif; ?>
            <?php else: ?>
                <?php bcc_render_slack_webhook_form('team', $teamWebhook, $table, 'Kaydet'); ?>
                <?php if ($teamWebhook): ?>
                    <h3 class="sl-subhead">Mevcut takım webhook'u</h3>
                    <div class="sl-readonly-row">
                        <span class="sl-channel <?php echo ((string) $teamWebhook['channel_name'] === '') ? 'sl-channel-empty' : ''; ?>"><?php echo htmlspecialchars((string) $teamWebhook['channel_name'] ?: 'kanal adı belirtilmemiş', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="sp-code"><?php echo htmlspecialchars(bcc_slack_masked_url($teamWebhook), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php bcc_slack_status_pill($teamWebhook['is_active']); ?>
                        <?php // Tablo-özel listedeki AYNI test aksiyonu (bkz. yukarısı) —
                              // takım-geneli satır için de. margin-left:auto burada, iki
                              // butonu birlikte sağa itsin diye. ?>
                        <form method="post" action="/slack_settings.php" style="margin-left:auto;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="test_webhook">
                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                            <input type="hidden" name="webhook_id" value="<?php echo (int) $teamWebhook['id']; ?>">
                            <button type="submit" class="sp-icon-btn" title="Test mesajı gönder" aria-label="Takım webhook'una test mesajı gönder">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M17 3L9 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M17 3l-5.5 14-3-6-6-3L17 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                            </button>
                        </form>
                        <form method="post" action="/slack_settings.php" onsubmit="return confirm('Bu webhook\'u silmek istediğinize emin misiniz?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_webhook">
                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                            <input type="hidden" name="webhook_id" value="<?php echo (int) $teamWebhook['id']; ?>">
                            <button type="submit" class="sp-icon-btn sp-icon-btn--danger" title="Webhook'u sil" aria-label="Takım webhook'unu sil">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="settings-card">
            <h2>Koşullu yönlendirme kuralları <span class="sp-count"><?php echo count($routingRules); ?></span></h2>
            <div class="sp-note">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 5h5l3 5 4 0M4 15h5l1.5-2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 3l2.5 2-2.5 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Bir <strong>tekli seçim</strong> alanının değerine göre farklı bir webhook'a yönlendirir — ör. &ldquo;Marka&rdquo; alanı &ldquo;Trendyol&rdquo; ise Trendyol kanalına. Sıradaki <strong>ilk eşleşen</strong> kural kazanır; hiçbiri eşleşmezse yukarıdaki tablo-özel / takım-geneli webhook kullanılır.</span>
            </div>

            <?php if (empty($singleSelectFields)): ?>
                <p class="settings-empty">
                    <strong>Bu tabloda tekli seçim alanı yok.</strong>
                    <span class="sp-muted">Koşullu yönlendirme için önce <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">bir tekli seçim alanı ekleyin</a>.</span>
                </p>
            <?php elseif (empty($availableWebhooksForRules)): ?>
                <p class="settings-empty">
                    <strong>Henüz webhook yok.</strong>
                    <span class="sp-muted">Kural kurabilmek için önce yukarıda en az bir webhook oluşturun.</span>
                </p>
            <?php else: ?>
                <?php if (empty($routingRules)): ?>
                    <p class="settings-empty">
                        <strong>Henüz kural yok.</strong>
                        <span class="sp-muted">Aşağıdaki satırdan ilk kuralınızı ekleyin.</span>
                    </p>
                <?php else: ?>
                    <div class="settings-table-wrap">
                        <table class="settings-table">
                            <thead><tr><th>Alan</th><th>Koşul</th><th>Değer</th><th>Hedef webhook</th><th>Durum</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($routingRules as $i => $r): ?>
                                <tr>
                                    <td class="sl-channel"><?php echo htmlspecialchars($r['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="sl-operator"><?php echo htmlspecialchars($GLOBALS['BCC_SLACK_ROUTING_OPERATORS'][$r['operator']], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="sl-value"><?php echo htmlspecialchars($r['value'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php
                                        // Kanal adı varsa düz metin; yoksa maskeli URL kod rozetiyle.
                                        if ((string) $r['webhook_channel_name'] !== '') {
                                            echo '<span class="sl-channel">' . htmlspecialchars((string) $r['webhook_channel_name'], ENT_QUOTES, 'UTF-8') . '</span>';
                                        } else {
                                            echo '<span class="sp-code">' . htmlspecialchars(bcc_slack_masked_url(array('webhook_url' => $r['webhook_url'])), ENT_QUOTES, 'UTF-8') . '</span>';
                                        }
                                    ?></td>
                                    <td><?php bcc_slack_status_pill($r['is_active']); ?></td>
                                    <?php if ($canEdit): ?>
                                    <td class="settings-row-actions">
                                        <span class="sp-move-group">
                                            <form method="post" action="/slack_settings.php">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="move_routing_rule">
                                                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                                <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="sp-icon-btn" title="Yukarı taşı" aria-label="Kuralı yukarı taşı" <?php echo $i === 0 ? 'disabled' : ''; ?>>
                                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 15V5m0 0l-4 4m4-4l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </button>
                                            </form>
                                            <form method="post" action="/slack_settings.php">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="move_routing_rule">
                                                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                                <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="sp-icon-btn" title="Aşağı taşı" aria-label="Kuralı aşağı taşı" <?php echo $i === count($routingRules) - 1 ? 'disabled' : ''; ?>>
                                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 5v10m0 0l4-4m-4 4l-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </button>
                                            </form>
                                        </span>
                                        <?php $ruleOn = ((int) $r['is_active'] === 1); ?>
                                        <form method="post" action="/slack_settings.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_routing_rule">
                                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                            <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                            <button type="submit" class="sp-icon-btn" title="<?php echo $ruleOn ? 'Pasifleştir' : 'Aktifleştir'; ?>" aria-label="<?php echo $ruleOn ? 'Kuralı pasifleştir' : 'Kuralı aktifleştir'; ?>">
                                                <?php if ($ruleOn): ?>
                                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M8 7.5v5M12 7.5v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                                <?php else: ?>
                                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M8.5 7l4.5 3-4.5 3V7z" fill="currentColor"/></svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                        <form method="post" action="/slack_settings.php" onsubmit="return confirm('Bu kuralı silmek istediğinize emin misiniz?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_routing_rule">
                                            <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                            <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                            <button type="submit" class="sp-icon-btn sp-icon-btn--danger" title="Sil" aria-label="Kuralı sil">
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <h3 class="sl-subhead">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                        Yeni kural ekle
                    </h3>
                    <?php // Dört alan artık ALT ALTA değil TEK SATIRDA (sl-rule-form,
                          // yatay grid) — `settings-form-stacked` kaldırıldı. Dar
                          // ekranda önce iki, sonra tek sütuna iner.
                          // slack-routing.js'in bağlı olduğu id'ler (#routing-rule-field,
                          // #routing-rule-value) AYNEN korundu. ?>
                    <form class="settings-form sl-rule-form" method="post" action="/slack_settings.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_routing_rule">
                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                        <label class="settings-field">Alan
                            <select name="field_id" id="routing-rule-field" required>
                                <?php foreach ($singleSelectFields as $f): ?>
                                    <option value="<?php echo (int) $f['id']; ?>" data-choices="<?php echo htmlspecialchars(json_encode(select_choices_from_options($f['options']), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="settings-field">Koşul
                            <select name="operator">
                                <?php foreach ($GLOBALS['BCC_SLACK_ROUTING_OPERATORS'] as $opKey => $opLabel): ?>
                                    <option value="<?php echo htmlspecialchars($opKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="settings-field">Değer
                            <select name="value" id="routing-rule-value" required></select>
                        </label>
                        <label class="settings-field">Hedef webhook
                            <select name="webhook_id" required>
                                <?php foreach ($availableWebhooksForRules as $w): ?>
                                    <option value="<?php echo (int) $w['id']; ?>">
                                        <?php echo htmlspecialchars((string) $w['channel_name'] ?: bcc_slack_masked_url($w), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="sl-rule-submit">
                            <button type="submit" class="settings-btn settings-btn-primary">Kural Ekle</button>
                        </div>
                    </form>
                    <script src="<?php echo bcc_asset_url('slack-routing.js'); ?>" defer></script>
                <?php endif; ?>
            <?php endif; ?>
        </div>
</div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
