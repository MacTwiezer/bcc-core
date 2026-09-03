<?php
// TABLO COGALTMA — "kopya ile asil BIRBIRINDEN BAGIMSIZ olsun" istegi.
//
// ⚠️ ONEMLI AYRIM: GORUNUM cogaltmak BUNU YAPAMAZ. Gorunum, tablonun verisine
// bakan bir MERCEKtir: bir views satirini kopyalamak table_id'yi AYNI birakir,
// iki gorunum AYNI kayitlari gosterir ve birinde hucre degistirmek digerinde
// de gorunur. Bu bir hata degil, gorunumun tanimi. Gercekten bagimsiz kopya
// TABLO duzeyinde olur — bu test onu dogrular.
//
// Kullanici UC kez "kopyadan yaptigim degisiklik orijinali etkiliyor" diye
// bildirdi; olculdu ve dogru cikti. Bu yuzden gorunum menusundeki
// "Gorunumu cogalt" -> "Bagimsiz kopya olustur" oldu ve BU ucnoktayi
// (api/table_duplicate.php) cagiriyor; eski api/view_duplicate.php
// KALDIRILDI (bkz. bolum I).
//
// Kapsam:
//   A) Sema kopyalaniyor (alanlar, siralari, tipleri, zorunluluk)
//   B) BAGIMSIZLIK — asil test: kopyada degisiklik asli, aslinda degisiklik
//      kopyayi ETKILEMIYOR (iki yonlu)
//   C) views.config'teki ALAN-ID referanslari YENIDEN ESLENIYOR
//      (column_widths / grid_state sort-group-filter-hidden / kanban / form)
//   E) Dosya ekleri: DISKTE de kopyalaniyor (stored_name PAYLASILMIYOR)
//   F) autonumber_next tasiniyor (kopyada numaralar cakismasin)
//   G) "Kayitlari kopyalama" secenegi: sema gelir, veri gelmez
//   H) Yetki + dogrulama (owner isi, ad benzersizligi base kapsaminda)
//   I) Kontrast: gorunum cogaltma HALA veriyi PAYLASIYOR (beklenen davranis)
//
// ⚠️ GERCEK VERIYE DOKUNMAZ: kendi kullanicilarini/base'ini yaratir, siler.
//
// On kosul: Apache ayakta. Calistirma:
//   C:\php73\php.exe scripts\_verify_table_duplicate.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../config/database.php';

// Bu betik GERCEK uc noktalardan yaziyor; bir kayit/hucre degisikligi
// bcc_slack_dispatch() uzerinden CANLI Slack kanalina mesaj gonderiyordu
// (denetim turunda olculdu). Aktif webhooklar test suresince susturulur,
// kapanista geri acilir.
require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();
bcc_test_purge_own_audit();
require __DIR__ . '/../src/schema.php';
require __DIR__ . '/../src/audit.php';

define('BASE_URL', 'http://localhost');
define('OWNER_EMAIL', 'tdup.owner@bcc-test.local');
define('EDITOR_EMAIL', 'tdup.editor@bcc-test.local');
define('TEST_PASS', 'TDup!2026');

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

function eq($label, $actual, $expected)
{
    check($label, $actual === $expected,
        'beklenen ' . var_export($expected, true) . ', gelen ' . var_export($actual, true));
}

function http_request($method, $path, $cookie = null, $postFields = null)
{
    $headers = array();
    if ($cookie !== null) { $headers[] = 'Cookie: ' . $cookie; }
    $options = array('http' => array('method' => $method, 'ignore_errors' => true));
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['http']['content'] = http_build_query($postFields);
    }
    $options['http']['header'] = implode("\r\n", $headers);
    $body = @file_get_contents(BASE_URL . $path, false, stream_context_create($options));
    $status = 0; $newCookie = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
            if (stripos($h, 'Set-Cookie:') === 0) { $p = explode(';', substr($h, 11)); $newCookie = trim($p[0]); }
        }
    }
    return array('body' => (string) $body, 'cookie' => $newCookie, 'status' => $status);
}

function extract_csrf($html)
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/', $html, $m)) { return $m[1]; }
    return null;
}

function login($email)
{
    $r = http_request('GET', '/login.php');
    $c = $r['cookie'];
    $r = http_request('POST', '/login.php', $c, array(
        'email' => $email, 'password' => TEST_PASS, 'csrf_token' => extract_csrf($r['body']),
    ));
    return $r['cookie'] ? $r['cookie'] : $c;
}

function cell_text($recordId, $fieldId)
{
    return bcc_fetch_column(
        'SELECT value_text FROM cell_values WHERE record_id = :r AND field_id = :f',
        array('r' => $recordId, 'f' => $fieldId)
    );
}

$createdFiles = array();

$cleanup = function () use (&$createdFiles) {
    foreach (array(OWNER_EMAIL, EDITOR_EMAIL) as $mail) {
        $baseIds = array_column(bcc_fetch_all(
            'SELECT b.id FROM bases b INNER JOIN users u ON u.id = b.created_by WHERE u.email = :e',
            array(':e' => $mail)
        ), 'id');
        foreach ($baseIds as $bid) {
            // ⚠️ DOSYALAR, base SILINMEDEN ONCE temizlenir. Bu betik tabloyu
            // birden cok kez cogaltiyor (H bolumundeki ucnokta testleri dahil)
            // ve her cogaltma ekin FIZIKSEL kopyasini uretiyor; yalnizca elle
            // kaydedilen $createdFiles silinince o kopyalar diskte KALIYORDU.
            // Ölçüldü: her tam kosuda storage/attachments'a bir dosya birikiyordu
            // (birikmis 28 oksuz dosyanin bir kismi buradan geldi).
            // 'DELETE FROM bases' cascade ile attachments satirlarini goturur,
            // o yuzden okuma SIRASI onemli.
            foreach (bcc_fetch_all(
                'SELECT id FROM tables_meta WHERE base_id = :b', array(':b' => $bid)
            ) as $t) {
                bcc_delete_attachment_files_by_table((int) $t['id']);
            }
            bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $bid));
        }
        bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => $mail));
    }
    foreach ($createdFiles as $p) { if (is_file($p)) { @unlink($p); } }
};
$cleanup();
register_shutdown_function($cleanup);

try {
    $team = bcc_fetch_one("SELECT id FROM teams WHERE name = 'TY' LIMIT 1");
    if (!$team) { echo "HATA: TY ekibi yok.\n"; exit(1); }
    $teamId = (int) $team['id'];

    $mkUser = function ($email, $role) use ($teamId) {
        bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e, :h, :n, 0, 1)',
            array(':e' => $email, ':h' => password_hash(TEST_PASS, PASSWORD_DEFAULT), ':n' => 'TDup ' . $role));
        $uid = (int) bcc_last_insert_id();
        bcc_execute('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
            array(':t' => $teamId, ':u' => $uid, ':r' => $role));
        return $uid;
    };
    $ownerId = $mkUser(OWNER_EMAIL, 'owner');
    $mkUser(EDITOR_EMAIL, 'editor');

    bcc_execute('INSERT INTO bases (team_id, name, created_by) VALUES (:t, :n, :u)',
        array(':t' => $teamId, ':n' => 'TDup Base', ':u' => $ownerId));
    $baseId = (int) bcc_last_insert_id();

    bcc_execute('INSERT INTO tables_meta (base_id, name, description, position) VALUES (:b, :n, :d, 0)',
        array(':b' => $baseId, ':n' => 'Kaynak', ':d' => 'Aciklama'));
    $srcTable = (int) bcc_last_insert_id();

    // Alanlar: metin, secim (kanban/filtre icin), sayi, otomatik numara, dosya eki
    $selOpts = json_encode(array('choices' => array('Acik', 'Kapali')), JSON_UNESCAPED_UNICODE);
    $fieldDefs = array(
        array('Ad',    'single_line_text', null, 1),
        array('Durum', 'single_select',    $selOpts, 0),
        array('Adet',  'number',           null, 0),
        array('No',    'autonumber',       null, 0),
        array('Dosya', 'attachment',       null, 0),
    );
    $srcFields = array();
    foreach ($fieldDefs as $pos => $d) {
        bcc_execute('INSERT INTO fields (table_id, name, field_type, options, position, is_required) VALUES (:t, :n, :ft, :o, :p, :r)',
            array(':t' => $srcTable, ':n' => $d[0], ':ft' => $d[1], ':o' => $d[2], ':p' => $pos, ':r' => $d[3]));
        $srcFields[$d[0]] = (int) bcc_last_insert_id();
    }
    // autonumber sayaci ilerlemis olsun (F)
    bcc_execute('UPDATE fields SET autonumber_next = 7 WHERE id = :f', array(':f' => $srcFields['No']));

    // Kayitlar
    $srcRecords = array();
    foreach (array('Birinci', 'Ikinci') as $i => $adVal) {
        bcc_execute('INSERT INTO records (table_id, position, created_by) VALUES (:t, :p, :u)',
            array(':t' => $srcTable, ':p' => $i, ':u' => $ownerId));
        $rid = (int) bcc_last_insert_id();
        $srcRecords[] = $rid;
        bcc_execute('INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)',
            array(':r' => $rid, ':f' => $srcFields['Ad'], ':v' => $adVal));
    }

    // Dosya eki (E): gercek bir dosya yazilir
    $attDir = bcc_attachment_storage_dir_ensured();
    $srcStored = 'tdup_src_' . bin2hex(random_bytes(6)) . '.txt';
    file_put_contents($attDir . '/' . $srcStored, 'kaynak dosya icerigi');
    $createdFiles[] = $attDir . '/' . $srcStored;
    bcc_execute('INSERT INTO attachments (field_id, record_id, original_name, stored_name, mime_type, file_size, uploaded_by)
                 VALUES (:f, :r, :on, :sn, :mt, :fs, :u)',
        array(':f' => $srcFields['Dosya'], ':r' => $srcRecords[0], ':on' => 'not.txt',
              ':sn' => $srcStored, ':mt' => 'text/plain', ':fs' => 21, ':u' => $ownerId));

    // Gorunumler: grid (config ALAN-ID dolu) + form
    $gridConfig = json_encode(array(
        'column_widths' => array('row' => 80, 'f' . $srcFields['Ad'] => 300, 'f' . $srcFields['Adet'] => 150),
        'grid_state' => array(
            'sort_field_1' => $srcFields['Ad'], 'sort_dir_1' => 'asc',
            'group_field_1' => $srcFields['Durum'], 'group_dir_1' => 'asc',
            'filter_field_1' => $srcFields['Adet'], 'filter_cond_1' => 'gt', 'filter_value_1' => '5',
            'hidden_fields' => (string) $srcFields['No'],
        ),
        'kanban_field_id' => $srcFields['Durum'],
        'kanban_card_fields' => array($srcFields['Ad'], $srcFields['Adet']),
        'form_fields' => array($srcFields['Ad'], $srcFields['Durum']),
    ), JSON_UNESCAPED_UNICODE);

    bcc_execute('INSERT INTO views (table_id, name, view_type, position, config, created_by) VALUES (:t, :n, :vt, 0, :c, :u)',
        array(':t' => $srcTable, ':n' => 'Tablo görünümü', ':vt' => 'grid', ':c' => $gridConfig, ':u' => $ownerId));
    $srcGridView = (int) bcc_last_insert_id();

    // Form gorunumu fiksturu KALDIRILDI (ayni gerekce).

    // =====================================================================
    echo "\n--- A) Sema kopyalaniyor ---\n";
    // =====================================================================
    $res = bcc_duplicate_table($srcTable, 'Kopya', true, $ownerId);
    check('A) cogaltma basarili', !empty($res['ok']), json_encode($res));
    $dupTable = (int) $res['id'];

    $dupFieldRows = bcc_fetch_all('SELECT id, name, field_type, position, is_required, autonumber_next
                                   FROM fields WHERE table_id = :t ORDER BY position', array('t' => $dupTable));
    eq('A) alan sayisi ayni', count($dupFieldRows), count($fieldDefs));
    $dupFields = array();
    foreach ($dupFieldRows as $f) { $dupFields[$f['name']] = (int) $f['id']; }
    eq('A) alan sirasi/adlari korundu',
        implode(',', array_column($dupFieldRows, 'name')), 'Ad,Durum,Adet,No,Dosya');
    eq('A) tipler korundu',
        implode(',', array_column($dupFieldRows, 'field_type')),
        'single_line_text,single_select,number,autonumber,attachment');
    eq('A) zorunluluk korundu', (int) $dupFieldRows[0]['is_required'], 1);
    // ⚠️ YENI alan id'leri olmali — ayni id'ler donseydi kopya asil tablonun
    // alanlarini paylasiyor demekti.
    check('A) alan id leri YENI (paylasim yok)',
        $dupFields['Ad'] !== $srcFields['Ad'] && $dupFields['Dosya'] !== $srcFields['Dosya']);

    // =====================================================================
    echo "\n--- B) BAGIMSIZLIK (asil istek) ---\n";
    // =====================================================================
    $dupRecords = array_column(bcc_fetch_all(
        'SELECT id FROM records WHERE table_id = :t ORDER BY position', array('t' => $dupTable)), 'id');
    eq('B) kayit sayisi ayni', count($dupRecords), 2);
    eq('B) hucre degerleri kopyalandi', cell_text($dupRecords[0], $dupFields['Ad']), 'Birinci');
    check('B) kayit id leri YENI', (int) $dupRecords[0] !== $srcRecords[0]);

    // KOPYADA degistir -> ASIL degismemeli
    bcc_execute('UPDATE cell_values SET value_text = :v WHERE record_id = :r AND field_id = :f',
        array('v' => 'KOPYADA DEGISTI', 'r' => $dupRecords[0], 'f' => $dupFields['Ad']));
    eq('B) kopyada degisiklik ASLI etkilemiyor', cell_text($srcRecords[0], $srcFields['Ad']), 'Birinci');

    // ASILDA degistir -> KOPYA degismemeli
    bcc_execute('UPDATE cell_values SET value_text = :v WHERE record_id = :r AND field_id = :f',
        array('v' => 'ASILDA DEGISTI', 'r' => $srcRecords[1], 'f' => $srcFields['Ad']));
    eq('B) asilda degisiklik KOPYAYI etkilemiyor', cell_text($dupRecords[1], $dupFields['Ad']), 'Ikinci');

    // =====================================================================
    echo "\n--- C) views.config ALAN-ID yeniden eslemesi ---\n";
    // =====================================================================
    // ⚠️ BU OLMADAN COGALTMA SESSIZCE BOZUK URETIR: kopyanin gorunumleri ESKI
    // tablonun alan id'lerini isaret eder, hicbir hata vermeden.
    $dupGrid = bcc_fetch_one("SELECT config FROM views WHERE table_id = :t AND view_type = 'grid'",
        array('t' => $dupTable));
    $cfg = json_decode($dupGrid['config'], true);

    check('C) column_widths yeni alan id lerine tasindi',
        isset($cfg['column_widths']['f' . $dupFields['Ad']])
        && !isset($cfg['column_widths']['f' . $srcFields['Ad']]));
    eq('C) column_widths degeri korundu', (int) $cfg['column_widths']['f' . $dupFields['Ad']], 300);
    eq('C) "row" anahtari (alan DEGIL) korundu', (int) $cfg['column_widths']['row'], 80);
    eq('C) sort_field yeniden eslendi', (int) $cfg['grid_state']['sort_field_1'], $dupFields['Ad']);
    eq('C) group_field yeniden eslendi', (int) $cfg['grid_state']['group_field_1'], $dupFields['Durum']);
    eq('C) filter_field yeniden eslendi', (int) $cfg['grid_state']['filter_field_1'], $dupFields['Adet']);
    eq('C) filtre degeri korundu', $cfg['grid_state']['filter_value_1'], '5');
    eq('C) hidden_fields yeniden eslendi', $cfg['grid_state']['hidden_fields'], (string) $dupFields['No']);
    eq('C) kanban_field_id yeniden eslendi', (int) $cfg['kanban_field_id'], $dupFields['Durum']);
    eq('C) kanban_card_fields yeniden eslendi',
        implode(',', $cfg['kanban_card_fields']), $dupFields['Ad'] . ',' . $dupFields['Adet']);
    eq('C) form_fields yeniden eslendi',
        implode(',', $cfg['form_fields']), $dupFields['Ad'] . ',' . $dupFields['Durum']);
    // Hicbir ESKI id kalmamali
    check('C) config te ESKI alan id si KALMADI',
        strpos($dupGrid['config'], '"f' . $srcFields['Ad'] . '"') === false);
    // D) bolumu (form gorunumu kopyalama: yeni token + fail-closed) KALDIRILDI —
    // herkese acik form ozelligi ve views.form_token / form_enabled kolonlari
    // migrations/023_drop_form_view.sql ile tamamen silindi.

    // =====================================================================
    echo "\n--- E) Dosya ekleri: DISKTE de kopyalandi ---\n";
    // =====================================================================
    $dupAtt = bcc_fetch_one('SELECT stored_name, original_name FROM attachments WHERE record_id = :r',
        array('r' => $dupRecords[0]));
    check('E) ek satiri kopyalandi', $dupAtt !== null && $dupAtt !== false);
    eq('E) orijinal ad korundu', $dupAtt['original_name'], 'not.txt');
    // ⚠️ stored_name PAYLASILSAYDI: kopyadaki kaydi silmek diskteki dosyayi
    // silip ASIL tablonun ekini bozardi.
    check('E) stored_name PAYLASILMIYOR', $dupAtt['stored_name'] !== $srcStored);
    $dupPath = bcc_attachment_storage_path($dupAtt['stored_name']);
    $createdFiles[] = $dupPath;
    check('E) yeni dosya DISKTE var', is_file($dupPath));
    eq('E) dosya icerigi ayni', is_file($dupPath) ? file_get_contents($dupPath) : null, 'kaynak dosya icerigi');
    check('E) kaynak dosya hala yerinde', is_file($attDir . '/' . $srcStored));

    // =====================================================================
    echo "\n--- F) autonumber_next tasindi ---\n";
    // =====================================================================
    eq('F) kayitla birlikte sayac tasindi',
        (int) bcc_fetch_column('SELECT autonumber_next FROM fields WHERE id = :f', array('f' => $dupFields['No'])), 7);

    // =====================================================================
    echo "\n--- G) Kayitsiz cogaltma (yalnizca sema) ---\n";
    // =====================================================================
    $res2 = bcc_duplicate_table($srcTable, 'Sadece Sema', false, $ownerId);
    check('G) cogaltma basarili', !empty($res2['ok']), json_encode($res2));
    $schemaOnly = (int) $res2['id'];
    eq('G) alanlar geldi',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM fields WHERE table_id = :t', array('t' => $schemaOnly)),
        count($fieldDefs));
    eq('G) KAYIT gelmedi',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t', array('t' => $schemaOnly)), 0);
    // 2 -> 1: form gorunumu fiksturu kaldirildi, geriye yalnizca grid kaldi.
    eq('G) gorunumler yine geldi',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id = :t', array('t' => $schemaOnly)), 1);
    // Kayit yoksa sayac 1'den baslamali — tasimanin anlami yok.
    $soNo = (int) bcc_fetch_column("SELECT autonumber_next FROM fields WHERE table_id = :t AND name = 'No'",
        array('t' => $schemaOnly));
    eq('G) kayitsizda autonumber 1 e sifirlandi', $soNo, 1);

    // =====================================================================
    echo "\n--- H) Ucnokta: yetki + dogrulama ---\n";
    // =====================================================================
    $ownerCookie = login(OWNER_EMAIL);
    $editorCookie = login(EDITOR_EMAIL);
    $g = http_request('GET', '/grid.php?table_id=' . $srcTable, $ownerCookie);
    $csrf = extract_csrf($g['body']);
    check('H) CSRF token bulundu', $csrf !== null);

    $r = http_request('GET', '/api/table_duplicate.php', $ownerCookie);
    eq('H) GET reddediliyor', $r['status'], 405);

    $r = http_request('POST', '/api/table_duplicate.php', $ownerCookie, array('table_id' => $srcTable));
    eq('H) CSRF YOKKEN 403', $r['status'], 403);

    $ec = extract_csrf(http_request('GET', '/grid.php?table_id=' . $srcTable, $editorCookie)['body']);
    $r = http_request('POST', '/api/table_duplicate.php', $editorCookie,
        array('table_id' => $srcTable, 'name' => 'Editor Kopya', 'csrf_token' => $ec));
    eq('H) editor 403 (owner isi)', $r['status'], 403);
    eq('H) reddedilen istek tablo OLUSTURMADI',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM tables_meta WHERE base_id = :b AND name = :n',
            array(':b' => $baseId, ':n' => 'Editor Kopya')), 0);

    $r = http_request('POST', '/api/table_duplicate.php', $ownerCookie,
        array('table_id' => $srcTable, 'name' => 'Kopya', 'csrf_token' => $csrf));
    eq('H) ayni base te mukerrer ad 422', $r['status'], 422);

    // Ad bos -> sunucu "X kopyasi" uretir ve cakisirsa sayi ekler
    $r = http_request('POST', '/api/table_duplicate.php', $ownerCookie,
        array('table_id' => $srcTable, 'name' => '', 'with_records' => '1', 'csrf_token' => $csrf));
    $d = json_decode($r['body'], true);
    eq('H) ad bosken 200', $r['status'], 200);
    check('H) otomatik ad uretildi', !empty($d['ok']) && !empty($d['table_id']), $r['body']);
    eq('H) redirect_url SUNUCUDAN', isset($d['redirect_url']) ? $d['redirect_url'] : null,
        '/grid.php?table_id=' . (int) $d['table_id']);
    eq('H) uretilen ad "Kaynak kopyasi" deseninde',
        bcc_fetch_column('SELECT name FROM tables_meta WHERE id = :i', array('i' => (int) $d['table_id'])),
        'Kaynak kopyası');
    eq('H) kayit sayisi bildirildi', isset($d['record_count']) ? (int) $d['record_count'] : -1, 2);

    // =====================================================================
    echo "\n--- I) KONTRAST: YENI GORUNUM veriyi PAYLASIR (tablo cogaltma paylasmaz) ---\n";
    // =====================================================================
    // ⚠️ BU BOLUM DEGISTI. Eskiden api/view_duplicate.php'yi cagiriyordu;
    // o ucnokta KALDIRILDI (bkz. asagisi) ve kontrast artik ayni kavrami
    // gosteren KALAN yol uzerinden olculuyor: "+ Yeni olustur..."
    // (api/view_create.php) ayni tablonun yeni bir gorunumunu yaratir.
    //
    // Kontrastin amaci degismedi: gorunum bir MERCEKtir -- ayni table_id'ye
    // baglidir, kendi kayitlarini TASIMAZ. Gercekten bagimsiz kopya yalnizca
    // TABLO duzeyinde olur (yukaridaki A-H bolumleri).
    //
    // NEDEN view_duplicate.php KALDIRILDI: kullanici UC kez "kopyadan
    // yaptigim degisiklik orijinali etkiliyor" diye bildirdi. Menudeki
    // "Gorunumu cogalt" artik api/table_duplicate.php'yi cagiriyor
    // ("Bagimsiz kopya olustur"), yani o ucnoktanin UI cagirani kalmadi --
    // birakilsaydi istenmeyen davranis API uzerinden erisilebilir kalirdi.
    $viewsBefore = (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id = :t', array('t' => $srcTable));
    $r = http_request('POST', '/api/view_create.php', $ownerCookie,
        array('table_id' => $srcTable, 'view_type' => 'grid', 'csrf_token' => $csrf));
    $vd = json_decode($r['body'], true);
    check('I) yeni gorunum olusturuldu', !empty($vd['ok']) || $r['status'] === 200, $r['body']);
    eq('I) gorunum sayisi bir artti',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM views WHERE table_id = :t', array('t' => $srcTable)),
        $viewsBefore + 1);
    $newViewTableId = (int) bcc_fetch_column(
        'SELECT table_id FROM views WHERE table_id = :t AND id <> :v ORDER BY id DESC LIMIT 1',
        array('t' => $srcTable, 'v' => $srcGridView));
    eq('I) yeni gorunum AYNI tabloya bagli (veri PAYLASILIR)', $newViewTableId, $srcTable);
    eq('I) yeni gorunum YENI kayit uretmedi',
        (int) bcc_fetch_column('SELECT COUNT(*) FROM records WHERE table_id = :t', array('t' => $srcTable)), 2);
    // Kaldirilan ucnokta GERI GELMESIN: geri gelirse "bagimsiz kopya"
    // beklentisi yeniden sessizce bozulurdu.
    check('I) api/view_duplicate.php KALDIRILDI (UI cagirani kalmadi)',
        !is_file(__DIR__ . '/../public/api/view_duplicate.php'));

    $cleanup();
} catch (Throwable $e) {
    echo "\nISTISNA: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $cleanup();
    $results[] = false;
}

$pass = count(array_filter($results));
$total = count($results);
echo "\n==================================\n";
echo ($pass === $total ? "SONUC: GECTI ($pass/$total)" : "SONUC: $pass/$total") . "\n";
echo "==================================\n";
exit($pass === $total ? 0 : 1);
