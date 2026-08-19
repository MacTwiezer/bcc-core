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

api_require_login();

// Geçmiş penceresi. Sorgu filtresi TEK BAŞINA yeterli: 15 günden eski satırlar
// listede görünmez. Fiziksel silme ayrı bir adım (fırsatçı temizlik, Adım 11) —
// projede zamanlanmış görev (cron) altyapısı YOK, o yüzden buraya bağlanacak.
const BCC_NOTE_VIEW_WINDOW_DAYS = 15;

// Çok bakılan bir notta 15 günde yüzlerce satır birikebilir; açılır liste
// sonsuz uzayamaz. En yeni 200 kayıt pratikte fazlasıyla yeterli.
const BCC_NOTE_VIEW_LIST_LIMIT = 200;

// Süreyi ekranda okunur metne çevirir: "45 sn" / "2 dk 18 sn" / "1 sa 05 dk".
// Sunucuda biçimlendiriliyor çünkü Türkçe kısaltmalar ve dakika/saniye eşiği
// bir SUNUM kuralıdır ve tek yerde durmalı — istemcide ikinci bir kopya olsaydı
// ileride biri değişip diğeri kalırdı.
function bcc_note_view_duration_text($seconds)
{
    if ($seconds === null) {
        return null;
    }

    $seconds = (int) $seconds;

    if ($seconds < 60) {
        return $seconds . ' sn';
    }

    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' dk ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . ' sn';
    }

    return intdiv($seconds, 3600) . ' sa ' . str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . ' dk';
}

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

// LEFT JOIN (INNER değil): fk_rvl_user ON DELETE CASCADE olduğu için normalde
// öksüz satır OLUŞAMAZ. Yine de LEFT tercih edildi — denetim verisinde eksik
// bir satır, "Bilinmeyen kullanıcı" yazan bir satırdan DAHA KÖTÜDÜR: ileride
// FK kontrolleri kapatılarak yapılan bir toplu içe aktarma öksüz satır
// bırakırsa, o satır listeden SESSİZCE kaybolmasın.
//
// Sıralama opened_at DESC: en son inceleyen EN ÜSTTE (istenen davranış).
// idx_rvl_record_opened (record_id, opened_at) bu sorgunun tamamını karşılar —
// eşitlik + aralık + sıralama tek index üzerinden, filesort YOK.
//
// Pencere ve limit sabitleri sorguya BİRLEŞTİRME ile giriyor: MySQL
// "INTERVAL :days DAY" ve "LIMIT :n" biçiminde parametre bağlamaya izin vermez.
// İkisi de kodda tanımlı sabit (kullanıcı girdisi DEĞİL), enjeksiyon riski yok.
$rows = bcc_fetch_all(
    'SELECT rvl.id, rvl.user_id, rvl.role_at_view, rvl.opened_at,
            rvl.closed_at, rvl.duration_seconds, u.full_name
       FROM record_view_log rvl
       LEFT JOIN users u ON u.id = rvl.user_id
      WHERE rvl.record_id = :record_id
        AND rvl.opened_at >= (NOW() - INTERVAL ' . BCC_NOTE_VIEW_WINDOW_DAYS . ' DAY)
      ORDER BY rvl.opened_at DESC
      LIMIT ' . BCC_NOTE_VIEW_LIST_LIMIT,
    array('record_id' => $recordId)
);

// FIRSATÇI TEMİZLİK — 15 günden eski satırlar fiziksel olarak silinir.
//
// Neden burada ve neden rastgele: projede zamanlanmış görev (cron / Windows
// Task Scheduler / MySQL EVENT) altyapısı YOK ve yalnızca bunun için bir tane
// kurmak dağıtım+izleme yükü getirirdi. Bunun yerine, zaten bu tabloya bakan
// tek uçnokta ara sıra süpürüyor — src/auth.php'deki bcc_touch_user_activity()
// ile AYNI "yan etki olarak bakım" deseni.
//
// Yüzde 1: her istekte silmek gereksiz (aynı satırlar defalarca taranırdı),
// hiç silmemek ise tabloyu sonsuza dek büyütürdü.
//
// LIMIT 500: tek istekte uzun bir kilit tutmasın. Bir turda temizlenemeyen
// satırlar sonraki turlarda gider — 15 günlük filtre onları zaten LİSTEDE
// göstermiyor, yani gecikmenin kullanıcıya yansıyan bir etkisi yok.
//
// closed_at NULL olan (tamamlanmamış) satırlar da opened_at üzerinden
// kapsanır — yoksa tarayıcısı çöken kullanıcıların satırları hiç silinmezdi.
if (mt_rand(1, 100) === 1) {
    bcc_execute(
        'DELETE FROM record_view_log
          WHERE opened_at < (NOW() - INTERVAL ' . BCC_NOTE_VIEW_WINDOW_DAYS . ' DAY)
          LIMIT 500'
    );
}

$views = array();
foreach ($rows as $row) {
    // closed_at NULL = inceleme tamamlanmamış (sekme hâlâ açık ya da tarayıcı
    // kapanmış). Satır GİZLENMİYOR — "baktı ama ne kadar baktığı bilinmiyor"
    // bilgisi de bir denetim bilgisidir; arayüz is_open ile ayırt eder.
    $isOpen = $row['closed_at'] === null;

    $views[] = array(
        'id' => (int) $row['id'],
        'user_name' => $row['full_name'] !== null ? $row['full_name'] : 'Bilinmeyen kullanıcı',
        'role_at_view' => $row['role_at_view'],
        'opened_at' => $row['opened_at'],
        'opened_at_display' => date('d.m.Y H:i', strtotime($row['opened_at'])),
        'duration_seconds' => $isOpen ? null : (int) $row['duration_seconds'],
        'duration_display' => bcc_note_view_duration_text($isOpen ? null : $row['duration_seconds']),
        'is_open' => $isOpen,
    );
}

echo json_encode(array('ok' => true, 'views' => $views), JSON_UNESCAPED_UNICODE);
