<?php
// Slack otomasyonu ayar sayfası (Faz 1 + Faz 2). İki bağımsız kapsam yönetilir:
// - Tablo-özel webhook (table_id = bu tablo)
// - Takım-geneli webhook (table_id NULL, team_id = bu takım) — takımın TÜM
//   tablolarında tetiklenir, DDL gerekmedi (table_id zaten nullable).
// Tablo-özel bir webhook varsa takım-genelinden önceliklidir (bkz. bcc_find_slack_webhook).
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

// Kapsama göre "mevcut satır" WHERE koşulu + INSERT değerleri — iki kapsam
// (table/team) aynı POST/GET mantığını paylaşır, kod tekrarı olmaz.
function bcc_slack_scope_where_and_insert($scope, $table)
{
    if ($scope === 'team') {
        return array(
            'where' => array('sql' => 'team_id = :team_id AND table_id IS NULL', 'params' => array('team_id' => $table['team_id'])),
            'insert_table_id' => null,
        );
    }

    return array(
        'where' => array('sql' => 'table_id = :table_id', 'params' => array('table_id' => $table['id'])),
        'insert_table_id' => $table['id'],
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    require_role($table['team_id'], 'editor');

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $scope = (isset($_POST['scope']) && $_POST['scope'] === 'team') ? 'team' : 'table';
    $scopeInfo = bcc_slack_scope_where_and_insert($scope, $table);

    $existing = bcc_fetch_one(
        "SELECT id, webhook_url FROM slack_webhooks WHERE {$scopeInfo['where']['sql']} LIMIT 1",
        $scopeInfo['where']['params']
    );
    $existing = $existing !== false ? $existing : null;

    if ($action === 'save') {
        $webhookUrlRaw = isset($_POST['webhook_url']) ? trim($_POST['webhook_url']) : '';
        $channelNameRaw = isset($_POST['channel_name']) ? trim($_POST['channel_name']) : '';
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

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
                // webhook_url log_audit'e YAZILMAZ — yalnızca değişti/değişmedi bilgisi bile değil.
                log_audit('slack.webhook_update', 'table', $table['id'], array('scope' => $scope, 'channel_name' => $channelName, 'is_active' => $isActive), $table['team_id']);
                $success = 'Webhook güncellendi.';
            } else {
                bcc_execute(
                    'INSERT INTO slack_webhooks (team_id, table_id, webhook_url, channel_name, is_active) VALUES (:team_id, :table_id, :url, :channel, :active)',
                    array('team_id' => $table['team_id'], 'table_id' => $scopeInfo['insert_table_id'], 'url' => $webhookUrlRaw, 'channel' => $channelName, 'active' => $isActive)
                );
                log_audit('slack.webhook_create', 'table', $table['id'], array('scope' => $scope, 'channel_name' => $channelName), $table['team_id']);
                $success = 'Webhook kaydedildi.';
            }
        }
    } elseif ($action === 'delete' && $existing) {
        bcc_execute('DELETE FROM slack_webhooks WHERE id = :id', array('id' => $existing['id']));
        log_audit('slack.webhook_delete', 'table', $table['id'], array('scope' => $scope), $table['team_id']);
        $success = 'Webhook silindi.';
    }
}

// Sayfa render'ı için iki kapsamın da GÜNCEL satırını çek.
$tableWhere = bcc_slack_scope_where_and_insert('table', $table);
$teamWhere = bcc_slack_scope_where_and_insert('team', $table);

$tableWebhook = bcc_fetch_one("SELECT id, channel_name, is_active, webhook_url FROM slack_webhooks WHERE {$tableWhere['where']['sql']} LIMIT 1", $tableWhere['where']['params']);
$tableWebhook = $tableWebhook !== false ? $tableWebhook : null;

$teamWebhook = bcc_fetch_one("SELECT id, channel_name, is_active, webhook_url FROM slack_webhooks WHERE {$teamWhere['where']['sql']} LIMIT 1", $teamWhere['where']['params']);
$teamWebhook = $teamWebhook !== false ? $teamWebhook : null;

function bcc_slack_masked_url($webhook)
{
    return $webhook ? ('••••••••' . substr($webhook['webhook_url'], -4)) : null;
}

$pageTitle = $table['name'] . ' — Slack';
require __DIR__ . '/../src/partials/header.php';
require __DIR__ . '/../src/partials/top_nav.php';

// Bir kapsam için tam kart (form + sil butonu) basar — tablo-özel ve takım-geneli
// AYNI bu fonksiyonu kullanır, HTML iki kez yazılmaz.
function bcc_render_slack_scope_card($scope, $title, $hint, $webhook, $table, $canEdit)
{
    $masked = bcc_slack_masked_url($webhook);
    ?>
    <div class="card">
        <h2><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="hint"><?php echo htmlspecialchars($hint, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($canEdit): ?>
            <form class="stacked" method="post" action="/slack_settings.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                <label>Webhook URL<?php if ($webhook): ?> (mevcut: <?php echo htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?> — değiştirmek için yeni URL girin, boş bırakırsanız korunur)<?php endif; ?>
                    <input type="url" name="webhook_url" placeholder="https://hooks.slack.com/services/...">
                </label>
                <label>Kanal adı (yalnızca gösterim için, opsiyonel)
                    <input type="text" name="channel_name" value="<?php echo $webhook ? htmlspecialchars((string) $webhook['channel_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="#duyuru-bcc-ty">
                </label>
                <label>
                    <input type="checkbox" name="is_active" value="1" <?php echo (!$webhook || (int) $webhook['is_active'] === 1) ? 'checked' : ''; ?> style="display:inline-block;width:auto;">
                    Aktif
                </label>
                <button type="submit">Kaydet</button>
            </form>
            <?php if ($webhook): ?>
                <form method="post" action="/slack_settings.php" onsubmit="return confirm('Bu webhook\'u silmek istediğinize emin misiniz?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <button type="submit" class="btn-sm btn-danger">Webhook'u sil</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($webhook): ?>
                <p>Aktif webhook: <?php echo htmlspecialchars((string) $webhook['channel_name'] ?: '(kanal adı belirtilmemiş)', ENT_QUOTES, 'UTF-8'); ?> — <?php echo ((int) $webhook['is_active'] === 1) ? 'aktif' : 'pasif'; ?></p>
            <?php else: ?>
                <p class="hint">Ayarlanmamış.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
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

    <?php
        bcc_render_slack_scope_card('table', 'Bu tabloya özel webhook', 'Yalnızca "' . $table['name'] . '" tablosuna yeni kayıt eklendiğinde tetiklenir. Takım-geneli bir webhook da varsa, bu öncelikli olur.', $tableWebhook, $table, $canEdit);
        bcc_render_slack_scope_card('team', 'Takım-geneli webhook', 'Bu takımın TÜM tablolarında (bu tablo dahil, tablo-özel bir webhook tanımlanmamışsa) yeni kayıt eklendiğinde tetiklenir.', $teamWebhook, $table, $canEdit);
    ?>
</div>
<?php require __DIR__ . '/../src/partials/footer.php'; ?>
