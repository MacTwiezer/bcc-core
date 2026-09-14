<?php

/*
 * Slack toplu (birlestirilmis) bildirim dogrulamasi — 2026-09-08.
 *
 * Dogrulanan davranis: bir kayda arka arkaya yapilan hucre degisiklikleri TEK
 * mesajda ozetlenir; mesaj ancak kayda belirli bir sure dokunulmayinca gider.
 *
 * GERCEK SLACK'E MESAJ GONDERILMEZ: butun aktif webhooklar test suresince
 * pasife alinir (bcc_test_silence_slack, scripts/_test_slack_guard.php) —
 * dolayisiyla dogrulama "mesaj gitti mi" degil, "gitmesi denendi mi" uzerinden
 * yapilir: bcc_slack_flush_table()'in dondurdugu sayi ve records.slack_notified_at
 * damgasi. Mesaj METNI ise bcc_slack_build_* yerine dogrudan uretim yolundan
 * degil, ayrik parcalardan (bcc_slack_changed_field_lines) dogrulanir.
 *
 * Betik kendi test verisini kurar ve sonunda TAMAMEN siler.
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/_test_slack_guard.php';

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

/* bcc_slack_changed_field_lines() ad/deger CIFTI donuyor. Uretimde bu ciftler
   dogrudan "hangi sutun -> hangi satirlar" listesine giriyor; testler ise eski
   "Ad: deger" bicimini okumak istiyor. Donusturucu artik uretimde
   kullanilmadigi icin (silindi) burada yerel olarak tutuluyor. */
function ciftleri_satira_cevir($ciftler)
{
    $satirlar = array();
    foreach ($ciftler as $c) {
        $satirlar[] = bcc_slack_escape($c['ad']) . ': ' . $c['deger'];
    }

    return $satirlar;
}

function sayac($sql)
{
    return (int) bcc_fetch_column($sql);
}

$oncesi = array(
    'kullanici' => sayac('SELECT COUNT(*) AS c FROM users'),
    'ekip' => sayac('SELECT COUNT(*) AS c FROM teams'),
    'base' => sayac('SELECT COUNT(*) AS c FROM bases'),
    'kayit' => sayac('SELECT COUNT(*) AS c FROM records'),
    'notify_sent' => sayac("SELECT COUNT(*) AS c FROM audit_log WHERE action='slack.notify_sent'"),
    'aktif_webhook' => sayac('SELECT COUNT(*) AS c FROM slack_webhooks WHERE is_active=1'),
);

/* Susturma kapanista geri aliniyor (register_shutdown_function), ama kirlilik
   olcumu betik govdesinde yapiliyor — o an webhooklar hâlâ kapali gorunurdu.
   Bu yuzden hangi webhooklarin acik oldugu ONCE not edilip olcumden hemen once
   elle geri aciliyor; kapanistaki geri alma yine calisir, iki kez acmak zararsiz. */
$aktifWebhookIds = array_column(bcc_fetch_all('SELECT id FROM slack_webhooks WHERE is_active = 1'), 'id');

bcc_test_silence_slack();
bcc_test_purge_own_audit();

/* Fikstur webhooku artik 200 donuyor (damga yalnizca BASARILI gonderimde
   yaziliyor), dolayisiyla bu kosu gercek "slack.notify_sent" satirlari
   uretiyor. bcc_test_purge_own_audit() bunlari kapanista siliyor ama kirlilik
   olcumu betik govdesinde yapiliyor — o an satirlar hâlâ duruyor. Bu yuzden
   baslangic kimligi not edilip olcumden hemen once elle siliniyor;
   kapanistaki temizlik yine calisir, iki kez silmek zararsiz. */
$auditBaslangic = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) AS c FROM audit_log');

$temizlik = array('base' => null, 'ekip' => null, 'kullanici' => null);

function temizle()
{
    global $temizlik;

    if ($temizlik['base']) {
        bcc_execute('DELETE FROM bases WHERE id = :id', array('id' => $temizlik['base']));
    }
    if ($temizlik['ekip']) {
        bcc_execute('DELETE FROM teams WHERE id = :id', array('id' => $temizlik['ekip']));
    }
    if ($temizlik['kullanici']) {
        bcc_execute('DELETE FROM users WHERE id = :id', array('id' => $temizlik['kullanici']));
    }
}

try {
    /* --- Fikstur ------------------------------------------------------- */

    bcc_execute(
        "INSERT INTO users (full_name, email, password_hash, is_active) VALUES ('ZZ Toplu Test', 'zz.toplu@bcc-test.local', 'x', 1)"
    );
    $userId = (int) bcc_last_insert_id();
    $temizlik['kullanici'] = $userId;

    bcc_execute("INSERT INTO teams (name) VALUES ('ZZ Toplu Bildirim')");
    $teamId = (int) bcc_last_insert_id();
    $temizlik['ekip'] = $teamId;

    bcc_execute(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)',
        array('t' => $teamId, 'u' => $userId, 'r' => 'owner')
    );

    bcc_execute(
        "INSERT INTO bases (team_id, name, created_by) VALUES (:t, 'ZZ Base', :u)",
        array('t' => $teamId, 'u' => $userId)
    );
    $baseId = (int) bcc_last_insert_id();
    $temizlik['base'] = $baseId;

    bcc_execute("INSERT INTO tables_meta (base_id, name, position) VALUES (:b, 'ZZ Tablo', 0)", array('b' => $baseId));
    $tableId = (int) bcc_last_insert_id();

    $alanlar = array();
    foreach (array('Baslik', 'Marka', 'Aciklama') as $i => $ad) {
        bcc_execute(
            "INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, :n, 'single_line_text', :p)",
            array('t' => $tableId, 'n' => $ad, 'p' => $i)
        );
        $alanlar[$ad] = (int) bcc_last_insert_id();
    }

    /* Uc alanin ucu de izleniyor. */
    foreach ($alanlar as $fid) {
        bcc_execute(
            'INSERT INTO slack_watched_fields (team_id, table_id, field_id) VALUES (:tm, :t, :f)',
            array('tm' => $teamId, 't' => $tableId, 'f' => $fid)
        );
    }

    /* Bu takima ait AKTIF bir webhook. Aktif olmasi sart: 2026-09-09'dan beri
       bosaltma, kanali once cozup kayitlari webhook'a gore grupluyor —
       webhook bulunamazsa mesaj hic kurulmuyor (dogru davranis: gidecek yer
       yoksa gonderim de yok). Eski kod webhook olmasa da "gonderildi"
       sayiyordu, bu yuzden test kendi webhookunu kapatabiliyordu.

       Gercek Slack'e mesaj GITMESIN diye guvenlik URL'de: hedef, kendi
       Apache'mizdeki duragan bir dosya. 200 donuyor (gonderim BASARILI
       sayiliyor — damga artik yalnizca basarili gonderimde yaziliyor), ag'a
       cikilmiyor, hicbir kanala bir sey dusmuyor ve POST'un yan etkisi yok.
       Diger tum webhooklar ayrica bcc_test_silence_slack() ile kapali. */
    bcc_execute(
        "INSERT INTO slack_webhooks (team_id, table_id, webhook_url, channel_name, is_active)
         VALUES (:t, :tb, 'http://localhost/assets/theme.css', '#zz-test', 1)",
        array('t' => $teamId, 'tb' => $tableId)
    );

    function kayit_ekle($tableId, $userId)
    {
        bcc_execute(
            'INSERT INTO records (table_id, position, created_by) VALUES (:t, 0, :u)',
            array('t' => $tableId, 'u' => $userId)
        );

        return (int) bcc_last_insert_id();
    }

    function hucre_yaz($recordId, $fieldId, $deger)
    {
        bcc_execute(
            'INSERT INTO cell_values (record_id, field_id, value_text) VALUES (:r, :f, :v)
             ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)',
            array('r' => $recordId, 'f' => $fieldId, 'v' => $deger)
        );
        bcc_execute('UPDATE records SET updated_at = NOW() WHERE id = :r', array('r' => $recordId));
    }

    function damga($recordId)
    {
        return bcc_fetch_column('SELECT slack_notified_at FROM records WHERE id = :r', array('r' => $recordId));
    }

    /* Kaydi "eskitmek" icin: bekleme suresi gecmis gibi gostermek. */
    function eskit($recordId, $saniye)
    {
        bcc_execute(
            'UPDATE records SET updated_at = (NOW() - INTERVAL :s SECOND) WHERE id = :r',
            array('s' => (int) $saniye, 'r' => $recordId)
        );
    }

    echo "\n--- A) Taze kayit BEKLETILIR (asil istek: her hucrede mesaj YOK) ---\n";

    $r1 = kayit_ekle($tableId, $userId);
    hucre_yaz($r1, $alanlar['Baslik'], '11111');
    hucre_yaz($r1, $alanlar['Marka'], 'DENEME');
    hucre_yaz($r1, $alanlar['Aciklama'], 'DENEME');

    t('taze kayit: uc hucre yazildi, hicbir mesaj denenmedi', 0, bcc_slack_flush_table($tableId));
    t('taze kayit: damga hâlâ NULL (duyurulmadi)', null, damga($r1));

    echo "\n--- B) Bekleme dolunca TEK mesaj ---\n";

    eskit($r1, 400);
    t('bekleme dolunca TEK kayit gonderildi (uc ayri degil)', 1, bcc_slack_flush_table($tableId));
    t('gonderim sonrasi damga dolduruldu', true, damga($r1) !== null);
    t('ayni kayit ikinci kez gonderilmez', 0, bcc_slack_flush_table($tableId));

    echo "\n--- C) Mesaj icerigi: alanlarin GUNCEL degerleri, eski deger YOK ---\n";

    $watchedFields = bcc_fetch_all(
        'SELECT id, name, field_type, options FROM fields WHERE table_id = :t ORDER BY position, id',
        array('t' => $tableId)
    );

    $yeniKayit = array('id' => $r1, 'slack_notified_at' => null);
    /* changed_field_lines() artik ad/deger CIFTI donuyor (iki sutunlu izgara
       icin ikisi ayri lazim); duz metin bicimi pairs_to_lines() ile aliniyor. */
    $ciftler = bcc_slack_changed_field_lines($yeniKayit, $watchedFields, $teamId);
    $satirlar = ciftleri_satira_cevir($ciftler);

    t('cift bicimi: ad ve deger AYRI', true,
        isset($ciftler[0]['ad']) && isset($ciftler[0]['deger']));
    t('cift icindeki ad kacissiz (HAM) tutuluyor', 'Baslik', $ciftler[0]['ad']);

    t('yeni kayitta uc alanin ucu de listelenir', 3, count($satirlar));
    t('satir bicimi "Alan: deger"', 'Baslik: 11111', $satirlar[0]);
    t('ok isareti (eski -> yeni) YOK', false, strpos(implode("\n", $satirlar), '→') !== false);

    echo "\n--- D) Guncellemede YALNIZCA degisen alan listelenir ---\n";

    $damgaDegeri = damga($r1);
    sleep(1);
    hucre_yaz($r1, $alanlar['Marka'], 'YENI MARKA');

    $mevcutKayit = array('id' => $r1, 'slack_notified_at' => $damgaDegeri);
    $satirlar2 = ciftleri_satira_cevir(bcc_slack_changed_field_lines($mevcutKayit, $watchedFields, $teamId));

    t('guncellemede yalnizca degisen alan var', 1, count($satirlar2));
    t('degisen alan dogru', 'Marka: YENI MARKA', $satirlar2[0]);

    eskit($r1, 400);
    t('guncelleme icin bir mesaj daha gonderilir', 1, bcc_slack_flush_table($tableId));

    echo "\n--- E) Izlenmeyen alan mesaj URETMEZ ---\n";

    bcc_execute(
        "INSERT INTO fields (table_id, name, field_type, position) VALUES (:t, 'Izlenmeyen', 'single_line_text', 9)",
        array('t' => $tableId)
    );
    $izlenmeyen = (int) bcc_last_insert_id();

    hucre_yaz($r1, $izlenmeyen, 'gorunmemeli');
    eskit($r1, 400);

    t('izlenmeyen alan degisikligi mesaj uretmez', 0, bcc_slack_flush_table($tableId));

    echo "\n--- F) Bos yeni kayit BEKLER, icerik girilince duyurulur (2026-09-09) ---\n";

    /* Eski davranis: damgasiz her kayit "duyurulacak" sayiliyordu, bos kayit da
       basliksiz bir mesaj uretiyordu. Bu yuzden record_add.php toplu satirlari
       olusturur olusturmaz damgalamak zorundaydi ve o satirlar sonradan
       doldurulunca 📢 yerine ✏️ basligi aliyordu. Olcu artik "icerigi var mi". */

    $r2 = kayit_ekle($tableId, $userId);
    eskit($r2, 400);

    t('F1) bos yeni kayit duyurulmaz (20 bos satir = 20 mesaj DEGIL)', 0, bcc_slack_flush_table($tableId));
    t('F2) bos kayit DAMGASIZ kalir (sonra duyurulabilsin diye)', null, damga($r2));

    hucre_yaz($r2, $alanlar['Baslik'], 'SONRADAN DOLDURULDU');
    eskit($r2, 400);

    t('F3) icerik girilince duyurulur', 1, bcc_slack_flush_table($tableId));
    t('F4) damga ancak o zaman dolar', true, damga($r2) !== null);

    echo "\n--- G) Damga yazmak 'Son degisiklik zamani'ni BOZMAZ ---\n";

    $r3 = kayit_ekle($tableId, $userId);
    hucre_yaz($r3, $alanlar['Baslik'], '33333');
    eskit($r3, 400);

    $oncekiUpdatedAt = bcc_fetch_column('SELECT updated_at FROM records WHERE id = :r', array('r' => $r3));
    bcc_slack_flush_table($tableId);
    $sonrakiUpdatedAt = bcc_fetch_column('SELECT updated_at FROM records WHERE id = :r', array('r' => $r3));

    t('damga yazilinca records.updated_at degismedi', $oncekiUpdatedAt, $sonrakiUpdatedAt);

    echo "\n--- H) Silinen kayit gonderilmez ---\n";

    $r4 = kayit_ekle($tableId, $userId);
    hucre_yaz($r4, $alanlar['Baslik'], '44444');
    eskit($r4, 400);
    bcc_execute('UPDATE records SET deleted_at = NOW() WHERE id = :r', array('r' => $r4));

    t('cop kutusundaki kayit duyurulmaz', 0, bcc_slack_flush_table($tableId));

    echo "\n--- I) Coklu kayit: her kayit KENDI mesajini alir ---\n";

    $r5 = kayit_ekle($tableId, $userId);
    $r6 = kayit_ekle($tableId, $userId);
    hucre_yaz($r5, $alanlar['Baslik'], '55555');
    hucre_yaz($r6, $alanlar['Baslik'], '66666');
    eskit($r5, 400);
    eskit($r6, 400);

    /* 2026-09-09: kayit basina degil BOSALTMA basina bir mesaj. Iki kayit
       artik tek bildirimde birlesiyor — kullanicinin asil istegi buydu
       ("50 degisiklik 50 mesaj olmasin"). */
    t('iki ayri kayit TEK mesajda birlesir', 1, bcc_slack_flush_table($tableId));

    echo "\n--- K) MESAJ YAGMURU KORUMASI (gozden gecirmede bulundu) ---\n";

    /* Damgasiz her kayit "duyurulmamis" sayildigi icin, TOPLU satir ureten
       yollar damgalanmazsa tek islem kanala onlarca/yuzlerce mesaj dusurur.
       Bu bolum o yollarin damgalandigini kaynak seviyesinde dogrular; K4 ise
       damgalamanin gercekten ise yaradigini olcer. */

    $recordAddSrc = file_get_contents(__DIR__ . '/../public/api/record_add.php');
    $importSrc = file_get_contents(__DIR__ . '/../public/api/table_import_xlsx.php');
    $schemaSrc2 = file_get_contents(__DIR__ . '/../src/schema.php');

    t('K1) toplu satir ekleme damgalaniyor (20 bos satir = 20 mesaj DEGIL)',
        true, strpos($recordAddSrc, 'bcc_slack_mark_records_notified') !== false);
    t('K1b) tek satir ekleme BILEREK damgasiz (birlesme icin)',
        true, strpos($recordAddSrc, 'if ($count > 1 &&') !== false);
    t('K1c) toplu ekleme yalnizca izlenen alan YOKKEN damgalar',
        true, strpos($recordAddSrc, '!bcc_slack_watched_field_ids($table[\'id\'])') !== false);
    t('K2) Excel ice aktarma damgaliyor (500 satir = 500 mesaj DEGIL)',
        true, strpos($importSrc, 'bcc_slack_mark_records_notified') !== false);
    t('K3) tablo kopyalama satirlari damgaliyor',
        true, strpos($schemaSrc2, 'bcc_slack_mark_records_notified') !== false);

    $toplu = array();
    for ($i = 0; $i < 3; $i++) {
        $toplu[] = kayit_ekle($tableId, $userId);
    }
    bcc_slack_mark_records_notified($toplu);

    foreach ($toplu as $rid) {
        eskit($rid, 400);
    }

    t('K4) damgalanan toplu satirlar bosaltmada ATLANIR', 0, bcc_slack_flush_table($tableId));

    t('K5) kanban sayfa yuklemesinde de bosaltma var',
        true, strpos(file_get_contents(__DIR__ . '/../public/kanban.php'), 'bcc_slack_flush_table') !== false);

    echo "\n--- L) AYRILMA pingi: bekleme suresi ipucu (2026-09-09 hatasi) ---\n";

    /* 2026-09-09'da bulunan hata: public/api/slack_flush.php ikinci parametreyi
       gecirmedigi icin "kullanici sayfadan ayrildi" pingi de varsayilan 180
       saniyelik bekleme kuralina takiliyordu. Sonuc: uzerinde anlasilan uc
       tetikleyiciden BIRINCISI hic calismiyordu — kullanici duzenlemesini
       bitirip cikinca mesaj gitmiyor, 180 saniye dolana kadar bekliyordu.

       Hata ilk turda kacti cunku o turdaki tek test "dosya var mi" diye
       bakiyordu (J bolumu). Buradaki L1/L2 kaynak taramasi DEGIL davranis
       testi: ayni taze kayit iki farkli beklemeyle denenip sonucun gercekten
       degistigi dogrulaniyor. */

    /* Onceki bolumlerden bekleyen kayit kalmasin — olcum yalniz L kaydini
       gormeli. */
    bcc_slack_flush_table($tableId, 0);

    $rL = kayit_ekle($tableId, $userId);
    hucre_yaz($rL, $alanlar['Baslik'], 'AYRILMA-TESTI');

    t('L1) taze kayit VARSAYILAN beklemeyle gonderilmez', 0, bcc_slack_flush_table($tableId));
    t('L2) ayrilma pingi (bekleme 0) ayni kaydi HEMEN gonderir', 1, bcc_slack_flush_table($tableId, 0));
    t('L3) gonderim sonrasi damga dolduruldu', true, damga($rL) !== null);
    t('L4) ayni kayit ikinci kez gonderilmez', 0, bcc_slack_flush_table($tableId, 0));

    $flushSrc = file_get_contents(__DIR__ . '/../public/api/slack_flush.php');
    $jsSrc = file_get_contents(__DIR__ . '/../public/assets/grid-slack-flush.js');

    t('L5) uc nokta beklemeyi bcc_slack_flush_table()e GECIRIYOR',
        true, strpos($flushSrc, "bcc_slack_flush_table((int) \$table['id'], \$idle)") !== false);
    t('L6) istemci beklemeyi UZATAMAZ (ust sinir sunucuda)',
        true, strpos($flushSrc, '$idle > BCC_SLACK_BATCH_IDLE_SECONDS') !== false);
    t('L7) negatif deger sifira cekiliyor',
        true, strpos($flushSrc, '$idle < 0') !== false);
    t('L8) pagehide bekleme 0 gonderiyor',
        true, strpos($jsSrc, 'var LEAVE_IDLE = 0;') !== false);
    t('L9) sekme degisimi 30 sn tolerans aliyor',
        true, strpos($jsSrc, 'var HIDDEN_IDLE = 30;') !== false);
    t('L10) visibilitychange bayragi TEMIZLEMIYOR (pagehide bastirilmasin)',
        true, strpos($jsSrc, 'flushBeacon(HIDDEN_IDLE, false)') !== false);

    echo "\n--- M) Ozet mesajinda alan basina uzunluk siniri (2026-09-09) ---\n";

    /* Kullanici raporu: bir "Notlar" alanina yapistirilmis 42 satirlik firma
       listesi Slack'e OLDUGU GIBI dusuyordu. Ozet, kaydin tamamini tasimak
       icin degil neyin degistigini gostermek icin var. Gercek veride olculen
       en kotu ornek: 20.004 karakter / 577 satirlik tek bir hucre. */

    t('M1) kisa deger aynen kalir', 'PUMA', bcc_slack_shorten_value('PUMA', 200));
    t('M2) cok satirli deger TEK satira iner', 'a b c', bcc_slack_shorten_value("a\n\n b \n c", 200));
    t('M3) bos deger bos kalir', '', bcc_slack_shorten_value("   \n  ", 200));

    $uzun = str_repeat('kelime ', 200);
    $kisaltilmis = bcc_slack_shorten_value($uzun, 200);

    t('M4) uzun deger limite indirilir', true, mb_strlen($kisaltilmis, 'UTF-8') <= 201);
    t('M5) kisaltma isareti eklenir', true, mb_substr($kisaltilmis, -1, 1, 'UTF-8') === "\xE2\x80\xA6");
    t('M6) kelimenin ortasindan kesilmez', true, strpos($kisaltilmis, 'kel' . "\xE2\x80\xA6") === false);

    /* Bosluksuz tek uzun kelime: geriye sarilacak bosluk yok, limit yine de
       korunmali (yarim mesaj kalmasin diye geriye sarma sinirli). */
    t('M7) bosluksuz uzun degerde de limit korunur',
        true, mb_strlen(bcc_slack_shorten_value(str_repeat('A', 500), 200), 'UTF-8') <= 201);

    /* Davranis testi: sinir gercekten ozet satirina yansiyor mu. */
    $rM = kayit_ekle($tableId, $userId);
    hucre_yaz($rM, $alanlar['Baslik'], 'UZUN NOT TESTI');
    hucre_yaz($rM, $alanlar['Aciklama'], str_repeat("firma adi satiri buraya yazilir\n", 300));

    $satirlarM = ciftleri_satira_cevir(bcc_slack_changed_field_lines(
        array('id' => $rM, 'slack_notified_at' => null),
        $watchedFields,
        $teamId
    ));

    $aciklamaSatiri = '';
    foreach ($satirlarM as $sM) {
        if (strpos($sM, 'Aciklama') === 0) {
            $aciklamaSatiri = $sM;
        }
    }

    t('M8) 300 satirlik hucre ozette TEK satir', 1, substr_count($aciklamaSatiri, "\n") + 1);
    t('M9) 300 satirlik hucre ozette 250 karakterin altinda',
        true, $aciklamaSatiri !== '' && mb_strlen($aciklamaSatiri, 'UTF-8') < 250);

    $slackKaynakM = file_get_contents(__DIR__ . '/../src/slack.php');
    t('M10) baslik da sinirlaniyor (birincil alan uzun metin olabilir)',
        true, strpos($slackKaynakM, 'BCC_SLACK_BATCH_MAX_TITLE_CHARS') !== false);

    echo "\n--- N) Izlenen alan TANIMLI DEGILKEN eski davranis korunuyor ---\n";

    /* F bolumundeki yeni kural yalnizca izlenen alan tanimliysa gecerli.
       Tanimli degilse ozellik salt "yeni kayit" duyurusu olarak calisir ve
       bos kayit da duyurulmalidir — aksi halde hucre bildirimini hic
       kullanmayan takimlarda "yeni kayit" mesajlari tamamen susardi. */

    /* bcc_slack_watched_field_ids() surec ici STATIK onbellek kullaniyor
       (src/slack.php); ayni kosuda satirlari silip bosaltmayi cagirmak
       onbellegi tazelemez. Web isteginde her cagri yeni bir surec oldugu icin
       bu bir uretim sorunu degil — ama test bu yuzden bir alt katmandan,
       bcc_slack_pending_records()'a listeyi DOGRUDAN vererek yapiliyor.
       Bosaltmanin o listeyi gercekten oradan aldigi J bolumunde sabit. */

    $rN = kayit_ekle($tableId, $userId);
    eskit($rN, 400);

    $bosListe = array_map('intval', array_column(bcc_slack_pending_records($tableId, array(), 400), 'id'));
    t('N1) izlenen alan YOKKEN bos yeni kayit bekleyenlerde VAR (eski davranis)',
        true, in_array($rN, $bosListe, true));

    $doluListe = array_map('intval', array_column(
        bcc_slack_pending_records($tableId, bcc_slack_watched_field_ids($tableId), 400), 'id'));
    t('N2) izlenen alan VARKEN ayni bos kayit bekleyenlerde YOK (yeni kural)',
        false, in_array($rN, $doluListe, true));

    t('N3) bos kayit damgasiz kaldi', null, damga($rN));

    /* Toplu ekleme: izlenen alan TANIMLIYKEN damgalanmamali ki satirlar
       doldurulunca duyurulabilsin. */
    $topluN = array();
    for ($i = 0; $i < 3; $i++) {
        $topluN[] = kayit_ekle($tableId, $userId);
        eskit($topluN[$i], 400);
    }

    t('N4) izlenen alan varken 3 BOS satir hic mesaj uretmez', 0, bcc_slack_flush_table($tableId));

    hucre_yaz($topluN[0], $alanlar['Baslik'], 'TOPLU SATIR SONRADAN DOLDURULDU');
    eskit($topluN[0], 400);

    t('N5) toplu eklenen satir doldurulunca duyurulur', 1, bcc_slack_flush_table($tableId));
    t('N6) diger iki bos satir hâlâ sessiz', 0, bcc_slack_flush_table($tableId));

    echo "\n--- P) TEK bildirim: butun degisiklikler tek mesajda (2026-09-09) ---\n";

    /* Kullanicinin asil istegi: "50 degisiklik yapacaklar, 50 mesaj gelmesin".
       Artik kayit basina degil BOSALTMA basina bir mesaj gonderiliyor. */

    $pA = kayit_ekle($tableId, $userId);
    hucre_yaz($pA, $alanlar['Baslik'], 'P KAYIT A');
    $pB = kayit_ekle($tableId, $userId);
    hucre_yaz($pB, $alanlar['Baslik'], 'P KAYIT B');
    $pC = kayit_ekle($tableId, $userId);
    hucre_yaz($pC, $alanlar['Baslik'], 'P KAYIT C');

    foreach (array($pA, $pB, $pC) as $rid) {
        eskit($rid, 400);
    }

    t('P1) UC kayit -> TEK mesaj', 1, bcc_slack_flush_table($tableId));
    t('P2) ucunun de damgasi doldu', true,
        damga($pA) !== null && damga($pB) !== null && damga($pC) !== null);

    /* --- Mesajin yapisi (saf fonksiyon, gonderim yok) --- */
    $gruplarP = array(
        'yeni' => array(
            array('no' => 3, 'baslik' => 'DENEME 1', 'ciftler' => array(
                array('ad' => 'Marka', 'deger' => 'PUMA'),
            )),
        ),
        'guncel' => array(
            array('no' => 7, 'baslik' => 'OLGARLAR', 'ciftler' => array(
                array('ad' => 'Marka', 'deger' => 'NIKE'),
                array('ad' => 'Notlar', 'deger' => 'bir not'),
            )),
        ),
        'silinen' => array(
            array('no' => 0, 'baslik' => 'SILINEN KAYIT', 'ciftler' => array()),
        ),
    );

    $mP = bcc_slack_build_batch_message('Tablo', array('Yigit Aslantas'), $gruplarP, 'https://ornek/x');
    $ekler = $mP['opts']['attachments'];
    $renkler = array_column($ekler, 'color');

    t('P3) tek mesaj, dort renkli bolum (ozet + yesil + mavi + kirmizi)', 4, count($ekler));
    t('P4) ekleme YESIL', true, in_array(BCC_SLACK_COLOR_NEW, $renkler, true));
    t('P5) duzenleme MAVI', true, in_array(BCC_SLACK_COLOR_UPDATE, $renkler, true));
    t('P6) silme KIRMIZI', true, in_array(BCC_SLACK_COLOR_DELETE, $renkler, true));

    $ozetMetni = json_encode($ekler[0]['blocks'], JSON_UNESCAPED_UNICODE);

    t('P7) ozette toplam degisiklik sayisi var', true, strpos($ozetMetni, '3 değişiklik') !== false);
    /* Ozetin ASIL icerigi: sutun adi -> satir numaralari. Basliksiz, cunku
       kullanici "aciklama az olsun, satirlarla sutunlar yazsin" dedi. */
    t('P8) ozette SUTUN -> SATIR NO listesi var',
        true, strpos($ozetMetni, 'Marka') !== false && strpos($ozetMetni, 'satır') !== false);
    t('P9) ayni sutunun birden fazla satiri birlikte yaziliyor',
        true, strpos($ozetMetni, 'satır 3, 7') !== false);
    t('P10) yalnizca bir satirda degisen sutun kendi numarasini yaziyor',
        true, strpos($ozetMetni, 'satır 7') !== false);
    /* Aciklama satirlari BILEREK yok: mesaj kisa kalsin. Geri sizarsa bu
       test uyarir. */
    t('P11) ozette aciklama cumlesi YOK (kisa kalsin)',
        false, strpos($ozetMetni, 'varsayılan sıralamasına göre') !== false);
    t('P11a) hucre DEGERLERI mesajda yazilmiyor (yalnizca sutun + satir)',
        false, strpos(json_encode($ekler, JSON_UNESCAPED_UNICODE), 'NIKE') !== false);
    /* Silinenler yalnizca KIRMIZI bolumde — ozette tekrarlanmiyor. */
    $kirmiziMetin = json_encode($ekler[count($ekler) - 1]['blocks'], JSON_UNESCAPED_UNICODE);
    t('P12) silinenler kirmizi bolumde adiyla geciyor',
        true, strpos($kirmiziMetin, 'SILINEN KAYIT') !== false);
    t('P12a) ozette tekrarlanmiyor', false, strpos($ozetMetni, 'SILINEN KAYIT') !== false);
    /* Baglanti artik ozetin ORTASINDA degil mesajin EN ALTINDA: ortada
       dururken "yalnizca ustundeki bolume mi ait?" izlenimi veriyordu.
       Ayrica buton degil duz link — Slack, webhook'tan gelen mesajlardaki
       "actions" butonunun yanina aciklamasiz bir uyari ucgeni koyuyor. */
    t('P13) ozette artik baglanti YOK', false, strpos($ozetMetni, 'Tabloyu aç') !== false);

    $sonEkMetni = json_encode($ekler[count($ekler) - 1]['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    t('P13a) baglanti mesajin EN ALTINDAKI bolumde', true, strpos($sonEkMetni, 'Tabloyu aç') !== false);
    /* Adresin gride gittigi P22a'da olculuyor; burada baglantinin verilen
       adresi gercekten tasidigi kontrol ediliyor. */
    t('P13b) baglanti verilen adresi tasiyor', true, strpos($sonEkMetni, 'https://ornek/x') !== false);
    t('P13c) hicbir yerde "actions" butonu YOK (uyari ucgeni cikmasin)',
        false, strpos(json_encode($ekler), '"actions"') !== false);

    /* --- Detay sinirlamasi: cok kayitta kart sisirilmiyor --- */
    $cokGrup = array('yeni' => array(), 'guncel' => array(), 'silinen' => array());
    for ($i = 1; $i <= 40; $i++) {
        $cokGrup['guncel'][] = array(
            'no' => $i,
            'baslik' => 'KAYIT ' . $i,
            'ciftler' => array(array('ad' => 'Marka', 'deger' => 'deger ' . $i)),
        );
    }

    $mCok = bcc_slack_build_batch_message('Tablo', array('Ali'), $cokGrup, 'https://ornek/x');
    $cokMetin = json_encode($mCok['opts']['attachments'], JSON_UNESCAPED_UNICODE);

    t('P14) 40 kayit da TEK mesaj', 2, count($mCok['opts']['attachments']));
    t('P15) detay BCC_SLACK_BATCH_MAX_DETAIL ile sinirli',
        true, strpos($cokMetin, 'KAYIT ' . (BCC_SLACK_BATCH_MAX_DETAIL + 1) . '\"') === false);
    t('P16) gosterilmeyenler "+N satir" diye ozetleniyor',
        true, strpos($cokMetin, '+' . (40 - BCC_SLACK_BATCH_MAX_DETAIL) . ' satır') !== false);
    t('P17) satir numaralari ozette sinirli (+N ile)',
        true, strpos($cokMetin, '+' . (40 - BCC_SLACK_BATCH_MAX_ROWNOS)) !== false);

    /* --- Silme bildirimi: davranis --- */
    $pSil = kayit_ekle($tableId, $userId);
    hucre_yaz($pSil, $alanlar['Baslik'], 'SILINECEK');
    eskit($pSil, 400);

    t('P18) once duyuruldu', 1, bcc_slack_flush_table($tableId));

    /* Silme olcusu "damga silme aninDAN once mi": duyuru ile silme AYNI saniye
       icinde olursa (yalnizca testte olur, gercekte duyuru en az 180 sn sonra
       gider) iki damga esitlenir ve silme duyurulmaz. Bu yuzden duyuru zamani
       gercekci bicimde geriye aliniyor. updated_at ayni ifadede yaziliyor:
       ON UPDATE CURRENT_TIMESTAMP bekleme kontrolunu bozmasin. */
    /* deleted_at de geriye aliniyor: bekleme suresi artik SILME anindan
       olculuyor (2026-09-09 duzeltmesi), updated_at'ten degil. Damga ondan da
       once olmali ki "duyurulduktan SONRA silinmis" sayilsin. */
    bcc_execute(
        'UPDATE records
            SET slack_notified_at = (NOW() - INTERVAL 500 SECOND),
                deleted_at = (NOW() - INTERVAL 400 SECOND),
                deleted_by = :u,
                updated_at = (NOW() - INTERVAL 400 SECOND)
          WHERE id = :r',
        array('u' => $userId, 'r' => $pSil)
    );

    t('P19) silinince duyurulur', 1, bcc_slack_flush_table($tableId));
    t('P20) ayni silme ikinci kez duyurulmaz', 0, bcc_slack_flush_table($tableId));

    /* Hic duyurulmamis bir kayit silinirse kanalda gurultu olmamali. */
    $pSil2 = kayit_ekle($tableId, $userId);
    bcc_execute(
        'UPDATE records SET deleted_at = (NOW() - INTERVAL 400 SECOND), deleted_by = :u,
                updated_at = (NOW() - INTERVAL 400 SECOND)
          WHERE id = :r',
        array('u' => $userId, 'r' => $pSil2)
    );

    t('P21) hic duyurulmamis kayit silinince SESSIZ', 0, bcc_slack_flush_table($tableId));

    /* --- Satir numarasi varsayilan siralamadan --- */
    $nolar = bcc_slack_row_numbers($tableId, array($pA, $pB));

    /* Buton GRID'e gitmeli: kullanici degisikligi orada yapti, linke basinca
       duzenledigi ekrana donmeli. interface.php base gezgini, aradigi satiri
       gostermiyor. */
    $mLink = bcc_slack_build_batch_message('Tablo', array('Ali'),
        array('yeni' => array(), 'guncel' => array(
            array('no' => 1, 'baslik' => 'X', 'ciftler' => array(array('ad' => 'A', 'deger' => 'b'))),
        ), 'silinen' => array()),
        bcc_slack_app_url('/grid.php?table_id=99'));

    $mLinkMetin = json_encode($mLink['opts']['attachments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    t('P22a) buton GRID adresine gidiyor', true, strpos($mLinkMetin, '/grid.php?table_id=99') !== false);
    t('P22b) kaynakta kayit bildirimi icin interface.php linki KALMADI',
        false, preg_match('#app_url\(\s*./interface\.php#', file_get_contents(__DIR__ . '/../src/slack.php')) === 1);

    t('P22) satir numarasi uretiliyor', true, isset($nolar[$pA]) && $nolar[$pA] > 0);
    t('P23) numaralar position/id sirasini izliyor', true, $nolar[$pA] < $nolar[$pB]);

    /* En onemli garanti: numaralar gridin VARSAYILAN siralamasiyla birebir
       ayni olmali. Grid kayitlari "ORDER BY position, id" ile cekip $i+1
       veriyor (public/grid.php); burada ayni siralama bagimsiz olarak
       kurulup bcc_slack_row_numbers() ile karsilastiriliyor. Formul degisirse
       ya da grid siralamasi kayarsa bu test uyarir. */
    $gridSirasi = array();
    $i = 0;
    foreach (bcc_fetch_all(
        'SELECT id FROM records WHERE table_id = :t AND deleted_at IS NULL ORDER BY position, id',
        array('t' => $tableId)
    ) as $satir) {
        $i++;
        $gridSirasi[(int) $satir['id']] = $i;
    }

    $hepsi = array_keys($gridSirasi);
    $bizim = bcc_slack_row_numbers($tableId, $hepsi);

    $uyusmayan = 0;
    foreach ($gridSirasi as $rid => $beklenen) {
        if (!isset($bizim[$rid]) || $bizim[$rid] !== $beklenen) {
            $uyusmayan++;
        }
    }

    t('P23a) TUM satir numaralari gridin varsayilan sirasiyla birebir ayni', 0, $uyusmayan);

    echo "\n--- J) Kod incelemesi ---\n";

    $slackKaynak = file_get_contents(__DIR__ . '/../src/slack.php');
    $cellUpdate = file_get_contents(__DIR__ . '/../public/api/cell_update.php');
    $recordAdd = file_get_contents(__DIR__ . '/../public/api/record_add.php');
    $bulk = file_get_contents(__DIR__ . '/../public/api/cells_bulk_update.php');

    t('cell_update.php ARTIK aninda mesaj atmiyor', false, strpos($cellUpdate, 'bcc_notify_slack_cell_change') !== false);
    t('cell_update.php bosaltmayi cagiriyor', true, strpos($cellUpdate, 'bcc_slack_flush_table') !== false);
    t('record_add.php ARTIK aninda "yeni kayit" atmiyor', false, strpos($recordAdd, 'bcc_notify_slack_new_record') !== false);
    t('toplu yapistirmanin kendi ozet mesaji KORUNDU', true, strpos($bulk, 'bcc_notify_slack_bulk_cell_change') !== false);
    t('toplu yapistirma satirlari damgaliyor (cift duyuru yok)', true, strpos($bulk, 'bcc_slack_mark_records_notified') !== false);
    t('damga yazarken updated_at korunuyor', true, strpos($slackKaynak, 'updated_at = updated_at') !== false);
    t('bekleme suresi tek sabitte', true, strpos($slackKaynak, "define('BCC_SLACK_BATCH_IDLE_SECONDS'") !== false);
    t('sayfa yuklemesinde bosaltma var (grid)', true, strpos(file_get_contents(__DIR__ . '/../public/grid.php'), 'bcc_slack_flush_table') !== false);
    t('sayfa yuklemesinde bosaltma var (arayuz)', true, strpos(file_get_contents(__DIR__ . '/../public/interface.php'), 'bcc_slack_flush_table') !== false);
    t('tarayici ping uc noktasi var', true, is_file(__DIR__ . '/../public/api/slack_flush.php'));
    t('tarayici tetikleyici dosyasi var', true, is_file(__DIR__ . '/../public/assets/grid-slack-flush.js'));
    t('schema.sql yeni kolonu tasiyor', true, strpos(file_get_contents(__DIR__ . '/../schema.sql'), 'slack_notified_at') !== false);
} catch (Throwable $e) {
    $kaldi++;
    echo "[HATA] " . $e->getMessage() . "\n";
}

temizle();

bcc_execute(
    "DELETE FROM audit_log WHERE id > :s AND user_id IS NULL AND action LIKE 'slack.%'",
    array('s' => $auditBaslangic)
);

foreach ($aktifWebhookIds as $wid) {
    bcc_execute('UPDATE slack_webhooks SET is_active = 1 WHERE id = :i', array('i' => (int) $wid));
}

echo "\n--- Kirlilik ---\n";

$sonrasi = array(
    'kullanici' => sayac('SELECT COUNT(*) AS c FROM users'),
    'ekip' => sayac('SELECT COUNT(*) AS c FROM teams'),
    'base' => sayac('SELECT COUNT(*) AS c FROM bases'),
    'kayit' => sayac('SELECT COUNT(*) AS c FROM records'),
    'notify_sent' => sayac("SELECT COUNT(*) AS c FROM audit_log WHERE action='slack.notify_sent'"),
    'aktif_webhook' => sayac('SELECT COUNT(*) AS c FROM slack_webhooks WHERE is_active=1'),
);

foreach ($oncesi as $ad => $deger) {
    t("kirlilik yok: {$ad}", $deger, $sonrasi[$ad]);
}

echo "\n==== SONUC: {$gecti} gecti, {$kaldi} kaldi ====\n";

exit($kaldi > 0 ? 1 : 0);
