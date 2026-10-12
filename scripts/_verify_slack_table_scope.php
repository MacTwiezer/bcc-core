<?php

/*
 * Slack bildirimi TABLO kapsami dogrulamasi — 2026-10-12.
 *
 * Musteri istegi: bir Slack kanalina yalnizca BAGLANDIGI tablonun
 * degisiklikleri gitsin; ayni base'in / ekibin diger tablolari o kanala
 * yansimasin. Kok neden "takim-geneli yedek kanal"di (slack_webhooks.table_id
 * IS NULL): kendi webhook'u olmayan her tablo ona dusuyordu.
 *
 * GERCEK SLACK'E MESAJ GONDERILMEZ: mevcut aktif webhooklar test suresince
 * pasife alinir; fikstur webhooklari kendi Apache'mizdeki duragan bir dosyaya
 * gider (200 doner, aga cikilmaz). HTTP ile kaydedilen hooks.slack.com adresi
 * PASIF kaydedilir, hic cagrilmaz.
 *
 * Betik kendi test verisini kurar ve sonunda TAMAMEN siler.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/_test_slack_guard.php';

const SESSIZ_URL = 'http://localhost/assets/theme.css';

$gecti = 0;
$kaldi = 0;

function t($ad, $bekle, $gercek)
{
    global $gecti, $kaldi;

    if ($bekle === $gercek) {
        $gecti++;
        echo "[GECTI] {$ad}\n";
    } else {
        $kaldi++;
        echo "[KALDI] {$ad}\n";
        echo "        beklenen: " . var_export($bekle, true) . "\n";
        echo "        gelen   : " . var_export($gercek, true) . "\n";
    }
}

function sayac($sql, $params = array())
{
    return (int) bcc_fetch_column($sql, $params);
}

function post_as($userId, $page, $query, $post)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/_post_as_case.php')
        . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg($page)
        . ' ' . escapeshellarg($query) . ' ' . escapeshellarg(base64_encode(json_encode($post)));
    $out = (string) shell_exec($cmd . ' 2>&1');
    $status = preg_match('/HTTP_STATUS=(\d+)/', $out, $m) ? (int) $m[1] : 0;

    return array('status' => $status, 'body' => $out);
}

function hook_id($tableId, $teamId, $recordId = null)
{
    $w = bcc_find_slack_webhook($tableId, $teamId, $recordId);

    return $w ? (int) $w['id'] : null;
}

function slack_audit($sinceId, $entityType, $entityId)
{
    return sayac(
        "SELECT COUNT(*) AS c FROM audit_log
         WHERE id > :s AND action LIKE 'slack.notify_%' AND entity_type = :et AND entity_id = :eid",
        array('s' => $sinceId, 'et' => $entityType, 'eid' => $entityId)
    );
}

function olc()
{
    return array(
        'kullanici' => sayac('SELECT COUNT(*) AS c FROM users'),
        'ekip' => sayac('SELECT COUNT(*) AS c FROM teams'),
        'base' => sayac('SELECT COUNT(*) AS c FROM bases'),
        'tablo' => sayac('SELECT COUNT(*) AS c FROM tables_meta'),
        'kayit' => sayac('SELECT COUNT(*) AS c FROM records'),
        'webhook' => sayac('SELECT COUNT(*) AS c FROM slack_webhooks'),
        'aktif_webhook' => sayac('SELECT COUNT(*) AS c FROM slack_webhooks WHERE is_active=1'),
        'kural' => sayac('SELECT COUNT(*) AS c FROM slack_routing_rules'),
        'notify_sent' => sayac("SELECT COUNT(*) AS c FROM audit_log WHERE action='slack.notify_sent'"),
        'audit_log' => sayac('SELECT COUNT(*) AS c FROM audit_log'),
    );
}

$oncesi = olc();
$aktifWebhookIds = array_column(bcc_fetch_all('SELECT id FROM slack_webhooks WHERE is_active = 1'), 'id');
$auditBaslangic = sayac('SELECT COALESCE(MAX(id), 0) AS c FROM audit_log');

bcc_test_silence_slack();

$temizlik = array('ekipler' => array(), 'kullanici' => null);

function temizle()
{
    global $temizlik, $auditBaslangic;

    /* Denetim satirlari ekip/kullanici silinmeden ONCE: silme sonrasi
       team_id/user_id NULL'a duserse kime ait olduklari anlasilmaz. */
    foreach ($temizlik['ekipler'] as $teamId) {
        bcc_execute('DELETE FROM audit_log WHERE id > :s AND team_id = :t', array('s' => $auditBaslangic, 't' => $teamId));
    }
    if ($temizlik['kullanici']) {
        bcc_execute('DELETE FROM audit_log WHERE id > :s AND user_id = :u', array('s' => $auditBaslangic, 'u' => $temizlik['kullanici']));
    }
    foreach ($temizlik['ekipler'] as $teamId) {
        bcc_execute('DELETE FROM bases WHERE team_id = :t', array('t' => $teamId));
        bcc_execute('DELETE FROM teams WHERE id = :t', array('t' => $teamId));
    }
    if ($temizlik['kullanici']) {
        bcc_execute('DELETE FROM users WHERE id = :u', array('u' => $temizlik['kullanici']));
    }
}

try {
    /* --- Fikstur ------------------------------------------------------- */

    bcc_execute(
        "INSERT INTO users (full_name, email, password_hash, is_active) VALUES ('ZZ Kapsam Test', 'zz.kapsam@bcc-test.local', 'x', 1)"
    );
    $userId = (int) bcc_last_insert_id();
    $temizlik['kullanici'] = $userId;

    bcc_execute("INSERT INTO teams (name) VALUES ('ZZ Slack Kapsam')");
    $teamId = (int) bcc_last_insert_id();
    $temizlik['ekipler'][] = $teamId;

    bcc_execute("INSERT INTO teams (name) VALUES ('ZZ Slack Kapsam Yabanci')");
    $yabanciTeamId = (int) bcc_last_insert_id();
    $temizlik['ekipler'][] = $yabanciTeamId;

    bcc_execute(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $teamId, 'u' => $userId, 'r' => 'owner')
    );

    function base_kur($teamId, $userId, $ad)
    {
        bcc_execute(
            'INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
            array('t' => $teamId, 'n' => $ad, 'u' => $userId)
        );

        return (int) bcc_last_insert_id();
    }

    function tablo_kur($baseId, $ad, $pos)
    {
        bcc_execute(
            'INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, :p)',
            array('b' => $baseId, 'n' => $ad, 'p' => $pos)
        );
        $tableId = (int) bcc_last_insert_id();

        bcc_execute(
            "INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, 'Baslik', 'single_line_text', 0)",
            array('t' => $tableId)
        );

        return array('id' => $tableId, 'baslik' => (int) bcc_last_insert_id());
    }

    function webhook_kur($teamId, $tableId, $kanal)
    {
        bcc_execute(
            'INSERT INTO slack_webhooks (team_id, table_id, webhook_url, channel_name, is_active)
             VALUES (:t, :tb, :u, :c, 1)',
            array('t' => $teamId, 'tb' => $tableId, 'u' => SESSIZ_URL, 'c' => $kanal)
        );

        return (int) bcc_last_insert_id();
    }

    function kayit_kur($tableId, $fieldId, $deger, $userId)
    {
        bcc_execute(
            'INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)',
            array('t' => $tableId, 'u' => $userId)
        );
        $recordId = (int) bcc_last_insert_id();

        bcc_execute(
            'INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
            array('r' => $recordId, 'f' => $fieldId, 'v' => $deger)
        );
        /* Bekleme suresi gecmis gibi goster. */
        bcc_execute('UPDATE records SET updated_at = (NOW() - INTERVAL 1 HOUR) WHERE id = :r', array('r' => $recordId));

        return $recordId;
    }

    /* Ayni base'de iki sekme (A, B) + ayni ekibin baska base'inde bir tablo (C). */
    $base1 = base_kur($teamId, $userId, 'ZZ Kapsam Base 1');
    $base2 = base_kur($teamId, $userId, 'ZZ Kapsam Base 2');
    $A = tablo_kur($base1, 'ZZ DUYURULAR', 0);
    $B = tablo_kur($base1, 'ZZ ITSM', 1);
    $C = tablo_kur($base2, 'ZZ SUREC', 0);

    $hookA = webhook_kur($teamId, $A['id'], '#zz-duyurular');

    echo "--- A) Kanal yalnizca bagli tabloda cozuluyor ---\n";

    t('A tablosu kendi webhook\'unu buluyor', $hookA, hook_id($A['id'], $teamId));
    t('ayni base\'deki B sekmesi hicbir kanala cozulmuyor', null, hook_id($B['id'], $teamId));
    t('baska base\'deki C tablosu hicbir kanala cozulmuyor', null, hook_id($C['id'], $teamId));

    echo "\n--- B) Eski takim-geneli webhook artik yedek DEGIL ---\n";

    $hookTeam = webhook_kur($teamId, null, '#zz-takim-geneli');

    t('takim-geneli aktifken B yine cozulmuyor', null, hook_id($B['id'], $teamId));
    t('takim-geneli aktifken C yine cozulmuyor', null, hook_id($C['id'], $teamId));
    t('A hala kendi webhook\'unu kullaniyor', $hookA, hook_id($A['id'], $teamId));

    bcc_execute('UPDATE slack_webhooks SET is_active = 0 WHERE id = :i', array('i' => $hookA));
    t('A\'nin webhook\'u pasifken takim-geneline DUSMUYOR', null, hook_id($A['id'], $teamId));
    bcc_execute('UPDATE slack_webhooks SET is_active = 1 WHERE id = :i', array('i' => $hookA));

    echo "\n--- C) Gercek gonderim yollari ---\n";

    $s = sayac('SELECT COALESCE(MAX(id), 0) AS c FROM audit_log');
    bcc_notify_slack_new_field($B['id'], $B['baslik'], 'Baslik', 'single_line_text', 'ZZ');
    t('B\'ye alan eklendi: gonderim DENENMEDI', 0, slack_audit($s, 'field', $B['baslik']));

    bcc_notify_slack_new_field($C['id'], $C['baslik'], 'Baslik', 'single_line_text', 'ZZ');
    t('C\'ye alan eklendi: gonderim DENENMEDI', 0, slack_audit($s, 'field', $C['baslik']));

    bcc_notify_slack_new_field($A['id'], $A['baslik'], 'Baslik', 'single_line_text', 'ZZ');
    t('A\'ya alan eklendi: gonderim yapildi (tam 1)', 1, slack_audit($s, 'field', $A['baslik']));

    bcc_notify_slack_new_table($C['id'], 'ZZ');
    t('yeni tablo bildirimi: kendi webhook\'u olmayan tablo icin gonderim yok', 0, slack_audit($s, 'table', $C['id']));

    /* Toplu ozet (hucre degisikligi / yeni kayit) yolu. */
    foreach (array($A, $B) as $tbl) {
        bcc_execute(
            'INSERT INTO slack_watched_fields (team_id, table_id, field_id) VALUES (:tm, :t, :f)',
            array('tm' => $teamId, 't' => $tbl['id'], 'f' => $tbl['baslik'])
        );
    }
    $recA = kayit_kur($A['id'], $A['baslik'], 'A kaydi', $userId);
    $recB = kayit_kur($B['id'], $B['baslik'], 'B kaydi', $userId);

    $notifyOnce = sayac("SELECT COUNT(*) AS c FROM audit_log WHERE id > :s AND action = 'slack.notify_sent'", array('s' => $s));
    $sonucB = bcc_slack_flush_table($B['id'], 0);
    t('B bosaltildi: hicbir mesaj gitmedi', $notifyOnce,
        sayac("SELECT COUNT(*) AS c FROM audit_log WHERE id > :s AND action = 'slack.notify_sent'", array('s' => $s)));
    t('B bosaltma sonucu 0', 0, (int) $sonucB);
    t('B kaydi damgalandi (webhook sonradan baglanirsa yigin dusmez)', true,
        bcc_fetch_column('SELECT slack_notified_at FROM records WHERE id = :r', array('r' => $recB)) !== null);

    $sonucA = bcc_slack_flush_table($A['id'], 0);
    t('A bosaltildi: tam 1 mesaj gitti', $notifyOnce + 1,
        sayac("SELECT COUNT(*) AS c FROM audit_log WHERE id > :s AND action = 'slack.notify_sent'", array('s' => $s)));
    t('A bosaltma sonucu > 0', true, (int) $sonucA > 0);
    t('A kaydi damgalandi', true,
        bcc_fetch_column('SELECT slack_notified_at FROM records WHERE id = :r', array('r' => $recA)) !== null);

    echo "\n--- D) Acik kural, eski takim-geneli webhook'u hala hedefleyebiliyor ---\n";

    bcc_execute(
        "INSERT INTO fields (table_id, name, field_type, options, position) VALUES (:t, 'Marka', 'single_select', :o, 1)",
        array('t' => $B['id'], 'o' => json_encode(array('choices' => array('X', 'Y'))))
    );
    $markaB = (int) bcc_last_insert_id();
    bcc_execute(
        "INSERT INTO slack_routing_rules (team_id, table_id, field_id, operator, value, webhook_id, position)
         VALUES (:tm, :t, :f, 'equals', 'X', :w, 0)",
        array('tm' => $teamId, 't' => $B['id'], 'f' => $markaB, 'w' => $hookTeam)
    );
    $kuralId = (int) bcc_last_insert_id();

    $recBX = kayit_kur($B['id'], $markaB, 'X', $userId);
    $recBY = kayit_kur($B['id'], $markaB, 'Y', $userId);

    t('B\'de kurala UYAN kayit kuralin hedefine gidiyor', $hookTeam, hook_id($B['id'], $teamId, $recBX));
    t('B\'de kurala UYMAYAN kayit hicbir kanala gitmiyor', null, hook_id($B['id'], $teamId, $recBY));
    t('B\'nin kurali A\'yi etkilemiyor', $hookA, hook_id($A['id'], $teamId, $recA));

    echo "\n--- E) Ayar sayfasi (gercek uc nokta) ---\n";

    $r = post_as($userId, 'slack_settings.php', 'table_id=' . $C['id'], array('action' => 'goruntule', 'table_id' => $C['id']));
    t('sayfa aciliyor', 200, $r['status']);
    t('eski takim-geneli kart gorunuyor', true, strpos($r['body'], 'id="legacy-team-webhooks"') !== false);
    t('"Bu tabloya bagla" dugmesi var', true, strpos($r['body'], 'data-bind-webhook="' . $hookTeam . '"') !== false);
    t('"Yedek kanal ekle" formu KALDIRILDI', false, strpos($r['body'], 'Yedek kanal ekle') !== false);
    t('takim kapsamli form alani kalmadi', false, strpos($r['body'], 'name="scope" value="team"') !== false);

    $nullOnce = sayac('SELECT COUNT(*) AS c FROM slack_webhooks WHERE team_id = :t AND table_id IS NULL', array('t' => $teamId));
    $r = post_as($userId, 'slack_settings.php', 'table_id=' . $C['id'], array(
        'action' => 'save_webhook', 'scope' => 'team', 'table_id' => $C['id'],
        'webhook_url' => 'https://hooks.slack.com/services/ZZ/KAPSAM/test', 'channel_name' => '#zz-scope-team',
    ));
    t('scope=team gonderilse de istek calisiyor', 200, $r['status']);
    t('yeni takim-geneli webhook OLUSMADI', $nullOnce,
        sayac('SELECT COUNT(*) AS c FROM slack_webhooks WHERE team_id = :t AND table_id IS NULL', array('t' => $teamId)));
    t('webhook C tablosuna baglandi', 1,
        sayac("SELECT COUNT(*) AS c FROM slack_webhooks WHERE table_id = :tb AND channel_name = '#zz-scope-team'", array('tb' => $C['id'])));
    bcc_execute("DELETE FROM slack_webhooks WHERE table_id = :tb AND channel_name = '#zz-scope-team'", array('tb' => $C['id']));

    $r = post_as($userId, 'slack_settings.php', 'table_id=' . $C['id'], array(
        'action' => 'bind_team_webhook', 'table_id' => $C['id'], 'webhook_id' => $hookTeam,
    ));
    t('baska tablonun kurali varken baglama REDDEDILDI', true,
        bcc_fetch_column('SELECT table_id FROM slack_webhooks WHERE id = :i', array('i' => $hookTeam)) === null);
    t('ret nedeni kullaniciya soyleniyor', true, strpos($r['body'], 'yönlendirme kuralında kullanılıyor') !== false);

    bcc_execute('DELETE FROM slack_routing_rules WHERE id = :i', array('i' => $kuralId));

    $yabanciHook = webhook_kur($yabanciTeamId, null, '#zz-yabanci');
    $r = post_as($userId, 'slack_settings.php', 'table_id=' . $C['id'], array(
        'action' => 'bind_team_webhook', 'table_id' => $C['id'], 'webhook_id' => $yabanciHook,
    ));
    t('baska ekibin webhook\'u baglanamiyor (404)', 404, $r['status']);
    t('baska ekibin webhook\'u degismedi', true,
        bcc_fetch_column('SELECT table_id FROM slack_webhooks WHERE id = :i', array('i' => $yabanciHook)) === null);

    $r = post_as($userId, 'slack_settings.php', 'table_id=' . $C['id'], array(
        'action' => 'bind_team_webhook', 'table_id' => $C['id'], 'webhook_id' => $hookTeam,
    ));
    t('baglama istegi calisti', 200, $r['status']);
    t('webhook artik C tablosuna bagli', $C['id'],
        (int) bcc_fetch_column('SELECT table_id FROM slack_webhooks WHERE id = :i', array('i' => $hookTeam)));
    t('baglama denetim kaydina yazildi', 1,
        sayac("SELECT COUNT(*) AS c FROM audit_log WHERE id > :s AND action = 'slack.webhook_bind' AND entity_id = :e",
            array('s' => $auditBaslangic, 'e' => $C['id'])));
    t('baglandiktan sonra eski kart kayboldu', false, strpos($r['body'], 'id="legacy-team-webhooks"') !== false);
    t('C artik bu kanala gidiyor', $hookTeam, hook_id($C['id'], $teamId));
    t('B hala hicbir kanala gitmiyor', null, hook_id($B['id'], $teamId));
    t('A hala kendi kanalinda', $hookA, hook_id($A['id'], $teamId));

    $r = post_as($userId, 'slack_settings.php', 'table_id=' . $A['id'], array(
        'action' => 'bind_team_webhook', 'table_id' => $A['id'], 'webhook_id' => $hookTeam,
    ));
    t('zaten bagli webhook baska tabloya CEKILEMIYOR (404)', 404, $r['status']);
    t('C\'deki baglanti bozulmadi', $C['id'],
        (int) bcc_fetch_column('SELECT table_id FROM slack_webhooks WHERE id = :i', array('i' => $hookTeam)));

    echo "\n--- F) Kod incelemesi ---\n";

    $kaynak = file_get_contents(__DIR__ . '/../src/slack.php');
    t('webhook sorgusunda takim-geneli dali kalmadi', false, strpos($kaynak, 'table_id IS NULL AND team_id') !== false);
} catch (Throwable $e) {
    $kaldi++;
    echo "[HATA] " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}

temizle();

bcc_execute(
    "DELETE FROM audit_log WHERE id > :s AND user_id IS NULL AND team_id IS NULL AND action LIKE 'slack.%'",
    array('s' => $auditBaslangic)
);

foreach ($aktifWebhookIds as $wid) {
    bcc_execute('UPDATE slack_webhooks SET is_active = 1 WHERE id = :i', array('i' => (int) $wid));
}

echo "\n--- Kirlilik ---\n";

$sonrasi = olc();
foreach ($oncesi as $ad => $deger) {
    t("kirlilik yok: {$ad}", $deger, $sonrasi[$ad]);
}

echo "\n==== SONUC: {$gecti} gecti, {$kaldi} kaldi ====\n";

exit($kaldi > 0 ? 1 : 0);
