<?php
// Ortak test korumasi: regresyon betikleri CANLI Slack'e mesaj gondermesin.
//
// NEDEN VAR: regresyon betikleri uygulamanin GERCEK uc noktalarindan yaziyor
// (record_add / cell_update / cells_bulk_update / record_duplicate /
// table_create / table_duplicate). Bu uc noktalarin hepsi bcc_slack_dispatch()
// cagiriyor ve slack_webhooks'ta o ekip icin AKTIF bir satir varsa mesaj
// gercekten gidiyor. Iki webhook ekip GENELI kapsamda (table_id NULL), yani
// o ekipteki HERHANGI bir tabloya yazan her test tetikliyor.
//
// Bu teorik degil, olculdu: denetim turunda audit_log'da tek bir gunde 344
// 'slack.notify_sent' satiri bulundu; bunlarin 59'u tek bir regresyon
// kosusunda uretilmisti (10:47-10:50 araligi, hepsi ayni ekip). Yani suit her
// calistiginda gercek bir Slack kanalina onlarca mesaj dusuyordu.
//
// _verify_slack_integration.php bu korumayi KENDI ICINDE zaten tasiyordu
// (dosyasindaki nota bakin: "regresyon kosusu CANLI Slack kanalina mesaj
// gonderiyordu"). Ders orada ogrenilmis ama diger betiklere tasinmamisti;
// burasi o dersin ortak yeri.
//
// Kullanim (bootstrap'tan SONRA, ilk yazmadan ONCE):
//     require __DIR__ . '/_test_slack_guard.php';
//     bcc_test_silence_slack();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu dosya yalnizca komut satirindan kullanilabilir.\n");
}

// Aktif TUM webhooklari test suresince pasife alir ve kapanista geri acar.
//
// Neden ekibe gore degil de HEPSI: betikler kendi ekiplerini kosu sirasinda
// yaratiyor (id'ler onceden bilinmiyor) ve ekip GENELI kapsamli bir webhook
// beklenmedik bir tabloda da atesleniyor. Hepsini susturmak tek guvenli olcut.
//
// Geri acma, pasife almadan ONCE baglanir: ters sirada olsaydi aradaki her
// hata gercek webhooklari kalici olarak PASIF birakirdi.
//
// Donus: susturulan webhook sayisi (cagiran betik isterse yazdirabilir).
function bcc_test_silence_slack()
{
    $hooks = bcc_fetch_all('SELECT id FROM slack_webhooks WHERE is_active = 1');

    register_shutdown_function(function () use ($hooks) {
        foreach ($hooks as $h) {
            bcc_execute('UPDATE slack_webhooks SET is_active = 1 WHERE id = :i', array('i' => (int) $h['id']));
        }
    });

    foreach ($hooks as $h) {
        bcc_execute('UPDATE slack_webhooks SET is_active = 0 WHERE id = :i', array('i' => (int) $h['id']));
    }

    return count($hooks);
}

// Bu kosunun urettigi DENETIM satirlarini kapanista temizler.
//
// NEDEN: betikler gercek uc noktalardan yaziyor, dolayisiyla audit_log'a
// gercek satirlar dusuyor. Sonra test kullanicisi/ekibi siliniyor ama
// audit_log.user_id -> users ve audit_log.team_id -> teams FK'leri SET NULL
// oldugu icin SATIRLAR KALIYOR. Olculdu: tam suit kosusu audit_log'a 235 satir
// ekliyordu ve veritabaninda birikmis 4.878 tamamen oksuz satir vardi
// (en eskisi 2026-07-13).
//
// OLCUT — neden guvenli: yalnizca (a) BU kosuda olusmus (id > baslangic) VE
// (b) aktoru ARTIK VAR OLMAYAN (user_id IS NULL) satirlar siliniyor. Kosu
// sirasindaki gercek kullanici etkinligi kendi user_id'sini korur, bu yuzden
// asla silinmez. Ikinci kosul olmadan (duz "id > baslangic") es zamanli gercek
// etkinlik de silinirdi.
//
// SIRA: temizlik, kapanisin ICINDEN ikinci bir kapanis kaydederek erteleniyor.
// Boylece betigin KENDI temizligi (kullanici/ekip silme) ONCE calisir ve
// user_id o noktada zaten NULL olmus olur. PHP, kapanis sirasinda kaydedilen
// fonksiyonlari listenin sonuna ekler (bu davranis olcumle dogrulandi).
function bcc_test_purge_own_audit()
{
    $since = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');

    register_shutdown_function(function () use ($since) {
        register_shutdown_function(function () use ($since) {
            bcc_execute(
                'DELETE FROM audit_log WHERE id > :s AND user_id IS NULL',
                array('s' => $since)
            );
        });
    });
}
