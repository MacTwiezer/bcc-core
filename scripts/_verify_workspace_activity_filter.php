<?php
// Calisma alani "Son Hareketler" akisinin (bcc_workspace_activity) gurultu
// filtresini dogrular.
//
// Panelin kurali: akis "kim neyi DEGISTIRDI" sorusuna cevap verir, "kim ne
// zaman bakti/indirdi"ye degil. Bu yuzden base.open, user.login/logout ve TUM
// export izleri elenir.
//
// BULUNAN GERCEK TUTARSIZLIK: export'lar bir LISTEYLE eleniyordu
// ('view.export_xlsx', 'team_member.export_xlsx') ve liste BAYATLAMISTI. Canli
// audit_log'da team_id'si DOLU olan 'note_view.export_xlsx' (1),
// 'view.export_csv' (5) ve 'team_member.export_csv' (3) satirlari da vardi ve
// panelin kendi kuralina aykiri olarak akista GORUNUYORLARDI.
//
// Duzeltme: adlandirma sozlesmesi "<varlik>.export_<bicim>" oldugu icin eleme
// artik desenle yapiliyor (NOT LIKE '%.export/_%' ESCAPE '/'), yani bugunku ve
// sonradan eklenecek tum bicimleri kapsiyor. Desendeki "_" LITERAL olmak
// zorunda - ESCAPE olmadan tek karakter jokeri olurdu ve "xexportY" gibi
// alakasiz adlari da elerdi.
//
// Calistirma: C:\php73\php.exe scripts\_verify_workspace_activity_filter.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/_test_slack_guard.php';
bcc_test_silence_slack();

define('PROBE_TEAM', 'ZZ Hareket Akisi Filtresi');
define('PROBE_MAIL', 'activity.owner@bcc-test.local');

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$cleanup = function () {
    foreach (bcc_fetch_all('SELECT id FROM teams WHERE name = :n', array(':n' => PROBE_TEAM)) as $x) {
        bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array(':t' => $x['id']));
        bcc_execute('DELETE FROM teams WHERE id = :i', array(':i' => $x['id']));
    }
    bcc_execute('DELETE FROM users WHERE email = :e', array(':e' => PROBE_MAIL));
};
$cleanup();
register_shutdown_function($cleanup);

bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => PROBE_TEAM));
$teamId = (int) bcc_last_insert_id();
bcc_execute('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:e,:h,:n,0,1)',
    array(':e' => PROBE_MAIL, ':h' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), ':n' => 'Akis Owner'));
$userId = (int) bcc_last_insert_id();

// ---------------------------------------------------------------------------
echo "A) Elenmesi ve kalmasi gereken eylemler\n";
// ---------------------------------------------------------------------------
// audit_log'a DOGRUDAN yazilir: log_audit() oturumdaki kullaniciya bagli, bu
// betikte oturum yok ve testin olctugu sey zaten SORGUNUN filtresi.
$elenecek = array(
    'base.open',
    'user.login',
    'user.logout',
    'view.export_xlsx',
    'team_member.export_xlsx',
    // Listede OLMAYAN, yalnizca desenin yakaladiklari — asil bulgu bunlar:
    'note_view.export_xlsx',
    'view.export_csv',
    'team_member.export_csv',
    // Henuz var olmayan bir bicim de kapsanmali (liste bayatlamasin diye desen).
    'view.export_pdf',
);
$kalacak = array(
    'record.create', 'cell.update', 'field.create', 'table.duplicate',
    'team_member.assign', 'view.create', 'attachment.upload', 'base.create',
);

foreach (array_merge($elenecek, $kalacak) as $action) {
    bcc_execute(
        'INSERT INTO audit_log (user_id, team_id, action, entity_type, entity_id, details) VALUES (:u, :t, :a, :et, NULL, NULL)',
        array(':u' => $userId, ':t' => $teamId, ':a' => $action, ':et' => 'table')
    );
}

$akis = bcc_workspace_activity($teamId, 50);
$gorunen = array();
foreach ($akis as $satir) { $gorunen[] = $satir['action']; }

foreach ($elenecek as $a) {
    check('A) "' . $a . '" ELENDI', !in_array($a, $gorunen, true), implode(', ', $gorunen));
}
foreach ($kalacak as $a) {
    check('A) "' . $a . '" akista KALDI', in_array($a, $gorunen, true), implode(', ', $gorunen));
}
check('A) baska hicbir satir sizmadi', count($gorunen) === count($kalacak),
    count($gorunen) . ' satir: ' . implode(', ', $gorunen));

// ---------------------------------------------------------------------------
echo "\nB) Desen FAZLA eleme yapmiyor\n";
// ---------------------------------------------------------------------------
// "_" LITERAL olmali: ESCAPE olmadan tek karakter jokeri olur ve asagidaki
// adlari da yanlislikla elerdi.
$yanlisElenmemeli = array('table.exportXdata', 'record.exports_list', 'view.reexport_note');
foreach ($yanlisElenmemeli as $a) {
    bcc_execute(
        'INSERT INTO audit_log (user_id, team_id, action, entity_type, entity_id, details) VALUES (:u, :t, :a, :et, NULL, NULL)',
        array(':u' => $userId, ':t' => $teamId, ':a' => $a, ':et' => 'table')
    );
}
$akis2 = bcc_workspace_activity($teamId, 50);
$gorunen2 = array();
foreach ($akis2 as $satir) { $gorunen2[] = $satir['action']; }
foreach ($yanlisElenmemeli as $a) {
    check('B) "' . $a . '" YANLISLIKLA elenmedi', in_array($a, $gorunen2, true), implode(', ', $gorunen2));
}

// ---------------------------------------------------------------------------
echo "\nC) KVKK: akis yalnizca ISTENEN ekibin satirlarini donduruyor\n";
// ---------------------------------------------------------------------------
bcc_execute('INSERT INTO teams (name) VALUES (:n)', array(':n' => PROBE_TEAM . ' 2'));
$teamId2 = (int) bcc_last_insert_id();
bcc_execute(
    'INSERT INTO audit_log (user_id, team_id, action, entity_type, entity_id, details) VALUES (:u, :t, :a, :et, NULL, NULL)',
    array(':u' => $userId, ':t' => $teamId2, ':a' => 'record.create', ':et' => 'table')
);
$idler1 = array();
foreach (bcc_workspace_activity($teamId, 50) as $s) { $idler1[] = (int) $s['id']; }
$yabanci = (int) bcc_fetch_column(
    'SELECT id FROM audit_log WHERE team_id = :t ORDER BY id DESC LIMIT 1', array(':t' => $teamId2)
);
check('C) diger ekibin satiri akista YOK', !in_array($yabanci, $idler1, true));
bcc_execute('DELETE FROM audit_log WHERE team_id = :t', array(':t' => $teamId2));
bcc_execute('DELETE FROM teams WHERE id = :i', array(':i' => $teamId2));

// ---------------------------------------------------------------------------
echo "\nD) limit sinirlaniyor (SQL'e gomuldugu icin)\n";
// ---------------------------------------------------------------------------
check('D) limit 0 -> en az 1', count(bcc_workspace_activity($teamId, 0)) >= 1);
check('D) limit 9999 -> patlamiyor, en fazla 50', count(bcc_workspace_activity($teamId, 9999)) <= 50);
check('D) sayisal olmayan limit patlamiyor', is_array(bcc_workspace_activity($teamId, 'abc')));

// ---------------------------------------------------------------------------
echo "\nE) Satirlar insan diline cevriliyor\n";
// ---------------------------------------------------------------------------
$ilk = !empty($akis2) ? $akis2[0] : null;
check('E) etiket uretiliyor', $ilk !== null && $ilk['label'] !== '' && $ilk['label'] !== null);
check('E) aktor adi dolu', $ilk !== null && $ilk['actor'] === 'Akis Owner', $ilk ? $ilk['actor'] : 'null');
check('E) goreli zaman uretiliyor', $ilk !== null && $ilk['ago'] !== '—', $ilk ? $ilk['ago'] : 'null');
// Bilinmeyen bir eylem sessizce bos etiket URETMEMELI (ham adi donmeli).
check('E) bilinmeyen eylemin etiketi ham adi', bcc_audit_action_label('zzz.bilinmeyen') === 'zzz.bilinmeyen',
    bcc_audit_action_label('zzz.bilinmeyen'));

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
