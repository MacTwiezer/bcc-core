<?php
// Temsilci not-inceleme (record_view_log) raporlamasının ORTAK çekirdeği.
//
// İKİ çıktı biçimi bu dosyayı paylaşır:
//   - api/note_view_list.php        -> interface.php'deki panel (JSON)
//   - api/note_view_export_xlsx.php -> aynı panelin "Excel indir" düğmesi
//
// Pencere sabiti, sorgu ve süre biçimlendirmesi BİLEREK tek yerde: bunlar
// ikinci kez yazılsaydı ileride biri (ör. 15 gün -> 30 gün) değişip diğeri
// kalırdı ve panelde görünen liste ile indirilen Excel BİRBİRİNİ TUTMAZDI.
// Bu, denetim verisinde sessiz ve tehlikeli bir tutarsızlık olurdu.

// Geçmiş penceresi. Sorgu filtresi TEK BAŞINA yeterli: 15 günden eski satırlar
// listede/raporda görünmez. Fiziksel silme ayrı bir adım (fırsatçı temizlik,
// bkz. bcc_note_view_sweep_old) — projede zamanlanmış görev (cron) altyapısı YOK.
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

// Bir notun son BCC_NOTE_VIEW_WINDOW_DAYS günlük inceleme satırları, EN YENİ ÜSTTE.
//
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
function bcc_note_view_rows($recordId)
{
    return bcc_fetch_all(
        'SELECT rvl.id, rvl.user_id, rvl.role_at_view, rvl.opened_at,
                rvl.closed_at, rvl.duration_seconds, u.full_name
           FROM record_view_log rvl
           LEFT JOIN users u ON u.id = rvl.user_id
          WHERE rvl.record_id = :record_id
            AND rvl.opened_at >= (NOW() - INTERVAL ' . BCC_NOTE_VIEW_WINDOW_DAYS . ' DAY)
          ORDER BY rvl.opened_at DESC
          LIMIT ' . BCC_NOTE_VIEW_LIST_LIMIT,
        array('record_id' => (int) $recordId)
    );
}

// Notun "başlığı" — birincil alanın (tablonun İLK alanı) metni. Excel raporunun
// hangi nota ait olduğu dosyanın içinden anlaşılsın diye. Hücre hiç yazılmamışsa
// LEFT JOIN sayesinde NULL döner; çağıran taraf kendi yedek metnini kullanır.
function bcc_note_view_record_title($recordId)
{
    $row = bcc_fetch_one(
        'SELECT cv.value_text
           FROM records r
           INNER JOIN fields f ON f.table_id = r.table_id
           LEFT JOIN cell_values cv ON cv.record_id = r.id AND cv.field_id = f.id
          WHERE r.id = :record_id
          ORDER BY f.position, f.id
          LIMIT 1',
        array('record_id' => (int) $recordId)
    );

    if (!$row || $row['value_text'] === null || trim((string) $row['value_text']) === '') {
        return '(başlıksız not)';
    }

    return (string) $row['value_text'];
}

// FIRSATÇI TEMİZLİK — pencereden eski satırlar fiziksel olarak silinir.
//
// Neden buradan ve neden rastgele: projede zamanlanmış görev (cron / Windows
// Task Scheduler / MySQL EVENT) altyapısı YOK ve yalnızca bunun için bir tane
// kurmak dağıtım+izleme yükü getirirdi. Bunun yerine, zaten bu tabloya bakan
// uçnokta ara sıra süpürüyor — src/auth.php'deki bcc_touch_user_activity()
// ile AYNI "yan etki olarak bakım" deseni.
//
// Yüzde 1: her istekte silmek gereksiz (aynı satırlar defalarca taranırdı),
// hiç silmemek ise tabloyu sonsuza dek büyütürdü.
//
// LIMIT 500: tek istekte uzun bir kilit tutmasın. Bir turda temizlenemeyen
// satırlar sonraki turlarda gider — pencere filtresi onları zaten LİSTEDE
// göstermiyor, yani gecikmenin kullanıcıya yansıyan bir etkisi yok.
//
// closed_at NULL olan (tamamlanmamış) satırlar da opened_at üzerinden
// kapsanır — yoksa tarayıcısı çöken kullanıcıların satırları hiç silinmezdi.
//
// ⚠️ YALNIZCA listeleme uçnoktasından çağrılır, Excel indirmeden DEĞİL: bir
// rapor indirme isteğinin yan etkisi olarak veri silmek şaşırtıcı olurdu.
function bcc_note_view_sweep_old()
{
    if (mt_rand(1, 100) !== 1) {
        return;
    }

    bcc_execute(
        'DELETE FROM record_view_log
          WHERE opened_at < (NOW() - INTERVAL ' . BCC_NOTE_VIEW_WINDOW_DAYS . ' DAY)
          LIMIT 500'
    );
}
