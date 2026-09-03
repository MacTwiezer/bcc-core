<?php

function bcc_find_slack_webhook($tableId, $teamId, $recordId = null)
{
    if ($recordId !== null) {
        $rules = bcc_fetch_all(
            'SELECT r.operator, r.value AS rule_value, cv.value_text AS actual_value,
                    sw.id, sw.webhook_url, sw.channel_name, sw.table_id
             FROM slack_routing_rules r
             INNER JOIN slack_webhooks sw ON sw.id = r.webhook_id AND sw.is_active = 1
             LEFT JOIN cell_values cv ON cv.record_id = :record_id AND cv.field_id = r.field_id
             WHERE r.table_id = :table_id AND r.is_active = 1
             ORDER BY r.position ASC, r.id ASC',
            array('record_id' => $recordId, 'table_id' => $tableId)
        );

        foreach ($rules as $rule) {
            $matches = ($rule['operator'] === 'not_equals')
                ? ((string) $rule['actual_value'] !== (string) $rule['rule_value'])
                : ((string) $rule['actual_value'] === (string) $rule['rule_value']);

            if ($matches) {
                return array(
                    'id' => $rule['id'],
                    'webhook_url' => $rule['webhook_url'],
                    'channel_name' => $rule['channel_name'],
                    'table_id' => $rule['table_id'],
                );
            }
        }
    }

    $row = bcc_fetch_one(
        'SELECT id, webhook_url, channel_name, table_id FROM slack_webhooks
         WHERE is_active = 1 AND (table_id = :table_id OR (table_id IS NULL AND team_id = :team_id))
         ORDER BY (table_id IS NULL) ASC, id ASC
         LIMIT 1',
        array('table_id' => $tableId, 'team_id' => $teamId)
    );

    return $row !== false ? $row : null;
}

function bcc_slack_escape($text)
{
    return str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), (string) $text);
}

function bcc_slack_send_webhook($webhookUrl, $text)
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $payload = json_encode(array('text' => $text), JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    $GLOBALS['BCC_SLACK_LAST_STATUS'] = $httpCode;

    return ($errno === 0 && $httpCode >= 200 && $httpCode < 300);
}

function bcc_slack_text_from_rich($html)
{
    $s = (string) $html;

    $s = preg_replace('#<br\s*/?>#i', "\n", $s);

    $links = array();

    $marker = 'BCCLNK' . bin2hex(random_bytes(4));
    $s = preg_replace_callback(
        '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
        function ($m) use (&$links, $marker) {
            $href = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));

            if (!preg_match('#^(https?|mailto|tel):#i', $href)) {
                return $text;
            }

            $href = str_replace(array('<', '>', '|'), '', $href);
            $label = ($text !== '') ? bcc_slack_escape($text) : $href;

            $key = '{{' . $marker . count($links) . '}}';
            $links[$key] = '<' . $href . '|' . $label . '>';

            return $key;
        },
        $s
    );

    $s = preg_replace('#</?(?:b|strong)\b[^>]*>#i', '*', $s);
    $s = preg_replace('#</?(?:i|em)\b[^>]*>#i', '_', $s);

    $s = strip_tags($s);

    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = str_replace("\xC2\xA0", ' ', $s);

    $s = bcc_slack_escape($s);

    if (!empty($links)) {
        $s = strtr($s, $links);
    }

    $s = preg_replace("/\n{3,}/", "\n\n", $s);

    return trim($s);
}

function bcc_slack_cell_value_markup($fieldType, $display)
{
    if ((string) $display === '') {
        return '';
    }

    return ($fieldType === 'long_text')
        ? bcc_slack_text_from_rich($display)
        : bcc_slack_escape($display);
}

define('BCC_SLACK_MAX_TEXT', 35000);

function bcc_slack_truncate($text, $limit)
{
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '… _(kısaltıldı)_';
}

function bcc_slack_fit_pair($a, $b, $budget)
{
    $la = mb_strlen($a, 'UTF-8');
    $lb = mb_strlen($b, 'UTF-8');

    if ($budget < 200) {
        $budget = 200;
    }
    if ($la + $lb <= $budget) {
        return array($a, $b);
    }

    $half = intdiv($budget, 2);

    if ($la <= $half) {
        return array($a, bcc_slack_truncate($b, $budget - $la));
    }
    if ($lb <= $half) {
        return array(bcc_slack_truncate($a, $budget - $lb), $b);
    }

    return array(bcc_slack_truncate($a, $half), bcc_slack_truncate($b, $budget - $half));
}

function bcc_slack_app_url($path)
{
    return bcc_app_base_url() . $path;
}

function bcc_slack_dispatch($tableId, $teamId, $recordId, $text, $entityType, $entityId)
{
    try {
        $webhook = bcc_find_slack_webhook($tableId, $teamId, $recordId);
        if (!$webhook) {
            return false;
        }

        $ok = bcc_slack_send_webhook($webhook['webhook_url'], $text);

        log_audit(
            $ok ? 'slack.notify_sent' : 'slack.notify_failed',
            $entityType,
            $entityId,
            array('table_id' => $tableId),
            $teamId
        );

        return $ok;
    } catch (Throwable $e) {

        return false;
    }
}

function bcc_slack_record_title($tableId, $recordId, $teamId)
{
    $primaryField = bcc_fetch_one(
        'SELECT id, field_type, options FROM fields WHERE table_id = :table_id ORDER BY position, id LIMIT 1',
        array('table_id' => $tableId)
    );

    if (!$primaryField) {
        return '(başlıksız kayıt)';
    }

    $cellRow = bcc_fetch_one(
        'SELECT value_text, value_number, value_date, value_json FROM cell_values WHERE record_id = :record_id AND field_id = :field_id LIMIT 1',
        array('record_id' => $recordId, 'field_id' => $primaryField['id'])
    );
    $cellRow = $cellRow !== false ? $cellRow : null;

    $usersById = ($primaryField['field_type'] === 'user') ? bcc_team_users_by_id($teamId) : array();
    $display = cell_display_text($primaryField['field_type'], $cellRow, $usersById, $primaryField['options']);

    if ($primaryField['field_type'] === 'long_text' && $display !== '') {
        $display = preg_replace('#<br\s*/?>#i', ' ', $display);
        $display = html_entity_decode(strip_tags($display), ENT_QUOTES, 'UTF-8');
        $display = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $display)));
    }

    return ($display !== '') ? $display : '(başlıksız kayıt)';
}

function bcc_slack_watched_field_ids($tableId)
{
    static $cache = array();

    $tableId = (int) $tableId;
    if (!array_key_exists($tableId, $cache)) {
        $cache[$tableId] = array_map('intval', array_column(
            bcc_fetch_all('SELECT field_id FROM slack_watched_fields WHERE table_id = :table_id', array('table_id' => $tableId)),
            'field_id'
        ));
    }

    return $cache[$tableId];
}

function bcc_notify_slack_new_record($tableId, $recordId, $userFullName = null)
{
    try {
        $tableRow = bcc_fetch_one(
            'SELECT t.name AS table_name, b.id AS base_id, b.team_id
             FROM tables_meta t
             INNER JOIN bases b ON b.id = t.base_id
             WHERE t.id = :table_id LIMIT 1',
            array('table_id' => $tableId)
        );
        if (!$tableRow) {
            return;
        }

        $primaryDisplay = bcc_slack_record_title($tableId, $recordId, $tableRow['team_id']);

        $link = bcc_slack_app_url('/interface.php?base_id=' . (int) $tableRow['base_id'] . '&table_id=' . (int) $tableId);

        $text = "📢 *" . bcc_slack_escape($tableRow['table_name']) . "* tablosuna yeni bir duyuru eklendi\n*"
            . bcc_slack_escape($primaryDisplay) . "*\n";
        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Ekleyen: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Duyuruyu görüntüle>';

        bcc_slack_dispatch($tableId, $tableRow['team_id'], $recordId, $text, 'record', $recordId);
    } catch (Throwable $e) {

    }
}

function bcc_notify_slack_new_table($tableId, $userFullName = null)
{
    try {
        $row = bcc_fetch_one(
            'SELECT t.name AS table_name, b.id AS base_id, b.name AS base_name, b.team_id
             FROM tables_meta t
             INNER JOIN bases b ON b.id = t.base_id
             WHERE t.id = :table_id LIMIT 1',
            array('table_id' => $tableId)
        );
        if (!$row) {
            return;
        }

        $link = bcc_slack_app_url('/grid.php?table_id=' . (int) $tableId);

        $text = "🗂️ *" . bcc_slack_escape($row['base_name']) . "* base'inde yeni tablo oluşturuldu\n*"
            . bcc_slack_escape($row['table_name']) . "*\n";
        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Oluşturan: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Tabloyu aç>';

        bcc_slack_dispatch($tableId, $row['team_id'], null, $text, 'table', $tableId);
    } catch (Throwable $e) {

    }
}

function bcc_notify_slack_new_field($tableId, $fieldId, $fieldName, $fieldType, $userFullName = null)
{
    try {
        $row = bcc_fetch_one(
            'SELECT t.name AS table_name, b.id AS base_id, b.team_id
             FROM tables_meta t
             INNER JOIN bases b ON b.id = t.base_id
             WHERE t.id = :table_id LIMIT 1',
            array('table_id' => $tableId)
        );
        if (!$row) {
            return;
        }

        $typeLabel = isset($GLOBALS['BCC_FIELD_TYPES'][$fieldType])
            ? $GLOBALS['BCC_FIELD_TYPES'][$fieldType]
            : $fieldType;

        $link = bcc_slack_app_url('/grid.php?table_id=' . (int) $tableId);

        $text = "🧩 *" . bcc_slack_escape($row['table_name']) . "* tablosuna yeni alan eklendi\n*"
            . bcc_slack_escape($fieldName) . "* (" . bcc_slack_escape($typeLabel) . ")\n";
        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Ekleyen: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Tabloyu aç>';

        bcc_slack_dispatch($tableId, $row['team_id'], null, $text, 'field', $fieldId);
    } catch (Throwable $e) {

    }
}

function bcc_notify_slack_cell_change($tableId, $recordId, $fieldType, $fieldName, $oldDisplay, $newDisplay, $userFullName = null)
{
    try {
        $row = bcc_fetch_one(
            'SELECT t.name AS table_name, b.id AS base_id, b.team_id
             FROM tables_meta t
             INNER JOIN bases b ON b.id = t.base_id
             WHERE t.id = :table_id LIMIT 1',
            array('table_id' => $tableId)
        );
        if (!$row) {
            return;
        }

        $oldMarkup = bcc_slack_cell_value_markup($fieldType, $oldDisplay);
        $newMarkup = bcc_slack_cell_value_markup($fieldType, $newDisplay);

        list($oldMarkup, $newMarkup) = bcc_slack_fit_pair($oldMarkup, $newMarkup, BCC_SLACK_MAX_TEXT - 800);

        $oldText = ($oldMarkup !== '') ? $oldMarkup : '—';
        $newText = ($newMarkup !== '') ? $newMarkup : '—';

        $link = bcc_slack_app_url('/interface.php?base_id=' . (int) $row['base_id'] . '&table_id=' . (int) $tableId);

        $text = "✏️ *" . bcc_slack_escape($row['table_name']) . "* tablosunda bir kayıt güncellendi\n*"
            . bcc_slack_escape(bcc_slack_record_title($tableId, $recordId, $row['team_id'])) . "*\n";

        $isBlock = (strpos($oldText, "\n") !== false || strpos($newText, "\n") !== false
            || mb_strlen($oldText, 'UTF-8') > 80 || mb_strlen($newText, 'UTF-8') > 80);

        if (!$isBlock) {
            $text .= bcc_slack_escape($fieldName) . ': ' . $oldText . ' → ' . $newText . "\n";
        } else {

            $text .= '*' . bcc_slack_escape($fieldName) . "* güncellendi\n\n"
                . "_Önce:_\n" . bcc_slack_blockquote($oldText) . "\n\n"
                . "_Sonra:_\n" . bcc_slack_blockquote($newText) . "\n\n";
        }

        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Değiştiren: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Kaydı görüntüle>';

        bcc_slack_dispatch($tableId, $row['team_id'], $recordId, $text, 'record', $recordId);
    } catch (Throwable $e) {

    }
}

function bcc_slack_blockquote($text)
{
    $lines = preg_split("/\n/", (string) $text);

    foreach ($lines as $i => $line) {
        $lines[$i] = '>' . $line;
    }

    return implode("\n", $lines);
}

function bcc_notify_slack_bulk_cell_change($tableId, $fieldNames, $cellCount, $userFullName = null)
{
    try {
        if (empty($fieldNames) || (int) $cellCount < 1) {
            return;
        }

        $row = bcc_fetch_one(
            'SELECT t.name AS table_name, b.id AS base_id, b.team_id
             FROM tables_meta t
             INNER JOIN bases b ON b.id = t.base_id
             WHERE t.id = :table_id LIMIT 1',
            array('table_id' => $tableId)
        );
        if (!$row) {
            return;
        }

        $link = bcc_slack_app_url('/interface.php?base_id=' . (int) $row['base_id'] . '&table_id=' . (int) $tableId);

        $escaped = array();
        foreach ($fieldNames as $n) {
            $escaped[] = '*' . bcc_slack_escape($n) . '*';
        }

        $text = "✏️ *" . bcc_slack_escape($row['table_name']) . "* tablosunda toplu düzenleme\n"
            . implode(', ', $escaped) . ' alanında ' . (int) $cellCount . " hücre güncellendi\n";
        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Değiştiren: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Tabloyu aç>';

        bcc_slack_dispatch($tableId, $row['team_id'], null, $text, 'table', $tableId);
    } catch (Throwable $e) {

    }
}

function bcc_slack_send_test($webhookId, $teamId, $userFullName = null)
{
    $row = bcc_fetch_one(
        'SELECT id, webhook_url, channel_name, table_id FROM slack_webhooks
         WHERE id = :id AND team_id = :team_id LIMIT 1',
        array('id' => $webhookId, 'team_id' => $teamId)
    );

    if ($row === false || $row === null) {

        return array('ok' => false, 'error' => 'Webhook bulunamadı.');
    }

    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'Sunucuda curl eklentisi yok — Slack gönderimi yapılamıyor.');
    }

    $text = "✅ *Slack Integration Connected Successfully*\n"
        . bcc_brand_name() . " bu kanala bağlandı. Bu bir test mesajıdır.\n";
    if ($row['channel_name']) {
        $text .= 'Kanal: ' . bcc_slack_escape($row['channel_name']) . "\n";
    }
    if ($userFullName !== null && $userFullName !== '') {
        $text .= 'Test eden: ' . bcc_slack_escape($userFullName) . "\n";
    }
    $text .= 'Zaman: ' . date('d.m.Y H:i');

    $ok = bcc_slack_send_webhook($row['webhook_url'], $text);
    $status = isset($GLOBALS['BCC_SLACK_LAST_STATUS']) ? (int) $GLOBALS['BCC_SLACK_LAST_STATUS'] : 0;

    log_audit(
        $ok ? 'slack.test_sent' : 'slack.test_failed',
        'table',
        $row['table_id'] !== null ? (int) $row['table_id'] : null,
        array('webhook_id' => (int) $row['id'], 'http_status' => $status),
        $teamId
    );

    return array(
        'ok' => $ok,
        'error' => $ok ? null : 'Slack mesajı gönderilemedi. URL geçerli mi ve kanal hâlâ var mı kontrol edin.',
    );
}
