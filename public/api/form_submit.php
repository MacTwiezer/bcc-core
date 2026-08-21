<?php
// ⚠️⚠️⚠️ PROJENİN TEK KİMLİK DOĞRULAMASIZ YAZMA UÇ NOKTASI (Grup View-Form).
//
// public/api/ altındaki DİĞER HER uç nokta api_require_login() ile başlar.
// Bu dosya BİLEREK başlamaz — herkese açık form linkini dolduran kişinin
// oturumu yoktur. Bu yüzden her satır "kimliği doğrulanmamış biri buraya
// ne gönderebilir" sorusuyla yazıldı.
//
// KAPILAR (sırayla, hepsi SUNUCUDA):
//   1. Token biçimi + DB eşleşmesi (view_type='form' VE form_enabled=1)
//   2. Honeypot — doluysa BAŞARILI dön ama KAYDETME
//   3. Zaman-bazlı HMAC nonce — imza + "3sn'den hızlı gönderilmiş mi"
//   4. form_fields whitelist'i — SUNUCUDAN okunur, istemcinin gönderdiği
//      alan listesine ASLA güvenilmez
//   5. Tip filtresi (bcc_field_allowed_in_form) — salt-okunur/attachment/
//      long_text/user alanları SESSİZCE atlanır (config kurcalanmış olsa bile)
//   6. normalize_cell_value() — her değerin kendi tip doğrulaması
//   7. is_required — boş bırakılan zorunlu alan 422
//
// KASITLI OLARAK YOK: klasik CSRF token'ı. Gerekçe src/form_security.php'de
// ayrıntılı yazılı (özet: CSRF kurbanın YETKİSİNİ kötüye kullanmaktır; burada
// gönderen zaten yetkisiz ve form zaten herkese açık).

require __DIR__ . '/../../src/api_bootstrap.php';
require_once __DIR__ . '/../../src/form_security.php';

api_require_post();
// ⚠️ api_require_login() ve api_require_csrf() BİLEREK ÇAĞRILMIYOR — bkz. yukarısı.

$token = isset($_POST['t']) ? (string) $_POST['t'] : '';

// KAPI 1 — token. Biçim önce ucuzca elenir (32 hex), sonra DB.
// form_enabled = 1 ŞART: migrations/015'te söz verilen üç okuma noktasından İKİNCİSİ.
$view = null;
if (preg_match('/^[0-9a-f]{32}$/', $token)) {
    $view = bcc_fetch_one(
        "SELECT v.id, v.config, v.table_id, t.name AS table_name, b.team_id
         FROM views v
         INNER JOIN tables_meta t ON t.id = v.table_id
         INNER JOIN bases b ON b.id = t.base_id
         WHERE v.form_token = :token AND v.view_type = 'form' AND v.form_enabled = 1
         LIMIT 1",
        array(':token' => $token)
    );
}

if (!$view) {
    // AYRIMSIZ 404: "token yanlış" ile "form kapalı" ayrımı bir saldırgana
    // geçerli token'ları elemede bilgi verirdi (form.php ile AYNI disiplin).
    json_fail(404, 'Bu form bulunamadı veya kapatılmış.');
}

$formConfig = bcc_form_config_from_view($view);

// KAPI 2 — HONEYPOT.
// ⚠️ Bot'a HATA DÖNDÜRÜLMEZ: "başarılı" yanıt döner ama HİÇBİR ŞEY KAYDEDİLMEZ.
// Hata döndürmek, saldırganın honeypot'u tespit edip bir sonraki denemede boş
// bırakmasını sağlardı. Erken çıkış — DB'ye hiç dokunulmaz.
if (bcc_form_honeypot_tripped($_POST)) {
    echo json_encode(array(
        'ok' => true,
        'message' => $formConfig['form_success_message'],
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// KAPI 3 — NONCE. İmza sahte ya da form 3 saniyeden hızlı gönderilmişse
// (sayfayı hiç render etmeden doğrudan POST) reddedilir.
$nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
if (!bcc_form_nonce_valid($nonce)) {
    json_fail(422, 'Form oturumu geçersiz veya zaman aşımına uğradı. Sayfayı yenileyip tekrar deneyin.');
}

// KAPI 4 + 5 — ALAN WHITELIST'İ.
// Alan listesi SUNUCUDAN (views.config) okunur. İstemcinin POST'ladığı anahtar
// adları yalnızca DEĞER taşımak için kullanılır; hangi alanların yazılabileceğine
// istemci KARIŞAMAZ. Ayrıca her alan tip filtresinden de geçer — config elle
// kurcalanıp salt-okunur/attachment/long_text/user bir alan eklenmiş olsa bile
// buraya giremez.
$allFields = bcc_fetch_all(
    'SELECT id, name, field_type, options, is_required FROM fields WHERE table_id = :tid ORDER BY position, id',
    array(':tid' => $view['table_id'])
);
$fieldsById = array();
foreach ($allFields as $f) {
    $fieldsById[(int) $f['id']] = $f;
}

$allowedFields = array();
foreach ($formConfig['form_fields'] as $fid) {
    if (isset($fieldsById[$fid]) && bcc_field_allowed_in_form($fieldsById[$fid]['field_type'])) {
        $allowedFields[] = $fieldsById[$fid];
    }
}

if (empty($allowedFields)) {
    json_fail(422, 'Bu form henüz yapılandırılmamış.');
}

// KAPI 6 + 7 — DEĞER DOĞRULAMA. Kayıt DB'ye yazılmadan ÖNCE tüm hücreler
// normalize edilip zorunlu alan kontrolü yapılır (table_import_xlsx.php ile
// AYNI disiplin: "yarım" kayıt oluşmasın).
$cellsToInsert = array();

foreach ($allowedFields as $f) {
    $fieldId = (int) $f['id'];
    $inputName = 'f' . $fieldId;
    $rawValue = isset($_POST[$inputName]) ? $_POST[$inputName] : null;

    // multiple_select: istemci f<id>[] olarak gönderir, PHP bunu $_POST['f<id>']
    // altında DİZİ olarak ayrıştırır. normalize_cell_value() ise JSON dizi METNİ
    // bekliyor (grid.js'in gönderdiği biçim) — çeviri burada yapılıyor,
    // normalize_cell_value()'nun KENDİSİ değiştirilmiyor.
    // is_scalar süzgeci: iç içe dizi gönderilirse (kurcalanmış istek)
    // (string) cast'i "Array" üretirdi; sessizce atılıyor.
    if ($f['field_type'] === 'multiple_select') {
        $multi = is_array($rawValue) ? $rawValue : (($rawValue === null || $rawValue === '') ? array() : array($rawValue));
        $clean = array();
        foreach ($multi as $item) {
            if (is_scalar($item)) {
                $clean[] = (string) $item;
            }
        }
        $rawValue = json_encode($clean, JSON_UNESCAPED_UNICODE);
    } elseif (is_array($rawValue)) {
        // Diğer tiplerde dizi gelmesi beklenmez (elle kurcalanmış istek) —
        // (string) cast'i "Array" üretirdi, o yüzden boşa çevriliyor.
        $rawValue = '';
    }

    $result = normalize_cell_value($f['field_type'], $f['options'], $rawValue, array());

    if (!$result['ok']) {
        json_fail(422, $f['name'] . ': ' . $result['error']);
    }

    // cell_update.php'nin uyguladığı AYNI zorunluluk kuralı.
    if ((int) $f['is_required'] === 1 && $result['value'] === null) {
        json_fail(422, $f['name'] . ' alanı zorunludur.');
    }

    if ($result['value'] === null) {
        continue; // boş isteğe bağlı alan — cell_values'a satır yazılmaz
    }

    $cellsToInsert[] = array(
        'field_id' => $fieldId,
        'column' => $result['column'],
        'value' => $result['value'],
    );
}

try {
    bcc_begin_transaction();

    $nextPos = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 FROM records WHERE table_id = :tid',
        array(':tid' => $view['table_id'])
    );

    // created_by = NULL: gönderen anonim. records.created_by zaten nullable
    // (canlı doğrulandı) ve "Oluşturan" alanı bu kayıtlarda boş görünür —
    // created_by'ı NULL olan eski kayıtlarla AYNI, bilinen davranış.
    bcc_execute(
        'INSERT INTO records (table_id, position, created_by) VALUES (:tid, :pos, NULL)',
        array(':tid' => $view['table_id'], ':pos' => $nextPos)
    );
    $recordId = (int) bcc_last_insert_id();

    // Autonumber (Grup C2): bu, kayıt oluşturmanın BEŞİNCİ noktası.
    // ⚠️ bcc_last_insert_id() ÇAĞRISINDAN SONRA — bcc_assign_autonumbers()
    // LAST_INSERT_ID(expr) ile oturumun last-insert-id'sini EZER.
    bcc_assign_autonumbers($view['table_id'], $recordId);

    foreach ($cellsToInsert as $cell) {
        $column = $cell['column'];
        bcc_execute(
            "INSERT INTO cell_values (record_id, field_id, {$column}) VALUES (:rid, :fid, :val)",
            array(':rid' => $recordId, ':fid' => $cell['field_id'], ':val' => $cell['value'])
        );
    }

    // log_audit() DEĞİŞTİRİLMEDEN çalışır: içinde current_user() null döner ve
    // 'user_id' => null yazılır. audit_log.user_id nullable (canlı doğrulandı,
    // hâlihazırda 1250 satır NULL) — ek DDL gerekmedi.
    log_audit('record.form_submit', 'record', $recordId, array('table_id' => $view['table_id'], 'view_id' => $view['id']), $view['team_id']);

    bcc_commit();
} catch (Throwable $e) {
    bcc_rollback();
    json_fail(500, 'Kaydedilemedi, lütfen tekrar deneyin.');
}

// Slack bildirimi commit'TEN SONRA (DB mutasyonu değil, kendi try/catch'i var) —
// record_add.php ile AYNI sıra.
// ⚠️ VARSAYILAN AÇIK (bkz. bcc_form_config_from_view). Form herkese açık
// olduğu için istenmeyen gönderimler de ekibin Slack kanalına taşabilir;
// kapatma anahtarı form_edit.php'de.
if (!empty($formConfig['form_slack_notify'])) {
    bcc_notify_slack_new_record($view['table_id'], $recordId, null);
}

echo json_encode(array(
    'ok' => true,
    'message' => $formConfig['form_success_message'],
), JSON_UNESCAPED_UNICODE);
