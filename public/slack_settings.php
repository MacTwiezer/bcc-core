<?php
// Slack otomasyonu ayar sayfası. Üç bağımsız bölüm yönetilir:
// - Tablo-özel webhook'lar (table_id = bu tablo) — ARTIK BİR LİSTE: aynı
//   tabloda birden fazla webhook olabilir (marka/operasyon başına bir tane,
//   ör. "Trendyol kanalı", "Yves Rocher kanalı") — koşullu yönlendirme
//   kuralları bunlardan birine işaret eder.
// - Takım-geneli webhook (table_id NULL, team_id = bu takım) — takımın TÜM
//   tablolarında tetiklenir, tek satır (Airtable'daki tek "Otherwise" yedek
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

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = in_array($role, array('editor', 'owner'), true);

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    require_role($table['team_id'], 'editor');

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
            $moved = bcc_reorder_sibling('slack_routing_rules', 'table_id', $table['id'], $rule['id'], $direction);

            if ($moved) {
                log_audit('slack.routing_rule_reorder', 'table', $table['id'], array('rule_id' => $rule['id'], 'direction' => $direction), $table['team_id']);
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

$pageTitle = $table['name'] . ' — Slack';
require __DIR__ . '/../src/partials/header.php';
require __DIR__ . '/../src/partials/top_nav.php';

// Bir webhook için tam form (yeni ekleme VEYA düzenleme — $webhook null ise yeni).
// Tablo-özel VE ekip-geneli kapsam AYNI bu fonksiyonu kullanır, HTML iki kez yazılmaz.
function bcc_render_slack_webhook_form($scope, $webhook, $table, $submitLabel)
{
    $masked = bcc_slack_masked_url($webhook);
    ?>
    <form class="stacked" method="post" action="/slack_settings.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_webhook">
        <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
        <input type="hidden" name="webhook_id" value="<?php echo $webhook ? (int) $webhook['id'] : ''; ?>">
        <label>Webhook URL<?php if ($webhook): ?> (mevcut: <?php echo htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?> — değiştirmek için yeni URL girin, boş bırakırsanız korunur)<?php endif; ?>
            <input type="url" name="webhook_url" placeholder="https://hooks.slack.com/services/...">
        </label>
        <label>Kanal adı (yalnızca gösterim için, opsiyonel)
            <input type="text" name="channel_name" value="<?php echo $webhook ? htmlspecialchars((string) $webhook['channel_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="#trendyol-siparis">
        </label>
        <label>
            <input type="checkbox" name="is_active" value="1" <?php echo (!$webhook || (int) $webhook['is_active'] === 1) ? 'checked' : ''; ?> style="display:inline-block;width:auto;">
            Aktif
        </label>
        <button type="submit"><?php echo htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?></button>
    </form>
    <?php
}
?>
<div class="page">
    <p>
        <a href="/base_tables.php?base_id=<?php echo (int) $table['base_id']; ?>">&larr; <?php echo htmlspecialchars($table['base_name'], ENT_QUOTES, 'UTF-8'); ?> tablolarına dön</a>
        · <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">Grid'i görüntüle</a>
        · <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">Alanları yönet</a>
    </p>
    <h1><?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?> — Slack bildirimleri</h1>

    <?php require __DIR__ . '/../src/partials/flash.php'; ?>

    <?php if (!$canEdit): ?>
        <p class="hint">Slack bildirimlerini ayarlamak için editor veya owner rolü gerekir.</p>
    <?php endif; ?>

    <div class="card">
        <h2>Bu tabloya özel webhook'lar</h2>
        <p class="hint">Yalnızca "<?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?>" tablosuna yeni kayıt eklendiğinde tetiklenir. Aynı tabloda birden fazla webhook olabilir (ör. marka başına bir kanal) — hangisinin kullanılacağı aşağıdaki "Koşullu yönlendirme kuralları" ile belirlenir; hiç kural yoksa listedeki İLK aktif webhook kullanılır.</p>

        <?php if (empty($tableWebhooks)): ?>
            <p class="hint">Bu tabloya özel webhook yok.</p>
        <?php else: ?>
            <table>
                <tr><th>Kanal</th><th>Webhook</th><th>Durum</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr>
                <?php foreach ($tableWebhooks as $w): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $w['channel_name'] ?: '(kanal adı belirtilmemiş)', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(bcc_slack_masked_url($w), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo ((int) $w['is_active'] === 1) ? 'aktif' : 'pasif'; ?></td>
                        <?php if ($canEdit): ?>
                        <td class="row-actions">
                            <a class="btn-sm" href="/slack_settings.php?table_id=<?php echo (int) $table['id']; ?>&edit_webhook=<?php echo (int) $w['id']; ?>">Düzenle</a>
                            <form method="post" action="/slack_settings.php" onsubmit="return confirm('Bu webhook\'u silmek istediğinize emin misiniz?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_webhook">
                                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                <input type="hidden" name="webhook_id" value="<?php echo (int) $w['id']; ?>">
                                <button type="submit" class="btn-sm btn-danger">Sil</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <?php if ($canEdit && $editWebhook): ?>
            <h3>Webhook'u Düzenle</h3>
            <?php bcc_render_slack_webhook_form('table', $editWebhook, $table, 'Kaydet'); ?>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <h3>+ Yeni webhook ekle</h3>
            <?php bcc_render_slack_webhook_form('table', null, $table, 'Ekle'); ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Takım-geneli webhook</h2>
        <p class="hint">Bu takımın TÜM tablolarında (bu tablo dahil, tablo-özel bir webhook/kural eşleşmemişse) yeni kayıt eklendiğinde tetiklenir.</p>

        <?php if (!$canEdit): ?>
            <?php if ($teamWebhook): ?>
                <p>Aktif webhook: <?php echo htmlspecialchars((string) $teamWebhook['channel_name'] ?: '(kanal adı belirtilmemiş)', ENT_QUOTES, 'UTF-8'); ?> — <?php echo ((int) $teamWebhook['is_active'] === 1) ? 'aktif' : 'pasif'; ?></p>
            <?php else: ?>
                <p class="hint">Ayarlanmamış.</p>
            <?php endif; ?>
        <?php else: ?>
            <?php bcc_render_slack_webhook_form('team', $teamWebhook, $table, 'Kaydet'); ?>
            <?php if ($teamWebhook): ?>
                <form method="post" action="/slack_settings.php" onsubmit="return confirm('Bu webhook\'u silmek istediğinize emin misiniz?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_webhook">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <input type="hidden" name="webhook_id" value="<?php echo (int) $teamWebhook['id']; ?>">
                    <button type="submit" class="btn-sm btn-danger">Webhook'u sil</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Koşullu yönlendirme kuralları</h2>
        <p class="hint">Bir alanın (yalnızca tekli seçim tipi) değerine göre farklı bir webhook'a yönlendirir — ör. "Marka" alanı "Trendyol" ise Trendyol kanalına, "Yves Rocher" ise başka bir kanala. Sıradaki İLK eşleşen kural kazanır; hiçbiri eşleşmezse yukarıdaki tablo-özel/ekip-geneli webhook kullanılır.</p>

        <?php if (empty($singleSelectFields)): ?>
            <p class="hint">Bu tabloda tekli seçim alanı yok — koşullu yönlendirme kurulamaz. Önce <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">bir tekli seçim alanı ekleyin</a>.</p>
        <?php elseif (empty($availableWebhooksForRules)): ?>
            <p class="hint">Önce yukarıda en az bir webhook oluşturun.</p>
        <?php else: ?>
            <?php if (empty($routingRules)): ?>
                <p class="hint">Henüz kural yok.</p>
            <?php else: ?>
                <table>
                    <tr><th>Alan</th><th>Koşul</th><th>Değer</th><th>Hedef webhook</th><th>Durum</th><?php if ($canEdit): ?><th>İşlemler</th><?php endif; ?></tr>
                    <?php foreach ($routingRules as $i => $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['field_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($GLOBALS['BCC_SLACK_ROUTING_OPERATORS'][$r['operator']], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['value'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $r['webhook_channel_name'] ?: bcc_slack_masked_url(array('webhook_url' => $r['webhook_url'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo ((int) $r['is_active'] === 1) ? 'aktif' : 'pasif'; ?></td>
                            <?php if ($canEdit): ?>
                            <td class="row-actions">
                                <form method="post" action="/slack_settings.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="move_routing_rule">
                                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                    <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn-sm" <?php echo $i === 0 ? 'disabled' : ''; ?>>&uarr;</button>
                                </form>
                                <form method="post" action="/slack_settings.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="move_routing_rule">
                                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                    <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn-sm" <?php echo $i === count($routingRules) - 1 ? 'disabled' : ''; ?>>&darr;</button>
                                </form>
                                <form method="post" action="/slack_settings.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_routing_rule">
                                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                    <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn-sm"><?php echo ((int) $r['is_active'] === 1) ? 'Pasifleştir' : 'Aktifleştir'; ?></button>
                                </form>
                                <form method="post" action="/slack_settings.php" onsubmit="return confirm('Bu kuralı silmek istediğinize emin misiniz?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_routing_rule">
                                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                                    <input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn-sm btn-danger">Sil</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <h3>+ Yeni kural ekle</h3>
                <form class="stacked" method="post" action="/slack_settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_routing_rule">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <label>Alan
                        <select name="field_id" id="routing-rule-field" required>
                            <?php foreach ($singleSelectFields as $f): ?>
                                <option value="<?php echo (int) $f['id']; ?>" data-choices="<?php echo htmlspecialchars(json_encode(select_choices_from_options($f['options']), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Koşul
                        <select name="operator">
                            <?php foreach ($GLOBALS['BCC_SLACK_ROUTING_OPERATORS'] as $opKey => $opLabel): ?>
                                <option value="<?php echo htmlspecialchars($opKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Değer
                        <select name="value" id="routing-rule-value" required></select>
                    </label>
                    <label>Hedef webhook
                        <select name="webhook_id" required>
                            <?php foreach ($availableWebhooksForRules as $w): ?>
                                <option value="<?php echo (int) $w['id']; ?>">
                                    <?php echo htmlspecialchars((string) $w['channel_name'] ?: bcc_slack_masked_url($w), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit">Kural Ekle</button>
                </form>
                <script src="/assets/slack-routing.js" defer></script>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
