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
//     (OpsFlow'un "Conditional groups" modeliyle aynı ilke) — sonraki
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

    // Son gönderimin HTTP durum kodu — YALNIZCA sayı (200/404/...). Test
    // akışının audit kaydına bunu yazabilmesi için saklanır: "bağlantı çalıştı
    // mı" sorusunun kanıtı durum kodudur, URL'in kendisi DEĞİL. Bağlantı hiç
    // kurulamadıysa (DNS/timeout) curl 0 döndürür, o da anlamlı bir sinyaldir.
    $GLOBALS['BCC_SLACK_LAST_STATUS'] = $httpCode;

    return ($errno === 0 && $httpCode >= 200 && $httpCode < 300);
}

// Uygulamanın kendi adresini üretir (Slack mesajlarındaki "görüntüle" linki).
// Üç bildirim fonksiyonu da bunu çağırır — scheme/host hesabı tek yerde.
function bcc_slack_app_url($path)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    return $scheme . '://' . $host . $path;
}

// ---------------------------------------------------------------------------
// ORTAK GÖNDERİM ÇEKİRDEĞİ
// ---------------------------------------------------------------------------
// "Webhook'u bul -> gönder -> sonucu logla -> ASLA istisna sızdırma" zinciri.
// Üç olay (yeni kayıt / yeni tablo / yeni alan) de bunu çağırır; her biri
// yalnızca KENDİ metnini üretir. Bu çekirdek çıkarılmadan önce zincir
// bcc_notify_slack_new_record()'un içine gömülüydü ve yeni bir olay eklemek
// onu kopyalamayı gerektirirdi.
//
// $tableId  : yönlendirme kuralları bu tabloya göre çözülür
// $teamId   : ekip-geneli (table_id NULL) webhook'a düşüş için
// $recordId : yalnızca kayıt olaylarında; null ise koşullu kurallar atlanır ve
//             doğrudan tablo-özel/ekip-geneli webhook'a düşülür (yeni tablo ve
//             yeni alan olaylarında doğru davranış — ortada bir kayıt yok).
//
// Dönüş: gönderim yapıldıysa true, webhook yoksa/hata olduysa false. Çağıran
// tarafın bu değeri kontrol etmesi GEREKMEZ.
function bcc_slack_dispatch($tableId, $teamId, $recordId, $text, $entityType, $entityId)
{
    try {
        $webhook = bcc_find_slack_webhook($tableId, $teamId, $recordId);
        if (!$webhook) {
            return false;
        }

        $ok = bcc_slack_send_webhook($webhook['webhook_url'], $text);

        // webhook_url burada YOK — yalnızca sonuç (başarılı/başarısız) loglanır.
        log_audit(
            $ok ? 'slack.notify_sent' : 'slack.notify_failed',
            $entityType,
            $entityId,
            array('table_id' => $tableId),
            $teamId
        );

        return $ok;
    } catch (Throwable $e) {
        // Sessiz devam — bildirim bir YAN ETKİDİR, asıl işlemi (kayıt/tablo/alan
        // oluşturma) hiçbir koşulda engellemez veya geri almaz.
        return false;
    }
}

// Yeni kayıt eklendiğinde ilgili tabloya bağlı Slack webhook'una (varsa) bildirim
// gönderir. record_add.php (AJAX), grid.php (JS'siz form fallback),
// record_duplicate.php ve form_submit.php AYNI fonksiyonu çağırır — ikinci bir
// tetikleme mekanizması yazılmaz. Webhook yoksa, tablo/alan bulunamazsa ya da
// gönderim başarısız olursa bu fonksiyon SESSİZCE döner; hiçbir durumda kayıt
// ekleme akışını etkilemez (çağıran taraf dönüş değerini kontrol etmek zorunda
// değildir).
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
        $link = bcc_slack_app_url('/interface.php?base_id=' . (int) $tableRow['base_id'] . '&table_id=' . (int) $tableId);

        $text = "📢 *" . bcc_slack_escape($tableRow['table_name']) . "* tablosuna yeni bir duyuru eklendi\n*"
            . bcc_slack_escape($primaryDisplay) . "*\n";
        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Ekleyen: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Duyuruyu görüntüle>';

        bcc_slack_dispatch($tableId, $tableRow['team_id'], $recordId, $text, 'record', $recordId);
    } catch (Throwable $e) {
        // Sessiz devam — kayıt ekleme akışı bu fonksiyonun başarısından etkilenmez.
    }
}

// Yeni TABLO oluşturulduğunda. Tek çağıran: base_tables.php — uygulamadaki tek
// tablo oluşturma yolu (tables_meta'ya ekleme yapan başka dosya yok; bunu
// scripts/_verify_slack_integration.php denetliyor).
//
// Yönlendirme kuralları BİLEREK atlanır ($recordId = null): kurallar bir kaydın
// hücre DEĞERİNE bakar, yeni tabloda ne kayıt ne kural vardır. Böylece bildirim
// doğal olarak tablo-özel (yoksa) -> EKİP-GENELİ webhook'a düşer; yeni bir
// tablonun kendi webhook'u olamayacağı için pratikte hedef ekip-geneli kanaldır.
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
        // Sessiz devam.
    }
}

// Yeni ALAN (sütun) oluşturulduğunda. Tek çağıran: bcc_create_field()
// (src/schema.php) — table_fields.php'nin tam sayfa formu VE
// api/field_create.php'nin grid "+" popup'ı ikisi de o fonksiyondan geçtiği
// için tek hook her iki yolu da kapsar, ikinci bir tetikleyici yazılmaz.
//
// $recordId = null (yeni tablo olayıyla AYNI gerekçe: ortada kayıt yok).
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
        // Sessiz devam.
    }
}

// "Bağlantıyı test et" — slack_settings.php'deki test butonu. Kayıtlı bir
// webhook satırına deneme mesajı gönderir.
//
// Diğer bildirimlerden İKİ FARKI var, ikisi de kasıtlı:
//   1. Sessiz DEĞİL — dönüş değeri kullanıcıya gösterilir ("başarılı/başarısız").
//      Testin amacı zaten sonucu bildirmek.
//   2. bcc_find_slack_webhook() ile ÇÖZÜLMEZ — kullanıcının test etmek istediği
//      SATIR doğrudan id ile alınır. Aksi hâlde "hangi webhook'u test ettim?"
//      sorusu yönlendirme kurallarına bağlı olurdu ve pasif (is_active = 0) bir
//      satır hiç test edilemezdi.
//
// Dönüş: array('ok' => bool, 'error' => string|null)
function bcc_slack_send_test($webhookId, $teamId, $userFullName = null)
{
    $row = bcc_fetch_one(
        'SELECT id, webhook_url, channel_name, table_id FROM slack_webhooks
         WHERE id = :id AND team_id = :team_id LIMIT 1',
        array('id' => $webhookId, 'team_id' => $teamId)
    );

    if ($row === false || $row === null) {
        // Ekip kontrolü sorgunun İÇİNDE — başka bir ekibin webhook'u burada
        // "bulunamadı" olur, varlığı sızmaz (KVKK izolasyonu deseni).
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

    // Audit detayında YALNIZCA satır id'si ve HTTP durum kodu var —
    // webhook_url, token ya da mesaj gövdesi ASLA yazılmaz.
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
