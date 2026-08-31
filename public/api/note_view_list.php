<?php
// AJAX uçnoktası: bir notun (records satırı) TEMSİLCİ İNCELEME GEÇMİŞİNİ
// listeler — interface.php'deki detay panelinde açılan "Temsilci İnceleme
// Geçmişi" alanını besler (Adım 9). Son 15 gün, EN YENİ ÜSTTE.
//
// GET + CSRF YOK: salt-okunur bir listeleme. comment_list.php ile AYNI desen
// (yazma uçnoktalarından farklı olarak api_require_post()/api_require_csrf()
// çağrılmaz — CSRF, durum DEĞİŞTİREN istekleri korur).
//
// YETKİ — iki katman:
//   1) require_team_access()            -> KVKK; team_id İSTEKTEN DEĞİL,
//                                          bcc_find_record() zincirinden gelir.
//   2) bcc_can_view_record_audits()     -> yalnızca owner. "Kim neye ne kadar
//                                          baktı" verisi personel gözetimi
//                                          niteliğindedir; İZLENEN TARAF
//                                          ('commenter') kendi verisini GÖRMEZ.
//
// ⚠️ Bu, projedeki İKİ FARKLI rol sorusunun ikincisi: note_view_start.php
// "bu kişi temsilci mi" (bcc_is_representative) diye sorar, burası "bu kişi
// geçmişi görebilir mi" (bcc_can_view_record_audits) diye. İkisi asla
// birbirinin yerine kullanılmamalı (bkz. src/auth.php'deki notlar).

require __DIR__ . '/../../src/api_bootstrap.php';
// Pencere sabiti, sorgu ve süre biçimlendirmesi ORTAK modülden — aynı veriyi
// .xlsx olarak veren api/note_view_export_xlsx.php ile TEK kaynağı paylaşır,
// ikisi birbirinden ayrı düşemesin diye (bkz. o dosyanın başlığı).
require __DIR__ . '/../../src/note_view_report.php';

api_require_login();

$recordId = isset($_GET['record_id']) ? (int) $_GET['record_id'] : 0;

$record = bcc_find_record($recordId);
if (!$record) {
    json_fail(404, 'Kayıt bulunamadı.');
}

require_team_access($record['team_id']);

$role = current_user_role_in_team($record['team_id']);

if (!bcc_can_view_record_audits($role)) {
    json_fail(403, 'İnceleme geçmişini görüntüleme yetkiniz yok.');
}

// Sorgu ORTAK modülde (src/note_view_report.php): pencere, sıralama ve limit
// kararları Excel raporuyla BİREBİR aynı olsun diye tek yerde duruyor.
$rows = bcc_note_view_rows($recordId);

// Fırsatçı temizlik (pencereden eski satırların fiziksel silinmesi) ORTAK
// modülde — gerekçesi ve %1 oranı orada. Excel indirmeden BİLEREK
// çağrılmaz: rapor almanın yan etkisi olarak veri silmek şaşırtıcı olurdu.
bcc_note_view_sweep_old();

$views = array();
foreach ($rows as $row) {
    // closed_at NULL = inceleme tamamlanmamış (sekme hâlâ açık ya da tarayıcı
    // kapanmış). Satır GİZLENMİYOR — "baktı ama ne kadar baktığı bilinmiyor"
    // bilgisi de bir denetim bilgisidir; arayüz is_open ile ayırt eder.
    $isOpen = $row['closed_at'] === null;

    // ⚠️ AÇIK SATIRIN SÜRESİ ARTIK VAR (kullanıcı bildirdi: "kaç saniye baktığı
    // gözükmüyor"): api/note_view_ping.php not açık kaldığı sürece
    // duration_seconds'ı tazeliyor. Yani closed_at NULL olsa bile süre BİLİNİYOR
    // olabilir — "hâlâ bakıyor" ya da "kapanış olayı ulaşmadı, en son buraya
    // kadar bakmıştı" demektir. Süre gerçekten hiç yazılmamışsa (nabız bile
    // atamadan kesilmiş) eski davranış korunur.
    $hasDuration = $row['duration_seconds'] !== null;

    $views[] = array(
        'id' => (int) $row['id'],
        'user_name' => $row['full_name'] !== null ? $row['full_name'] : 'Bilinmeyen kullanıcı',
        'role_at_view' => $row['role_at_view'],
        'opened_at' => $row['opened_at'],
        // Saniye DAHİL: "saati saatine" görünsün diye (kullanıcı isteği).
        'opened_at_display' => date('d.m.Y H:i:s', strtotime($row['opened_at'])),
        // Kapanış saati de gösteriliyor — "13:54:21 → 13:55:07" okunduğunda
        // süre tek başına bir sayı olmaktan çıkıp doğrulanabilir hâle geliyor.
        'closed_at_display' => $isOpen ? null : date('H:i:s', strtotime($row['closed_at'])),
        'duration_seconds' => $hasDuration ? (int) $row['duration_seconds'] : null,
        'duration_display' => bcc_note_view_duration_text($hasDuration ? $row['duration_seconds'] : null),
        'is_open' => $isOpen,
    );
}

echo json_encode(array('ok' => true, 'views' => $views), JSON_UNESCAPED_UNICODE);
