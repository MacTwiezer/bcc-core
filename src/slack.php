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

/* Mesaj kartinin sol kenar rengi — 2026-09-09.
   Duz metin yerine Slack'in "attachment" bicimi kullanilinca mesaj kenarlikli
   bir kart icinde ciziliyor; arka arkaya gelen bildirimler birbirine
   karismiyor. Renk olay turunu bir bakista ayiriyor. */
define('BCC_SLACK_COLOR_NEW', '#2EB67D');
define('BCC_SLACK_COLOR_UPDATE', '#4A90D9');

function bcc_slack_send_webhook($webhookUrl, $text, $opts = array())
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $color = isset($opts['color']) ? (string) $opts['color'] : '';
    $footer = isset($opts['footer']) ? (string) $opts['footer'] : '';

    if (!empty($opts['attachments'])) {
        /* Hazir attachment listesi: TEK mesaj icinde birden fazla renkli
           bolum. Slack her attachment'i kendi sol renk seridiyle ciziyor,
           boylece "eklenenler yesil / degisenler mavi / silinenler kirmizi"
           ayrimi tek bildirimde yapilabiliyor. */
        $ilkSatir = trim(strtok($text, "\n"));

        $body = array(
            'attachments' => $opts['attachments'],
        );

        if ($ilkSatir !== '') {
            foreach ($body['attachments'] as $i => $ek) {
                if (!isset($ek['fallback'])) {
                    $body['attachments'][$i]['fallback'] = $ilkSatir;
                }
            }
        }
    } elseif (!empty($opts['blocks'])) {
        /* Block Kit: kartin ICI bloklara ayriliyor (baslik, alan izgarasi,
           buton, alt serit). Bloklar attachment'in icinde tasiniyor ki sol
           kenardaki renk seridi korunsun — ust duzey 'blocks' kullanilsaydi
           renk olmazdi. */
        $ilkSatir = trim(strtok($text, "\n"));

        $body = array('attachments' => array(array(
            'color' => ($color !== '') ? $color : BCC_SLACK_COLOR_UPDATE,
            'fallback' => ($ilkSatir !== '') ? $ilkSatir : $text,
            'blocks' => $opts['blocks'],
        )));
    } elseif ($color === '') {
        /* Renk verilmeyen cagrilar (ornegin baglanti testi) eski duz metin
           bicimini kullanmaya devam ediyor. */
        $body = array('text' => $text);
    } else {
        /* 'fallback' bildirim onizlemesinde (masaustu/mobil popup, kanal
           listesi) gorunen metin; kartin kendisi cizilemedigi yerlerde de
           mesaj okunabilir kalsin diye ilk satir kullaniliyor. */
        $ilkSatir = trim(strtok($text, "\n"));

        $attachment = array(
            'color' => $color,
            'fallback' => ($ilkSatir !== '') ? $ilkSatir : $text,
            'text' => $text,
            'mrkdwn_in' => array('text'),
        );

        if ($footer !== '') {
            $attachment['footer'] = $footer;
        }

        $body = array('attachments' => array($attachment));
    }

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

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

function bcc_slack_dispatch($tableId, $teamId, $recordId, $text, $entityType, $entityId, $opts = array())
{
    try {
        /* Yonlendirme kurallari kayda gore farkli kanala dusurebiliyor; toplu
           mesajda hangi kanala gidilecegi disarida COZULUP buraya veriliyor
           (bcc_slack_flush_table kayitlari webhook'a gore grupluyor). */
        $webhook = isset($opts['webhook']) ? $opts['webhook'] : bcc_find_slack_webhook($tableId, $teamId, $recordId);
        if (!$webhook) {
            return false;
        }

        $ok = bcc_slack_send_webhook($webhook['webhook_url'], $text, $opts);

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

        $link = bcc_slack_app_url('/grid.php?table_id=' . (int) $tableId);

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

        $link = bcc_slack_app_url('/grid.php?table_id=' . (int) $tableId);

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

        $link = bcc_slack_app_url('/grid.php?table_id=' . (int) $tableId);

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

/*
 * Toplu (birlestirilmis) hucre degisikligi bildirimi — 2026-09-08.
 *
 * ESKI DAVRANIS: cell_update.php her hucre icin ANINDA bir Slack mesaji
 * gonderiyordu; bir kaydin uc alanini doldurmak kanala uc mesaj dusuruyordu.
 *
 * YENI DAVRANIS: mesaj hemen gonderilmez. Bir kaydin son dokunulmasindan
 * BCC_SLACK_BATCH_IDLE_SECONDS saniye gectiginde, o kayda ait tum degisiklikler
 * TEK mesajda ozetlenir.
 *
 * AYRI BIR KUYRUK TABLOSU YOK: "neyin degistigi" iki damgadan cikariliyor —
 * cell_values.updated_at (ON UPDATE CURRENT_TIMESTAMP, schema.sql:289) ve yeni
 * records.slack_notified_at. Bu yuzden mesajda eski deger DEGIL, alanin GUNCEL
 * degeri yazar (istenen bicim buydu).
 *
 * TETIKLEME (cron YOK, projenin "ziyaret aninda kontrol" desenine uygun —
 * PROJE-DURUM.md:339): (1) sonraki her yazma istegi, (2) grid/arayuz sayfa
 * yuklemesi, (3) tarayicinin sayfadan ayrilirken ya da bosta kalinca attigi
 * ping (public/api/slack_flush.php). Ucu de bu ayni fonksiyonu cagirir.
 */
define('BCC_SLACK_BATCH_IDLE_SECONDS', 180);

/* Bir bosaltmada islenecek en fazla kayit. 2026-09-09'da 20'den 50'ye
   cikarildi: artik kayit basina bir mesaj DEGIL, bosaltma basina TEK mesaj
   gonderiliyor, yani 50 kayit da tek bir bildirime siginiyor. */
define('BCC_SLACK_BATCH_MAX_RECORDS', 50);

/* Tek mesajda kac kaydin DETAYI (alan degerleri) yazilsin. Fazlasi "...ve N
   kayit daha" diye ozetleniyor: Slack'in blok/karakter sinirlari asilmasin ve
   kart okunaksiz uzunluga cikmasin. Ozet listesinde (sutun -> satir no) ise
   kayitlarin HEPSI gorunur. */
define('BCC_SLACK_BATCH_MAX_DETAIL', 15);

/* Ozet listesinde bir sutunun yanina en fazla kac satir numarasi yazilsin. */
define('BCC_SLACK_BATCH_MAX_ROWNOS', 20);

define('BCC_SLACK_COLOR_DELETE', '#D64545');
define('BCC_SLACK_COLOR_SUMMARY', '#6B7785');

define('BCC_SLACK_BATCH_MAX_FIELDS', 25);

define('BCC_SLACK_BATCH_TIME_BUDGET', 6.0);

/* Ozet mesajinda alan basina uzunluk siniri — 2026-09-09.
   Onceden alan basina sinir YOKTU; tek sinir 35.000 karakterlik toplam tavandi
   (BCC_SLACK_MAX_TEXT). Bir "Notlar" alanina yapistirilmis 42 satirlik firma
   listesi kanala oldugu gibi dusuyordu. Ozet, kaydin TAMAMINI tasimak icin
   degil "neyin degistigini" gostermek icin var; ayrinti icin mesajin sonundaki
   link zaten kayda goturuyor. */
define('BCC_SLACK_BATCH_MAX_VALUE_CHARS', 200);
define('BCC_SLACK_BATCH_MAX_TITLE_CHARS', 120);

function bcc_slack_shorten_value($markup, $limit)
{
    /* Ozette her alan TEK satir olsun: hucre icindeki satir sonlari ve
       tekrarlanan bosluklar tek bosluga iniyor. Cok satirli bir not, alt alta
       yayilmak yerine tek satirda basliyor ve limitte kesiliyor. */
    $tek = trim(preg_replace('/\s+/u', ' ', (string) $markup));

    if ($tek === '' || mb_strlen($tek, 'UTF-8') <= $limit) {
        return $tek;
    }

    $kesik = mb_substr($tek, 0, $limit, 'UTF-8');

    /* Kelimenin ortasindan kesme — ama cok geriye de sarma: tek uzun bir
       kelimede mesajin yarisi ucmasin diye yalnizca son 40 karakter icinde
       bosluk araniyor. */
    $sonBosluk = mb_strrpos($kesik, ' ', 0, 'UTF-8');
    if ($sonBosluk !== false && $sonBosluk > $limit - 40) {
        $kesik = mb_substr($kesik, 0, $sonBosluk, 'UTF-8');
    }

    return rtrim($kesik) . '…';
}

function bcc_slack_mark_records_notified($recordIds)
{
    $ids = array();
    foreach ((array) $recordIds as $rid) {
        $rid = (int) $rid;
        if ($rid > 0) {
            $ids[$rid] = $rid;
        }
    }

    if (empty($ids)) {
        return;
    }

    /* updated_at = updated_at KASITLI: records.updated_at ON UPDATE
       CURRENT_TIMESTAMP tasiyor; bu atama olmadan damga yazmak "Son degisiklik
       zamani" alanini bildirim saatine kaydirirdi. */
    bcc_execute(
        'UPDATE records SET slack_notified_at = NOW(), updated_at = updated_at
         WHERE id IN (' . implode(',', $ids) . ')'
    );
}

function bcc_slack_pending_records($tableId, $watchedFieldIds, $idleSeconds)
{
    $idleSeconds = (int) $idleSeconds;
    $limit = (int) BCC_SLACK_BATCH_MAX_RECORDS;

    if (!empty($watchedFieldIds)) {
        $ids = implode(',', array_map('intval', $watchedFieldIds));

        /* "Icerigi var mi" olcusu: bos bir hucre satiri (deger silinmis ya da
           hic yazilmamis) sayilmaz. value_json'da NULL kontrolu YETMIYOR:
           coklu secim / ek alani doldurulup sonra temizlenince SQL NULL degil
           '[]' saklaniyor, dolayisiyla gorunuste bos bir satir yine
           "icerigi var" sayilip 📢 "(basliksiz kayit)" olarak duyurulurdu —
           tam da onlenmek istenen gurultu. */
        $dolu = "SELECT 1 FROM cell_values cv
                 WHERE cv.record_id = r.id AND cv.field_id IN ({$ids})
                   AND (
                        (cv.value_text IS NOT NULL AND cv.value_text <> '')
                     OR cv.value_number IS NOT NULL
                     OR cv.value_date IS NOT NULL
                     OR (cv.value_json IS NOT NULL AND JSON_LENGTH(cv.value_json) > 0)
                   )";

        /* 2026-09-09 — Yeni kayit ancak ICERIK girildiginde duyurulur.
           Onceden damgasiz her kayit "duyurulacak" sayiliyordu; bu yuzden
           "20 satir ekle" komutunun urettigi bos satirlar kanala 20 tane
           basliksiz mesaj dusurebiliyordu ve record_add.php onlari olusturur
           olusturmaz damgalamak zorunda kalmisti. Damgalanmis satir ise
           sonradan doldurulunca 📢 "yeni duyuru" degil ✏️ "guncellendi"
           basligi aliyordu. Olcuyu buraya tasiyinca ikisi de cozuluyor:
           bos satir bekler (damgasiz kalir), doldurulunca 📢 olarak gider.

           NOT: Bu kosul yalnizca izlenen alan TANIMLIYSA gecerli. Tanimli
           degilse ozellik eskiden oldugu gibi salt "yeni kayit" duyurusu
           olarak calisir — o davranis korunuyor. */
        $kosul = "(r.slack_notified_at IS NULL AND EXISTS ({$dolu}))
                  OR (r.slack_notified_at IS NOT NULL AND EXISTS (
                        SELECT 1 FROM cell_values cv
                        WHERE cv.record_id = r.id AND cv.field_id IN ({$ids})
                          AND cv.updated_at > r.slack_notified_at))";
    } else {
        $kosul = 'r.slack_notified_at IS NULL';
    }

    return bcc_fetch_all(
        "SELECT r.id, r.slack_notified_at, r.created_at,
                COALESCE(r.updated_by, r.created_by) AS actor_id
         FROM records r
         WHERE r.table_id = :table_id
           AND r.deleted_at IS NULL
           AND r.updated_at <= (NOW() - INTERVAL {$idleSeconds} SECOND)
           AND ({$kosul})
         ORDER BY r.updated_at ASC
         LIMIT {$limit}",
        array('table_id' => $tableId)
    );
}

function bcc_slack_changed_field_lines($record, $watchedFields, $teamId)
{
    /* Yeni kayitta damga NULL — o zaman kaydin dolu olan TUM izlenen alanlari
       listelenir. Guncellemede yalnizca son bildirimden SONRA yazilan hucreler. */
    $since = ($record['slack_notified_at'] !== null) ? $record['slack_notified_at'] : '1000-01-01 00:00:00';

    /* Harita kayit basina degil ekip basina bir kez cekiliyor: bosaltma tek
       turda 20 kayit isleyebiliyor, aksi halde ayni sorgu 20 kez donerdi. */
    static $usersCache = array();

    $teamId = (int) $teamId;
    if (!array_key_exists($teamId, $usersCache)) {
        $usersCache[$teamId] = bcc_team_users_by_id($teamId);
    }
    $usersById = $usersCache[$teamId];

    $lines = array();

    foreach ($watchedFields as $field) {
        if (count($lines) >= BCC_SLACK_BATCH_MAX_FIELDS) {
            break;
        }

        $cell = bcc_fetch_one(
            'SELECT value_text, value_number, value_date, value_json
             FROM cell_values
             WHERE record_id = :record_id AND field_id = :field_id AND updated_at > :since
             LIMIT 1',
            array('record_id' => $record['id'], 'field_id' => $field['id'], 'since' => $since)
        );

        if ($cell === false || $cell === null) {
            continue;
        }

        $display = cell_display_text($field['field_type'], $cell, $usersById, $field['options']);
        $markup = bcc_slack_shorten_value(
            bcc_slack_cell_value_markup($field['field_type'], $display),
            BCC_SLACK_BATCH_MAX_VALUE_CHARS
        );

        if ($markup === '') {
            continue;
        }

        /* Ad ve deger AYRI donuyor: ozet mesaji "hangi sutun -> hangi satirlar"
           listesini kurarken alan ADINA tek basina ihtiyac duyuyor. Ad HAM
           birakiliyor; Slack kacisi kullanildigi yerde yapiliyor. */
        $lines[] = array('ad' => $field['name'], 'deger' => $markup);
    }

    return $lines;
}

/* Silinen kayitlar — 2026-09-09.

   BEKLEME OLCUSU deleted_at, updated_at DEGIL (duzeltme 2026-09-09):
   her iki soft-delete yolu da updated_at'i bilerek koruyor
   (record_delete.php ve record_soft_delete.php: updated_at = updated_at),
   yani updated_at 'en son ne zaman DUZENLENDI' demek. Ona bakilinca aylar
   once duzenlenmis bir satiri silmek beklemeyi aninda dolmus sayiyordu:
   silme, ayni turdaki degisikliklerle BIRLESMEDEN kendi ayri mesajini
   atiyordu. Kullanici bunu 'silince direkt geliyor ama degisiklikler icin
   ana menuye donmek gerekiyor' diye bildirdi.
   Yalnizca DAHA ONCE duyurulmus bir kayit silindiginde haber veriliyor:
   hic duyurulmamis (ornegin yanlislikla eklenip hemen silinen bos) bir satir
   kanalda gurultu yaratmasin. Olcu ayni damga kolonu: damga silme anindan
   ONCEYSE, silinme henuz duyurulmamis demektir. */
function bcc_slack_deleted_records($tableId, $idleSeconds)
{
    $idleSeconds = (int) $idleSeconds;
    $limit = (int) BCC_SLACK_BATCH_MAX_RECORDS;

    return bcc_fetch_all(
        "SELECT r.id, r.slack_notified_at, r.created_at, r.deleted_at,
                COALESCE(r.deleted_by, r.updated_by, r.created_by) AS actor_id
         FROM records r
         WHERE r.table_id = :table_id
           AND r.deleted_at IS NOT NULL
           AND r.slack_notified_at IS NOT NULL
           AND r.slack_notified_at < r.deleted_at
           /* Bekleme SILME anindan olculur, son duzenlemeden degil. Aciklama
              fonksiyonun ustundeki yorumda. */
           AND r.deleted_at <= (NOW() - INTERVAL {$idleSeconds} SECOND)
         ORDER BY r.deleted_at ASC
         LIMIT {$limit}",
        array('table_id' => $tableId)
    );
}

/* Satir numarasi: gridin VARSAYILAN siralamasindaki sira (position, id).
   Kullanicinin ekraninda filtre/siralama varsa numaralar kayar — bu yuzden
   mesajda "varsayilan siralama" oldugu belirtiliyor. Silinen kayitlar
   numaralandirmaya girmiyor, cunku gridde de gorunmuyorlar. */
function bcc_slack_row_numbers($tableId, $recordIds)
{
    $ids = array();
    foreach ((array) $recordIds as $rid) {
        $rid = (int) $rid;
        if ($rid > 0) {
            $ids[$rid] = $rid;
        }
    }

    if (empty($ids)) {
        return array();
    }

    $rows = bcc_fetch_all(
        'SELECT r.id,
                (SELECT COUNT(*) + 1 FROM records r2
                  WHERE r2.table_id = r.table_id AND r2.deleted_at IS NULL
                    AND (r2.position < r.position
                         OR (r2.position = r.position AND r2.id < r.id))) AS satir_no
         FROM records r
         WHERE r.id IN (' . implode(',', $ids) . ')'
    );

    $map = array();
    foreach ($rows as $row) {
        $map[(int) $row['id']] = (int) $row['satir_no'];
    }

    return $map;
}

/* TEK bildirim — 2026-09-09.
   Onceden her kayit ayri bir mesajdi; 50 satir duzenleyen biri kanala 50 mesaj
   dusuruyordu. Artik bir bosaltmadaki butun degisiklikler tek mesajda
   toplaniyor:
     - En ustte OZET: kim, kac degisiklik, ve HANGI SUTUNUN HANGI SATIRLARI
       degisti (sutun adi -> satir numaralari). Bu kisim hep gorunur.
     - Altinda renk kodlu bolumler: yesil eklenen, mavi degisen, kirmizi
       silinen. Her bolum ayri bir attachment oldugu icin Slack her birini
       kendi renk seridiyle ciziyor — ama hepsi TEK mesaj.
     - Detay (alan degerleri) en altta; Slack uzun karti kendi "Show more"
       baglantisiyla katliyor, yani "tiklayinca hepsini gor" davranisi ek
       altyapi olmadan calisiyor. */
function bcc_slack_build_batch_message($tableName, $kimler, $gruplar, $link)
{
    $toplam = count($gruplar['yeni']) + count($gruplar['guncel']) + count($gruplar['silinen']);

    if (count($kimler) === 1) {
        $kim = '*' . bcc_slack_escape($kimler[0]) . '*';
    } elseif (count($kimler) > 1) {
        $kim = '*' . bcc_slack_escape($kimler[0]) . '* ve ' . (count($kimler) - 1) . ' kişi daha';
    } else {
        $kim = 'Birisi';
    }

    $fallback = "\xF0\x9F\x94\x94 " . (count($kimler) === 1 ? $kimler[0] : 'Ekip')
        . ', ' . $tableName . ' tablosunda ' . $toplam . ' değişiklik yaptı';

    /* --- 1. bolum: kim, ne kadar, hangi sutun hangi satirlar --- */
    $ozetBloklar = array();

    $ozetBloklar[] = array(
        'type' => 'context',
        'elements' => array(array(
            'type' => 'mrkdwn',
            'text' => "\xF0\x9F\x94\x94  " . $kim . '  ·  _' . bcc_slack_escape($tableName)
                . '_ tablosunda  ·  *' . $toplam . ' değişiklik*',
        )),
    );

    /* Sutun -> satir numaralari. Mesajin ASIL icerigi bu: hangi sutunda hangi
       satirlar degisti. Hucre DEGERLERI bilerek yazilmiyor — kullanici
       "aciklama az olsun, satirlarla sutunlar yazsin" dedi; ayrinti icin
       mesajin altindaki baglanti zaten tabloyu aciyor. */
    $sutunlar = array();
    foreach (array('yeni', 'guncel') as $tur) {
        foreach ($gruplar[$tur] as $kayit) {
            foreach ($kayit['ciftler'] as $cift) {
                $ad = $cift['ad'];
                if (!isset($sutunlar[$ad])) {
                    $sutunlar[$ad] = array();
                }
                if ($kayit['no'] > 0) {
                    $sutunlar[$ad][] = $kayit['no'];
                }
            }
        }
    }

    if (!empty($sutunlar)) {
        $satirlar = array();
        foreach ($sutunlar as $ad => $nolar) {
            $nolar = array_values(array_unique($nolar));
            sort($nolar);

            $fazla = count($nolar) - BCC_SLACK_BATCH_MAX_ROWNOS;
            if ($fazla > 0) {
                $nolar = array_slice($nolar, 0, BCC_SLACK_BATCH_MAX_ROWNOS);
            }

            $etiket = empty($nolar)
                ? '—'
                : ('satır ' . implode(', ', $nolar) . ($fazla > 0 ? ' +' . $fazla : ''));

            $satirlar[] = '*' . bcc_slack_escape($ad) . '*  →  ' . $etiket;
        }

        /* Slack bir section blogunda en fazla 3000 karakter tasiyor; asilirsa
           MESAJIN TAMAMINI reddediyor (invalid_blocks). Elli kayitlik bir
           turda cok sayida izlenen sutun birikebildigi icin liste burada
           kirpiliyor — yoksa mesaj hic gitmezdi. */
        $ozetMetni = implode("\n", $satirlar);
        if (mb_strlen($ozetMetni, 'UTF-8') > 2600) {
            $kesik = array();
            $uzunluk = 0;
            foreach ($satirlar as $satir) {
                if ($uzunluk + mb_strlen($satir, 'UTF-8') + 1 > 2600) {
                    break;
                }
                $kesik[] = $satir;
                $uzunluk += mb_strlen($satir, 'UTF-8') + 1;
            }
            $kalanSutun = count($satirlar) - count($kesik);
            $ozetMetni = implode("\n", $kesik) . "\n_+" . $kalanSutun . ' sütun daha_';
        }

        $ozetBloklar[] = array(
            'type' => 'section',
            'text' => array('type' => 'mrkdwn', 'text' => $ozetMetni),
        );
    }

    $attachments = array(array(
        'color' => BCC_SLACK_COLOR_SUMMARY,
        'fallback' => $fallback,
        'blocks' => $ozetBloklar,
    ));

    /* --- Renk kodlu bolumler: her biri TEK satir, yalnizca "#no baslik" --- */
    $bolumler = array(
        array('anahtar' => 'yeni', 'renk' => BCC_SLACK_COLOR_NEW,
              'etiket' => "\xF0\x9F\x9F\xA2 *Yeni*"),
        array('anahtar' => 'guncel', 'renk' => BCC_SLACK_COLOR_UPDATE,
              'etiket' => "\xF0\x9F\x94\xB5 *Düzenlenen*"),
        array('anahtar' => 'silinen', 'renk' => BCC_SLACK_COLOR_DELETE,
              'etiket' => "\xF0\x9F\x94\xB4 *Silinen*"),
    );

    foreach ($bolumler as $bolum) {
        $kayitlar = $gruplar[$bolum['anahtar']];
        if (empty($kayitlar)) {
            continue;
        }

        $parcalar = array();
        $gosterilen = 0;

        foreach ($kayitlar as $kayit) {
            if ($gosterilen >= BCC_SLACK_BATCH_MAX_DETAIL) {
                break;
            }

            $parcalar[] = (($kayit['no'] > 0) ? ('*#' . $kayit['no'] . '* ') : '')
                . bcc_slack_escape($kayit['baslik']);
            $gosterilen++;
        }

        $kalan = count($kayitlar) - $gosterilen;
        if ($kalan > 0) {
            $parcalar[] = '_+' . $kalan . ' satır_';
        }

        $attachments[] = array(
            'color' => $bolum['renk'],
            'fallback' => $fallback,
            'blocks' => array(array(
                'type' => 'section',
                'text' => array(
                    'type' => 'mrkdwn',
                    'text' => $bolum['etiket'] . ' (' . count($kayitlar) . ')   '
                        . implode(',   ', $parcalar),
                ),
            )),
        );
    }

    /* Baglanti EN ALTTA ve buton degil duz link: Slack, incoming webhook'tan
       gelen mesajdaki "actions" butonunun yanina aciklamasiz bir uyari ucgeni
       koyuyor (etkilesim tanimli bir Slack App olmadigi icin). */
    $sonEk = count($attachments) - 1;
    $attachments[$sonEk]['blocks'][] = array(
        'type' => 'section',
        'text' => array(
            'type' => 'mrkdwn',
            'text' => "\xF0\x9F\x94\x8E  <" . $link . '|*Tabloyu aç*>',
        ),
    );

    return array('text' => $fallback, 'opts' => array('attachments' => $attachments));
}

function bcc_slack_flush_table($tableId, $idleSeconds = null)
{
    try {
        $tableId = (int) $tableId;
        if ($tableId < 1) {
            return 0;
        }

        if ($idleSeconds === null) {
            $idleSeconds = BCC_SLACK_BATCH_IDLE_SECONDS;
        }

        $tableRow = bcc_fetch_one(
            'SELECT t.name AS table_name, b.id AS base_id, b.team_id
             FROM tables_meta t
             INNER JOIN bases b ON b.id = t.base_id
             WHERE t.id = :table_id AND b.deleted_at IS NULL LIMIT 1',
            array('table_id' => $tableId)
        );
        if (!$tableRow) {
            return 0;
        }

        $watchedIds = bcc_slack_watched_field_ids($tableId);

        $pending = bcc_slack_pending_records($tableId, $watchedIds, $idleSeconds);
        $silinenler = bcc_slack_deleted_records($tableId, $idleSeconds);

        if (empty($pending) && empty($silinenler)) {
            return 0;
        }

        $watchedFields = array();
        if (!empty($watchedIds)) {
            $watchedFields = bcc_fetch_all(
                'SELECT id, name, field_type, options FROM fields
                 WHERE table_id = :table_id AND id IN (' . implode(',', array_map('intval', $watchedIds)) . ')
                 ORDER BY position, id',
                array('table_id' => $tableId)
            );
        }

        /* Kayit/satir bildirimleri GRID'e gitmeli: kullanici degisikligi orada
           yapti, linke basinca duzenledigi ekrana donmeli. interface.php base
           gezgini — bir ust katman, aradigi satiri gostermiyor. Yeni tablo ve
           yeni alan bildirimleri zaten grid.php kullaniyordu; interface.php
           kalan tek istisnaydi. */
        $link = bcc_slack_app_url('/grid.php?table_id=' . (int) $tableId);

        $tumIdler = array_merge(
            array_column($pending, 'id'),
            array_column($silinenler, 'id')
        );
        $satirNolari = bcc_slack_row_numbers($tableId, $tumIdler);

        /* Yonlendirme kurallari kaydin alan DEGERINE gore farkli kanala
           dusurebiliyor. Toplu mesajda bu bilgi kaybolmasin diye kayitlar once
           gidecekleri webhook'a gore grupleniyor: iki farkli kanala giden
           kayitlar iki ayri mesaj olur, ayni kanala gidenlerin hepsi TEK
           mesajda birlesir. */
        $kovalar = array();
        $damgalanacak = array();

        foreach ($pending as $record) {
            $isNew = ($record['slack_notified_at'] === null);

            $ciftler = empty($watchedFields)
                ? array()
                : bcc_slack_changed_field_lines($record, $watchedFields, $tableRow['team_id']);

            /* Gosterilecek hicbir sey yoksa mesaja koyma — ama damgayi ilerlet
               ki ayni kayit her turda taranmasin. Yeni kayitta bu dal ancak
               izlenen alan TANIMLIYSA calisir; tanimli degilse "yeni kayit"
               duyurusu alan listesi olmadan da gitmelidir (eski davranis). */
            if (empty($ciftler) && (!$isNew || !empty($watchedFields))) {
                $damgalanacak[] = $record['id'];
                continue;
            }

            $webhook = bcc_find_slack_webhook($tableId, $tableRow['team_id'], $record['id']);
            if (!$webhook) {
                /* Gidecek kanal yok: damgala ki webhook sonradan eklenince
                   birikmis yigin dusmesin (2026-09-08'de verilmis karar). */
                $damgalanacak[] = $record['id'];
                continue;
            }

            $anahtar = (int) $webhook['id'];
            if (!isset($kovalar[$anahtar])) {
                $kovalar[$anahtar] = array(
                    'webhook' => $webhook,
                    'kimler' => array(),
                    'idler' => array(),
                    'gruplar' => array('yeni' => array(), 'guncel' => array(), 'silinen' => array()),
                );
            }

            $actorName = bcc_actor_name_by_id($record['actor_id']);
            if ($actorName !== '' && !in_array($actorName, $kovalar[$anahtar]['kimler'], true)) {
                $kovalar[$anahtar]['kimler'][] = $actorName;
            }

            $kovalar[$anahtar]['gruplar'][$isNew ? 'yeni' : 'guncel'][] = array(
                'no' => isset($satirNolari[(int) $record['id']]) ? $satirNolari[(int) $record['id']] : 0,
                'baslik' => bcc_slack_shorten_value(
                    bcc_slack_record_title($tableId, $record['id'], $tableRow['team_id']),
                    BCC_SLACK_BATCH_MAX_TITLE_CHARS
                ),
                'ciftler' => $ciftler,
            );
            $kovalar[$anahtar]['idler'][] = $record['id'];
        }

        foreach ($silinenler as $record) {
            $webhook = bcc_find_slack_webhook($tableId, $tableRow['team_id'], $record['id']);
            if (!$webhook) {
                $damgalanacak[] = $record['id'];
                continue;
            }

            $anahtar = (int) $webhook['id'];
            if (!isset($kovalar[$anahtar])) {
                $kovalar[$anahtar] = array(
                    'webhook' => $webhook,
                    'kimler' => array(),
                    'idler' => array(),
                    'gruplar' => array('yeni' => array(), 'guncel' => array(), 'silinen' => array()),
                );
            }

            $actorName = bcc_actor_name_by_id($record['actor_id']);
            if ($actorName !== '' && !in_array($actorName, $kovalar[$anahtar]['kimler'], true)) {
                $kovalar[$anahtar]['kimler'][] = $actorName;
            }

            $kovalar[$anahtar]['gruplar']['silinen'][] = array(
                'no' => 0,
                'baslik' => bcc_slack_shorten_value(
                    bcc_slack_record_title($tableId, $record['id'], $tableRow['team_id']),
                    BCC_SLACK_BATCH_MAX_TITLE_CHARS
                ),
                'ciftler' => array(),
            );
            $kovalar[$anahtar]['idler'][] = $record['id'];
        }

        if (!empty($damgalanacak)) {
            bcc_slack_mark_records_notified($damgalanacak);
        }

        /* Bu fonksiyon sayfa yuklemelerinden de cagriliyor ve her gonderim
           Slack'e senkron bir HTTP istegi. Yavas/olu bir webhook sayfayi
           kilitlemesin diye toplam sure butcesi: butce dolunca kalan kovalar
           bir sonraki tetiklemeye birakilir (damga yazilmadigi icin
           kaybolmazlar). */
        $deadline = microtime(true) + BCC_SLACK_BATCH_TIME_BUDGET;
        $sent = 0;

        foreach ($kovalar as $kova) {
            if ($sent > 0 && microtime(true) >= $deadline) {
                break;
            }

            $mesaj = bcc_slack_build_batch_message(
                $tableRow['table_name'],
                $kova['kimler'],
                $kova['gruplar'],
                $link
            );

            $opts = $mesaj['opts'];
            $opts['webhook'] = $kova['webhook'];

            $gonderildi = bcc_slack_dispatch(
                $tableId,
                $tableRow['team_id'],
                null,
                bcc_slack_truncate($mesaj['text'], BCC_SLACK_MAX_TEXT),
                'table',
                $tableId,
                $opts
            );

            /* Damga YALNIZCA gonderim basariliysa yaziliyor. Eskiden donus
               degeri yok sayiliyordu: Slack 5xx dondugunde, webhook iptal
               edildiginde ya da 5 saniyelik zaman asimi dolduğunda kayitlar
               "duyuruldu" diye damgalaniyor ve o degisiklikler bir daha ASLA
               haber verilemiyordu. Damgasiz kalirlarsa bir sonraki tetikleme
               yeniden dener. */
            if (!$gonderildi) {
                continue;
            }

            bcc_slack_mark_records_notified($kova['idler']);
            $sent++;
        }

        return $sent;
    } catch (Throwable $e) {

        return 0;
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
