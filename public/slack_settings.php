<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = bcc_can_manage_schema($role);

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

        $existing = null;
        if ($webhookIdRaw > 0) {
            $existing = ($scope === 'team')
                ? bcc_fetch_one('SELECT id, webhook_url FROM slack_webhooks WHERE id = :id AND team_id = :team_id AND table_id IS NULL LIMIT 1', array('id' => $webhookIdRaw, 'team_id' => $table['team_id']))
                : bcc_fetch_one('SELECT id, webhook_url FROM slack_webhooks WHERE id = :id AND table_id = :table_id LIMIT 1', array('id' => $webhookIdRaw, 'table_id' => $table['id']));
            $existing = $existing !== false ? $existing : null;

            if (!$existing) {
                bcc_error_page('Webhook bulunamadı', 'Bu webhook bu tabloya ya da ekibe ait değil.', 404);
            }
        } elseif ($scope === 'team') {
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
                $urlToSave = $webhookUrlRaw !== '' ? $webhookUrlRaw : $existing['webhook_url'];

                bcc_execute(
                    'UPDATE slack_webhooks SET webhook_url = :url, channel_name = :channel, is_active = :active WHERE id = :id',
                    array('url' => $urlToSave, 'channel' => $channelName, 'active' => $isActive, 'id' => $existing['id'])
                );
                log_audit('slack.webhook_update', 'table', $table['id'], array('scope' => $scope, 'channel_name' => $channelName, 'is_active' => $isActive), $table['team_id']);
                $success = 'Webhook güncellendi.';
            } else {
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
            bcc_error_page('Webhook bulunamadı', 'Bu webhook bu ekibe ait değil.', 404);
        }

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

        $webhook = bcc_fetch_one('SELECT id FROM slack_webhooks WHERE id = :id AND team_id = :team_id LIMIT 1', array('id' => $webhookIdRaw, 'team_id' => $table['team_id']));

        if (!$field || !$webhook) {
            bcc_error_page('Kayıt bulunamadı', 'Bu alan ya da webhook bu tabloya/ekibe ait değil.', 404);
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
    } elseif ($action === 'save_watched_fields') {
        $postedWatch = isset($_POST['watched_fields']) && is_array($_POST['watched_fields']) ? $_POST['watched_fields'] : array();

        $fieldIdsInTable = array();
        foreach (bcc_fetch_all('SELECT id FROM fields WHERE table_id = :table_id', array('table_id' => $table['id'])) as $ff) {
            $fieldIdsInTable[] = (int) $ff['id'];
        }

        $selectedWatch = array();
        foreach ($postedWatch as $rawWid) {
            $wid = (int) $rawWid;
            if (in_array($wid, $fieldIdsInTable, true) && !in_array($wid, $selectedWatch, true)) {
                $selectedWatch[] = $wid;
            }
        }

        try {
            bcc_begin_transaction();

            bcc_execute('DELETE FROM slack_watched_fields WHERE table_id = :table_id', array('table_id' => $table['id']));

            foreach ($selectedWatch as $wid) {
                bcc_execute(
                    'INSERT INTO slack_watched_fields (team_id, table_id, field_id) VALUES (:team_id, :table_id, :field_id)',
                    array('team_id' => $table['team_id'], 'table_id' => $table['id'], 'field_id' => $wid)
                );
            }

            log_audit('slack.watched_fields_update', 'table', $table['id'], array('field_count' => count($selectedWatch)), $table['team_id']);

            bcc_commit();
        } catch (Throwable $e) {
            bcc_rollback();
            $error = 'Kaydedilemedi (veritabanı hatası).';
        }

        if ($error === null) {
            $success = empty($selectedWatch)
                ? 'Hücre değişikliği bildirimi kapatıldı (hiçbir alan izlenmiyor).'
                : 'İzlenen alanlar kaydedildi (' . count($selectedWatch) . ' alan).';
        }
    } elseif ($action === 'delete_routing_rule' || $action === 'move_routing_rule' || $action === 'toggle_routing_rule') {
        $ruleIdRaw = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;

        $rule = bcc_fetch_one('SELECT id, is_active FROM slack_routing_rules WHERE id = :id AND table_id = :table_id LIMIT 1', array('id' => $ruleIdRaw, 'table_id' => $table['id']));

        if (!$rule) {
            bcc_error_page('Kural bulunamadı', 'Bu kural bu tabloya ait değil.', 404);
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

$tableWebhooks = bcc_fetch_all(
    'SELECT id, channel_name, is_active, webhook_url FROM slack_webhooks WHERE table_id = :table_id ORDER BY id',
    array('table_id' => $table['id'])
);

$teamWebhookRows = bcc_fetch_all(
    'SELECT id, channel_name, is_active, webhook_url FROM slack_webhooks
     WHERE team_id = :team_id AND table_id IS NULL
     ORDER BY id ASC',
    array('team_id' => $table['team_id'])
);
$teamWebhook = !empty($teamWebhookRows) ? $teamWebhookRows[0] : null;
$extraTeamWebhooks = array_slice($teamWebhookRows, 1);

$editWebhookId = isset($_GET['edit_webhook']) ? (int) $_GET['edit_webhook'] : 0;
$editWebhook = null;
foreach ($tableWebhooks as $w) {
    if ((int) $w['id'] === $editWebhookId) {
        $editWebhook = $w;
        break;
    }
}

$availableWebhooksForRules = $tableWebhooks;
if ($teamWebhook) {
    $availableWebhooksForRules[] = $teamWebhook;
}

$fields = bcc_fetch_all('SELECT id, name, field_type, options FROM fields WHERE table_id = :table_id ORDER BY position, id', array('table_id' => $table['id']));
$singleSelectFields = array();
foreach ($fields as $f) {
    if ($f['field_type'] === 'single_select') {
        $singleSelectFields[] = $f;
    }
}

$watchedFieldIds = bcc_slack_watched_field_ids($table['id']);

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


$homeActiveNav = 'fields';
$homePageTitle = bcc_tab_title($table['name'] . ': Slack');
$homeExtraCss = array('settings-page.css', 'slack-settings.css');
require __DIR__ . '/../src/partials/home_shell_top.php';

function bcc_slack_status_pill($isActive)
{
    $on = ((int) $isActive === 1);
    ?><span class="sp-status <?php echo $on ? 'sp-status-on' : ''; ?>"><?php echo $on ? 'aktif' : 'pasif'; ?></span><?php
}

function bcc_render_slack_team_row($w, $table, $canEdit, $isExtra = false)
{
    $hasChannel = ((string) $w['channel_name'] !== '');
    ?>
    <div class="sl-readonly-row<?php echo $isExtra ? ' sl-row-extra' : ''; ?>">
        <span class="sl-channel <?php echo $hasChannel ? '' : 'sl-channel-empty'; ?>"><?php echo htmlspecialchars($hasChannel ? (string) $w['channel_name'] : 'kanal adı belirtilmemiş', ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="sp-code"><?php echo htmlspecialchars(bcc_slack_masked_url($w), ENT_QUOTES, 'UTF-8'); ?></span>
        <?php bcc_slack_status_pill($w['is_active']); ?>
        <?php if ($canEdit): ?>
            <?php
                  ?>
            <span class="sl-row-actions">
                <?php if (!$isExtra): ?>
                <form method="post" action="/slack_settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="test_webhook">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <input type="hidden" name="webhook_id" value="<?php echo (int) $w['id']; ?>">
                    <button type="submit" class="sp-icon-btn" title="Test mesajı gönder" aria-label="Takım webhook'una test mesajı gönder">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M17 3L9 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M17 3l-5.5 14-3-6-6-3L17 3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                    </button>
                </form>
                <?php endif; ?>
                <form method="post" action="/slack_settings.php" data-confirm="Bu webhook&#039;u silmek istediğinize emin misiniz?" data-confirm-title="Webhook&#039;u sil">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_webhook">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                    <input type="hidden" name="webhook_id" value="<?php echo (int) $w['id']; ?>">
                    <button type="submit" class="sp-icon-btn sp-icon-btn--danger" title="Webhook'u sil" aria-label="Takım webhook'unu sil">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </form>
            </span>
        <?php endif; ?>
    </div>
    <?php
}

function bcc_render_slack_webhook_form($scope, $webhook, $table, $submitLabel)
{
    ?>
    <form class="settings-form sl-webhook-form" method="post" action="/slack_settings.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_webhook">
        <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
        <input type="hidden" name="webhook_id" value="<?php echo $webhook ? (int) $webhook['id'] : ''; ?>">
        <?php
              ?>
        <label class="settings-field">
            <?php
                  ?>
            <?php
                  ?>
            <span class="sl-label">Webhook URL
                <?php if ($webhook): ?>
                    <span class="sl-opt">boş = değişmez</span>
                <?php else: ?>
                    <span class="sl-req">zorunlu</span>
                <?php endif; ?>
            </span>
            <?php if ($webhook): ?>
                <?php
                      ?>
                <span class="sl-hint">Boş bırakırsanız <strong>mevcut adres korunur</strong> — sadece kanal adını ya da Aktif anahtarını değiştirmek için URL'i yeniden yapıştırmanız gerekmez. Yeni bir adres yapıştırırsanız <strong>hedef kanal da değişir</strong>.</span>
            <?php else: ?>
                <span class="sl-hint">Slack &rarr; <em>Apps</em> &rarr; <em>Incoming Webhooks</em> &rarr; <em>Add New Webhook to Workspace</em> &rarr; kanalı seçin, size verilen adresi buraya yapıştırın. <strong>Hedef kanalı bu adres belirler</strong>; başka bir kanala göndermek için Slack'te yeni bir webhook oluşturmanız gerekir.</span>
            <?php endif; ?>
            <input type="url" name="webhook_url" placeholder="https://hooks.slack.com/services/..."
                   <?php echo $webhook ? '' : 'required'; ?>>
        </label>
        <label class="settings-field">
            <span class="sl-label">Kanal adı <span class="sl-opt">isteğe bağlı</span></span>
            <span class="sl-hint">Yalnızca bu sayfadaki <strong>etiket</strong> — Slack'e gönderilmez, hiçbir şeyi yönlendirmez. Yukarıdaki adresin gerçekten gittiği kanalın adını yazın; koşullu kural kurarken hedefi bu isimden seçeceksiniz.</span>
            <input type="text" name="channel_name" value="<?php echo $webhook ? htmlspecialchars((string) $webhook['channel_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="#trendyol-siparis">
        </label>
        <div class="sl-form-footer">
            <?php
                  ?>
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
                                <?php
                                      ?>
                                <td class="settings-row-actions">
                                    <?php
                                          ?>
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
                                    <form method="post" action="/slack_settings.php" data-confirm="Bu webhook&#039;u silmek istediğinize emin misiniz?" data-confirm-title="Webhook&#039;u sil">
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

            <?php
                  ?>
            <?php if ($teamWebhook): ?>
                <h3 class="sl-subhead sl-subhead--tight">Bağlı kanal</h3>
                <?php bcc_render_slack_team_row($teamWebhook, $table, $canEdit); ?>
            <?php else: ?>
                <p class="settings-empty">
                    <strong>Yedek kanal ayarlanmamış.</strong>
                    <span class="sp-muted">Tablo-özel bir webhook ya da kural eşleşmezse hiçbir bildirim gitmez.</span>
                </p>
            <?php endif; ?>

            <?php
                  ?>
            <?php if ($canEdit && !empty($extraTeamWebhooks)): ?>
                <div class="sp-note sp-note--warn">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3.5l7 12.5H3l7-12.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                    <span><strong>Bu ekipte fazladan <?php echo count($extraTeamWebhooks); ?> takım-geneli webhook var.</strong> Takım-geneli webhook <em>tek</em> olmalıdır. Fazlalıklardan biri <strong>aktifse</strong> bildirimleriniz yukarıda görünen kanala değil ona gidiyor olabilir. Kullanmadıklarınızı silin.</span>
                </div>
                <div class="sl-extra-list">
                    <?php foreach ($extraTeamWebhooks as $ew): ?>
                        <?php bcc_render_slack_team_row($ew, $table, $canEdit, true); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <h3 class="sl-subhead">
                    <?php if ($teamWebhook): ?>
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13.2 3.8l3 3L7.5 15.5l-3.7.7.7-3.7 8.7-8.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        Yedek kanalı düzenle
                    <?php else: ?>
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                        Yedek kanal ekle
                    <?php endif; ?>
                </h3>
                <?php
                      ?>
                <?php bcc_render_slack_webhook_form('team', $teamWebhook, $table, $teamWebhook ? 'Kaydet' : 'Ekle'); ?>
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
                                        <form method="post" action="/slack_settings.php" data-confirm="Bu kuralı silmek istediğinize emin misiniz?" data-confirm-title="Kuralı sil">
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
                    <?php
                          ?>
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

        <?php
        $watchableFields = array();
        foreach ($fields as $wf) {
            if (!in_array($wf['field_type'], $GLOBALS['BCC_READONLY_FIELD_TYPES'], true)) {
                $watchableFields[] = $wf;
            }
        }
        ?>
        <div class="settings-card">
            <h2>Hücre değişikliği bildirimi <span class="sp-count"><?php echo count($watchedFieldIds); ?></span></h2>
            <div class="sp-note">
                <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13.2 3.8l3 3L7.5 15.5l-3.7.7.7-3.7 8.7-8.7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                <span>Yukarıdaki üç olay (<strong>yeni kayıt</strong>, <strong>yeni tablo</strong>, <strong>yeni alan</strong>) her zaman açıktır. Bu kart <strong>dördüncü</strong> olayı yönetir: <em>var olan</em> bir kaydın hücresi değiştiğinde de bildirim gitsin mi. Yalnızca burada işaretlediğiniz alanlar tetikler — <strong>hiçbiri işaretli değilse özellik kapalıdır</strong>.</span>
            </div>

            <?php if (empty($watchableFields)): ?>
                <p class="settings-empty">
                    <strong>İzlenebilecek alan yok.</strong>
                    <span class="sp-muted">Otomatik alanlar (oluşturulma zamanı, oluşturan, son değişiklik, otomatik numara) elle değiştirilemediği için listeye girmez.</span>
                </p>
            <?php elseif (!$canEdit): ?>
                <?php if (empty($watchedFieldIds)): ?>
                    <p class="settings-empty"><strong>Hiçbir alan izlenmiyor.</strong></p>
                <?php else: ?>
                    <div class="sl-readonly-row">
                        <?php foreach ($watchableFields as $wf): ?>
                            <?php if (in_array((int) $wf['id'], $watchedFieldIds, true)): ?>
                                <span class="sl-channel"><?php echo htmlspecialchars($wf['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php
                      ?>
                <form method="post" action="/slack_settings.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_watched_fields">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">

                    <div class="sl-watch-list">
                        <?php foreach ($watchableFields as $wf): ?>
                            <?php
                            $wfTypeLabel = isset($GLOBALS['BCC_FIELD_TYPES'][$wf['field_type']])
                                ? $GLOBALS['BCC_FIELD_TYPES'][$wf['field_type']]
                                : $wf['field_type'];
                            ?>
                            <?php
                                  ?>
                            <label class="sl-watch-item sp-toggle">
                                <input type="checkbox" name="watched_fields[]" value="<?php echo (int) $wf['id']; ?>" <?php echo in_array((int) $wf['id'], $watchedFieldIds, true) ? 'checked' : ''; ?>>
                                <span class="sp-toggle-track"></span>
                                <span class="sl-watch-name"><?php echo htmlspecialchars($wf['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="sl-watch-type"><?php echo htmlspecialchars($wfTypeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="sp-note sp-note--warn">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3.5l7 12.5H3l7-12.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                        <span>Uzun metin ve sayı gibi sık düzenlenen alanları işaretlerken dikkat: her kaydetmede kanala bir mesaj düşer. <strong>Toplu yapıştırmada</strong> ise hücre başına değil, işlem başına <strong>tek özet mesaj</strong> gönderilir.</span>
                    </div>

                    <div class="sl-form-footer">
                        <button type="submit" class="settings-btn settings-btn-primary">Kaydet</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
</div>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
