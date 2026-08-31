<?php
// "Temsilci İnceleme Geçmişi" panelindeki Excel indirme düğmesi: bir notun son
// 15 günlük inceleme kayıtlarını .xlsx olarak indirir.
//
// api/note_view_list.php'nin AYNI verisinin ikinci bir çıktı biçimi —
// team_members_export_xlsx.php'nin team_members.php ile ilişkisiyle BİREBİR
// aynı desen. Sorgu, pencere sabiti ve süre biçimlendirmesi ORTAK bir yerden
// (src/note_view_report.php) gelir; ikinci bir kopya YAZILMAZ, yoksa ileride
// biri değişip diğeri kalırdı.
//
// GET + CSRF YOK: salt-okunur indirme (note_view_list.php ile aynı gerekçe;
// CSRF durum DEĞİŞTİREN istekleri korur).
//
// YETKİ — note_view_list.php ile BİREBİR AYNI iki katman:
//   1) require_team_access()        -> KVKK; team_id İSTEKTEN DEĞİL,
//                                      bcc_find_record() zincirinden gelir.
//   2) bcc_can_view_record_audits() -> yalnızca owner (platform admini
//                                      current_user_role_in_team() üzerinden
//                                      her ekipte 'owner' sayılır, bkz.
//                                      src/auth.php). İZLENEN TARAF
//                                      ('commenter') kendi verisini GÖRMEZ.
// ⚠️ Rapor kişisel gözetim verisi taşır: bu iki kontrol GEVŞETİLMEMELİ.

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/xlsx_writer.php';
require __DIR__ . '/../../src/note_view_report.php';

require_login();

$recordId = isset($_GET['record_id']) ? (int) $_GET['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    http_response_code(404);
    die('Kayıt bulunamadı.');
}

require_team_access($record['team_id']);

$role = current_user_role_in_team($record['team_id']);

if (!bcc_can_view_record_audits($role)) {
    http_response_code(403);
    die('İnceleme geçmişini görüntüleme yetkiniz yok.');
}

// Dönemin İKİ UCU da burada, TEK yerde hesaplanır ve hem Excel'in başlığına
// hem dosya adına aynı değerler gider — "raporda yazan tarihle dosya adındaki
// tarih farklı" durumu doğal olarak imkânsız olsun diye.
$periodEnd = time();
$periodStart = $periodEnd - (BCC_NOTE_VIEW_WINDOW_DAYS * 86400);

$rowsRaw = bcc_note_view_rows($recordId);

// Notun başlığı: raporun hangi nota ait olduğu Excel'de yazsın. Birincil alan
// (ilk sütun) grid/interface'te de "başlık" olarak kullanılan alandır.
$noteTitle = bcc_note_view_record_title($recordId);

$rows = array();
foreach ($rowsRaw as $r) {
    $isOpen = $r['closed_at'] === null;
    $duration = $r['duration_seconds'] === null
        ? 'süre kaydedilmedi'
        : ($isOpen ? 'en az ' . bcc_note_view_duration_text($r['duration_seconds'])
                   : bcc_note_view_duration_text($r['duration_seconds']));

    $rows[] = array(
        // bcc_csv_injection_guard(): isim kullanıcı verisi — "=" ile başlayan
        // bir ad Excel'de formül olarak çalışırdı (diğer export'larla AYNI koruma).
        bcc_csv_injection_guard($r['full_name'] !== null ? $r['full_name'] : 'Bilinmeyen kullanıcı'),
        bcc_csv_injection_guard(isset($GLOBALS['BCC_ROLE_LABELS'][$r['role_at_view']])
            ? $GLOBALS['BCC_ROLE_LABELS'][$r['role_at_view']]
            : $r['role_at_view']),
        date('d.m.Y H:i:s', strtotime($r['opened_at'])),
        $r['closed_at'] !== null ? date('d.m.Y H:i:s', strtotime($r['closed_at'])) : '',
        bcc_csv_injection_guard($duration),
        // Süre ayrıca HAM SANİYE olarak da veriliyor: Excel'de toplama/sıralama
        // yapılabilsin diye ("2 dk 18 sn" metniyle bu mümkün değil).
        $r['duration_seconds'] !== null ? (string) (int) $r['duration_seconds'] : '',
    );
}

// Başlık bloğu — kullanıcının istediği "dönemin başlangıcı ve sonu" burada.
// bcc_send_xlsx()'in $preamble parametresi (src/xlsx_writer.php) sütun
// başlıklarının DA üstüne yazar.
$preamble = array(
    array('Temsilci İnceleme Raporu'),
    array('Not', bcc_csv_injection_guard($noteTitle)),
    array('Dönem başlangıcı', date('d.m.Y H:i', $periodStart)),
    array('Dönem bitişi', date('d.m.Y H:i', $periodEnd)),
    array('Dönem uzunluğu', BCC_NOTE_VIEW_WINDOW_DAYS . ' gün'),
    array('Rapor tarihi', date('d.m.Y H:i')),
    array('Toplam inceleme', (string) count($rows)),
    array(),
);

$fileName = 'temsilci_inceleme_'
    . date('Y-m-d', $periodStart) . '_' . date('Y-m-d', $periodEnd) . '.xlsx';

log_audit(
    'note_view.export_xlsx',
    'record',
    $recordId,
    array(
        'row_count' => count($rows),
        'period_start' => date('c', $periodStart),
        'period_end' => date('c', $periodEnd),
    ),
    $record['team_id']
);

bcc_send_xlsx(
    $fileName,
    'Temsilci İnceleme',
    array('İnceleyen', 'Rol', 'Başlangıç', 'Bitiş', 'Süre', 'Süre (saniye)'),
    $rows,
    $preamble
);
