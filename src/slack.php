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

// ---------------------------------------------------------------------------
// ZENGİN METİN (long_text) -> SLACK mrkdwn
// ---------------------------------------------------------------------------
// SORUN (kullanıcı bildirdi, ekran görüntüsüyle): "Notlar" alanı HTML saklıyor
// (bkz. bcc_sanitize_rich_text, src/schema.php). Bu değer Slack'e olduğu gibi
// gidince mesajda ham etiketler görünüyordu:
//     Notlar: — → <i>sadjo</i><a href="http://...">psj</a><b>poadjas</b>
// ve <br>'ler satır sonuna çevrilmediği için 40 satırlık bir not tek bir
// paragraf hâlinde akıyordu.
//
// ⚠️ DÖNÜŞ DEĞERİ ZATEN SLACK'E HAZIRDIR — çağıran taraf bunu bir daha
// bcc_slack_escape()'ten GEÇİRMEMELİDİR. Geçirirse ürettiğimiz <url|metin>
// bağlantıları &lt;url|metin&gt; olur ve yine ham metin görünür. Bu yüzden
// aşağıdaki bcc_slack_cell_value_markup() tek giriş kapısı olarak var:
// alan tipine göre doğru olanı seçer, çağıran karar vermez.
//
// ETİKET KAPSAMI, sanitizer'ın whitelist'iyle BİREBİR aynı (strong/b, em/i,
// br, a[href], span[style]) — orada izin verilmeyen bir etiket buraya zaten
// gelemez, o yüzden <p>/<li>/<u> gibi karşılıklar YAZILMADI (ölü kod olurdu).
//
// SIRA ÖNEMLİ: bağlantılar önce yer tutucuya alınır, çünkü ürettiğimiz
// <http://...|metin> parçası strip_tags() için bir HTML etiketi gibi görünür
// ve silinirdi (ölçüldü).
function bcc_slack_text_from_rich($html)
{
    $s = (string) $html;

    // 1) Satır sonları — <br> Slack'te gerçek satır sonu olur.
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);

    // 2) Bağlantılar -> yer tutucu. href whitelist'i bcc_cell_link_href ile
    //    AYNI ilke: yalnızca http/https/mailto/tel. Diğerlerinde (javascript:
    //    dahil) yalnızca metin bırakılır, bağlantı üretilmez.
    $links = array();
    // ⚠️ YER TUTUCU DÜZ ASCII OLMAK ZORUNDA. İlk sürüm "\x00L0\x00" kullanıyordu
    // ve bağlantılar kayboluyordu (test ile yakalandı): PHP'nin strip_tags()
    // fonksiyonu çıktıdan NUL baytlarını SİLİYOR, dolayısıyla 7. adımdaki
    // strtr() eşleşecek bir anahtar bulamıyordu.
    // İşaretçi her çağrıda RASTGELE: kullanıcının notunda tesadüfen aynı
    // metnin geçip yanlış bir bağlantıya dönüşmesi imkânsız olsun.
    $marker = 'BCCLNK' . bin2hex(random_bytes(4));
    $s = preg_replace_callback(
        '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
        function ($m) use (&$links, $marker) {
            $href = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));

            if (!preg_match('#^(https?|mailto|tel):#i', $href)) {
                return $text;
            }

            // Slack'in <url|metin> sözdiziminde | ve > ayraçtır — URL'de
            // geçerlerse mesajı bozarlar.
            $href = str_replace(array('<', '>', '|'), '', $href);
            $label = ($text !== '') ? bcc_slack_escape($text) : $href;

            $key = '{{' . $marker . count($links) . '}}';
            $links[$key] = '<' . $href . '|' . $label . '>';

            return $key;
        },
        $s
    );

    // 3) Biçimlendirme. Slack: *kalın*, _italik_.
    $s = preg_replace('#</?(?:b|strong)\b[^>]*>#i', '*', $s);
    $s = preg_replace('#</?(?:i|em)\b[^>]*>#i', '_', $s);

    // 4) Kalan her şey (span[style] dahil) — metni koru, etiketi at.
    $s = strip_tags($s);

    // 5) Entity'ler (&amp; &nbsp; &#39; ...) gerçek karakterlere. Slack'e
    //    HTML gitmiyor, dolayısıyla entity'nin orada bir anlamı yok.
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = str_replace("\xC2\xA0", ' ', $s); // &nbsp; -> normal boşluk

    // 6) ŞİMDİ kaçırılır (yer tutucular hâlâ yerinde, bağlantılar korunur).
    $s = bcc_slack_escape($s);

    // 7) Yer tutucular -> gerçek Slack bağlantıları.
    if (!empty($links)) {
        $s = strtr($s, $links);
    }

    // 8) Üç ve daha fazla ardışık boş satır iki satıra iner — editörde arka
    //    arkaya basılan <br><br><br> Slack'te devasa boşluk bırakıyordu.
    $s = preg_replace("/\n{3,}/", "\n\n", $s);

    return trim($s);
}

// Bir hücrenin GÖRÜNEN değerini Slack'e hazır metne çevirir.
//
// TEK GİRİŞ KAPISI: çağıran "bu alan zengin metin mi" diye düşünmez, sadece
// alan tipini verir. Bu ayrım çağıranlara bırakılsaydı biri unutur ve o alan
// ya ham HTML ya çift kaçırılmış metin basardı.
function bcc_slack_cell_value_markup($fieldType, $display)
{
    if ((string) $display === '') {
        return '';
    }

    return ($fieldType === 'long_text')
        ? bcc_slack_text_from_rich($display)
        : bcc_slack_escape($display);
}

// Slack'in tek mesaj için pratik metin sınırı ~40.000 karakter; üstünü kendisi
// kesiyor. 35.000 güvenlik payıyla seçildi (başlık, alan adı, "Değiştiren",
// bağlantı gibi ek metinler bu bütçenin İÇİNDEN harcanıyor).
define('BCC_SLACK_MAX_TEXT', 35000);

// Kırpma — SON ÇARE.
//
// ⚠️ ESKİDEN SABİT 700 KARAKTERDİ ve kullanıcı bunu bildirdi: normal
// uzunluktaki notların sonunda "(kısaltıldı)" görünüyordu. Canlı veride
// ölçüldü: 329 zengin metin hücresinin 69'u 700'ü aşıyor, en uzunu 20.004 —
// yani 700 gerçek verinin beşte birini kesiyordu.
//
// Sınır TAMAMEN kaldırılamaz: "önce" ve "sonra" değerlerinin İKİSİ birden aynı
// mesaja giriyor ve her biri 20.000'e kadar çıkabiliyor (bcc_sanitize_rich_text
// üst sınırı) — toplam 40.000, tam da Slack'in kestiği yer. Sabit bir tavan
// yerine bcc_slack_fit_pair() mesajın GERÇEK bütçesini hesaplıyor; pratikte
// (20.000 + kısa bir eski değer) rahatça sığıyor ve hiç kırpma olmuyor.
function bcc_slack_truncate($text, $limit)
{
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '… _(kısaltıldı)_';
}

// İki değeri (önce/sonra) verilen bütçeye sığdırır.
//
// Sığıyorsa HİÇBİR ŞEY YAPMAZ — kırpma işareti yalnızca gerçekten mecbur
// kalınca çıksın. Sığmıyorsa önce UZUN olandan kırpar: kısa olan (çoğu zaman
// "—" ya da bir cümlelik eski değer) bütçeyi paylaşmak zorunda kalmasın.
function bcc_slack_fit_pair($a, $b, $budget)
{
    $la = mb_strlen($a, 'UTF-8');
    $lb = mb_strlen($b, 'UTF-8');

    if ($budget < 200) {
        $budget = 200; // patolojik durum: yine de anlamlı bir şey basalım
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

    // İkisi de yarıdan uzun — eşit paylaşım.
    return array(bcc_slack_truncate($a, $half), bcc_slack_truncate($b, $budget - $half));
}

// Uygulamanın kendi adresini üretir (Slack mesajlarındaki "görüntüle" linki).
// Dört bildirim fonksiyonu da bunu çağırır — adres hesabı tek yerde.
//
// ⚠️ ESKİDEN scheme + $_SERVER['HTTP_HOST'] ile KENDİ hesabını yapıyordu ve
// config/app.php'deki $APP_BASE_URL'i hiç sormuyordu. İki sonucu vardı:
//   1. Ayar doluyken bile (bu kurulumda dolu) Slack linkleri ondan değil,
//      isteğin Host başlığından üretiliyordu — e-posta linkleriyle tutarsız.
//   2. Host başlığını İSTEMCİ gönderir. Bir editör kayıt eklerken
//      "Host: kotu.example" yollayıp Slack kanalına o adrese giden bir
//      "Duyuruyu görüntüle" linki bastırabilirdi; kanaldaki herkes (ekip
//      dışındakiler dahil) o linki uygulamanın kendi linki sanardı. Host'ta
//      "|" ya da ">" varsa Slack'in <url|metin> sözdizimi de bozulurdu.
// bcc_app_base_url() zaten parola sıfırlama / e-posta doğrulama linklerinin
// tabanı; Slack de AYNI kaynağı kullanır.
function bcc_slack_app_url($path)
{
    return bcc_app_base_url() . $path;
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

// Bir kaydın "başlığı": birincil alanın (position/id'ye göre İLK alan) görünen
// değeri — grid.php/table_fields.php'nin her yerde kullandığı AYNI "primary
// field" kavramı.
//
// ⚠️ ORTAK YARDIMCI. Bu blok bcc_notify_slack_new_record()'un İÇİNDEYDİ; hücre
// değişikliği bildirimi de aynı başlığı istediği için oradan çıkarıldı —
// kopyalansaydı "başlıksız kayıt" metni ya da birincil alan tanımı değişince
// biri güncellenip diğeri unutulurdu.
//
// Fallback ("(başlıksız kayıt)") sık görülür ve KASITLIDIR: grid "önce boş
// kayıt ekle, sonra hücreleri doldur" akışıyla çalışıyor, yani yeni kayıt
// bildirimi anında birincil alan çoğu zaman boştur.
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

    // Birincil alan zengin metin olabilir (nadir ama mümkün — tablonun ilk
    // alanı long_text yapılabiliyor). O hâlde başlıkta ham <b>/<br> görünmesin.
    //
    // ⚠️ BURADA bcc_slack_text_from_rich() KULLANILMAZ: o fonksiyon çıktısını
    // ZATEN kaçırıyor, oysa bu fonksiyonun İKİ çağıranı da dönen değeri kendisi
    // bcc_slack_escape()'ten geçiriyor — çift kaçırma olurdu (& -> &amp;amp;).
    // Başlık için biçimlendirmeye de gerek yok: tek satırlık düz metin yeter.
    if ($primaryField['field_type'] === 'long_text' && $display !== '') {
        $display = preg_replace('#<br\s*/?>#i', ' ', $display);
        $display = html_entity_decode(strip_tags($display), ENT_QUOTES, 'UTF-8');
        $display = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $display)));
    }

    return ($display !== '') ? $display : '(başlıksız kayıt)';
}

// Bu tabloda hücre değişikliği bildirimi için İZLENEN alanların id listesi.
// Boş dizi = bu tabloda özellik KAPALI (varsayılan durum; bkz.
// migrations/022_slack_watched_fields.sql — boş tablo = kapalı).
//
// İstek başına statik önbellek: cell_update.php tek hücre kaydeder, yani
// istek başına en fazla bir kez çağrılır — ama toplu yapıştırma yolu aynı
// tablo için tekrar tekrar sorabilir, ikinci sorgu açılmasın.
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

        // Mesajın "başlığı" ORTAK yardımcıda — hücre değişikliği bildirimi de
        // AYNI başlığı kullanıyor, blok kopyalanmadı (bkz. bcc_slack_record_title).
        $primaryDisplay = bcc_slack_record_title($tableId, $recordId, $tableRow['team_id']);

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

// DÖRDÜNCÜ OLAY: var olan bir kaydın hücresi DEĞİŞTİĞİNDE.
//
// NEDEN VAR: üç "oluşturma" olayı (yeni kayıt/tablo/alan) varken bir satırdaki
// değeri güncellemek hiçbir bildirim üretmiyordu — kullanıcı "Durum"u
// "Kazanildi" yaptı, Slack'i bekledi, hiçbir şey gelmedi (kullanıcı bildirdi).
//
// ⚠️ HER HÜCREYE DEĞİL, YALNIZCA İZLENEN ALANLARA. Çağıran taraf
// (cell_update.php) bcc_slack_watched_field_ids() ile süzer; bu fonksiyon
// çağrıldıysa alan zaten izleniyordur. Gerekçe (gürültü) için bkz.
// migrations/022_slack_watched_fields.sql.
//
// KANAL SEÇİMİ ORTAK: bcc_slack_dispatch()'e $recordId VERİLİR, yani koşullu
// yönlendirme kuralları bu olayda da çalışır (ör. "Durum = Yeni ise X kanalı").
// Kural değerlendirmesi hücre YAZILDIKTAN sonra yapıldığı için kurallar YENİ
// değere bakar — "Kazanildi'ye geçen kayıtlar şu kanala" kurulabilmesi için
// doğru olan davranış bu.
//
// $oldDisplay / $newDisplay: cell_display_text()'ten geçmiş GÖRÜNEN metinler
// (ham DB değeri değil) — kullanıcı ekranda ne görüyorsa Slack'te de onu okur.
//
// Diğer üç bildirim gibi SESSİZ: hiçbir koşulda istisna sızdırmaz, hücre
// kaydetme akışını etkilemez.
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

        // Alan tipine göre doğru dönüşüm — zengin metin (Notlar) HTML sakladığı
        // için Slack'e ham etiket gitmesin (bkz. bcc_slack_cell_value_markup).
        // ⚠️ Bu iki değer ARTIK KAÇIRILMIŞ durumda; bir daha
        // bcc_slack_escape()'ten geçirilmemeli.
        $oldMarkup = bcc_slack_cell_value_markup($fieldType, $oldDisplay);
        $newMarkup = bcc_slack_cell_value_markup($fieldType, $newDisplay);

        // Kırpma SABİT bir tavanla değil, mesajın gerçek bütçesiyle yapılır.
        // Sabit paylar (başlık satırları, alan adı, "Değiştiren", bağlantı,
        // alıntı ">" önekleri) burada cömertçe 800 karakter sayılıyor; kalan
        // her şey iki değere ait. Böylece tek bir uzun not (20.000) tamamen
        // sığar ve "(kısaltıldı)" hiç görünmez — kullanıcı bunu bildirdi.
        list($oldMarkup, $newMarkup) = bcc_slack_fit_pair($oldMarkup, $newMarkup, BCC_SLACK_MAX_TEXT - 800);

        // Boş değerler "—" olarak görünür: "Durum: — → Kazanildi" okunabilir,
        // "Durum:  → Kazanildi" değil.
        $oldText = ($oldMarkup !== '') ? $oldMarkup : '—';
        $newText = ($newMarkup !== '') ? $newMarkup : '—';

        // Yeni kayıt bildirimiyle AYNI link hedefi (interface.php): rolden
        // bağımsız salt-okunur, viewer da açabilir — grid.php require_role('editor')
        // istediği için orada 403 alınırdı.
        $link = bcc_slack_app_url('/interface.php?base_id=' . (int) $row['base_id'] . '&table_id=' . (int) $tableId);

        $text = "✏️ *" . bcc_slack_escape($row['table_name']) . "* tablosunda bir kayıt güncellendi\n*"
            . bcc_slack_escape(bcc_slack_record_title($tableId, $recordId, $row['team_id'])) . "*\n";

        // ---- İKİ YERLEŞİM ------------------------------------------------
        // Kullanıcı bildirdi: çok satırlı bir not "Notlar: — → satır1 satır2 ..."
        // biçiminde TEK SATIRDA akıyor ve okunmuyordu; not düzenleyicideki gibi
        // görünmesi isteniyor.
        //
        // SATIR İÇİ ("Durum: Gorusuluyor → Kazanildi") kısa, tek satırlık
        // değerler için KORUNDU — kullanıcının beğendiği kompakt biçim bu.
        //
        // BLOK yerleşimine yalnızca değer gerçekten çok satırlıysa VEYA satır
        // içine sığmayacak kadar uzunsa geçilir. Ölçüt değerin KENDİSİ:
        // "long_text alanıysa her zaman blok" DEĞİL, çünkü tek kelimelik bir
        // not için üç satırlık blok gereksiz gürültü olurdu.
        $isBlock = (strpos($oldText, "\n") !== false || strpos($newText, "\n") !== false
            || mb_strlen($oldText, 'UTF-8') > 80 || mb_strlen($newText, 'UTF-8') > 80);

        if (!$isBlock) {
            $text .= bcc_slack_escape($fieldName) . ': ' . $oldText . ' → ' . $newText . "\n";
        } else {
            // Slack'te alıntı: her satır ">" ile başlar. ">>>" KULLANILMAZ —
            // o, mesajın GERİ KALANININ tamamını alıntıya çevirir ve altındaki
            // "Değiştiren"/link satırlarını da içine alırdı.
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
        // Sessiz devam — hücre kaydetme akışı bu fonksiyonun başarısından etkilenmez.
    }
}

// Çok satırlı bir değeri Slack alıntısına çevirir: HER satır ">" ile başlar.
// Boş satırlar da işaretlenir, yoksa alıntı ortadan bölünür ve editördeki
// paragraf aralıkları kaybolurdu.
function bcc_slack_blockquote($text)
{
    $lines = preg_split("/\n/", (string) $text);

    foreach ($lines as $i => $line) {
        $lines[$i] = '>' . $line;
    }

    return implode("\n", $lines);
}

// TOPLU YAPIŞTIRMA için TEK ÖZET mesaj (cells_bulk_update.php).
//
// ⚠️ NEDEN AYRI BİR FONKSİYON: yapıştırma tek istekte yüzlerce hücre
// yazabiliyor. Her hücre için bcc_notify_slack_cell_change() çağırmak aynı
// kanala yüzlerce mesaj basardı — kullanıcıya "gürültü" gerekçesiyle
// anlatılan tam olarak bu senaryo. Bunun yerine işlem başına TEK satır:
// hangi izlenen alanlarda kaç hücre değişti.
//
// Eski→yeni ayrıntısı KASITLI OLARAK YOK: yüzlerce değişimin listesi bir
// Slack mesajına sığmaz ve okunmaz. Ayrıntı için mesajdaki link tabloyu açar.
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

        // $recordId = null: tek bir kayıt yok, dolayısıyla koşullu kurallar
        // atlanır ve tablo-özel/ekip-geneli webhook'a düşülür — "yeni tablo"
        // ve "yeni alan" olaylarıyla AYNI gerekçe.
        $text = "✏️ *" . bcc_slack_escape($row['table_name']) . "* tablosunda toplu düzenleme\n"
            . implode(', ', $escaped) . ' alanında ' . (int) $cellCount . " hücre güncellendi\n";
        if ($userFullName !== null && $userFullName !== '') {
            $text .= 'Değiştiren: ' . bcc_slack_escape($userFullName) . "\n";
        }
        $text .= '<' . $link . '|Tabloyu aç>';

        bcc_slack_dispatch($tableId, $row['team_id'], null, $text, 'table', $tableId);
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
