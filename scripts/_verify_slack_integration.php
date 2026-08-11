<?php
// Slack entegrasyonu — olay tetikleyicilerinin UCTAN UCA dogrulanmasi.
//
// GERCEK SLACK'E MESAJ GITMEZ. Test, DEMO ekibine gecici bir webhook satiri
// yazar ve URL'i ERISILEMEZ bir yerel adrese (127.0.0.1:9, "discard" portu)
// isaret ettirir. Boylece:
//   - gonderim DENENIR  -> hook'un gercekten calistigi kanitlanir
//   - baglanti REDDEDILIR -> audit_log'a 'slack.notify_failed' yazilir
//   - hicbir dis trafik olusmaz, kullanicinin GERCEK kanallarina (team 1'deki
//     #trendyol-siparis / #yves-rocher-siparis / #genel) DOKUNULMAZ
//
// "Hook bagli mi" sorusunun dogru olcutu tam olarak budur: webhook YOKKEN
// hicbir audit satiri olusmamali, webhook VARKEN olusmali.
//
// Calistirma: C:\php73\php.exe scripts\_verify_slack_integration.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

// Erisilemez adres: RFC 863 "discard" portu, baglanti aninda reddedilir.
// Slack'e ait GERCEK bir host DEGIL — kasitli.
const UNREACHABLE = 'http://127.0.0.1:9/bcc-slack-test';

$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

function no_row($r) { return $r === false || $r === null; }

function post_as($userId, $page, $query, $post)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_post_as_case.php')
        . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg($page)
        . ' ' . escapeshellarg($query) . ' ' . escapeshellarg(base64_encode(json_encode($post)));
    $out = (string) shell_exec($cmd . ' 2>&1');
    $status = preg_match('/HTTP_STATUS=(\d+)/', $out, $m) ? (int) $m[1] : 0;

    return array('status' => $status, 'body' => $out);
}

// Bu betigin baslangicindan SONRA yazilan slack audit satirlarini sayar.
function slack_audit_since($sinceId, $action, $entityType = null, $entityId = null)
{
    $sql = 'SELECT COUNT(*) FROM audit_log WHERE id > :since AND action = :action';
    $params = array('since' => $sinceId, 'action' => $action);

    if ($entityType !== null) {
        $sql .= ' AND entity_type = :et';
        $params['et'] = $entityType;
    }
    if ($entityId !== null) {
        $sql .= ' AND entity_id = :eid';
        $params['eid'] = $entityId;
    }

    return (int) bcc_fetch_column($sql, $params);
}

// ---------------------------------------------------------------------------
// A) Kapsam matrisi — hangi mutasyon hangi bildirimi cagiriyor
// ---------------------------------------------------------------------------
echo "--- A) Olay kapsami (kaynak seviyesinde) ---\n";

$root = __DIR__ . '/..';

$coverage = array(
    // [dosya, beklenen cagri, aciklama]
    array('public/api/record_add.php', 'bcc_notify_slack_new_record(', 'satir ekleme (AJAX)'),
    array('public/grid.php', 'bcc_notify_slack_new_record(', 'satir ekleme (JS-siz form)'),
    array('public/api/record_duplicate.php', 'bcc_notify_slack_new_record(', 'satir cogaltma'),
    array('public/api/form_submit.php', 'bcc_notify_slack_new_record(', 'genel form gonderimi'),
    array('public/base_tables.php', 'bcc_notify_slack_new_table(', 'TABLO olusturma'),
    array('src/schema.php', 'bcc_notify_slack_new_field(', 'ALAN olusturma (ortak yol)'),
);

foreach ($coverage as $c) {
    check($c[2] . ' -> ' . rtrim($c[1], '('),
        strpos(file_get_contents($root . '/' . $c[0]), $c[1]) !== false, $c[0]);
}

// Alan olusturmanin IKI giris noktasi da ortak fonksiyondan gecmeli — hook'un
// tek yerde olmasinin gecerli olmasi buna bagli.
check('table_fields.php alan olusturmayi bcc_create_field() ile yapiyor',
    strpos(file_get_contents($root . '/public/table_fields.php'), 'bcc_create_field(') !== false);
check('api/field_create.php alan olusturmayi bcc_create_field() ile yapiyor',
    strpos(file_get_contents($root . '/public/api/field_create.php'), 'bcc_create_field(') !== false);
// Tablo olusturmanin TEK yolu base_tables.php olmali — baska bir dosya da
// tables_meta'ya INSERT etseydi oradaki olay bildirimsiz kalirdi.
$tableInsertFiles = array();
foreach (array_merge(glob($root . '/public/*.php'), glob($root . '/public/api/*.php'), glob($root . '/src/*.php')) as $f) {
    if (strpos(file_get_contents($f), 'INSERT INTO tables_meta') !== false) {
        $tableInsertFiles[] = basename($f);
    }
}
check('tables_meta INSERT yalnizca base_tables.php\'de (tek tablo olusturma yolu)',
    $tableInsertFiles === array('base_tables.php'), implode(', ', $tableInsertFiles));

// Bildirim COMMIT'ten SONRA olmali (geri alinmis bir islem icin mesaj gitmesin,
// Slack yavassa transaction acik kalmasin).
//
// Dosyalar diskte CRLF — arama yapmadan once LF'e normalize edilir, yoksa
// cok satirli desenler HIC eslesmez (bu betikte bir kez yasandi).
function lf($s) { return str_replace("\r\n", "\n", $s); }

// bcc_create_field()'in govdesi icinde: notify cagrisi, o fonksiyonun
// bcc_commit()'inden SONRA gelmeli. Fonksiyonun basindan itibaren bakilir ki
// dosyadaki BASKA bir bcc_commit() ile karistirilmasin.
$schemaSrc = lf(file_get_contents($root . '/src/schema.php'));
$fnStart = strpos($schemaSrc, 'function bcc_create_field(');
$fieldNotifyPos = strpos($schemaSrc, 'bcc_notify_slack_new_field(', $fnStart);
$commitPos = strpos($schemaSrc, 'bcc_commit();', $fnStart);
check('alan bildirimi COMMIT sonrasinda (transaction disinda)',
    $fnStart !== false && $commitPos !== false && $fieldNotifyPos !== false && $fieldNotifyPos > $commitPos,
    'commit=' . var_export($commitPos, true) . ' notify=' . var_export($fieldNotifyPos, true));

$btSrc = lf(file_get_contents($root . '/public/base_tables.php'));
check('tablo bildirimi COMMIT sonrasinda',
    strpos($btSrc, 'bcc_commit();') < strpos($btSrc, 'bcc_notify_slack_new_table('));

// ---------------------------------------------------------------------------
// B) Guvenlik — webhook_url hicbir yere sizmamali
// ---------------------------------------------------------------------------
echo "\n--- B) Guvenlik ---\n";

$slackSrc = file_get_contents($root . '/src/slack.php');
check('log_audit cagrilarinda webhook_url GECMIYOR',
    !preg_match("/log_audit\([^;]*webhook_url/s", $slackSrc));
check('ayar sayfasi URL\'i maskeleyerek gosteriyor (bcc_slack_masked_url)',
    strpos(file_get_contents($root . '/public/slack_settings.php'), 'bcc_slack_masked_url') !== false);
check('kaydetme yalnizca https://hooks.slack.com/ kabul ediyor',
    strpos(file_get_contents($root . '/public/slack_settings.php'), "'https://hooks.slack.com/'") !== false);
check('test gonderimi webhook satirini team_id ile suzuyor (baska ekibin webhook\'u test edilemez)',
    strpos($slackSrc, 'WHERE id = :id AND team_id = :team_id') !== false);

// ---------------------------------------------------------------------------
// C) CANLI TETIKLEME TESTI (demo ekibi, erisilemez URL)
// ---------------------------------------------------------------------------
echo "\n--- C) Canli tetikleme (gercek uc noktalar) ---\n";

$team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'Demo Calisma Alani' LIMIT 1");
if (no_row($team)) {
    die("Demo ekibi yok. Once: C:\\php73\\php.exe scripts\\seed_demo_users.php\n");
}
$teamId = (int) $team['id'];

$owner = bcc_fetch_one("SELECT id, full_name FROM users WHERE email = 'owner@bcc.local' LIMIT 1");
$ownerId = (int) $owner['id'];

$base = bcc_fetch_one("SELECT id FROM bases WHERE team_id = :t AND name = 'Demo CRM' LIMIT 1", array('t' => $teamId));
$baseId = (int) $base['id'];

$existingTable = bcc_fetch_one(
    "SELECT id FROM tables_meta WHERE base_id = :b AND name = 'Musteriler' LIMIT 1",
    array('b' => $baseId)
);
$tableId = (int) $existingTable['id'];

// ---------------------------------------------------------------------------
// IZOLASYON: demo ekibinde ONCEDEN yapilandirilmis (GERCEK) webhook varsa,
// test suresince GECICI OLARAK pasife alinir ve sonunda AYNEN geri acilir.
//
// Bulunan gercek test kusuru: onceden bu betik "demo ekibinde webhook yok"
// varsayiyordu. Demo calisma alanina gercek bir webhook eklendiginde
// bcc_find_slack_webhook()'un siralamasi (ORDER BY (table_id IS NULL) ASC,
// id ASC) DAHA DUSUK id'li GERCEK satiri seciyor ve betigin kendi erisilemez
// test URL'i hic kullanilmiyordu -> regresyon kosusu CANLI Slack kanalina
// mesaj gonderiyordu. Artik mumkun degil: gercek satirlar test boyunca pasif.
$preExistingHooks = bcc_fetch_all(
    'SELECT id, is_active FROM slack_webhooks WHERE team_id = :t AND is_active = 1',
    array('t' => $teamId)
);

foreach ($preExistingHooks as $h) {
    bcc_execute('UPDATE slack_webhooks SET is_active = 0 WHERE id = :i', array('i' => $h['id']));
}

check('demo ekibindeki mevcut webhooklar test suresince pasife alindi (canli kanala mesaj gitmez)',
    (int) bcc_fetch_column('SELECT COUNT(*) FROM slack_webhooks WHERE team_id = :t AND is_active = 1', array('t' => $teamId)) === 0,
    'pasife alinan: ' . count($preExistingHooks));

$startAuditId = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');

$createdTableIds = array();
$createdFieldIds = array();
$createdRecordIds = array();
$tempWebhookId = 0;

register_shutdown_function(function () use (&$createdTableIds, &$createdFieldIds, &$createdRecordIds, &$tempWebhookId, $startAuditId, $preExistingHooks) {
    // Gercek webhooklari HER DURUMDA (hata/istisna dahil) geri ac.
    foreach ($preExistingHooks as $h) {
        bcc_execute('UPDATE slack_webhooks SET is_active = 1 WHERE id = :i', array('i' => $h['id']));
    }

    foreach ($createdRecordIds as $id) {
        bcc_execute('DELETE FROM cell_values WHERE record_id = :i', array('i' => $id));
        bcc_execute('DELETE FROM records WHERE id = :i', array('i' => $id));
    }
    foreach ($createdFieldIds as $id) {
        bcc_execute('DELETE FROM cell_values WHERE field_id = :i', array('i' => $id));
        bcc_execute('DELETE FROM fields WHERE id = :i', array('i' => $id));
    }
    foreach ($createdTableIds as $id) {
        bcc_execute('DELETE FROM tables_meta WHERE id = :i', array('i' => $id));
    }
    if ($tempWebhookId > 0) {
        bcc_execute('DELETE FROM slack_webhooks WHERE id = :i', array('i' => $tempWebhookId));
    }
    // Bu kosunun urettigi TUM audit satirlari (slack + entity) temizlenir.
    bcc_execute('DELETE FROM audit_log WHERE id > :since', array('since' => $startAuditId));
});

// --- C1: webhook YOKKEN hicbir slack audit satiri olusmamali ---
$r = post_as($ownerId, 'api/field_create.php', '', array(
    'table_id' => $tableId, 'name' => 'SLACK_TEST_ALAN_1', 'field_type' => 'single_line_text',
));
check('webhook yokken alan olusturuldu (islem etkilenmedi)', $r['status'] === 200, 'HTTP ' . $r['status']);

$f = bcc_fetch_one("SELECT id FROM fields WHERE table_id = :t AND name = 'SLACK_TEST_ALAN_1'", array('t' => $tableId));
if (!no_row($f)) {
    $createdFieldIds[] = (int) $f['id'];
}
check('webhook YOKKEN slack audit satiri OLUSMADI',
    slack_audit_since($startAuditId, 'slack.notify_failed') === 0
    && slack_audit_since($startAuditId, 'slack.notify_sent') === 0);

// --- Gecici webhook: ERISILEMEZ adres ---
bcc_execute(
    'INSERT INTO slack_webhooks (team_id, table_id, webhook_url, channel_name, is_active)
     VALUES (:t, NULL, :u, :c, 1)',
    array('t' => $teamId, 'u' => UNREACHABLE, 'c' => '#bcc-otomatik-test')
);
$tempWebhookId = (int) bcc_last_insert_id();
check('gecici (ekip-geneli) webhook olusturuldu', $tempWebhookId > 0);

$auditBeforeEvents = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');

// --- C2: ALAN olusturma -> bildirim denendi mi? ---
$r = post_as($ownerId, 'api/field_create.php', '', array(
    'table_id' => $tableId, 'name' => 'SLACK_TEST_ALAN_2', 'field_type' => 'number',
));
check('alan olusturuldu (api/field_create.php)', $r['status'] === 200, 'HTTP ' . $r['status']);

$f2 = bcc_fetch_one("SELECT id FROM fields WHERE table_id = :t AND name = 'SLACK_TEST_ALAN_2'", array('t' => $tableId));
if (!no_row($f2)) {
    $createdFieldIds[] = (int) $f2['id'];
}
check('ALAN olusturma Slack bildirimini TETIKLEDI (entity_type=field)',
    !no_row($f2) && slack_audit_since($auditBeforeEvents, 'slack.notify_failed', 'field', (int) $f2['id']) === 1);

// --- C3: TABLO olusturma ---
$auditBeforeTable = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');
$r = post_as($ownerId, 'base_tables.php', 'base_id=' . $baseId, array(
    'action' => 'create_table', 'base_id' => $baseId, 'name' => 'SLACK_TEST_TABLO', 'description' => '',
));
check('tablo olusturuldu (base_tables.php)', $r['status'] === 200, 'HTTP ' . $r['status']);

$newTable = bcc_fetch_one("SELECT id FROM tables_meta WHERE base_id = :b AND name = 'SLACK_TEST_TABLO'", array('b' => $baseId));
if (!no_row($newTable)) {
    $createdTableIds[] = (int) $newTable['id'];
}
check('TABLO olusturma Slack bildirimini TETIKLEDI (entity_type=table)',
    !no_row($newTable) && slack_audit_since($auditBeforeTable, 'slack.notify_failed', 'table', (int) $newTable['id']) === 1);

// --- C4: SATIR ekleme (zaten calisiyordu — regresyon korumasi) ---
$auditBeforeRecord = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');
$r = post_as($ownerId, 'api/record_add.php', '', array('table_id' => $tableId));
check('satir eklendi (api/record_add.php)', $r['status'] === 200, 'HTTP ' . $r['status']);

$newRec = bcc_fetch_one('SELECT id FROM records WHERE table_id = :t ORDER BY id DESC LIMIT 1', array('t' => $tableId));
if (!no_row($newRec)) {
    $createdRecordIds[] = (int) $newRec['id'];
}
check('SATIR ekleme Slack bildirimini TETIKLEDI (entity_type=record)',
    !no_row($newRec) && slack_audit_since($auditBeforeRecord, 'slack.notify_failed', 'record', (int) $newRec['id']) === 1);

// --- C5: Slack erisilemezken asil islem BASARILI kalmali ---
check('Slack erisilemezken bile alan/tablo/satir GERCEKTEN olustu (bildirim islemi bloklamiyor)',
    !no_row($f2) && !no_row($newTable) && !no_row($newRec));

// --- C6: "Baglantiyi test et" ---
$auditBeforeTest = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');
$r = post_as($ownerId, 'slack_settings.php', 'table_id=' . $tableId, array(
    'action' => 'test_webhook', 'table_id' => $tableId, 'webhook_id' => $tempWebhookId,
));
check('test aksiyonu calisti (slack_settings.php)', $r['status'] === 200, 'HTTP ' . $r['status']);
check('test gonderimi denendi ve sonuc loglandi (slack.test_failed)',
    slack_audit_since($auditBeforeTest, 'slack.test_failed') === 1);
check('erisilemez URL\'de kullaniciya HATA gosteriliyor (sessiz basari yok)',
    strpos($r['body'], 'gönderilemedi') !== false, substr($r['body'], 0, 200));

// Test mesaji metni dogru mu?
check('test mesaji "Slack Integration Connected Successfully" iceriyor',
    strpos($slackSrc, 'Slack Integration Connected Successfully') !== false);

// Baska ekibin webhook'u test EDILEMEZ.
$foreign = bcc_fetch_one('SELECT id FROM slack_webhooks WHERE team_id <> :t LIMIT 1', array('t' => $teamId));
if (!no_row($foreign)) {
    $auditBeforeForeign = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');
    $r = post_as($ownerId, 'slack_settings.php', 'table_id=' . $tableId, array(
        'action' => 'test_webhook', 'table_id' => $tableId, 'webhook_id' => (int) $foreign['id'],
    ));
    check('BASKA ekibin webhook\'u test edilemiyor (gercek kanala mesaj gitmez)',
        strpos($r['body'], 'Webhook bulunamadı') !== false, substr($r['body'], 0, 160));
    check('yabanci webhook icin hicbir gonderim loglanmadi',
        slack_audit_since($auditBeforeForeign, 'slack.test_sent') === 0
        && slack_audit_since($auditBeforeForeign, 'slack.test_failed') === 0);
}

// --- C7: Yetki — owner olmayan test/kaydetme yapamaz ---
$editor = bcc_fetch_one("SELECT id FROM users WHERE email = 'editor@bcc.local' LIMIT 1");
$r = post_as((int) $editor['id'], 'slack_settings.php', 'table_id=' . $tableId, array(
    'action' => 'test_webhook', 'table_id' => $tableId, 'webhook_id' => $tempWebhookId,
));
check('editor test gonderemez (403)', $r['status'] === 403, 'HTTP ' . $r['status']);

// ---------------------------------------------------------------------------
echo "\n";
$failed = count(array_filter($results, function ($r) { return !$r; }));
echo ($failed === 0 ? 'TUM TESTLER GECTI' : $failed . ' TEST KALDI') . ' (' . count($results) . " kontrol)\n";
echo "\nNot: gercek Slack'e HICBIR mesaj gonderilmedi (URL 127.0.0.1:9).\n";
exit($failed === 0 ? 0 : 1);
