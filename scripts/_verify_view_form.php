<?php
// Grup View-Form dogrulamasi: gorunum turu ortak temeli + Form gorunumu.
//
// ⚠️ ODAK NOKTASI GUVENLIK: form_submit.php projenin TEK kimlik dogrulamasiz
// yazma uc noktasi. Bu betik oturumsuz (cerezsiz) istekler atarak butun
// kapilari ayri ayri zorlar: token, honeypot, nonce, alan whitelist'i,
// salt-okunur tipler, is_required, form_enabled.
//
// On kosul: Apache ayakta olmali (DocumentRoot = public, localhost:80).
// Calistirma: C:\php73\php.exe scripts\_verify_view_form.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/form_security.php';

define('BASE_URL', 'http://localhost');
define('TEST_EMAIL', 'viewform.test.owner@bcc-test.local');
define('TEST_PASS', 'ViewFormTest!2026');

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

// $cookie = null -> OTURUMSUZ istek (anonim kullaniciyi taklit eder).
// $followRedirects = false: PHP'nin http sarmalayicisi VARSAYILAN olarak
// yonlendirmeleri TAKIP EDER, bu yuzden 302 donen bir istekte $status hedef
// sayfanin 200'unu gosterir. Yonlendirmenin KENDISINI dogrulayan test icin
// takip kapatilmali (yoksa "302 mi dondu" sorusu asla olculemez).
function http_request($method, $path, $cookie = null, $postFields = null, $followRedirects = true)
{
    $headers = array();
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $options = array('http' => array('method' => $method, 'ignore_errors' => true));
    if (!$followRedirects) {
        $options['http']['follow_location'] = 0;
        $options['http']['max_redirects'] = 1;
    }

    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }

    $options['http']['header'] = implode("\r\n", $headers);

    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));

    $status = 0;
    $newCookie = null;
    $location = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
            if (stripos($h, 'Set-Cookie:') === 0) {
                $parts = explode(';', substr($h, 11));
                $newCookie = trim($parts[0]);
            }
            if (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
    }

    return array('body' => (string) $body, 'cookie' => $newCookie, 'status' => $status, 'location' => $location);
}

function extract_csrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));

$cleanup = function () {
    $baseIds = array_column(bcc_fetch_all(
        'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
        array(':e' => TEST_EMAIL)
    ), 'id');
    foreach ($baseIds as $baseId) {
        bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $baseId));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => TEST_EMAIL));
};

try {
    $teamId = (int) bcc_fetch_column("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$teamId) { echo "HATA: TY ekibi bulunamadi.\n"; exit(1); }

    bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
        array(':e' => TEST_EMAIL, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'ViewForm Test Owner'));
    $userId = (int) bcc_last_insert_id();
    bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)', array(':t' => $teamId, ':u' => $userId, ':r' => 'owner'));
    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)', array(':t' => $teamId, ':n' => 'ViewForm Test', ':u' => $userId));
    $baseId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO tables_meta (base_id, name, position) VALUES (:b, :n, 0)', array(':b' => $baseId, ':n' => 'Form Tablosu'));
    $tableId = (int) bcc_last_insert_id();

    $mkField = function ($name, $type, $pos, $options = null, $required = 0) use ($tableId) {
        bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position, is_required) VALUES (:t, :n, :ft, :o, :p, :r)',
            array(':t' => $tableId, ':n' => $name, ':ft' => $type, ':o' => $options, ':p' => $pos, ':r' => $required));
        return (int) bcc_last_insert_id();
    };

    // Formda GORUNECEK tipler
    $fAd    = $mkField('Ad', 'single_line_text', 0, null, 1);   // ZORUNLU
    $fMail  = $mkField('Eposta', 'email', 1);
    $fTel   = $mkField('Telefon', 'phone', 2);
    $fSayi  = $mkField('Sayi', 'number', 3);
    // Formda GORUNMEMESI gerekenler
    $fUzun  = $mkField('Uzun', 'long_text', 4);
    $fEk    = $mkField('Ek', 'attachment', 5);
    $fUser  = $mkField('Sorumlu', 'user', 6);
    $fCt    = $mkField('Olusturma', 'created_time', 7);
    $fCb    = $mkField('Olusturan', 'created_by', 8);

    // --- Oturum -----------------------------------------------------------
    $resp = http_request('GET', '/login.php');
    $cookie = $resp['cookie'];
    $resp = http_request('POST', '/login.php', $cookie, array('email' => TEST_EMAIL, 'password' => TEST_PASS, 'csrf_token' => extract_csrf($resp['body'])));
    if ($resp['cookie']) { $cookie = $resp['cookie']; }
    check('Giris yapildi (owner)', $cookie !== null);

    // =======================================================================
    // A) ORTAK TEMEL
    // =======================================================================
    echo "\n--- A) Ortak temel ---\n";
    check('A) BCC_VIEW_TYPES grid+form iceriyor',
        $GLOBALS['BCC_VIEW_TYPES'] === array('grid' => 'Tablo', 'form' => 'Form'),
        implode(',', array_keys($GLOBALS['BCC_VIEW_TYPES'])));
    check('A) bcc_view_route_for form -> form_edit.php',
        bcc_view_route_for('form', 1, 2) === '/form_edit.php?table_id=1&view_id=2');
    check('A) bcc_view_route_for grid -> grid.php',
        bcc_view_route_for('grid', 1, 2) === '/grid.php?table_id=1&view_id=2');
    check('A) bilinmeyen tur grid\'e duser (fail-safe)',
        bcc_view_route_for('kanban', 1, 2) === '/grid.php?table_id=1&view_id=2');

    $gridPage = http_request('GET', "/grid.php?table_id={$tableId}", $cookie);
    $csrf = extract_csrf($gridPage['body']);
    check('A) Tip secici menusu grid.php\'de render edildi',
        strpos($gridPage['body'], 'gs-view-create-panel') !== false
        && strpos($gridPage['body'], 'data-view-type="form"') !== false);

    // Gecersiz tur reddedilmeli
    $resp = http_request('POST', '/api/view_create.php', $cookie, array('csrf_token' => $csrf, 'table_id' => $tableId, 'view_type' => 'kanban'));
    check('A) Gecersiz view_type REDDEDILDI (422)', $resp['status'] === 422, 'durum: ' . $resp['status'] . ' ' . $resp['body']);

    // =======================================================================
    // B) FORM GORUNUMU OLUSTURMA
    // =======================================================================
    echo "\n--- B) Form gorunumu olusturma ---\n";
    $resp = http_request('POST', '/api/view_create.php', $cookie, array('csrf_token' => $csrf, 'table_id' => $tableId, 'view_type' => 'form'));
    $j = json_decode($resp['body'], true);
    check('B) Form gorunumu olusturuldu', is_array($j) && !empty($j['ok']), $resp['body']);
    $formViewId = (int) $j['view_id'];
    check('B) Ad ture gore numaralandi ("Form 1")', $j['name'] === 'Form 1', $j['name']);
    check('B) redirect_url form_edit.php\'ye isaret ediyor',
        strpos($j['redirect_url'], '/form_edit.php') === 0, $j['redirect_url']);

    $viewRow = bcc_fetch_one('SELECT view_type, form_token, form_enabled FROM views WHERE id = :id', array(':id' => $formViewId));
    check('B) view_type DB\'de "form"', $viewRow['view_type'] === 'form', $viewRow['view_type']);
    check('B) form_token uretildi (32 hex)', preg_match('/^[0-9a-f]{32}$/', (string) $viewRow['form_token']) === 1, (string) $viewRow['form_token']);
    check('B) form_enabled=1 (yeni form acik gelir)', (int) $viewRow['form_enabled'] === 1);
    $token = $viewRow['form_token'];

    // =======================================================================
    // C) GRID YONLENDIRMESI
    // =======================================================================
    echo "\n--- C) grid.php erken yonlendirmesi ---\n";
    $resp = http_request('GET', "/grid.php?table_id={$tableId}&view_id={$formViewId}", $cookie, null, false);
    check('C) Form view_id ile grid.php -> 302 form_edit.php',
        $resp['status'] === 302 && strpos((string) $resp['location'], '/form_edit.php') !== false,
        'durum: ' . $resp['status'] . ' konum: ' . $resp['location']);

    // =======================================================================
    // D) TASARIMCI (form_edit.php) — alan secimi
    // =======================================================================
    echo "\n--- D) form_edit.php tasarimcisi ---\n";
    $ed = http_request('GET', "/form_edit.php?table_id={$tableId}&view_id={$formViewId}", $cookie);
    check('D) form_edit.php acildi', $ed['status'] === 200, 'durum: ' . $ed['status']);
    check('D) Uyari metni var ("HERKES ... kayit ekleyebilir")',
        strpos($ed['body'], 'HERKES giriş yapmadan') !== false);
    check('D) Form linki token ile basildi', strpos($ed['body'], '/form.php?t=' . $token) !== false);
    foreach (array($fUzun => 'long_text', $fEk => 'attachment', $fUser => 'user', $fCt => 'created_time', $fCb => 'created_by') as $fid => $ftype) {
        check("D) '{$ftype}' alani secim listesinde YOK",
            strpos($ed['body'], 'value="' . $fid . '"') === false, "field_id {$fid} listede");
    }

    $edCsrf = extract_csrf($ed['body']);
    $resp = http_request('POST', '/form_edit.php', $cookie, array(
        'csrf_token' => $edCsrf, 'action' => 'save_form', 'table_id' => $tableId, 'view_id' => $formViewId,
        'form_enabled' => '1',
        'form_title' => 'Iletisim Formu',
        'form_description' => 'Bilgilerinizi birakin.',
        'form_success_message' => 'Tesekkurler!',
        'form_fields' => array($fAd, $fMail, $fTel, $fSayi, $fUzun, $fUser), // son ikisi ELENMELI
    ));
    check('D) Ayarlar kaydedildi', strpos($resp['body'], 'Form ayarları kaydedildi') !== false);
    $cfg = bcc_form_config_from_view(bcc_fetch_one('SELECT config FROM views WHERE id = :id', array(':id' => $formViewId)));
    check('D) Uygun OLMAYAN alanlar kayitta ELENDI',
        $cfg['form_fields'] === array($fAd, $fMail, $fTel, $fSayi), implode(',', $cfg['form_fields']));

    // =======================================================================
    // E) ANONIM FORM SAYFASI (form.php) — OTURUMSUZ
    // =======================================================================
    echo "\n--- E) Anonim form sayfasi (oturumsuz) ---\n";
    $pub = http_request('GET', "/form.php?t={$token}");   // cookie YOK
    check('E) Oturumsuz form acildi (200)', $pub['status'] === 200, 'durum: ' . $pub['status']);
    check('E) Baslik gorunuyor', strpos($pub['body'], 'Iletisim Formu') !== false);
    check('E) Aciklama gorunuyor', strpos($pub['body'], 'Bilgilerinizi birakin.') !== false);
    check('E) Honeypot alani basildi', strpos($pub['body'], 'name="' . BCC_FORM_HONEYPOT_FIELD . '"') !== false);
    check('E) Nonce basildi', preg_match('/name="nonce" value="(\d+\.[0-9a-f]{64})"/', $pub['body'], $nm) === 1);
    $nonce = isset($nm[1]) ? $nm[1] : '';
    foreach (array($fAd, $fMail, $fTel, $fSayi) as $fid) {
        check("E) Secili alan f{$fid} formda var", strpos($pub['body'], 'name="f' . $fid . '"') !== false);
    }
    foreach (array($fUzun => 'long_text', $fEk => 'attachment', $fUser => 'user', $fCt => 'created_time', $fCb => 'created_by') as $fid => $ftype) {
        check("E) '{$ftype}' formda HIC YOK", strpos($pub['body'], 'name="f' . $fid . '"') === false);
    }
    check('E) Tablo/base meta verisi SIZMIYOR (tablo adi yok)',
        strpos($pub['body'], 'Form Tablosu') === false);
    check('E) robots noindex var', strpos($pub['body'], 'noindex') !== false);

    $recordsBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t', array(':t' => $tableId));

    // =======================================================================
    // F) GUVENLIK KAPILARI
    // =======================================================================
    echo "\n--- F) Guvenlik kapilari (oturumsuz) ---\n";

    // F1 — NONCE: sayfayi hic acmadan dogrudan POST
    $resp = http_request('POST', '/api/form_submit.php', null, array('t' => $token, 'f' . $fAd => 'Bot', 'nonce' => 'sahte.imza'));
    check('F) Nonce\'suz/sahte POST REDDEDILDI (422)', $resp['status'] === 422, 'durum: ' . $resp['status'] . ' ' . $resp['body']);

    // F2 — NONCE cok hizli (3sn'den once)
    $freshNonce = bcc_form_nonce();
    $resp = http_request('POST', '/api/form_submit.php', null, array('t' => $token, 'f' . $fAd => 'Hizli', 'nonce' => $freshNonce));
    check('F) 3sn\'den HIZLI gonderim REDDEDILDI', $resp['status'] === 422, 'durum: ' . $resp['status'] . ' ' . $resp['body']);

    // Gecerli (yeterince eski) nonce uret
    $oldTs = (string) (time() - 10);
    $validNonce = $oldTs . '.' . hash_hmac('sha256', $oldTs, bcc_form_secret());

    // F3 — HONEYPOT dolu: BASARILI donmeli ama KAYIT OLUSMAMALI
    $resp = http_request('POST', '/api/form_submit.php', null, array(
        't' => $token, 'nonce' => $validNonce, 'f' . $fAd => 'Bot Kaydi',
        BCC_FORM_HONEYPOT_FIELD => 'http://spam.example',
    ));
    $j = json_decode($resp['body'], true);
    check('F) Honeypot dolu -> BASARILI yanit (bot geri bildirim ALMAZ)',
        is_array($j) && !empty($j['ok']), $resp['body']);
    $countAfterHp = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t', array(':t' => $tableId));
    check('F) Honeypot dolu -> KAYIT OLUSMADI', $countAfterHp === $recordsBefore,
        "once {$recordsBefore} sonra {$countAfterHp}");

    // F4 — is_required bos
    $resp = http_request('POST', '/api/form_submit.php', null, array('t' => $token, 'nonce' => $validNonce, 'f' . $fMail => 'a@b.com'));
    check('F) Zorunlu alan bos -> 422', $resp['status'] === 422, 'durum: ' . $resp['status'] . ' ' . $resp['body']);

    // F5 — whitelist DISI field_id enjeksiyonu (long_text + user + created_by)
    $resp = http_request('POST', '/api/form_submit.php', null, array(
        't' => $token, 'nonce' => $validNonce,
        'f' . $fAd => 'Enjeksiyon Testi',
        'f' . $fUzun => '<script>alert(1)</script>',
        'f' . $fUser => (string) $userId,
        'f' . $fCb => (string) $userId,
        'f' . $fEk => 'x',
    ));
    $j = json_decode($resp['body'], true);
    check('F) Whitelist disi alanlarla gonderim KABUL EDILDI (o alanlar atlanarak)',
        is_array($j) && !empty($j['ok']), $resp['body']);
    $injRecord = (int) bcc_fetch_column('SELECT id FROM records WHERE table_id = :t ORDER BY id DESC LIMIT 1', array(':t' => $tableId));
    foreach (array($fUzun => 'long_text', $fUser => 'user', $fCb => 'created_by', $fEk => 'attachment') as $fid => $ftype) {
        $leak = bcc_fetch_column('SELECT COUNT(*) FROM cell_values WHERE record_id = :r AND field_id = :f',
            array(':r' => $injRecord, ':f' => $fid));
        check("F) '{$ftype}' alanina HICBIR SEY yazilmadi", (int) $leak === 0, "cell_values satiri: {$leak}");
    }
    check('F) Izinli alan yazildi (Ad)',
        bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
            array(':r' => $injRecord, ':f' => $fAd)) === 'Enjeksiyon Testi');

    // F6 — form_enabled = 0
    bcc_execute('UPDATE views SET form_enabled = 0 WHERE id = :id', array(':id' => $formViewId));
    $resp = http_request('GET', "/form.php?t={$token}");
    check('F) form_enabled=0 -> form.php 404', $resp['status'] === 404, 'durum: ' . $resp['status']);
    $resp = http_request('POST', '/api/form_submit.php', null, array('t' => $token, 'nonce' => $validNonce, 'f' . $fAd => 'Kapali'));
    check('F) form_enabled=0 -> gonderim 404', $resp['status'] === 404, 'durum: ' . $resp['status']);
    bcc_execute('UPDATE views SET form_enabled = 1 WHERE id = :id', array(':id' => $formViewId));

    // F7 — gecersiz / ardisik token
    $resp = http_request('GET', '/form.php?t=' . str_repeat('a', 32));
    check('F) Gecersiz token -> 404', $resp['status'] === 404, 'durum: ' . $resp['status']);
    $resp = http_request('GET', '/form.php?t=' . $formViewId);
    check('F) view_id ile erisim CALISMIYOR (ardisik id ile kesif kapali)', $resp['status'] === 404);

    // =======================================================================
    // G) BASARILI GONDERIM
    // =======================================================================
    echo "\n--- G) Basarili anonim gonderim ---\n";
    $before = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t', array(':t' => $tableId));
    $auditBefore = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'record.form_submit'");

    $resp = http_request('POST', '/api/form_submit.php', null, array(
        't' => $token, 'nonce' => $validNonce,
        'f' . $fAd => 'Acme A.S.', 'f' . $fMail => 'iletisim@acme.example',
        'f' . $fTel => '0212 555 00 00', 'f' . $fSayi => '42',
    ));
    $j = json_decode($resp['body'], true);
    check('G) Gonderim basarili', is_array($j) && !empty($j['ok']), $resp['body']);
    check('G) Tesekkur metni dondu', isset($j['message']) && $j['message'] === 'Tesekkurler!', json_encode($j));

    $after = (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t', array(':t' => $tableId));
    check('G) Yeni kayit olustu', $after === $before + 1, "once {$before} sonra {$after}");

    $rec = bcc_fetch_one('SELECT id, created_by FROM records WHERE table_id = :t ORDER BY id DESC LIMIT 1', array(':t' => $tableId));
    check('G) created_by NULL (anonim)', $rec['created_by'] === null, var_export($rec['created_by'], true));
    check('G) Ad degeri dogru',
        bcc_fetch_column('SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $rec['id'], ':f' => $fAd)) === 'Acme A.S.');
    check('G) Sayi degeri dogru',
        (float) bcc_fetch_column('SELECT value_number FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $rec['id'], ':f' => $fSayi)) === 42.0);

    $auditAfter = (int) bcc_fetch_column("SELECT COUNT(*) FROM audit_log WHERE action = 'record.form_submit'");
    check('G) audit_log\'a yazildi', $auditAfter === $auditBefore + 1);
    $auditRow = bcc_fetch_one("SELECT user_id FROM audit_log WHERE action = 'record.form_submit' ORDER BY id DESC LIMIT 1");
    check('G) audit_log.user_id NULL (anonim)', $auditRow['user_id'] === null, var_export($auditRow['user_id'], true));

    // =======================================================================
    // H) AUTONUMBER — 5. cagri yeri
    // =======================================================================
    echo "\n--- H) Autonumber (5. cagri yeri) ---\n";
    $edPage = http_request('GET', "/table_fields.php?table_id={$tableId}", $cookie);
    http_request('POST', '/table_fields.php', $cookie, array(
        'csrf_token' => extract_csrf($edPage['body']), 'action' => 'create_field',
        'table_id' => $tableId, 'name' => 'Kayit No', 'field_type' => 'autonumber',
    ));
    $fAuto = (int) bcc_fetch_column("SELECT id FROM fields WHERE table_id = :t AND field_type = 'autonumber'", array(':t' => $tableId));
    $nextBefore = (int) bcc_fetch_column('SELECT autonumber_next FROM fields WHERE id = :f', array(':f' => $fAuto));

    $resp = http_request('POST', '/api/form_submit.php', null, array(
        't' => $token, 'nonce' => $validNonce, 'f' . $fAd => 'Autonumber Testi',
    ));
    check('H) Autonumber alanli tabloda gonderim basarili', !empty(json_decode($resp['body'], true)['ok']), $resp['body']);
    $newRec = (int) bcc_fetch_column('SELECT id FROM records WHERE table_id = :t ORDER BY id DESC LIMIT 1', array(':t' => $tableId));
    $gotNum = (int) bcc_fetch_column('SELECT value_number FROM cell_values WHERE record_id = :r AND field_id = :f', array(':r' => $newRec, ':f' => $fAuto));
    check('H) Form kaydi DOGRU autonumber aldi', $gotNum === $nextBefore, "beklenen {$nextBefore} bulunan {$gotNum}");
    check('H) Sayac ilerledi',
        (int) bcc_fetch_column('SELECT autonumber_next FROM fields WHERE id = :f', array(':f' => $fAuto)) === $nextBefore + 1);

    // =======================================================================
    // I) STATIK KONTROLLER
    // =======================================================================
    echo "\n--- I) Statik kontroller ---\n";
    $submitSrc = file_get_contents(__DIR__ . '/../public/api/form_submit.php');
    check('I) form_submit.php api_require_login() CAGIRMIYOR (bilerek)',
        preg_match('/^\s*api_require_login\(\);/m', $submitSrc) === 0);
    check('I) form_submit.php sunucu whitelist\'ini kullaniyor',
        strpos($submitSrc, "bcc_field_allowed_in_form") !== false
        && strpos($submitSrc, "\$formConfig['form_fields']") !== false);
    check('I) form_submit.php normalize_cell_value() kullaniyor (kopya dogrulama YOK)',
        strpos($submitSrc, 'normalize_cell_value(') !== false);
    check('I) form_submit.php bcc_assign_autonumbers() cagiriyor (5. nokta)',
        strpos($submitSrc, 'bcc_assign_autonumbers(') !== false);
    check('I) Slack bildirimi kosula bagli (varsayilan kapali)',
        strpos($submitSrc, "!empty(\$formConfig['form_slack_notify'])") !== false);

    $schemaSrc = file_get_contents(__DIR__ . '/../src/schema.php');
    check('I) "BES cagri yeri" yorumu guncellendi',
        strpos($schemaSrc, 'BEŞ çağrı yeri') !== false);
    check('I) Salt-okunur liste tek kaynakta (satir ici literal kalmadi)',
        strpos($schemaSrc, "array('created_time', 'created_by', 'last_modified_time', 'last_modified_by', 'autonumber'), true)") === false);

    $themeCss = file_get_contents(__DIR__ . '/../public/assets/theme.css');
    foreach (array_keys($GLOBALS['BCC_VIEW_TYPES']) as $vt) {
        check("I) theme.css: .view-type-badge--{$vt} ikonu tanimli",
            preg_match('/\.view-type-badge--' . $vt . '\b[^{]*\{[^}]*--view-icon:/', $themeCss) === 1);
    }

    $colType = bcc_fetch_one("SELECT COLUMN_TYPE t, IS_NULLABLE n FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'views' AND COLUMN_NAME = 'form_token'");
    check('I) DB: views.form_token char(32) nullable',
        $colType && strpos($colType['t'], 'char(32)') === 0 && $colType['n'] === 'YES',
        $colType ? json_encode($colType) : 'KOLON YOK');
    check('I) DB: uq_views_form_token UNIQUE index var',
        (int) bcc_fetch_column("SELECT COUNT(*) FROM information_schema.STATISTICS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'views'
                                AND INDEX_NAME = 'uq_views_form_token' AND NON_UNIQUE = 0") === 1);

    echo "\n";
} catch (Exception $e) {
    echo "\nISTISNA: " . $e->getMessage() . "\n";
    $results[] = false;
}

$cleanup();
echo "Temizlik tamam (test kullanicisi/base'i silindi).\n";

$passed = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo 'SONUC: ' . ($passed === $total ? 'GECTI' : 'KALDI') . " ({$passed}/{$total})\n";
echo "==================================\n";
exit($passed === $total ? 0 : 1);
