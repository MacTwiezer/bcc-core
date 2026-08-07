<?php
// Slack otomasyonu (Faz 1: webhook altyapısı + tek kanal gönderimi — TAMAM.
// Faz 2: curl doğrulaması + takım-geneli webhook — TAMAM).
// GÜVENLİK: webhook_url hiçbir yerde (log_audit, response, hata mesajı) tam olarak
// yazılmaz/loglanmaz. Gönderim tamamen "arka plan yan etkisi" olarak çalışır —
// bcc_notify_slack_new_record() hiçbir zaman istisna fırlatmaz, çağıran tarafın
// (record_add.php / grid.php) kayıt ekleme akışını ASLA engellemez.

// Bir tablo için geçerli, aktif webhook'u döndürür. Öncelik sırası:
// (1) $recordId verilmişse — slack_routing_rules'ta bu tabloya ait, aktif
//     kuralları position sırasına göre dener; kaydın kuralın alanındaki
//     DEĞERİ (cell_values.value_text) kuralın beklediği değerle eşleşirse
//     (operator'e göre) o kuralın webhook'u döner. İLK EŞLEŞEN kazanır
//     (Airtable'ın "Conditional groups" modeliyle aynı ilke) — sonraki
//     kurallar hiç değerlendirilmez.
// (2) Hiçbir kural yoksa/eşleşmezse (ya da $recordId hiç verilmemişse) —
//     ESKİ davranışa aynen düşülür: tablo-özel bir webhook varsa o, yoksa
//     takım-geneli (table_id NULL) webhook. table_id NULL olan satırlar
//     takım-geneli webhook'u temsil eder (o takımın TÜM tablolarında
//     tetiklenir) — DDL gerekmedi, table_id zaten nullable'dı.
// $recordId opsiyonel (varsayılan null) — geriye dönük uyumlu, tek çağıran
// yer (bcc_notify_slack_new_record) zaten kendi record id'sini biliyor.
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

    // Bulunan gerçek bug: bir tabloda BİRDEN FAZLA tablo-özel webhook varken
    // (slack_settings.php artık bunu bir liste olarak destekliyor, ör. marka
    // başına bir kanal) bu sorgu, aralarından hangisinin seçileceğini belirleyen
    // bir ikincil ORDER BY'a sahip DEĞİLDİ — yalnızca "(table_id IS NULL) ASC" ile
    // tablo-özel/ekip-geneli ayrımı yapılıyordu, aynı tabloya ait birden fazla satır
    // arasında MySQL'in garantisiz satır sırasına güveniliyordu. slack_settings.php'nin
    // kendi metni "hiç kural yoksa listedeki İLK aktif webhook kullanılır" diyor
    // (o liste id ASC ile gösteriliyor) — "sw.id ASC" eklenerek bu garanti SQL'de
    // AÇIKÇA sağlanıyor, önceden yalnızca tesadüfen (satır ekleme sırasıyla) tutarlıydı.
    $row = bcc_fetch_one(
        'SELECT id, webhook_url, channel_name, table_id FROM slack_webhooks
         WHERE is_active = 1 AND (table_id = :table_id OR (table_id IS NULL AND team_id = :team_id))
         ORDER BY (table_id IS NULL) ASC, id ASC
         LIMIT 1',
        array('table_id' => $tableId, 'team_id' => $teamId)
    );

    return $row !== false ? $row : null;
}

// Slack mrkdwn'ı bozabilecek karakterleri kaçırır (Slack'in kendi önerdiği kural —
// HTML kaçırma değildir, XSS'le ilgisi yok, yalnızca mesaj biçimlendirmesi bozulmasın diye).
function bcc_slack_escape($text)
{
    return str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), (string) $text);
}

// Ham HTTP POST — curl olmadan (extension yüklenmemişse) sessizce false döner,
// böylece curl her zaman mevcut olmak zorunda değildir (bkz. PROJE-DURUM.md notu).
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
    // Kısa timeout: bu çağrı kayıt ekleme isteğinin İÇİNDE, senkron çalışır —
    // Slack yavaş/erişilemez olsa bile kullanıcı uzun süre beklemesin.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    return ($errno === 0 && $httpCode >= 200 && $httpCode < 300);
}

// Yeni kayıt eklendiğinde ilgili tabloya bağlı Slack webhook'una (varsa) bildirim
// gönderir. record_add.php (AJAX) VE grid.php (JS'siz form fallback) AYNI
// fonksiyonu çağırır — ikinci bir tetikleme mekanizması yazılmaz. Webhook yoksa,
// tablo/alan bulunamazsa ya da gönderim başarısız olursa bu fonksiyon SESSİZCE
// döner; hiçbir durumda kayıt ekleme akışını etkilemez (çağıran taraf dönüş
// değerini kontrol etmek zorunda değildir).
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

        $webhook = bcc_find_slack_webhook($tableId, $tableRow['team_id'], $recordId);
        if (!$webhook) {
            return;
        }

        // Mesajın "başlığı": birincil alanın (position/id'ye göre ilk alan) değeri —
        // grid.php/table_fields.php'nin her yerde kullandığı AYNI "primary field" kavramı.
        $primaryField = bcc_fetch_one(
            'SELECT id, field_type, options FROM fields WHERE table_id = :table_id ORDER BY position, id LIMIT 1',
            array('table_id' => $tableId)
        );

        // Kayıtlar boş oluşturulup hücreler sonradan doldurulduğu için (grid'in
        // "önce ekle, sonra düzenle" akışı) bu fallback sık görülür — makul,
        // kullanıcıya anlamlı bir metin.
        $primaryDisplay = '(başlıksız kayıt)';
        if ($primaryField) {
            $cellRow = bcc_fetch_one(
                'SELECT value_text, value_number, value_date, value_json FROM cell_values WHERE record_id = :record_id AND field_id = :field_id LIMIT 1',
                array('record_id' => $recordId, 'field_id' => $primaryField['id'])
            );
            $cellRow = $cellRow !== false ? $cellRow : null;

            $usersById = ($primaryField['field_type'] === 'user') ? bcc_team_users_by_id($tableRow['team_id']) : array();
            $display = cell_display_text($primaryField['field_type'], $cellRow, $usersById, $primaryField['options']);
            if ($display !== '') {
                $primaryDisplay = $display;
            }
        }

        // interface.php'ye (grid.php'ye DEĞİL) bağlanır — MADDE 1'de doğrulandığı
        // gibi bu sayfa rolden bağımsız salt-okunur, viewer da açabilir; grid.php
        // require_role('editor') istediği için viewer bu linkte 403 alırdı.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $link = $scheme . '://' . $host . '/interface.php?base_id=' . (int) $tableRow['base_id'] . '&table_id=' . (int) $tableId;

        $text = "📢 *" . bcc_slack_escape($tableRow['table_name']) . "* tablosuna yeni bir duyuru eklendi\n*"
            . bcc_slack_escape($primaryDisplay) . "*\n";
        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Ekleyen: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Duyuruyu görüntüle>';

        $ok = bcc_slack_send_webhook($webhook['webhook_url'], $text);

        // webhook_url burada da YOK — yalnızca sonuç (başarılı/başarısız) loglanır.
        log_audit($ok ? 'slack.notify_sent' : 'slack.notify_failed', 'record', $recordId, array('table_id' => $tableId), $tableRow['team_id']);
    } catch (Throwable $e) {
        // Sessiz devam — kayıt ekleme akışı bu fonksiyonun başarısından etkilenmez.
    }
}
