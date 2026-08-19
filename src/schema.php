<?php
// Base / tablo / alan şeması sayfalarının ortak yardımcıları (Faz 2).

require_once __DIR__ . '/auth.php';

$GLOBALS['BCC_FIELD_TYPES'] = array(
    'single_line_text' => 'Tek satır metin',
    'long_text' => 'Uzun metin',
    'number' => 'Sayı',
    'checkbox' => 'Onay kutusu',
    'date' => 'Tarih',
    'single_select' => 'Tekli seçim',
    'multiple_select' => 'Çoklu seçim',
    'time' => 'Saat',
    'user' => 'Kullanıcı',
    'attachment' => 'Dosya eki',
    // Otomatik/salt-okunur alanlar (OpsFlow "Created time"/"Created by" davranışı,
    // Grup B1) — DDL YOK, değer zaten records.created_at/created_by'da sağlam
    // duruyor (bkz. docs/PROJE-DURUM.md teşhis notu).
    'created_time' => 'Oluşturulma zamanı',
    'created_by' => 'Oluşturan',
    // "Last modified time"/"Last modified by" (Grup B2, migrations/013) —
    // records.updated_at ARTIK bcc_touch_record_modified() ile doğru tutuluyor
    // (yalnızca "içerik değişikliği" sayılan yazma noktalarından — bkz. o
    // fonksiyonun yorumu), updated_by YENİ kolon. created_time/created_by ile
    // BİREBİR AYNI mekanizma (BCC_RECORD_COLUMN_FIELD_TYPES), yalnızca kolon adı farklı.
    'last_modified_time' => 'Son değişiklik zamanı',
    'last_modified_by' => 'Son değiştiren',
    // Grup C1 — Currency/Percent/Rating: DDL YOK, üçü de value_number'ı
    // paylaşıyor (aşağıda), yalnızca GÖRÜNTÜLEME formatı + fields.options'ta
    // (JSON, zaten var olan mekanizma — select tiplerinin choices/colors'ıyla
    // AYNI kolon) küçük bir config farklı.
    'currency' => 'Para birimi',
    'percent' => 'Yüzde',
    'rating' => 'Değerlendirme',
    // Grup C2 — Autonumber: C1'in üç tipinden FARKLI olarak GERÇEK bir DDL
    // gerektirdi (migrations/014, fields.autonumber_next sayaç kolonu) çünkü
    // records.id (global AUTO_INCREMENT) ve records.position (sürükle-bırakla
    // kayar) ikisi de kullanılamıyordu. Değerin KENDİSİ yine value_number'da
    // (aşağıda) — yeni değer kolonu YOK. Kullanıcı tarafından ASLA düzenlenemez
    // (B1/B2 ile AYNI üç katmanlı salt-okunur zorlaması).
    'autonumber' => 'Otomatik numara',
    // Grup A — URL/E-posta/Telefon: DDL YOK, üçü de value_text'i
    // single_line_text ile PAYLAŞIYOR (aşağıda). Grup B/C'nin aksine bunlar
    // NORMAL DÜZENLENEBİLİR alanlar — salt-okunur zorlaması YOK, kullanıcı
    // serbestçe yazar. Tek fark GÖRÜNTÜLEMEDE: değer linkleştirilebiliyorsa
    // hücrede hover'da bir "yeni sekmede aç" ikonu belirir
    // (bcc_render_linkified_cell). Doğrulama YUMUŞAK — hiçbir giriş
    // reddedilmez, kontroller yalnızca linkleştirme kapısıdır
    // (bkz. bcc_cell_link_href).
    'url' => 'URL',
    'email' => 'E-posta',
    'phone' => 'Telefon numarası',
);

$GLOBALS['BCC_SELECT_FIELD_TYPES'] = array('single_select', 'multiple_select');

// Kullanıcının ASLA yazamadığı, sunucunun doldurduğu alan tipleri.
// TEK KAYNAK — bu dizi eskiden bcc_render_grid_data_row() içinde satır içi bir
// literal olarak duruyordu; form görünümü (Grup View-Form) aynı listeye ikinci
// bir yerden ihtiyaç duyunca buraya çıkarıldı. Kopyalanmadı: iki ayrı liste
// olsaydı yeni bir otomatik tip eklenince biri güncellenip diğeri unutulurdu —
// bu projede TAM OLARAK bu sınıf hata dört kez yaşandı (bkz. BCC_GROUP_DIR_LABELS).
//
// Okuyanlar:
//   1. bcc_render_grid_data_row()  — 'editable' class'ını HİÇ eklemez
//   2. form_edit.php / form.php    — bu tipler forma HİÇ girmez (doldurulamaz)
//   3. form_submit.php             — gelen değer bu tipteyse SESSİZCE atlanır
// (normalize_cell_value()'nun kendi red bloğu AYRI ve BİLEREK öyle: orada her
// tipin kendi hata mesajı var, tek bir in_array'e indirgemek mesajı kaybederdi.)
$GLOBALS['BCC_READONLY_FIELD_TYPES'] = array(
    'created_time', 'created_by', 'last_modified_time', 'last_modified_by', 'autonumber',
);

// Görünüm türleri. BCC_FIELD_TYPES ile AYNI desen: views.view_type ENUM DEĞİL
// VARCHAR, geçerli değerler burada whitelist'lenir — yeni tür eklemek DDL
// gerektirmez.
//
// ⚠️ SIRA ÖNEMLİ: bu dizinin sırası "+ Yeni oluştur..." tip seçici menüsünün
// sırasıdır (alan tipi sihirbazının $fieldTypeLabels'tan sırayla basılmasıyla
// AYNI mekanizma — liste elle YAZILMAZ, buradan döner).
//
// Yol haritası: Form -> Kanban (bu adım) -> Calendar -> diğerleri.
// ⚠️ YENİ BİR TÜR EKLERKEN ATLANMAMASI GEREKEN DÖRT YER (alan tiplerinde aynı
// sınıf hata dört kez yaşandı, o yüzden burada baştan yazılı):
//   1. bu dizi
//   2. theme.css'te .view-type-badge--<tür> ikonu (--view-icon tanımsızsa rozet
//      BOŞ kutu çizilir — background-image'ın yedeği YOK, C1'in düştüğü tuzak;
//      Form turunda da bu yüzden ayrıca kontrol edildi)
//   3. BCC_VIEW_ROUTES (aşağıda) — türün hangi sayfaya gittiği
//   4. view_create.php'nin tür-özel kurulum dalı (Form'da token üretimi,
//      Kanban'da varsayılan kanban_field_id seçimi)
// ⚠️ 'grid' etiketi "Tablo" DEĞİL "Tablo görünümü": "+ Yeni oluştur..." menüsü
// bu listeden üretiliyor ve "Tablo" yazınca kullanıcılar YENİ BİR TABLO
// oluşturduklarını sanıyordu. Oysa uçnokta (view_create.php) AYNI tablonun
// yeni bir GÖRÜNÜMÜNÜ yaratır — aynı şema, aynı kayıtlar; birinde yapılan
// düzenleme diğerinde de görünür. Bu "klonlama bugu" değil, görünümün tanımı;
// bug ETİKETTEYDİ. Yeni tablo açma yolu ayrı: tablo sekmelerindeki "+"
// (grid.php -> base_tables.php).
// Yan fayda: yeni grid görünümleri artık "Tablo görünümü 2" olarak adlandırılıyor
// (view_create.php ad sayacı bu etiketi kullanır) — varsayılan görünümün adı
// zaten "Tablo görünümü" idi, isimlendirme tutarsızlığı da kapandı.
$GLOBALS['BCC_VIEW_TYPES'] = array(
    'grid' => 'Tablo görünümü',
    'form' => 'Form',
    'kanban' => 'Kanban',
);

// Görünüm türü -> sayfa. TEK yönlendirme kaynağı; bcc_view_route_for()'un
// gövdesi eskiden iki değerli bir ternary'di ("form ise şu, değilse grid"),
// üçüncü tür ile ternary zinciri okunaksızlaşacaktı. Harita hem BCC_VIEW_TYPES
// ile aynı "whitelist" felsefesini sürdürüyor hem de dördüncü türü (Calendar)
// saf veri eklemesine indiriyor — fonksiyon gövdesine bir daha dokunulmaz.
$GLOBALS['BCC_VIEW_ROUTES'] = array(
    'grid' => '/grid.php',
    'form' => '/form_edit.php',
    'kanban' => '/kanban.php',
);

// Bir görünümün açılacağı sayfa — TEK yönlendirme noktası. grid.php'nin erken
// yönlendirmesi, view_create.php'nin redirect_url'i ve görünüm panelindeki
// bağlantılar ÜÇÜ DE buradan geçer, ikinci bir eşleme YOK.
//
// Bilinmeyen/bozuk bir view_type 'grid'e düşer (fail-safe): elle kurcalanmış ya
// da BCC_VIEW_ROUTES'a yazılmayı unutmuş bir değer beyaz ekran yerine tabloyu
// gösterir.
function bcc_view_route_for($viewType, $tableId, $viewId)
{
    $page = isset($GLOBALS['BCC_VIEW_ROUTES'][$viewType])
        ? $GLOBALS['BCC_VIEW_ROUTES'][$viewType]
        : '/grid.php';

    return $page . '?table_id=' . (int) $tableId . '&view_id=' . (int) $viewId;
}

// Form görünümünde GÖSTERİLEBİLECEK alanlar. Üç katmanlı filtrenin BİRİNCİ
// katmanı (tip bazlı) — tasarımcının bile açamayacağı tipler.
// Salt-okunur tiplere EK olarak BİLEREK kapsam dışı bırakılanlar:
//   * attachment — anonim dosya yükleme AYRI ve çok daha riskli bir iş
//   * long_text  — sanitize edilmiş HTML saklıyor; anonim girdiden gelen içerik
//                  ekip üyelerinin gridine düşeceği için depolanmış XSS yüzeyi
//   * user       — KVKK: değeri bir users.id ve normalize_cell_value() onu
//                  EKİBİN ÜYE LİSTESİNE karşı doğruluyor. Anonim bir doldurucuya
//                  seçenek sunmak, ekip üyelerinin adlarını ve id'lerini
//                  kimliği doğrulanmamış birine SIZDIRMAK olurdu. Bu proje
//                  ekip izolasyonu üzerine kurulu; form bunu delmemeli.
// Üçü de ileride ayrı bir turda, kendi güvenlik tasarımlarıyla açılabilir.
function bcc_field_allowed_in_form($fieldType)
{
    if (in_array($fieldType, $GLOBALS['BCC_READONLY_FIELD_TYPES'], true)) {
        return false;
    }

    return !in_array($fieldType, array('attachment', 'long_text', 'user'), true);
}

// Form ayarlarını views.config JSON'undan güvenli varsayılanlarla çözer.
// ÜÇ okuyucu paylaşır (form_edit.php, form.php, form_submit.php) — üçü ayrı ayrı
// json_decode edip kendi varsayılanını uydurursa biri değiştiğinde diğerleri
// sessizce ayrışırdı.
//
// ⚠️ form_fields her zaman int dizisi olarak döner: form_submit.php'nin
// whitelist'i BUNA güveniyor, yani buradaki tip zorlaması bir güvenlik
// kontrolüdür — config elle kurcalanmış olsa bile string/nested değer geçmez.
function bcc_form_config_from_view($view)
{
    $config = array();
    if (isset($view['config']) && $view['config'] !== null && $view['config'] !== '') {
        $decoded = json_decode($view['config'], true);
        $config = is_array($decoded) ? $decoded : array();
    }

    // Alan-id listesi çözümü ortak yardımcıya taşındı (bcc_config_field_id_list)
    // — Kanban'ın kanban_card_fields'ı AYNI çözümü gerektirince, ikinci kopya
    // yazmak yerine tek yere alındı. Davranış birebir aynı (is_scalar süzgeci
    // dahil, bu bir güvenlik kontrolü).
    $fieldIds = bcc_config_field_id_list($config, 'form_fields');

    $str = function ($key, $default) use ($config) {
        return (isset($config[$key]) && is_string($config[$key]) && trim($config[$key]) !== '')
            ? $config[$key]
            : $default;
    };

    return array(
        'form_fields' => $fieldIds,
        'form_title' => $str('form_title', ''),
        'form_description' => $str('form_description', ''),
        'form_success_message' => $str('form_success_message', 'Teşekkürler, kaydınız alındı.'),
        // Slack bildirimi VARSAYILAN KAPALI — anonim spam ekibin kanalına
        // taşmasın diye tasarımcı açıkça açmalı (güvenlik kararı).
        'form_slack_notify' => !empty($config['form_slack_notify']) ? 1 : 0,
    );
}

// views.config'ten SAF int dizisi cikarir — bcc_form_config_from_view() ve
// bcc_kanban_config_from_view() ORTAK yardimcisi (ikisinde de ayni alan-id
// listesi cozumu vardi, ikinci kopya yazilmadi).
//
// ⚠️ Bu bir GUVENLIK kontrolu: form_submit.php'nin ve kanban'in whitelist'leri
// buna guveniyor. is_scalar suzgeci sart — dizi/nesne gelirse (config elle
// kurcalanmis) (int) cast'i 1 uretir ve bu GERCEK bir field_id'ye denk gelebilir.
function bcc_config_field_id_list($config, $key)
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        return array();
    }

    $ids = array();
    foreach ($config[$key] as $rawId) {
        if (!is_scalar($rawId)) {
            continue;
        }
        $fid = (int) $rawId;
        if ($fid > 0 && !in_array($fid, $ids, true)) {
            $ids[] = $fid;
        }
    }

    return $ids;
}

// Kanban ayarlarini views.config'ten guvenli varsayilanlarla cozer —
// bcc_form_config_from_view() ile AYNI aile, ikinci bir JSON-cozme mantigi YOK.
// UC okuyucu paylasir: kanban.php (render), view_config_update.php (kaydetme),
// view_create.php (varsayilan kurulum).
//
// kanban_field_id 0 ise "henuz secilmemis" demektir (bos durum) — tabloda hic
// single_select alani yokken Kanban olusturulabilir, kanban.php yonlendirici bir
// bos ekran gosterir.
function bcc_kanban_config_from_view($view)
{
    $config = array();
    if (isset($view['config']) && $view['config'] !== null && $view['config'] !== '') {
        $decoded = json_decode($view['config'], true);
        $config = is_array($decoded) ? $decoded : array();
    }

    return array(
        'kanban_field_id' => (isset($config['kanban_field_id']) && is_scalar($config['kanban_field_id']))
            ? (int) $config['kanban_field_id']
            : 0,
        // Kartta birincil alanin ALTINDA gosterilecek EK alanlar. Birincil alan
        // her zaman basilir ve bu listede YER ALMAZ (grid'in $fields[0] kurali).
        'kanban_card_fields' => bcc_config_field_id_list($config, 'kanban_card_fields'),
    );
}

// Kanban sutunlamasi icin UYGUN alan tipleri.
//
// ILK TURDA YALNIZCA single_select — gerekce koda dayali: sonlu+sirali liste
// (select_choices_from_options), uyelik dogrulamasi (normalize_cell_value bir
// choices uyesi olmayan degeri 422 ile reddeder, yani surukle-birak sunucuda
// KENDILIGINDEN dogrulanir) ve renk haritasi (bcc_build_choice_color_map) —
// ucu de HAZIR, yeni mekanizma gerekmiyor.
//
// BILEREK DISARIDA:
//   * multiple_select — bir kayit BIRDEN FAZLA sutunda gorunurdu; "karti tasi"
//     hangi degeri degistirecegi belirsiz kalirdi (davranissal olarak bozuk)
//   * checkbox — teknik olarak iki sutun mumkun ama value_number kullaniyor ve
//     iki sutunlu bir tahtanin degeri dusuk
//   * user — sutunlar takim uyeleri olurdu (dinamik liste, choices mekanizmasi yok)
//   * date — bu Calendar'in isi
function bcc_field_allowed_for_kanban($fieldType)
{
    return $fieldType === 'single_select';
}

// views.config'e "oku-degistir-yaz": YALNIZCA $changes'teki anahtarlar
// guncellenir, geri kalan (frozen_column_count, grid_state, form_*, kanban_*)
// EZILMEZ.
//
// Bu blok view_config_update.php ve form_edit.php'de BIREBIR kopyalanmisti;
// Kanban ucuncusunu gerektirince tek yere alindi. Ayni JSON'a dort ayri ozellik
// yazdigi icin buradaki "ezme" disiplini kritik: kopyalardan biri unutulsaydi
// bir ozellik digerinin ayarlarini sessizce silerdi.
//
// $changes'te degeri null olan anahtar SILINIR (ornegin kanban_field_id'yi
// temizlemek icin) — bu, "0 yaz" ile "anahtari kaldir" arasindaki farki
// cagirana birakir.
function bcc_update_view_config($viewId, array $changes)
{
    $current = bcc_fetch_column('SELECT config FROM views WHERE id = :id', array(':id' => $viewId));

    $config = array();
    if ($current !== false && $current !== null && $current !== '') {
        $decoded = json_decode($current, true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($config[$key]);
            continue;
        }
        $config[$key] = $value;
    }

    bcc_execute(
        'UPDATE views SET config = :config WHERE id = :id',
        array(':config' => json_encode($config, JSON_UNESCAPED_UNICODE), ':id' => $viewId)
    );

    return $config;
}

// Kayıt çoğaltmada (record_duplicate.php) BİRİNCİL ALANIN sonuna " copy" eklenen
// tipler — OpsFlow'daki "Kayıt copy" davranışı.
//
// ⚠️ BU BİR WHITELIST: yalnızca burada AÇIKÇA sayılan tipler ek alır, diğer HER
// ŞEY (bugünküler ve gelecekte eklenecek olanlar) değeri OLDUĞU GİBİ kopyalar.
// Yeni bir SERBEST METİN tipi eklenirse buraya ELLE eklenmesi gerekir — kasıtlı
// olarak "unutulunca ek almaz" tarafına düşüyor, çünkü yanlış ek eklemek
// (aşağıdaki bug'lar) eksik ek eklemekten çok daha zararlı.
//
// NEDEN KOLON DEĞİL TİP: eskiden karar "değer kolonu value_text mi" diye
// veriliyordu. Ama value_text'i YEDİ tip paylaşıyor ve bunların çoğunun bir
// BİÇİM SÖZLEŞMESİ var — serbest metne " copy" eklemek zararsızken, biçimli bir
// değere eklemek onu BOZUYORDU (teşhis turunda canlı ölçüldü):
//   * url    — "https://example.com copy" linkleştirme regex'ine (^https?://,
//              yalnızca BAŞA çapalı) HÂLÂ uyuyordu, yani link ÜRETİLİYOR ama
//              tarayıcı boşluğu %20 olarak HOST'a katıyordu
//              (host = "example.com%20copy") -> kullanıcı sessizce VAR OLMAYAN
//              bir alan adına gidiyordu. En zararlı vaka: hata görünmüyor.
//   * email  — "user@example.com copy" FILTER_VALIDATE_EMAIL'i geçemiyor,
//              mailto: linki tamamen kayboluyordu.
//   * single_select — "Seçenek copy" alanın choices listesinde OLMAYAN bir değer;
//              DB'ye geçersiz bir seçim yazılıyordu.
//   * time   — "14:30 copy" geçerli bir saat değil.
//   * phone  — tek zararsız olanı (rakam indirgemesi " copy"yi zaten atıyor,
//              tel: href'i değişmiyordu) ama tutarlılık için o da ek ALMIYOR.
// Bu yüzden karar artık "hangi kolonda duruyor" değil, "biçim sözleşmesi var mı".
$GLOBALS['BCC_DUPLICATE_SUFFIX_FIELD_TYPES'] = array('single_line_text', 'long_text');

// Grup A — hücresi linkleştirilebilen tipler. Bir alan tipinin bu listede olması
// YALNIZCA "değeri linke çevrilebilir" demektir; değer yine düz metindir ve
// cell_display_text() bu tipler için de DÜZ METİN döndürmeye devam eder.
// Linkleştirme SADECE gerçekten HTML üreten iki noktada olur: grid <td>'si
// (bcc_render_linkified_cell) ve cell_update.php'nin display_link yanıtı.
$GLOBALS['BCC_LINKIFIED_FIELD_TYPES'] = array('url', 'email', 'phone');

// Hücre linklerinin izinli şemaları. Zengin metnin (long_text) varsayılanından
// (yalnızca http/https) FARKLI: mailto/tel de var, çünkü bu href'leri kullanıcı
// DEĞİL bcc_cell_link_href() kurar — kullanıcının yazdığı şey yalnızca ham metin.
define('BCC_CELL_LINK_SCHEMES', '#^(https?://|mailto:|tel:)#i');

// Bir alan tipinin değeri cell_values'ta hangi kolonda saklanır (Faz 3).
// 'attachment' BİLEREK burada YOK — bir hücrede birden fazla dosya olabildiği
// için değeri cell_values'ta değil, ayrı bir 'attachments' tablosunda
// (record_id/field_id doğrudan kolon) saklanıyor. Bu yüzden attachment alanları
// sort/filter/group'a hiç girmez (parse_grid_sort_rules/parse_grid_group_rules
// bu haritada anahtarı olmayan tipleri savunmacı biçimde atlar).
$GLOBALS['BCC_FIELD_VALUE_COLUMN'] = array(
    'single_line_text' => 'value_text',
    'long_text' => 'value_text',
    'number' => 'value_number',
    'checkbox' => 'value_number',
    'date' => 'value_date',
    'single_select' => 'value_text',
    'multiple_select' => 'value_json',
    // Saat: DDL yok, date'in aynısı gibi value_text'e "HH:MM" (24 saat, sıfır dolgulu)
    // yazılır — string karşılaştırma kronolojik sırayla birebir örtüşür.
    'time' => 'value_text',
    // User: DDL yok, users.id value_number'a yazılır (mevcut kolon, tamsayı olarak
    // kullanılır). Görünen ad DEĞİL id saklanır — görüntüleme id→ad haritası
    // (bcc_team_users_by_id) ile cell_display_text()'te çözülür.
    'user' => 'value_number',
    // created_time/created_by: cell_values'ta GERÇEKTEN bir satırları YOK (değer
    // records.created_at/created_by'dan geliyor) — burada yalnızca cell_raw_value()/
    // cell_display_text()/bcc_group_cell_row()'un beklediği $cellRow ŞEKLİNİ
    // (value_date/value_number anahtarları) ödünç alıyoruz; gerçek SQL kolon
    // adı için BCC_RECORD_COLUMN_FIELD_TYPES'a bakılır (aşağıda).
    'created_time' => 'value_date',
    'created_by' => 'value_number',
    // last_modified_time/by: created_time/by ile AYNI mantık (value_date/
    // value_number ödünç alınır) — gerçek kolon BCC_RECORD_COLUMN_FIELD_TYPES'ta.
    'last_modified_time' => 'value_date',
    'last_modified_by' => 'value_number',
    // currency/percent/rating: number ile AYNI kolon — GERÇEK bir sayı değeri,
    // yalnızca cell_display_text() farklı formatlar (sembol/yüzde/yıldız).
    // percent İSTİSNAİ: DB'de ondalık (0.45) saklanır, normalize_cell_value()
    // girileni 100'e böler, cell_display_text() ×100 yapıp geri çevirir.
    'currency' => 'value_number',
    'percent' => 'value_number',
    'rating' => 'value_number',
    // autonumber: number ile AYNI kolon — değer GERÇEKTEN cell_values'ta yaşıyor
    // (created_time/created_by'ın aksine, onlar records'tan türetiliyordu ve bu
    // yüzden BCC_RECORD_COLUMN_FIELD_TYPES'a da giriyorlardı; autonumber oraya
    // GİRMEZ). Yeni olan tek şey fields.autonumber_next SAYACI (migrations/014),
    // değerin saklandığı yer değil.
    'autonumber' => 'value_number',
    // url/email/phone (Grup A): single_line_text ile AYNI kolon — saklanan şey
    // düpedüz metin. Linkleştirme yalnızca RENDER katmanında olur, DB'de bir izi
    // yoktur; bu yüzden sıralama/filtre/gruplama single_line_text ile birebir
    // aynı yolu (value_text) kullanır ve ekstra hiçbir şey gerektirmez.
    'url' => 'value_text',
    'email' => 'value_text',
    'phone' => 'value_text',
);

// created_time/created_by/last_modified_time/last_modified_by gibi "records
// tablosundan doğrudan okunan" alan tipleri — bcc_build_grid_records_query()/
// filter_condition_sql() bu haritada bir field_type bulursa cell_values'a LEFT
// JOIN ATMAZ, records'un (alias 'r') KENDİ kolonunu doğrudan kullanır.
// BCC_FIELD_VALUE_COLUMN'daki 'value_date'/'value_number' İLE KARIŞTIRILMASIN
// — o render fonksiyonlarının $cellRow şekli için, bu ise gerçek SQL kolon adı için.
$GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'] = array(
    'created_time' => 'created_at',
    'created_by' => 'created_by',
    'last_modified_time' => 'updated_at',
    'last_modified_by' => 'updated_by',
);

// Grid sütun başlığında gösterilen kısa tip rozeti.
$GLOBALS['BCC_FIELD_TYPE_BADGE'] = array(
    'single_line_text' => 'Aa',
    'long_text' => '¶',
    'number' => '#',
    'checkbox' => '☑',
    'date' => '📅',
    'single_select' => '▾',
    'multiple_select' => '☰',
    'time' => '🕐',
    'user' => '@',
    'attachment' => '📎',
    'created_time' => '🕐',
    'created_by' => '@',
    'last_modified_time' => '🕐',
    'last_modified_by' => '@',
    'currency' => '💲',
    'percent' => '%',
    'rating' => '★',
    // autonumber: 'number' ile AYNI rozeti paylaşır — yeni bir simge çizilmedi.
    'autonumber' => '#',
    // Grup A. (Bu dizi yalnızca /api/field_create.php'nin JSON yanıtında
    // kullanılıyor — gridde ve sihirbazda görünen rozet theme.css'teki
    // .field-badge--<tip> / .field-type-badge--<tip> SVG ikonudur.)
    'url' => '🔗',
    'email' => '✉',
    'phone' => '☎',
);

// Grid filtresi (Faz 4): alan tipine göre izin verilen koşullar (whitelist).
// Anahtarlar SQL'e gömülmez — filter_condition_sql() içinde sabit switch/case ile eşlenir.
// Filtre panelindeki AZAMI kural sayisi (slot). URL sozlesmesi
// filter_field_1..N / filter_cond_1..N / filter_value_1..N seklinde ve bu
// sayi UC yerde birden geciyordu: parse_grid_filter_rules(), grid.php'nin
// panel dongusu ve bcc_grid_state_is_empty(). Tek kaynak buraya alindi —
// '+ Filtre ekle' butonu da istemcide ayni sayiyi kullaniyor.
$GLOBALS['BCC_FILTER_MAX_SLOTS'] = 5;

// Siralama panelindeki AZAMI kural sayisi. Filtredeki sabitle AYNI
// gerekce: bu sayi parse_grid_sort_rules(), grid.php'nin panel dongusu
// ve bcc_grid_state_is_empty() olmak uzere UC yerde birden geciyordu.
$GLOBALS['BCC_SORT_MAX_SLOTS'] = 3;

$GLOBALS['BCC_FILTER_OPERATORS'] = array(
    'single_line_text' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'long_text' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'single_select' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    // url/email/phone (Grup A): single_line_text ile BİREBİR AYNI set — değer
    // düz metin olarak saklanıyor, filtre de düz metin üzerinde çalışıyor.
    // ('empty'/'not_empty'nin doğru çalışması için filter_condition_sql()'in
    // $isTextLike dizisine de eklenmeleri ŞART — aksi halde yalnızca IS NULL
    // bakılır ve '' hücreleri kaçırılır.)
    'url' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'email' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'phone' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'number' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'checkbox' => array(
        'checked' => 'işaretli', 'unchecked' => 'işaretsiz',
    ),
    'date' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'multiple_select' => array(
        'contains' => 'içerir', 'not_contains' => 'içermez',
    ),
    'time' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'user' => array(
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    // created_time: 'date' ile AYNI operatörler ama empty/not_empty BİLEREK YOK —
    // records.created_at NOT NULL, hiçbir zaman boş olamaz (her zaman aynı sonucu
    // verecek anlamsız bir filtre olurdu).
    'created_time' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
    ),
    // created_by: 'user' ile AYNI — records.created_by NULL olabilir (oluşturan
    // kullanıcı silinmişse, FK ON DELETE SET NULL), empty/not_empty anlamlı.
    'created_by' => array(
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    // last_modified_time: created_time ile AYNI gerekçeyle empty/not_empty YOK
    // — records.updated_at NOT NULL, hiçbir zaman boş olamaz.
    'last_modified_time' => array(
        'before' => 'önce', 'after' => 'sonra', 'equals' => 'eşittir',
    ),
    // last_modified_by: created_by ile AYNI — records.updated_by NULL olabilir
    // (henüz hiç düzenlenmemiş VEYA düzenleyen kullanıcı silinmiş). NOT: bu
    // filtre/sıralama HAM updated_by üzerinde çalışır — grid/detay panelindeki
    // "hiç düzenlenmemişse created_by'a düş" GÖRÜNTÜLEME kuralı (bcc_cell_row_for_field)
    // burayı etkilemez, "empty" burada GERÇEKTEN "hiç düzenlenmemiş" anlamına gelir.
    'last_modified_by' => array(
        'equals' => 'eşittir', 'not_equals' => 'eşit değil',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    // currency/percent/rating: 'number' ile BİREBİR AYNI operatör seti —
    // filter_condition_sql()'in 'number' dalı da bu üç tipi kapsayacak
    // şekilde genişletildi, ikinci bir karşılaştırma mantığı yazılmadı.
    'currency' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'percent' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    'rating' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
    // autonumber: yine 'number' ile AYNI set. 'empty'/'not_empty' BİLEREK
    // korundu — teoride her kayıt numara alır, ama autonumber alanı eklenmeden
    // ÖNCE çöpe atılmış bir kayıt geri yüklenirse (backfill silinmiş kayıtları
    // da kapsıyor, bkz. bcc_backfill_autonumber_field) veya backfill'den sonra
    // hücre elle silinirse boş olabilir; filtreyi kaldırmak bu kayıtları
    // bulunamaz yapardı.
    'autonumber' => array(
        'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<', 'gte' => '≥', 'lte' => '≤',
        'empty' => 'boş', 'not_empty' => 'boş değil',
    ),
);

// Değer girdisi gerektirmeyen operatörler (input UI'da gizlenir).
$GLOBALS['BCC_FILTER_NO_VALUE_OPS'] = array('empty', 'not_empty', 'checked', 'unchecked');

// Slack koşullu yönlendirme kuralları (slack_routing_rules.operator) — yalnızca
// single_select alanları için anlamlı olduğundan BCC_FILTER_OPERATORS'ın o alt
// kümesiyle aynı ruhta, kasıtlı olarak küçük tutuldu. Kod-taraflı bir whitelist
// olduğu için yeni operatör eklemek DDL gerektirmez.
$GLOBALS['BCC_SLACK_ROUTING_OPERATORS'] = array(
    'equals' => 'eşittir',
    'not_equals' => 'eşit değil',
);

// Grid gruplama (Grid araçları Adım 2a): alan tipine göre yön dropdown etiketleri
// (mantık her zaman artan/azalan — yalnızca metin değişir, OpsFlow'daki gibi).
$GLOBALS['BCC_GROUP_DIR_LABELS'] = array(
    'single_line_text' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'long_text' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'single_select' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'multiple_select' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'number' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    'date' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),
    'checkbox' => array('asc' => 'İşaretsiz → İşaretli', 'desc' => 'İşaretli → İşaretsiz'),
    'time' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),
    // Ada göre değil, alttaki id'ye göre (görüntü adı değil ham değer sıralanır —
    // diğer tüm tiplerle aynı kural, bkz. bcc_build_grouped_tree segmentasyon notu).
    'user' => array('asc' => 'Küçük → Büyük', 'desc' => 'Büyük → Küçük'),
    // Bulunan gerçek bug (Grup A turunda yakalandı): Grup B1/B2/C1/C2 ile SEKİZ
    // yeni tip eklendi ama bu dizi hiç güncellenmedi. Gruplama paneli yalnızca
    // 'attachment'ı eliyor (public/grid.php), yani bu tiplerden birine göre
    // gruplamak ERİŞİLEBİLİR bir yol — ve grid.php'nin okuması KORUMASIZDI:
    // "Undefined index" notice'ı + yön dropdown'ında BOŞ etiketler.
    // İki katmanlı düzeltme: (1) eksik girişler burada tamamlandı,
    // (2) grid.php'deki okuma isset() ile korundu — gelecekte eklenecek bir tip
    // bu diziye yazılmayı unutursa notice yerine makul bir varsayılana düşsün.
    // created_time/created_by/last_modified_* BİLEREK YOK: bu dört tip
    // BCC_RECORD_COLUMN_FIELD_TYPES'ta ve gruplama panelinde zaten seçilebilir
    // olsalar da 'time'/'user' etiketleriyle örtüşürler — aşağıda ayrıca eklendi
    // ki hiçbir tip açıkta kalmasın.
    'created_time' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),
    'last_modified_time' => array('asc' => 'Erken → Geç', 'desc' => 'Geç → Erken'),
    'created_by' => array('asc' => 'Küçük → Büyük', 'desc' => 'Büyük → Küçük'),
    'last_modified_by' => array('asc' => 'Küçük → Büyük', 'desc' => 'Büyük → Küçük'),
    // C1/C2 — sayısal tipler, 'number' ile AYNI desen.
    'currency' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    'percent' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    'autonumber' => array('asc' => '1 → 9', 'desc' => '9 → 1'),
    // rating: sayısal ama kullanıcı yıldız olarak görüyor — "1 → 9" yanıltıcı
    // olurdu (maksimum alan başına 1-10 arasında ayarlanabiliyor).
    'rating' => array('asc' => 'Az → Çok', 'desc' => 'Çok → Az'),
    // Grup A — metin tipleri, single_line_text ile AYNI desen.
    'url' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'email' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
    'phone' => array('asc' => 'A → Z', 'desc' => 'Z → A'),
);

// Alan tipine gore yon etiketleri ('A -> Z', '1 -> 9', 'Erken -> Gec'...).
// TEK GIRIS NOKTASI: hem gruplama hem siralama paneli bunu cagiriyor.
// Dizinin adi tarihsel olarak GROUP_ ile basliyor ama icerigi alan tipine
// gore ARTAN/AZALAN etiketidir; gruplamaya ozel bir sey degil.
//
// isset() korumasi BURADA topland: ayni kontrol daha once grid.php'de
// satir ici yaziliyordu ve siralama paneli eklenince IKINCI bir kopyasi
// gerekecekti. Diziye eklenmeyi unutan yeni bir alan tipi 'Undefined
// index' yerine makul bir varsayilana duser.
function bcc_dir_labels($fieldType)
{
    $map = $GLOBALS['BCC_GROUP_DIR_LABELS'];

    return isset($map[$fieldType]) ? $map[$fieldType] : array('asc' => 'artan', 'desc' => 'azalan');
}

// Tekli/çoklu seçim seçeneklerinin renk paleti (Color): serbest hex DEĞİL, sabit
// whitelist — kullanıcıdan gelen bir rengi doğrudan style attribute'una basmak
// yerine (CSS/attribute injection riski + tutarsız görsel sonuç) yalnızca bu
// anahtarlardan biri kabul edilir, fields.options'ta ("colors" anahtarı) renk KEY'i
// saklanır, hex değeri her zaman buradan çözülür.
$GLOBALS['BCC_CHOICE_COLORS'] = array(
    'blue' => '#cfe2ff',
    'cyan' => '#cdf3f5',
    'teal' => '#d0f0e8',
    'green' => '#d7f0d1',
    'yellow' => '#fdf1c7',
    'orange' => '#fde2c8',
    'red' => '#fbdbd7',
    'pink' => '#fbdce8',
    'purple' => '#e6d9f7',
    'gray' => '#e6e6e9',
);

// Zengin metin (long_text — F6, "ilk aşama": kalın/italik/font büyüklüğü/link).
// Sabit boyut listesi — serbest CSS değil, hem sanitize edici (bcc_sanitize_rich_text)
// hem de düzenleme araç çubuğu (grid.js) AYNI listeyi kullanır.
$GLOBALS['BCC_RICH_TEXT_FONT_SIZES'] = array(10, 12, 14, 16, 18, 24, 32);

// Grid satır yüksekliği (Grid araçları Adım 3): whitelist + kaçta kaç satırın
// gösterileceği (line-clamp) etiketleri. Sıra panel render'ında da kullanılır.
$GLOBALS['BCC_ROW_HEIGHT_LABELS'] = array(
    'short' => 'Kısa',
    'medium' => 'Orta',
    'tall' => 'Uzun',
    'extra' => 'Ekstra uzun',
);

// team_id, bases üzerinden gelir; bir base'in verisine erişen her sayfa bunu kullanmalı.
// deleted_at IS NULL koşulu — Trash'e taşınmış (soft-delete) bir base'e link
// paylaşılmışsa/yer imi varsa doğrudan girmeye çalışan biri 404 alır. Bu TEK
// fonksiyon base.php/grid.php/interface.php/table_fields.php dahil HER
// sayfa tarafından çağrıldığı için koruma tek yerden sağlanır.
function find_base_or_404($baseId)
{
    $base = bcc_fetch_one(
        'SELECT id, team_id, name, description FROM bases WHERE id = :id AND deleted_at IS NULL LIMIT 1',
        array('id' => $baseId)
    );

    if (!$base) {
        http_response_code(404);
        die('Base bulunamadı.');
    }

    return $base;
}

// team_id, tables_meta -> bases üzerinden gelir; bir tablonun verisine erişen her sayfa bunu kullanmalı.
// find_base_or_404() ile AYNI koruma — base silinmişse (Trash) tablosuna
// doğrudan table_id ile de erişilemez.
function find_table_or_404($tableId)
{
    $table = bcc_fetch_one(
        'SELECT tm.id, tm.base_id, tm.name, tm.description, tm.position, b.team_id, b.name AS base_name
         FROM tables_meta tm
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE tm.id = :id AND b.deleted_at IS NULL LIMIT 1',
        array('id' => $tableId)
    );

    if (!$table) {
        http_response_code(404);
        die('Tablo bulunamadı.');
    }

    return $table;
}

// Bir tabloya ait TEK varsayılan görünüm satırını (id + name) döndürür; yoksa
// oluşturur. grid.php şu ana kadar "Grid view" adını sabit basıyordu — görünüm
// adını satır içi yeniden adlandırma özelliği kalıcı bir view_id gerektirdiği
// için bu fonksiyon her table_id'nin en az bir views satırına sahip olmasını
// garanti eder. Şemaya DOKUNMAZ (views tablosu zaten schema.sql'de var), yalnızca
// satır okur/yazar.
// Yarış koşulu: iki istek aynı anda ilk kez buraya gelirse ikisi de INSERT
// deneyebilir (views.table_id üzerinde UNIQUE kısıt yok, DDL uygulanmıyor).
// Bunu tamamen engellemek yerine ZARARSIZ hale getiriyoruz: INSERT'ten SONRA
// satır her zaman TEKRAR "id ASC LIMIT 1" ile okunur — olası bir kısa süreli
// çift satır oluşsa bile tüm çağıranlar hep AYNI (en eski) satırda buluşur,
// hiçbir çağıran "az önce ben oluşturdum" varsayımıyla ikinci bir satır üretmez.
function bcc_get_or_create_default_view($tableId)
{
    // bcc_find_view() ile AYNI kolon listesi — grid.php ikisinden hangisi
    // dönerse dönsün $view['view_type']'a güvenebilmeli (erken yönlendirme).
    $sql = 'SELECT v.id, v.name, v.description, v.config, v.created_by, v.view_type,
                   v.form_token, v.form_enabled, u.full_name AS created_by_name
            FROM views v
            LEFT JOIN users u ON u.id = v.created_by
            WHERE v.table_id = :table_id ORDER BY v.id ASC LIMIT 1';

    $view = bcc_fetch_one($sql, array('table_id' => $tableId));

    if ($view) {
        return $view;
    }

    $creator = current_user();

    bcc_execute(
        'INSERT INTO views (table_id, name, view_type, created_by)
         SELECT :table_id, :name, :view_type, :created_by
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM views WHERE table_id = :table_id)',
        array(
            'table_id' => $tableId,
            'name' => 'Tablo görünümü',
            'view_type' => 'grid',
            'created_by' => $creator ? $creator['id'] : null,
        )
    );

    return bcc_fetch_one($sql, array('table_id' => $tableId));
}

// ?view_id= ile GELEN bir view — YALNIZCA verilen $tableId'ye aitse döner
// (başka bir tablonun view_id'sini geçmeye çalışan istek sessizce reddedilir,
// çağıran bcc_get_or_create_default_view()'e düşer). grid.php'nin çoklu view
// desteği (sol panel) için — bcc_get_or_create_default_view() hâlâ "hiç
// view_id verilmemişse" veya "geçersizse" düşülecek varsayılan.
function bcc_find_view($viewId, $tableId)
{
    return bcc_fetch_one(
        // view_type/form_token/form_enabled (Grup View-Form): grid.php'nin erken
        // yönlendirmesi ve form_edit.php bu kolonları okur — ikinci bir sorgu
        // yazmak yerine zaten var olan tekil view sorgusu genişletildi.
        'SELECT v.id, v.name, v.description, v.config, v.created_by, v.view_type,
                v.form_token, v.form_enabled, u.full_name AS created_by_name
         FROM views v
         LEFT JOIN users u ON u.id = v.created_by
         WHERE v.id = :id AND v.table_id = :table_id LIMIT 1',
        array('id' => $viewId, 'table_id' => $tableId)
    );
}

// Sol Views paneli: tablonun TÜM view'ları — favoriler ÖNCE (kullanıcı bazlı,
// user_favorite_views), sonra position/id. $userId olmadan (ör. viewer için de
// panel dolduruluyor) favori bilgisi olmadan da çalışır.
function bcc_list_table_views($tableId, $userId = null)
{
    return bcc_fetch_all(
        // view_type: sol paneldeki her satırın hem ROZETİ hem HEDEF ADRESİ
        // (bcc_view_route_for) türe bağlı — form görünümleri form_edit.php'ye gider.
        'SELECT v.id, v.name, v.description, v.position, v.created_by, v.view_type,
                u.full_name AS created_by_name,
                (ufv.id IS NOT NULL) AS is_favorite
         FROM views v
         LEFT JOIN users u ON u.id = v.created_by
         LEFT JOIN user_favorite_views ufv ON ufv.view_id = v.id AND ufv.user_id = :user_id
         WHERE v.table_id = :table_id
         ORDER BY is_favorite DESC, v.position, v.id',
        array('table_id' => $tableId, 'user_id' => $userId)
    );
}

// Dondurulabilecek en fazla sütun sayısı (satır no dahil) — görünür alan sayısının
// yaklaşık yarısı, en az 1. grid.php (ilk render) ve view_config_update.php
// (sürükleme sonrası doğrulama) AYNI formülü paylaşır, ikisi ayrı ayrı hesaplamaz.
function bcc_max_frozen_columns($visibleFieldCount)
{
    $total = $visibleFieldCount + 1; // +1: satır no kolonu her zaman sayılır

    return max(1, (int) ceil($total / 2));
}

// Dondurulmuş sütun sayısının VARSAYILANI (satır no dahil).
//
// 2 = satır no + İLK VERİ SÜTUNU. Yani yatay kaydırmada ilk veri sütunu
// yerinde kalır, ikinci sütundan itibaren hepsi onun arkasından kayar.
// OpsFlow'un varsayılan davranışı bu; önceki değer 1'di (yalnızca satır no
// donuyordu, hiçbir veri sütunu donmuyordu).
//
// ⚠️ Bu YALNIZCA VARSAYILAN. Sayı hâlâ görünüm başına ayarlanabilir (sütun
// başlığındaki dondurma tutamacı → views.config.frozen_column_count) ve
// kullanıcının AÇIKÇA seçtiği değer bu değişiklikten ETKİLENMEZ: anahtar
// yalnızca tutamaç sürüklendiğinde yazılıyor, bu yüzden aşağıdaki isset()
// kontrolü "hiç ayarlanmamış" ile "elle 1 seçilmiş" durumlarını ayırıyor.
$GLOBALS['BCC_DEFAULT_FROZEN_COLUMNS'] = 2;

// Dondurulabilecek en fazla sütun sayısı — bkz. bcc_max_frozen_columns().
// Tek görünür alanı olan bir tabloda üst sınır 1'dir, yani varsayılan 2 orada
// 1'e kırpılır (donacak bir veri sütunu bırakıp kaydıracak hiçbir şey
// bırakmamak anlamsız olurdu).

// views.config JSON'ından dondurulmuş sütun sayısını SAVUNMACI biçimde okur:
// NULL, bozuk JSON, eksik anahtar veya beklenmedik tip (ör. string/float) gelirse
// sessizce varsayılana düşer, hata fırlatmaz. $maxAllowed verilirse üst sınıra da
// kırpılır (config'teki eski bir değer, sonradan alan gizlenip görünür sütun
// sayısı azalınca render'ı bozmasın diye).
function bcc_get_frozen_column_count($configJson, $maxAllowed = null)
{
    $count = $GLOBALS['BCC_DEFAULT_FROZEN_COLUMNS'];

    if ($configJson !== null && $configJson !== '') {
        $decoded = json_decode($configJson, true);
        if (is_array($decoded) && isset($decoded['frozen_column_count']) && is_int($decoded['frozen_column_count'])) {
            $count = $decoded['frozen_column_count'];
        }
    }

    if ($count < 1) {
        $count = 1;
    }
    if ($maxAllowed !== null && $count > $maxAllowed) {
        $count = $maxAllowed;
    }

    return $count;
}

// ---- Sütun genişliği (sürükle-boyutlandır) --------------------------------
//
// Sınırlar TEK yerde: grid.php (ilk render), grid-column-resize.js (sürükleme
// sırasında canlı kırpma, değerleri BCC_* global'leriyle sunucudan alır) ve
// view_config_update.php (kaydederken sunucu tarafı kırpma) AYNI üç sabiti
// paylaşır — istemciye güvenilmez, ama istemci de aynı davranışı gösterir.
$GLOBALS['BCC_MIN_COLUMN_WIDTH'] = 80;   // okunmaz hale gelmesin
$GLOBALS['BCC_MAX_COLUMN_WIDTH'] = 800;  // tek sütun tabloyu yutmasın
// Kaydedilmiş genişlikler VARKEN sonradan eklenen/görünür olan bir alanın
// genişliği (o alan haritada yok) — tablo `table-layout: fixed` moduna geçmiş
// olduğu için her sütunun bir genişliği OLMAK ZORUNDA.
$GLOBALS['BCC_DEFAULT_COLUMN_WIDTH'] = 180;

// Satır no / seçim sütununun (.grid-rownum) genişliği. Kaydedilmiş haritadan
// OKUNMUYOR, her zaman bu sabit basılıyor.
//
// Neden: bu sütun SÜRÜKLENEMEZ — grid-column-resize.js yalnızca
// th[data-col-key] olan başlıklara tutamaç takıyor, satır no <th>'sinde ise o
// öznitelik yok. Haritadaki 'row' anahtarı bu yüzden kullanıcı TERCİHİ değil,
// "tüm genişlikleri kaydet" turunda o an CSS'in ürettiği değerin ÖLÇÜLMÜŞ bir
// anlık görüntüsü (mevcut görünümlerde 80px ve 93px gibi değerler böyle
// oluşmuş). Yoğunluk küçüldüğünde o eski ölçüm satır içi <col style="width">
// olarak basılıp CSS'i yeniyordu (table-layout:fixed'te satır içi genişlik
// kazanır), yani sütun 40px'e inemiyordu.
//
// BCC_MIN_COLUMN_WIDTH (80) buna UYGULANMAZ: o alt sınır okunabilirliği olan
// VERİ sütunları için; burada yalnızca sayı/checkbox/genişlet ikonu var ve
// ikisi hover'da yer değiştirdiği için asla yan yana durmuyorlar.
$GLOBALS['BCC_ROW_COLUMN_WIDTH'] = 44;

// views.config JSON'ından sütun genişliklerini SAVUNMACI biçimde okur
// (bcc_get_frozen_column_count ile AYNI disiplin: NULL/bozuk JSON/eksik anahtar/
// beklenmedik tip -> sessizce boş dizi, hata fırlatmaz).
//
// Anahtar biçimi: 'row' (satır no sütunu — bir alan DEĞİL ama genişliği
// korunmalı) ve 'f<field_id>'. Düz {field_id: px} yerine önekli anahtar
// seçildi çünkü (a) satır no sütununun bir field_id'si yok, (b)
// json_decode(..., true) sayısal string anahtarları int'e çeviriyor ve
// 'row' ile karışık int/string anahtarlı bir dizi üretirdi.
//
// $visibleFields verilirse sonuç YALNIZCA o an görünür olan alanlara indirgenir:
// silinmiş ya da gizlenmiş bir alanın eski genişliği colgroup'u kaydırmasın.
// Dönüş boş dizi ise çağıran ESKİ (otomatik) yerleşimde kalır — bu, özelliğin
// "opt-in" olmasını sağlayan şeydir.
function bcc_get_column_widths($configJson, $visibleFields = null)
{
    if ($configJson === null || $configJson === '') {
        return array();
    }

    $decoded = json_decode($configJson, true);
    if (!is_array($decoded) || !isset($decoded['column_widths']) || !is_array($decoded['column_widths'])) {
        return array();
    }

    $allowed = null;
    if ($visibleFields !== null) {
        $allowed = array('row' => true);
        foreach ($visibleFields as $f) {
            $allowed['f' . (int) $f['id']] = true;
        }
    }

    $out = array();
    foreach ($decoded['column_widths'] as $key => $value) {
        $key = (string) $key;
        if ($allowed !== null && !isset($allowed[$key])) {
            continue;
        }
        if (!is_int($value) && !(is_float($value) && $value == (int) $value)) {
            continue;
        }
        $out[$key] = bcc_clamp_column_width((int) $value);
    }

    return $out;
}

function bcc_clamp_column_width($width)
{
    $width = (int) $width;

    if ($width < $GLOBALS['BCC_MIN_COLUMN_WIDTH']) {
        return $GLOBALS['BCC_MIN_COLUMN_WIDTH'];
    }
    if ($width > $GLOBALS['BCC_MAX_COLUMN_WIDTH']) {
        return $GLOBALS['BCC_MAX_COLUMN_WIDTH'];
    }

    return $width;
}

// İstemciden gelen ham column_widths dizisini kabul edilebilir hâle indirger:
// yalnızca 'row' ve BU TABLOYA ait alan anahtarları, yalnızca sayısal değerler,
// hepsi min/max'a kırpılmış. Whitelist kaynağı $fieldsById — parse_grid_*()
// ailesiyle AYNI "istekten gelen id'ye güvenme" deseni.
function bcc_sanitize_column_widths($raw, $fieldsById)
{
    if (!is_array($raw)) {
        return array();
    }

    $out = array();
    foreach ($raw as $key => $value) {
        $key = (string) $key;

        if ($key !== 'row') {
            if (strpos($key, 'f') !== 0) {
                continue;
            }
            $fieldId = (int) substr($key, 1);
            if ($fieldId <= 0 || !isset($fieldsById[$fieldId])) {
                continue;
            }
        }

        if (!is_numeric($value)) {
            continue;
        }

        $out[$key] = bcc_clamp_column_width((int) round((float) $value));
    }

    return $out;
}

// Bir base'e ait tüm tabloları (id + name) position,id sırasına göre döndürür.
// Sekme şeridi (grid.php) ve base.php köprü sayfası (ilk tabloyu bulmak için) aynı
// sorguyu paylaşır — iki yerde ayrı ayrı yazılmaz.
function bcc_list_base_tables($baseId)
{
    return bcc_fetch_all(
        'SELECT id, name FROM tables_meta WHERE base_id = :base_id ORDER BY position, id',
        array('base_id' => $baseId)
    );
}

function is_select_field_type($fieldType)
{
    return in_array($fieldType, $GLOBALS['BCC_SELECT_FIELD_TYPES'], true);
}

// "Her satırda bir seçenek" metnini fields.options için JSON'a çevirir.
function parse_select_choices($optionsText)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $optionsText);
    $choices = array();

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $choices[] = $line;
        }
    }

    return $choices;
}

function select_choices_from_options($optionsJson)
{
    if ($optionsJson === null || $optionsJson === '') {
        return array();
    }

    $decoded = json_decode($optionsJson, true);

    if (is_array($decoded) && isset($decoded['choices']) && is_array($decoded['choices'])) {
        return $decoded['choices'];
    }

    return array();
}

// fields.options'ın "colors" anahtarını okur: seçenek metni => BCC_CHOICE_COLORS
// anahtarı haritası. select_choices_from_options() ile AYNI JSON'un paralel bir
// alt-alanı — choices listesi düz string kalır, hiçbir doğrulama/filtre/sort/group
// fonksiyonu bu yüzden değişmez, yalnızca render ve düzenleme formu okur.
function select_choice_colors_from_options($optionsJson)
{
    if ($optionsJson === null || $optionsJson === '') {
        return array();
    }

    $decoded = json_decode($optionsJson, true);

    if (is_array($decoded) && isset($decoded['colors']) && is_array($decoded['colors'])) {
        return $decoded['colors'];
    }

    return array();
}

// Bir seçeneğin renk KEY'ini çözer. Alan açıkça bir renk kaydetmemişse (eski
// alanlar, ya da henüz seçilmemiş) palete sırayla (seçeneğin listedeki indeksine
// göre) düşer — böylece hiçbir seçenek "renksiz" görünmez, migration/backfill
// gerekmez ("Yeni alan OLUŞTURULURKEN renkler otomatik sırayla atanır" — bu geri
// dönüş, o davranışın kendisidir, ayrıca INSERT anında yazılmaz).
function bcc_resolved_choice_color_key($choiceColors, $choice, $index)
{
    $palette = $GLOBALS['BCC_CHOICE_COLORS'];

    if (isset($choiceColors[$choice]) && isset($palette[$choiceColors[$choice]])) {
        return $choiceColors[$choice];
    }

    $keys = array_keys($palette);

    return $keys[$index % count($keys)];
}

// Bir alanın TÜM seçenek listesi + kaydedilmiş renkler haritasından, her
// seçeneğin çözümlenmiş renk KEY'ini üretir (bcc_resolved_choice_color_key'in
// listeye uygulanmış hâli) — bcc_render_grid_data_row VE cell_update.php AYNI
// haritayı üretmek için bunu paylaşır, kod tekrarı olmaz.
function bcc_build_choice_color_map($choices, $savedColors)
{
    $map = array();
    foreach ($choices as $i => $choiceText) {
        $map[$choiceText] = bcc_resolved_choice_color_key($savedColors, $choiceText, $i);
    }

    return $map;
}

// bcc_render_choice_chips() ile AYNI mantığın JSON API karşılığı —
// cell_update.php'nin AJAX yanıtında istemciye (grid.js) gönderilecek
// {text, color} çiftlerini üretir (renk hex'i sunucuda çözülür, istemci
// palette'i bilmek zorunda kalmaz).
function bcc_choice_chip_data($values, $choiceColorMap)
{
    $palette = $GLOBALS['BCC_CHOICE_COLORS'];
    $chips = array();

    foreach ($values as $value) {
        $colorKey = isset($choiceColorMap[$value]) ? $choiceColorMap[$value] : null;
        $hex = ($colorKey !== null && isset($palette[$colorKey])) ? $palette[$colorKey] : $palette['gray'];
        $chips[] = array('text' => (string) $value, 'color' => $hex);
    }

    return $chips;
}

// Seçim alanları (single_select/multiple_select) için options JSON'u kurar —
// hem alan OLUŞTURMA (bcc_create_field) hem GÜNCELLEME (table_fields.php
// update_field) tarafından PAYLAŞILIR, ikinci bir kopya YOK. Select-olmayan
// tipler için sessizce options:null döner; select tipinde $optionsText boş
// seçenek listesine çözümlenirse hata döner (en az bir seçenek şart).
// $extraPost: ham $_POST/$postData dizisi — YALNIZCA currency/percent/rating
// kendi anahtarlarını buradan okur, select tipleri (choices/colors,
// $optionsText/$colorsPost üzerinden) bunu görmezden gelir. Sınırlar
// (ondalık basamak 0-6, max_rating 1-10, sembol 5 karaktere kadar) kötüye
// kullanıma/aşırı uzun girdiye karşı savunmacı.
// Okunan anahtarlar — HEM yeni alan (src/partials/field_type_wizard_fields.php)
// HEM mevcut alan düzenleme (public/table_fields.php) formlarında BİREBİR AYNI
// olmak ZORUNDA; currency ve percent'in ondalık input'ları BİLEREK farklı adlar
// taşıyor (currency_decimal_places / percent_decimal_places) çünkü hidden bir
// satırın input'ları da forma dahil olur — ortak bir "decimal_places" adı
// hangisinin $_POST'a gireceğini belirsiz bırakırdı:
//   currency → currency_symbol, currency_decimal_places
//   percent  → percent_decimal_places
//   rating   → max_rating
function bcc_build_field_options($fieldType, $optionsText, $colorsPost = null, $extraPost = array())
{
    if ($fieldType === 'currency') {
        $symbol = isset($extraPost['currency_symbol']) ? trim((string) $extraPost['currency_symbol']) : '';
        $symbol = $symbol !== '' ? mb_substr($symbol, 0, 5, 'UTF-8') : '₺';
        $decimals = (isset($extraPost['currency_decimal_places']) && ctype_digit((string) $extraPost['currency_decimal_places']))
            ? min(6, max(0, (int) $extraPost['currency_decimal_places']))
            : 2;

        return array('ok' => true, 'options' => json_encode(array('currency_symbol' => $symbol, 'decimal_places' => $decimals), JSON_UNESCAPED_UNICODE));
    }

    if ($fieldType === 'percent') {
        $decimals = (isset($extraPost['percent_decimal_places']) && ctype_digit((string) $extraPost['percent_decimal_places']))
            ? min(6, max(0, (int) $extraPost['percent_decimal_places']))
            : 0;

        return array('ok' => true, 'options' => json_encode(array('decimal_places' => $decimals), JSON_UNESCAPED_UNICODE));
    }

    if ($fieldType === 'rating') {
        $maxRating = (isset($extraPost['max_rating']) && ctype_digit((string) $extraPost['max_rating']))
            ? min(10, max(1, (int) $extraPost['max_rating']))
            : 5;

        return array('ok' => true, 'options' => json_encode(array('max_rating' => $maxRating), JSON_UNESCAPED_UNICODE));
    }

    if (!is_select_field_type($fieldType)) {
        return array('ok' => true, 'options' => null);
    }

    $choices = parse_select_choices($optionsText);
    if (empty($choices)) {
        return array('ok' => false, 'error' => 'Tekli/çoklu seçim alanları için en az bir seçenek girilmeli (her satıra bir tane).');
    }

    $optionsData = array('choices' => $choices);

    // colors[i]: $choices dizisindeki İNDEKS (seçenek metnini array key yapmak
    // yerine — özel karakter/[] riski yok). Whitelist dışı renk KEY'i ya da
    // $choices sınırları dışındaki indeks sessizce yok sayılır.
    if (is_array($colorsPost)) {
        $palette = $GLOBALS['BCC_CHOICE_COLORS'];
        $colors = array();
        foreach ($colorsPost as $i => $colorKey) {
            if (!ctype_digit((string) $i) || !isset($choices[(int) $i]) || !isset($palette[$colorKey])) {
                continue;
            }
            $colors[$choices[(int) $i]] = $colorKey;
        }
        if (!empty($colors)) {
            $optionsData['colors'] = $colors;
        }
    }

    return array('ok' => true, 'options' => json_encode($optionsData, JSON_UNESCAPED_UNICODE));
}

// Yeni alan (sütun) oluşturur — table_fields.php'nin tam sayfa formu VE
// /api/field_create.php (grid.php'deki "+" popup'ı, tip-önce-isim-sonra akışı)
// tarafından PAYLAŞILIR, ikinci bir insert mantığı YOK. $postData: name,
// field_type, is_required, options_text, colors[] anahtarlarını (ör. $_POST
// şeklinde) bekler.
// "Zorunlu alan" bayrağını alan tipine göre normalize eder. autonumber (Grup C2)
// için HER ZAMAN 0: değeri kullanıcı DEĞİL sunucu doldurur, "kullanıcı boş
// bırakmasın" diye bir durum yok — is_required=1 kalsaydı grid başlığında
// (grid.php'deki "*" rozeti) kullanıcının asla dolduramayacağı bir alan zorunlu
// görünürdü. field-type-wizard.js onay kutusunu zaten gizliyor ama son söz
// burada: "Alanı Düzenle" formunda kutu görünür kalıyor ve bir API isteği
// doğrudan is_required=1 gönderebilir.
// İKİ çağrı yeri (bcc_create_field ve table_fields.php update_field) — ayrı ayrı
// yazılsaydı biri güncellenip diğeri unutulurdu.
function bcc_normalize_is_required($fieldType, $rawIsRequired)
{
    if ($fieldType === 'autonumber') {
        return 0;
    }

    return !empty($rawIsRequired) ? 1 : 0;
}

function bcc_create_field($tableId, $teamId, $postData)
{
    $fieldTypes = $GLOBALS['BCC_FIELD_TYPES'];
    $name = isset($postData['name']) ? trim($postData['name']) : '';
    $fieldType = isset($postData['field_type']) ? $postData['field_type'] : '';
    $isRequired = bcc_normalize_is_required($fieldType, isset($postData['is_required']) ? $postData['is_required'] : null);
    $optionsText = isset($postData['options_text']) ? $postData['options_text'] : '';

    if ($name === '') {
        return array('ok' => false, 'error' => 'Alan adı boş olamaz.');
    }
    if (mb_strlen($name, 'UTF-8') > 150) {
        // fields.name VARCHAR(150) — bu kontrol olmadan uzun bir alan adı
        // hatasız sessizce kırpılıyordu (create_user.php/create_team.php'deki
        // AYNI kontrolle aynı gerekçe).
        return array('ok' => false, 'error' => 'Alan adı en fazla 150 karakter olabilir.');
    }
    if (!isset($fieldTypes[$fieldType])) {
        return array('ok' => false, 'error' => 'Geçersiz alan tipi.');
    }

    // Aynı tabloda aynı alan adı olamaz — başka tabloda serbest. Bu kontrol
    // ÖZELLİKLE önemli: iki "Telefon" sütunu grid'de ayırt edilemez, dışa
    // aktarmada aynı başlığı üretir ve içe aktarmada hangisine yazılacağı
    // belirsizleşir (bkz. api/table_import_xlsx.php başlık eşlemesi).
    if (bcc_name_taken('fields', $tableId, $name)) {
        return array('ok' => false, 'error' => bcc_name_taken_error('fields', 'alan'));
    }

    $optionsResult = bcc_build_field_options($fieldType, $optionsText, isset($postData['colors']) ? $postData['colors'] : null, $postData);
    if (!$optionsResult['ok']) {
        return array('ok' => false, 'error' => $optionsResult['error']);
    }

    $nextPos = (int) bcc_fetch_column(
        'SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM fields WHERE table_id = :table_id',
        array('table_id' => $tableId)
    );

    // Transaction (Grup C2'de eklendi): autonumber alanında alan INSERT'i,
    // mevcut kayıtların backfill'i ve sayaç UPDATE'i ATOMİK olmalı — ayrı ayrı
    // commit edilirse "alan var ama hiçbir kayıtta numara yok" veya "numaralar
    // var ama sayaç 1'de kalmış (sonraki kayıt ÇAKIŞAN numara alır)" durumu
    // kalır. Diğer tipler için de zararsız (tek INSERT + audit).
    try {
        bcc_begin_transaction();

        bcc_execute(
            'INSERT INTO fields (table_id, name, field_type, options, position, is_required)
             VALUES (:table_id, :name, :field_type, :options, :position, :is_required)',
            array(
                'table_id' => $tableId,
                'name' => $name,
                'field_type' => $fieldType,
                'options' => $optionsResult['options'],
                'position' => $nextPos,
                'is_required' => $isRequired,
            )
        );
        $newId = bcc_last_insert_id();

        // Autonumber (Grup C2): tablo ZATEN DOLUYSA mevcut kayıtlar 1'den
        // başlayarak numaralanır ve sayaç oradan devam eder (OpsFlow davranışı).
        // ⚠️ bcc_last_insert_id() çağrısından SONRA — bcc_backfill_autonumber_field()
        // içindeki UPDATE ... GREATEST(...) LAST_INSERT_ID kullanmasa da,
        // bcc_assign_autonumbers() ile AYNI sıralama disiplini korunuyor.
        if ($fieldType === 'autonumber') {
            bcc_backfill_autonumber_field((int) $newId, $tableId);
        }

        log_audit('field.create', 'field', $newId, array('name' => $name, 'field_type' => $fieldType, 'table_id' => $tableId), $teamId);

        bcc_commit();
    } catch (Throwable $e) {
        bcc_rollback();
        throw $e;
    }

    // Slack bildirimi — COMMIT'TEN SONRA, transaction'ın DIŞINDA. Gerekçe:
    // (1) geri alınmış bir alan için bildirim gitmemeli, (2) Slack yavaşsa
    // transaction o süre boyunca açık kalmamalı (satır kilitleri), (3) gönderim
    // hata verse bile alan oluşturma başarılı sayılmalı — bcc_notify_slack_*
    // zaten hiçbir koşulda istisna sızdırmaz.
    // Bu TEK hook iki giriş noktasını da kapsar (table_fields.php tam sayfa
    // formu + api/field_create.php grid "+" popup'ı), çünkü ikisi de bu
    // fonksiyondan geçer.
    $creator = current_user();
    bcc_notify_slack_new_field(
        $tableId,
        (int) $newId,
        $name,
        $fieldType,
        $creator ? $creator['full_name'] : null
    );

    return array('ok' => true, 'field_id' => $newId, 'name' => $name, 'field_type' => $fieldType, 'is_required' => $isRequired);
}

// Tekli/çoklu seçim hücrelerinde renkli "chip" render eder — htmlspecialchars
// burada uygulanır, çağıran taraf kaçırmaz. $values: gösterilecek seçenek
// metinleri (tekli için tek elemanlı, çoklu için seçili olanların dizisi);
// $choiceColorMap: seçenek metni => renk KEY'i (bcc_resolved_choice_color_key
// ile ÖNCEDEN, alanın TÜM seçenek listesi üzerinden hesaplanmış olmalı — indeks
// tabanlı geri dönüşün doğru çalışması için).
function bcc_render_choice_chips($values, $choiceColorMap)
{
    $palette = $GLOBALS['BCC_CHOICE_COLORS'];

    foreach ($values as $value) {
        $colorKey = isset($choiceColorMap[$value]) ? $choiceColorMap[$value] : null;
        $hex = ($colorKey !== null && isset($palette[$colorKey])) ? $palette[$colorKey] : $palette['gray'];
        ?>
        <span class="choice-chip" style="background:<?php echo htmlspecialchars($hex, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php
    }
}

// grid.php'nin Sort/Filter/Group/Hide-fields panelleri, kendi form'u submit
// olduğunda DİĞER panellerin durumunu (o panelin kendi state'i HARİÇ — o zaten
// kendi adlı alanlarıyla submit edilir) gizli input olarak taşır. Dört panelde
// ayrı ayrı yazılan aynı "foreach + htmlspecialchars" deseni TEK yerde: $state,
// birden fazla *State dizisinin ('+' ile) birleştirilmiş hâli (name => value).
// Davranış değişmez — yalnızca tekrar eden kod tek fonksiyona taşınır.
function bcc_render_grid_state_hidden_inputs($state)
{
    foreach ($state as $key => $value) {
        ?>
        <input type="hidden" name="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
        <?php
    }
}

// "User" alan tipi için TEK kaynak: o takımın (KVKK — yalnızca o takım) aktif
// üyelerini id => full_name haritası olarak döndürür. Hem hücre görüntüleme
// (cell_display_text), hem hücre/filtre editörünün seçenek listesi (data-options /
// BCC_TEAM_MEMBERS), hem de kayıt doğrulama (normalize_cell_value — gönderilen
// user_id gerçekten bu takımın üyesi mi) AYNI haritayı kullanır; başka bir takımın
// üyesi asla listeye girmez.
function bcc_team_users_by_id($teamId)
{
    $rows = bcc_fetch_all(
        'SELECT u.id, u.full_name
         FROM team_members tm
         INNER JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = :team_id AND u.is_active = 1
         ORDER BY u.full_name',
        array('team_id' => $teamId)
    );

    $byId = array();
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row['full_name'];
    }

    return $byId;
}

// team_members.php'nin "Ekleyen" kolonu — YENİ bir invited_by kolonu YOK
// (DDL gerekmez): audit_log zaten 'team_member.assign'/'team_member.role_change'
// satırlarını atayan (al.user_id) + details JSON'unda hedef (target) user_id ile
// tutuyor (bkz. src/audit.php, team_members.php'nin assign action'ı). Kronolojik
// ASC sırayla ilerleyip her hedef için üzerine yazılarak en SON atama/rol
// değişikliği kaydı tutulur. Hiç kaydı olmayan üyeler (ör. ilk admin,
// scripts/create_admin.php ile oluşturuldu) haritada yer almaz — çağıran taraf
// bunu "—" göstererek ele alır.
function bcc_team_members_invited_by($teamId)
{
    $rows = bcc_fetch_all(
        "SELECT al.details, u.full_name AS actor_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.team_id = :team_id AND al.action IN ('team_member.assign', 'team_member.role_change')
         ORDER BY al.created_at ASC, al.id ASC",
        array('team_id' => $teamId)
    );

    $byTargetUserId = array();
    foreach ($rows as $row) {
        $details = $row['details'] !== null ? json_decode($row['details'], true) : null;
        if (!is_array($details) || !isset($details['user_id'])) {
            continue;
        }
        $byTargetUserId[(int) $details['user_id']] = $row['actor_name'];
    }

    return $byTargetUserId;
}

// ---------------------------------------------------------------------------
// Üye listesi + üye MUTASYONLARI — team_members.php ile "Paylaş" modalının
// (src/partials/share_modal.php + api/team_member_assign.php|remove.php)
// PAYLAŞTIĞI tek kaynak.
// ---------------------------------------------------------------------------
// Bu blok team_members.php'nin POST gövdesinden ÇIKARILDI (davranış birebir
// korunarak): hiyerarşi kapısı (rank(hedef) <= rank(ben)), kendini çıkaramama,
// son owner'ı çıkaramama ve audit action adları artık TEK yerde. Modal ayrı bir
// kopya yazsaydı, iki yolun kuralları ilk değişiklikte sessizce ayrışırdı — bu
// dosyadaki RBAC disiplininin (bkz. src/auth.php yetenek haritası) aynısı.
//
// YETKİ KAPISI BURADA DEĞİL, ÇAĞIRANDA: her iki çağıran da önce
// bcc_can_manage_members() ile 403 veriyor (team_members.php die, uçnoktalar
// json_fail). Buradaki kontroller onun ÜSTÜNE gelen hiyerarşi/bütünlük
// kurallarıdır.

// Bir ekibin TÜM üyeleri (pasif hesaplar DAHİL) — rol ve is_active ile.
// grid.php'nin "Paylaş" özeti ve modal aynı satırları kullanır: aktif olanlar
// "Katılımcılar", is_active = 0 olanlar "Bekleyen davetler" (hesap
// oluşturulmuş ama register.php/verify_email.php akışı tamamlanmamış).
function bcc_team_members_with_roles($teamId)
{
    return bcc_fetch_all(
        'SELECT u.id, u.full_name, u.email, u.is_active, tm.role, tm.created_at
         FROM team_members tm
         INNER JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = :team_id
         ORDER BY u.full_name',
        array('team_id' => $teamId)
    );
}

// Üye ekleme VE mevcut üyenin rolünü değiştirme (tek sorgu, INSERT ... ON
// DUPLICATE KEY UPDATE — admin/assign_team.php ile AYNI desen). Doğru audit
// action'ı seçebilmek için önce mevcut satıra bakılır.
// Dönüş: array('ok' => bool, 'error' => ?string, 'created' => bool)
function bcc_team_member_assign($teamId, $targetUserId, $role, $myRank, $assignableRoles)
{
    $teamId = (int) $teamId;
    $targetUserId = (int) $targetUserId;

    if ($targetUserId <= 0 || !in_array($role, $assignableRoles, true)) {
        return array('ok' => false, 'error' => 'Geçersiz seçim.', 'created' => false);
    }

    if (!bcc_fetch_one('SELECT id FROM users WHERE id = :id AND is_active = 1', array('id' => $targetUserId))) {
        return array('ok' => false, 'error' => 'Kullanıcı bulunamadı.', 'created' => false);
    }

    $existingMember = bcc_fetch_one(
        'SELECT id, role FROM team_members WHERE team_id = :team_id AND user_id = :user_id',
        array('team_id' => $teamId, 'user_id' => $targetUserId)
    );

    // Hiyerarşi kapısı: $assignableRoles yalnızca ATANACAK rolü sınırlar,
    // hedefin ŞU ANKİ rütbesini değil — benden yüksek rütbedeki bir üyeye
    // dokunulamaz (arayüzde salt-okunur gösterilen satırın POST/AJAX ile de
    // değiştirilememesi).
    if ($existingMember && $GLOBALS['BCC_ROLE_RANK'][$existingMember['role']] > $myRank) {
        return array('ok' => false, 'error' => 'Bu kullanıcıyı yönetme yetkiniz yok.', 'created' => false);
    }

    bcc_execute(
        'INSERT INTO team_members (team_id, user_id, role) VALUES (:team_id, :user_id, :role)
         ON DUPLICATE KEY UPDATE role = VALUES(role)',
        array('team_id' => $teamId, 'user_id' => $targetUserId, 'role' => $role)
    );

    $auditAction = $existingMember ? 'team_member.role_change' : 'team_member.assign';
    log_audit($auditAction, 'team_member', null, array('team_id' => $teamId, 'user_id' => $targetUserId, 'role' => $role), $teamId);

    return array('ok' => true, 'error' => null, 'created' => !$existingMember);
}

// Tek satır "Çıkar" ve toplu seçim AYNI koddan geçer (tek eleman = tek elemanlı
// liste). Atlanma nedenleri: kendisi, yetki dışı rütbe, son owner, üye değil.
// Dönüş: array('removed' => int[], 'skipped' => int)
function bcc_team_member_remove_many($teamId, $targetUserIds, $actorUserId, $myRank)
{
    $teamId = (int) $teamId;
    $actorUserId = (int) $actorUserId;
    $removedIds = array();
    $skipped = 0;

    foreach ($targetUserIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            $skipped++;
            continue;
        }

        if ($id === $actorUserId) {
            // Kasıtlı olarak KOŞULSUZ engellenir (son owner olup olmadığına
            // bakılmaksızın) — admin/index.php'deki "kendi hesabını pasif
            // yapamama" korumasıyla AYNI ilke.
            $skipped++;
            continue;
        }

        $targetMember = bcc_fetch_one(
            'SELECT role FROM team_members WHERE team_id = :team_id AND user_id = :user_id',
            array('team_id' => $teamId, 'user_id' => $id)
        );

        if (!$targetMember) {
            $skipped++;
            continue;
        }

        if ($GLOBALS['BCC_ROLE_RANK'][$targetMember['role']] > $myRank) {
            $skipped++;
            continue;
        }

        if ($targetMember['role'] === 'owner') {
            $ownerCount = (int) bcc_fetch_column(
                "SELECT COUNT(*) FROM team_members WHERE team_id = :team_id AND role = 'owner'",
                array('team_id' => $teamId)
            );
            if ($ownerCount <= 1) {
                $skipped++;
                continue;
            }
        }

        bcc_execute('DELETE FROM team_members WHERE team_id = :team_id AND user_id = :user_id', array('team_id' => $teamId, 'user_id' => $id));
        $removedIds[] = $id;
    }

    if (!empty($removedIds)) {
        // admin/index.php'nin remove_from_team action'ıyla AYNI audit action adı.
        log_audit('team_member.remove', 'team', $teamId, array('user_ids' => $removedIds), $teamId);
    }

    return array('removed' => $removedIds, 'skipped' => $skipped);
}

// Çıkarma sonucunun kullanıcıya gösterilecek metni — team_members.php'nin flash
// mesajı ile modalın durum satırı AYNI cümleleri kullansın diye burada.
// Dönüş: array('error' => ?string, 'success' => ?string)
function bcc_team_member_remove_message($result)
{
    $removedCount = count($result['removed']);

    if ($removedCount === 0) {
        return array('error' => 'Kimse çıkarılamadı (kendiniz, son owner veya yetkiniz dışındaki bir rütbe).', 'success' => null);
    }
    if ($result['skipped'] > 0) {
        return array('error' => null, 'success' => $removedCount . ' kişi çıkarıldı, ' . $result['skipped'] . ' kişi atlandı (kendiniz, son owner veya yetkiniz dışında).');
    }

    return array(
        'error' => null,
        'success' => $removedCount === 1 ? 'Ekipten çıkarıldı.' : $removedCount . ' kişi ekipten çıkarıldı.',
    );
}

// team_id, fields -> tables_meta -> bases üzerinden gelir; bir alanın hücre verisine
// erişen her sayfa/uçnokta bunu kullanmalı. Bulunamazsa null döner (404/die yapmaz) —
// çağıran taraf kendi hata davranışını (die ile HTML ya da JSON) seçer.
function bcc_find_field($fieldId)
{
    return bcc_fetch_one(
        'SELECT f.id, f.table_id, f.name, f.field_type, f.options, f.is_required, tm.base_id, b.team_id
         FROM fields f
         INNER JOIN tables_meta tm ON tm.id = f.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE f.id = :id LIMIT 1',
        array('id' => $fieldId)
    );
}

// bcc_find_field() ile AYNI amaç, bir kaydın (records.id) hangi tabloya/ekibe
// ait olduğunu tek sorguda getirir — comment_list/add/update/delete.php'nin
// KVKK (require_team_access/require_role) kontrolü bu zincire dayanır.
// Bulunamazsa false döner.
function bcc_find_record($recordId)
{
    return bcc_fetch_one(
        'SELECT r.id, r.table_id, tm.base_id, b.team_id
         FROM records r
         INNER JOIN tables_meta tm ON tm.id = r.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE r.id = :id LIMIT 1',
        array('id' => $recordId)
    );
}

// bcc_find_field() ile AYNI amaç, 'attachment' alan tipi için: bir ek dosyanın
// (attachments.id) hangi alana/tabloya/ekibe ait olduğunu tek sorguda getirir —
// attachment_upload/delete/download.php'nin KVKK (require_team_access/require_role)
// kontrolü bu zincire dayanır. Bulunamazsa false döner (bcc_fetch_one ile aynı).
function bcc_find_attachment($attachmentId)
{
    return bcc_fetch_one(
        'SELECT a.id, a.field_id, a.record_id, a.original_name, a.stored_name, a.mime_type, a.file_size,
                f.table_id, tm.base_id, b.team_id
         FROM attachments a
         INNER JOIN fields f ON f.id = a.field_id
         INNER JOIN tables_meta tm ON tm.id = f.table_id
         INNER JOIN bases b ON b.id = tm.base_id
         WHERE a.id = :id LIMIT 1',
        array('id' => $attachmentId)
    );
}

// Ek dosyaların saklandığı DİZİN — storage/attachments/, public/ DIŞINDA
// (bkz. attachment_download.php yorumu: tek erişim yolu KVKK kontrollü uç nokta).
//
// Bulunan gerçek bug: attachment_upload.php bu dizini
// `dirname(bcc_attachment_storage_path(''))` ile hesaplıyordu. O çağrı sonu
// "/" ile biten bir yol döndürdüğü için dirname() BİR SEVİYE FAZLA kırpıyor ve
// "storage/attachments" yerine "storage" veriyordu. storage/ genelde var
// olduğundan (storage/backups) `is_dir()` koruması geçiyor, mkdir HİÇ
// çalışmıyor ve move_uploaded_file() olmayan dizine yazamayıp
// "Dosya kaydedilemedi." (500) ile düşüyordu. Hata yalnızca
// storage/attachments/ silinmişken ortaya çıkıyordu — dizin durduğu sürece
// yıllarca sessiz kaldı. Dizin adı artık TEK YERDE, sondaki "/" olmadan.
function bcc_attachment_storage_dir()
{
    return __DIR__ . '/../storage/attachments';
}

// Dizinin var olduğundan emin olur ve yolunu döndürür. Yükleme akışı bunu
// çağırır: klasör elle silinse bile ilk yüklemede kendini onarır.
function bcc_attachment_storage_dir_ensured()
{
    $dir = bcc_attachment_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

// Tek bir ek dosyanın diskteki tam yolu.
function bcc_attachment_storage_path($storedName)
{
    return bcc_attachment_storage_dir() . '/' . $storedName;
}

// Bir kayıt/alan SİLİNMEDEN ÖNCE çağrılmalı: attachments satırları ON DELETE
// CASCADE ile otomatik silinir ama diskteki fiziksel dosyalar silinmez — bu
// yüzden stored_name'ler DB satırı hâlâ varken (silme sorgusundan ÖNCE) okunup
// diskten temizlenir. DB satırının kendisini SİLMEZ (cascade zaten yapıyor).
function bcc_delete_attachment_files_by_record($recordId)
{
    $rows = bcc_fetch_all('SELECT stored_name FROM attachments WHERE record_id = :id', array('id' => $recordId));
    foreach ($rows as $row) {
        $path = bcc_attachment_storage_path($row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function bcc_delete_attachment_files_by_field($fieldId)
{
    $rows = bcc_fetch_all('SELECT stored_name FROM attachments WHERE field_id = :id', array('id' => $fieldId));
    foreach ($rows as $row) {
        $path = bcc_attachment_storage_path($row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

// "Verileri temizle" (Clear data) için: bir tablonun TÜM kayıtlarındaki dosya
// eklerini tek sorguda toplayıp siler — bcc_delete_attachment_files_by_record()'ı
// kayıt sayısı kadar çağırmak yerine (records join attachments) tek seferde.
function bcc_delete_attachment_files_by_table($tableId)
{
    $rows = bcc_fetch_all(
        'SELECT a.stored_name FROM attachments a INNER JOIN records r ON r.id = a.record_id WHERE r.table_id = :id',
        array('id' => $tableId)
    );
    foreach ($rows as $row) {
        $path = bcc_attachment_storage_path($row['stored_name']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

// Dosya adı/boyutu kısaltılmış küçük rozet metni (mime türüne göre) — grid,
// satır genişletme paneli VE interface.php (Duyuru) AYNI fonksiyonu paylaşır.
// Resimler zaten <img> ile küçük resim olarak gösterildiği için buraya hiç
// düşmez (bkz. bcc_render_grid_data_row) — yalnızca doküman tipleri için.
function bcc_attachment_type_badge($mimeType)
{
    $map = array(
        'application/pdf' => 'PDF',
        'application/msword' => 'DOC',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOC',
        'application/vnd.ms-excel' => 'XLS',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLS',
        'application/vnd.ms-powerpoint' => 'PPT',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PPT',
    );

    return isset($map[$mimeType]) ? $map[$mimeType] : 'DOSYA';
}

// Bir cell_values satırından (veya kayıt yoksa null'dan), o hücrenin edit alanına
// dolduracağımız "ham" değeri çıkarır (input/select doldurmak için).
function cell_raw_value($fieldType, $cellRow)
{
    if ($cellRow === null) {
        return $fieldType === 'multiple_select' ? '[]' : '';
    }

    switch ($fieldType) {
        case 'single_line_text':
        case 'long_text':
        case 'single_select':
        // url/email/phone (Grup A): ham değer kullanıcının yazdığının AYNISI —
        // linkleştirme yalnızca görüntülemede olur, edit kutusuna hep ham metin
        // döner (percent'in ×100 gibi bir dönüşümü YOK).
        case 'url':
        case 'email':
        case 'phone':
            return (string) $cellRow['value_text'];
        case 'number':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';
        case 'checkbox':
            return ((int) $cellRow['value_number'] === 1) ? '1' : '0';
        case 'date':
            return $cellRow['value_date'] !== null ? substr($cellRow['value_date'], 0, 10) : '';
        case 'multiple_select':
            return $cellRow['value_json'] !== null ? $cellRow['value_json'] : '[]';
        case 'time':
            return (string) $cellRow['value_text'];
        case 'user':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';
        // currency: number ile AYNI — DB'deki ham sayı, edit kutusu bunu
        // doğrudan gösterir (sembol/ondalık formatı SADECE cell_display_text()'te).
        case 'currency':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';
        // autonumber: her zaman tam sayı (value_number DECIMAL(20,6) olduğu için
        // (int) cast şart — aksi halde "3.000000" görünürdü). Salt-okunur olduğu
        // için bu "raw" bir edit kutusuna DEĞİL, yalnızca data-value'ya gider.
        case 'autonumber':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';
        // percent: normalize_cell_value()'un TERSİ — DB'de 0.45 duruyor ama edit
        // kutusu kullanıcının yazdığı "45"i göstermeli, ×100 burada yapılır.
        case 'percent':
            return $cellRow['value_number'] !== null ? (string) ((float) $cellRow['value_number'] * 100) : '';
        // rating: düz tam sayı — yıldız widget'ının başlangıç durumunu (kaç
        // yıldız dolu) belirlemek için JS bunu okuyup int'e çevirir.
        case 'rating':
            return $cellRow['value_number'] !== null ? (string) (int) round((float) $cellRow['value_number']) : '';
        // created_time/created_by/last_modified_time/last_modified_by:
        // bcc_cell_row_for_field() bu $cellRow'u records'tan taklit ediyor
        // (last_modified_by'ın "hiç düzenlenmemişse created_by'a düş" kuralı DAHİL,
        // bkz. o fonksiyonun yorumu — burada AYRI bir dal gerekmez, $cellRow
        // zaten doğru değerle gelir) — "raw" hiç kısaltılmaz (date'in aksine,
        // bu alanlar asla <input> doldurmak için kullanılmıyor).
        case 'created_time':
        case 'last_modified_time':
            return $cellRow['value_date'] !== null ? (string) $cellRow['value_date'] : '';
        case 'created_by':
        case 'last_modified_by':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';
        default:
            return '';
    }
}

// Grid hücresinde salt-okunur görüntülenecek metni üretir (htmlspecialchars çağıran taraf yapar).
// $usersById: bcc_team_users_by_id() ile hazırlanmış id => full_name haritası —
// yalnızca 'user'/'created_by'/'last_modified_by' tipleri için kullanılır.
// $options: fields.options JSON'unun ÇÖZÜLMÜŞ (json_decode edilmiş) hâli — SADECE
// currency/percent/rating için (sembol/ondalık basamak/max yıldız), diğer TÜM
// tipler bu parametreyi görmezden gelir. Grup C1'de eklendi (Grup B1/B2'de HİÇ
// gerekmemişti) — backward-compatible: 9 çağrı yerinden yalnızca $options'ı
// gerçekten geçirenler currency/percent/rating'i doğru formatlar, geçirmeyenler
// (varsa) o üç tip için formatsız/boş dönebilir ama mevcut number/date/vb.
// case'lerin DAVRANIŞI hiç değişmedi.
function cell_display_text($fieldType, $cellRow, $usersById = array(), $options = null)
{
    if ($cellRow === null) {
        return '';
    }

    // Çağıranlar $field['options']'ı (fields.options — ham JSON string veya
    // NULL) OLDUĞU GİBİ geçirebilir — select_choices_from_options()'ın AYNI
    // deseni, ikinci bir "önce sen decode et" yükü çağırana binmez.
    if (is_string($options)) {
        $decodedOptions = json_decode($options, true);
        $options = is_array($decodedOptions) ? $decodedOptions : null;
    }

    switch ($fieldType) {
        case 'single_line_text':
        case 'long_text':
        case 'single_select':
        // url/email/phone (Grup A): DÜZ METİN döner — HTML/link DEĞİL. Bu
        // BİLEREK böyle: bu fonksiyonun çıktısı Excel'e (view_export_xlsx.php),
        // Slack mesajına (src/slack.php), grup başlığına (grid.php) ve çöp
        // kutusu listesine (trash_records_list.php) de gidiyor — oralara HTML
        // sızmamalı. Linkleştirme SADECE gerçekten HTML üreten iki noktada
        // yapılır: bcc_render_linkified_cell() (grid <td>) ve cell_update.php'nin
        // display_link yanıtı. Bu yüzden imza da GENİŞLEMEDİ, 9 çağrı yerinin
        // hiçbirine dokunulmadı.
        case 'url':
        case 'email':
        case 'phone':
            return (string) $cellRow['value_text'];
        case 'number':
            return $cellRow['value_number'] !== null ? (string) (float) $cellRow['value_number'] : '';
        // autonumber: biçimlendirmesiz tam sayı — binlik ayırıcı BİLEREK YOK
        // (bu bir MİKTAR değil, bir KİMLİK; "1.024" değil "1024" okunmalı).
        case 'autonumber':
            return $cellRow['value_number'] !== null ? (string) (int) $cellRow['value_number'] : '';
        // currency: sembol + Türkçe sayı formatı (binlik nokta, ondalık virgül —
        // projenin geri kalanıyla AYNI Türkçe yerelleştirme). $options yoksa
        // (eski/formatsız çağrı) makul varsayılanlara düşer, hata VERMEZ.
        case 'currency':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $curOpts = is_array($options) ? $options : array();
            $symbol = isset($curOpts['currency_symbol']) && $curOpts['currency_symbol'] !== '' ? $curOpts['currency_symbol'] : '₺';
            $decimals = isset($curOpts['decimal_places']) ? (int) $curOpts['decimal_places'] : 2;
            return $symbol . number_format((float) $cellRow['value_number'], $decimals, ',', '.');
        // percent: normalize_cell_value()'un TERSİ — DB'deki ondalık (0.45) ×100
        // yapılıp "%" ile gösterilir (OpsFlow davranışı, araştırılıp netleştirilen karar).
        case 'percent':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $pctOpts = is_array($options) ? $options : array();
            $decimals = isset($pctOpts['decimal_places']) ? (int) $pctOpts['decimal_places'] : 0;
            // "%45" — Türkçe yazımda işaret sayının ÖNÜNE gelir (İngilizce "45%"
            // değil); bu case'in geri kalanı (binlik nokta, ondalık virgül) zaten
            // Türkçe yerelleştirme, işaretin yeri de ona uyuyor.
            return '%' . number_format((float) $cellRow['value_number'] * 100, $decimals, ',', '.');
        // rating: dolu/boş yıldız karakterleriyle salt-okunur gösterim (tıklanabilir
        // widget AYRI — grid.js/grid-row-detail.js, bu yalnızca METİN üretir; ör.
        // grup başlığı/Excel export/Slack gibi salt-metin bağlamlar için).
        case 'rating':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $ratingOpts = is_array($options) ? $options : array();
            $maxRating = isset($ratingOpts['max_rating']) ? (int) $ratingOpts['max_rating'] : 5;
            $val = max(0, min($maxRating, (int) round((float) $cellRow['value_number'])));
            return str_repeat('★', $val) . str_repeat('☆', max(0, $maxRating - $val));
        case 'checkbox':
            // grid.php'nin bcc_build_grouped_tree()'sindeki AYNI etiketler —
            // eskiden bu case burada yoktu (bulunan gerçek bug), grid.php grup
            // başlıkları için yerel bir workaround yazmıştı ama slack.php/
            // interface.php/view_export_xlsx.php gibi bu fonksiyonu DOĞRUDAN
            // çağıran diğer yerlerde checkbox hücreleri sessizce boş görünüyordu
            // (ör. Excel export'ta checkbox verisi kayboluyordu).
            return ((int) $cellRow['value_number'] === 1) ? 'İşaretli' : 'İşaretsiz';
        case 'date':
            return $cellRow['value_date'] !== null ? date('d.m.Y', strtotime($cellRow['value_date'])) : '';
        case 'multiple_select':
            $choices = $cellRow['value_json'] !== null ? json_decode($cellRow['value_json'], true) : array();
            return is_array($choices) ? implode(', ', $choices) : '';
        case 'time':
            return (string) $cellRow['value_text'];
        case 'user':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $userId = (int) $cellRow['value_number'];
            return isset($usersById[$userId]) ? $usersById[$userId] : '';
        // created_time/last_modified_time: tam tarih+saat (madde 5) — 'date'
        // case'i gibi sadece güne kısaltmaz. created_by/last_modified_by: 'user'
        // ile BİREBİR AYNI id→ad çözümü (AYNI $usersById haritası, ikinci bir
        // kaynak İCAT EDİLMEDİ) — last_modified_by'ın "hiç düzenlenmemişse
        // created_by'a düş" kuralı bcc_cell_row_for_field()'de UYGULANMIŞ olarak
        // gelir, burada ayrıca ele alınmaz.
        case 'created_time':
        case 'last_modified_time':
            return $cellRow['value_date'] !== null ? date('d.m.Y H:i', strtotime($cellRow['value_date'])) : '';
        case 'created_by':
        case 'last_modified_by':
            if ($cellRow['value_number'] === null) {
                return '';
            }
            $userId = (int) $cellRow['value_number'];
            return isset($usersById[$userId]) ? $usersById[$userId] : '';
        default:
            return '';
    }
}

// fields.options'a değil, bcc_team_users_by_id() id => full_name haritasına
// dayanır — hücre editörünün (grid.js buildInput) ve filtre panelinin 'user'
// alanları için data-options / BCC_TEAM_MEMBERS ile paylaştığı TEK şekil:
// [{"id": .., "name": ..}, ...], id sırası korunur (ad sırasına göre gelir).
function bcc_user_choices_from_map($usersById)
{
    $choices = array();
    foreach ($usersById as $id => $name) {
        $choices[] = array('id' => $id, 'name' => $name);
    }

    return $choices;
}

// Bir kayıt satırını (hücreler + varsa "Sil" formu) basar. Gruplu ve düz (grupsuz)
// tbody render'ı arasında paylaşılır; $groupPath verilirse satıra
// data-group-path eklenir (grid-group.js aç/kapa bunu prefix eşleşmesiyle kullanır).
// grid.php'nin ilk sayfa render'ı VE public/api/record_add.php (AJAX ile eklenen
// tek bir satırın HTML'ini üretmek için) aynı fonksiyonu paylaşır — iki yerde
// ayrı ayrı yazılmaz. $usersById: bcc_team_users_by_id() (yalnızca 'user' tipi
// hücreler için isim çözümü ve seçenek listesi — opsiyonel, boş dizi varsayılan).
// Satır genişletme paneli (grid-row-detail.js) TÜM alanları gösterir, sadece
// görünür sütunları değil — bu yüzden her satıra $allFields'in tamamı
// data-fields JSON'u olarak gömülür (id/tip/options/raw). Görünür alanlar
// zaten kendi <td>'sinde aynı veriyi taşıyor (data-value/data-options); panel
// önce canlı <td>'yi arar, yoksa (gizli alan) bu JSON'a düşer — ikinci bir
// AJAX/sorgu YOK. $allFields verilmezse (eski çağıranlarla uyumluluk)
// $visibleFields'e düşer.
// created_time/created_by: cell_values'ta GERÇEKTEN bir satırları yok (değer
// records.created_at/created_by'dan geliyor) — bu iki tip için $cellsByRecord'a
// hiç bakılmaz, bcc_group_cell_row() (zaten var, group başlığı render'ının da
// kullandığı AYNI yardımcı) ile $record'dan bir $cellRow "taklit edilir". Diğer
// tüm tipler değişmeden $cellsByRecord'dan okumaya devam eder. bcc_render_grid_row_fields_json()
// VE bcc_render_grid_data_row() İKİSİ DE bunu çağırır, kopya dal yazılmadı.
// "Last modified time/by" (Grup B2): records.updated_at zaten ON UPDATE
// CURRENT_TIMESTAMP ama YALNIZCA bu fonksiyon çağrılan satırlar için (elle,
// bilinçli) tetiklenir — log_audit() ile AYNI imza felsefesi, current_user()
// KENDİSİ çağrılır, çağıran taraf user id geçirmez. Yalnızca "içerik
// değişikliği" sayılan yazma noktalarından (cell_update.php, attachment_upload.php,
// attachment_delete.php) çağrılır — record_add.php/record_duplicate.php'nin
// pozisyon kaydırma UPDATE'i, record_soft_delete.php/record_restore.php'nin
// deleted_at UPDATE'i BİLEREK çağırmaz (bkz. docs/PROJE-DURUM.md Grup B2 tasarım
// notu — "içerik değişikliği" vs. "satır yaşam döngüsü/idari işlem" ayrımı).
function bcc_touch_record_modified($recordId)
{
    $user = current_user();
    bcc_execute(
        'UPDATE records SET updated_at = NOW(), updated_by = :uid WHERE id = :id',
        array(':uid' => $user ? $user['id'] : null, ':id' => $recordId)
    );
}

// Autonumber (Grup C2, migrations/014) — $tableId'deki TÜM autonumber alanları
// için birer numara ayırır ve $recordId'ye yazar. bcc_touch_record_modified()
// ile AYNI felsefe: TEK ortak fonksiyon, BEŞ çağrı yeri (record_add.php,
// record_duplicate.php, table_import_xlsx.php, grid.php'nin create_record
// aksiyonu ve — Grup View-Form ile eklenen BEŞİNCİSİ — api/form_submit.php),
// ikinci bir kopya YOK.
//
// Bir tabloda BİRDEN FAZLA autonumber alanı olabilir ve her birinin sayacı
// BAĞIMSIZDIR (fields.autonumber_next, alan başına) — bu yüzden döngü.
//
// ATOMİKLİK — neden LAST_INSERT_ID(expr) idiomu:
//   UPDATE fields SET autonumber_next = LAST_INSERT_ID(autonumber_next) + 1
// Sağ taraf ESKİ değerle hesaplanır: LAST_INSERT_ID(eski) o değeri OTURUMA
// yazıp geri döndürür, kolona eski+1 kaydedilir. Yani tek ifadede hem "bana
// verilecek numarayı ayır" hem "sayacı ilerlet".
//   * "UPDATE x = x+1, sonra ayrı SELECT x" transaction İÇİNDE güvenlidir
//     (InnoDB satıra X-lock koyar) ama transaction DIŞINDA yarışır — iki nokta,
//     birinin unutulması sessiz bug.
//   * "SELECT MAX(value_number)+1" KIRIK: kayıt silinince numara geri sarar ve
//     iki eşzamanlı okuma aynı MAX'ı görür.
//   * LAST_INSERT_ID(expr) BAĞLANTIYA ÖZELDİR — değer oturum değişkeninde durur,
//     başka bir bağlantı onu göremez/ezemez. İzolasyon seviyesinden ve
//     transaction'ın varlığından BAĞIMSIZ doğru. MySQL/MariaDB'nin kendi
//     belgelenmiş "sequence emülasyonu" idiomu.
//
// ⚠️ ÇAĞRI SIRASI (footgun): bu fonksiyon LAST_INSERT_ID(expr) kullandığı için
// OTURUMUN last-insert-id'sini EZER. bcc_last_insert_id() (config/database.php,
// mysqli->insert_id okur) bu fonksiyondan SONRA çağrılırsa yeni kaydın id'sini
// DEĞİL, son ayrılan autonumber'ı döndürür. Çağıran taraf $recordId'yi
// bcc_last_insert_id() ile ALMIŞ OLMALI. Beş çağrı yerinin BEŞİNDE de
// bcc_last_insert_id() INSERT'in HEMEN ardından çağrılıyor — araya kod sokan
// biri bu sırayı bozmasın diye burada açıkça yazılı.
//
// ⚠️ TRANSACTION: çağıran taraf bcc_begin_transaction() açmış OLMALI. Sayaç
// UPDATE'i ile cell_values INSERT'i ayrı commit edilirse araya düşen bir hata
// numarayı "yakar" (sayaç ilerlemiş ama kayıtta numara yok). İlk dört çağrı
// yerinin üçünde transaction zaten vardı; grid.php'nin create_record dalına
// Grup C2 turunda EKLENDİ (öncesinde hiç transaction'ı yoktu). Beşinci çağrı
// yeri (form_submit.php) kendi transaction'ıyla yazıldı.
function bcc_assign_autonumbers($tableId, $recordId)
{
    $autoFields = bcc_fetch_all(
        "SELECT id FROM fields WHERE table_id = :tid AND field_type = 'autonumber'",
        array(':tid' => $tableId)
    );

    foreach ($autoFields as $af) {
        $fieldId = (int) $af['id'];

        bcc_execute(
            'UPDATE fields SET autonumber_next = LAST_INSERT_ID(autonumber_next) + 1 WHERE id = :fid',
            array(':fid' => $fieldId)
        );
        $number = (int) bcc_fetch_column('SELECT LAST_INSERT_ID()');

        bcc_execute(
            'INSERT INTO cell_values (record_id, field_id, value_number) VALUES (:rid, :fid, :val)',
            array(':rid' => $recordId, ':fid' => $fieldId, ':val' => $number)
        );
    }
}

// Autonumber backfill — bir autonumber alanı, İÇİNDE ZATEN KAYIT OLAN bir
// tabloya eklendiğinde mevcut kayıtları 1'den başlayarak numaralar ve sayacı
// oradan devam ettirir (OpsFlow davranışı). İKİ yerden çağrılır:
//   (1) bcc_create_field() — yeni bir autonumber alanı oluşturulurken,
//   (2) table_fields.php update_field — mevcut bir alanın TİPİ autonumber'a
//       çevrilirken (bu form tip değiştirmeye izin veriyor; atlanırsa alan var
//       ama tüm kayıtlar boş görünürdü).
//
// SIRALAMA: position ASC, id ASC — kullanıcı alanı eklediği anda GRİDDE GÖRDÜĞÜ
// sıra. (Alternatif 'id ASC' = oluşturma sırası; position karıştırılmışsa ikisi
// ayrışır. Tek seferlik ve kozmetik bir fark, "gördüğüm sıra" daha az şaşırtıcı
// bulundu. Bundan SONRAKİ kayıtlar her hâlükârda oluşturma sırasına göre numara alır.)
//
// SİLİNMİŞ KAYITLAR DA NUMARALANIR — 'deleted_at IS NULL' filtresi BİLEREK YOK.
// Aksi hâlde çöp kutusundan geri yüklenen bir kayıt boş autonumber'la geri gelir
// ve bir daha ASLA numara alamaz (numara yalnızca oluşturma anında veriliyor).
//
// ROW_NUMBER() (MariaDB 10.2+, bu kurulum 10.4.32) kullanılıyor; "SET @n := @n+1"
// kullanıcı değişkeni hilesi BİLEREK seçilmedi — INSERT...SELECT içinde ORDER BY
// ile birlikte değişken değerlendirme sırası optimizer'a bağlıdır ve MySQL/MariaDB
// belgelerinde açıkça "güvenilmez" olarak işaretlidir.
//
// YALNIZCA NUMARASI OLMAYAN KAYITLAR doldurulur ve numaralama 1'den DEĞİL,
// alanın MEVCUT sayacından (autonumber_next) başlar. İki gerçek nedenle:
//   (1) cell_values'ta UNIQUE KEY (record_id, field_id) VAR — mevcut bir alanın
//       tipi autonumber'a çevrildiğinde o alanın hücreleri ZATEN VARDIR; koşulsuz
//       bir INSERT "Duplicate entry" ile patlardı. (Satır var ama value_number
//       NULL ise — ör. eski tip metindi — ON DUPLICATE KEY UPDATE ile doldurulur.)
//   (2) Tasarım kararı: autonumber -> number -> autonumber çevrimlerinde ESKİ
//       NUMARALAR KORUNUR ve sayaç GERİ SARMAZ. Koşulsuz bir backfill her
//       çevrimde her şeyi yeniden numaralar ve sayacı sıfırlardı.
// Sonuç: taze alan (sayaç 1) 1'den başlar; geri çevrilen alan hiçbir şeyi
// değiştirmez; arada eklenmiş numarasız kayıtlar sayacın kaldığı yerden alır.
//
// Sayaç güncellemesi GREATEST(...) ile MONOTON: asla azalmaz ve kullanılmış bir
// numaranın üstüne düşmez (kendi kendini onaran savunma — hücreler elle
// kurcalanmış olsa bile sonraki kayıt çakışan numara ALMAZ).
//
// ⚠️ TRANSACTION: çağıran taraf açmış OLMALI — alan INSERT'i, hücre backfill'i ve
// sayaç UPDATE'i ayrı commit edilirse "alan var ama numara yok" ya da "numaralar
// var ama sayaç 1'de kalmış (sonraki kayıt ÇAKIŞAN numara alır)" durumu kalır.
function bcc_backfill_autonumber_field($fieldId, $tableId)
{
    $start = (int) bcc_fetch_column(
        'SELECT autonumber_next FROM fields WHERE id = :fid',
        array(':fid' => $fieldId)
    );

    bcc_execute(
        'INSERT INTO cell_values (record_id, field_id, value_number)
         SELECT r.id, :fid, :start + ROW_NUMBER() OVER (ORDER BY r.position, r.id) - 1
         FROM records r
         LEFT JOIN cell_values cv ON cv.record_id = r.id AND cv.field_id = :fid2
         WHERE r.table_id = :tid AND cv.value_number IS NULL
         ON DUPLICATE KEY UPDATE value_number = VALUES(value_number)',
        array(':fid' => $fieldId, ':start' => $start, ':fid2' => $fieldId, ':tid' => $tableId)
    );

    bcc_execute(
        'UPDATE fields f
         SET f.autonumber_next = GREATEST(
             f.autonumber_next,
             COALESCE((SELECT MAX(cv.value_number) FROM cell_values cv WHERE cv.field_id = f.id), 0) + 1
         )
         WHERE f.id = :fid',
        array(':fid' => $fieldId)
    );
}

function bcc_cell_row_for_field($fieldType, $record, $cellsByRecord, $fieldId)
{
    if ($fieldType === 'created_time') {
        return bcc_group_cell_row('value_date', $record['created_at']);
    }
    if ($fieldType === 'created_by') {
        return bcc_group_cell_row('value_number', $record['created_by']);
    }
    if ($fieldType === 'last_modified_time') {
        // Kayıt hiç düzenlenmemişse (updated_by NULL — bcc_touch_record_modified()
        // hiç çağrılmamış) updated_at yine de created_at'e EŞİT (ON UPDATE
        // CURRENT_TIMESTAMP satır INSERT edilirken de bir kez tetiklenir, MySQL'in
        // kendi davranışı) — ayrı bir fallback YAZILMADI, records.updated_at
        // zaten doğru değeri taşıyor.
        return bcc_group_cell_row('value_date', $record['updated_at']);
    }
    if ($fieldType === 'last_modified_by') {
        // OpsFlow davranışı: hiç düzenlenmemiş bir kayıtta "Son değiştiren"
        // OLUŞTURAN kişiyi gösterir ("son değişiklik" == "oluşturma"). Yalnızca
        // GRİD/DETAY RENDER'ı için (bkz. filter_condition_sql — filtre/sıralama
        // HAM updated_by üzerinde çalışmaya devam eder, bu fallback'i görmez).
        $value = $record['updated_by'] !== null ? $record['updated_by'] : $record['created_by'];
        return bcc_group_cell_row('value_number', $value);
    }

    return isset($cellsByRecord[$record['id']][$fieldId]) ? $cellsByRecord[$record['id']][$fieldId] : null;
}

function bcc_render_grid_row_fields_json($allFields, $record, $cellsByRecord, $usersById, $attachmentsByRecord = array())
{
    $out = array();
    foreach ($allFields as $f) {
        $cellRow = bcc_cell_row_for_field($f['field_type'], $record, $cellsByRecord, $f['id']);
        $rawValue = cell_raw_value($f['field_type'], $cellRow);

        if (is_select_field_type($f['field_type'])) {
            $choices = select_choices_from_options($f['options']);
        } elseif ($f['field_type'] === 'user') {
            $choices = bcc_user_choices_from_map($usersById);
        } elseif ($f['field_type'] === 'rating') {
            // Detay panelindeki yıldız widget'ı (grid-row-detail.js) max_rating'i
            // buradan okur — select'in choices listesinden FARKLI bir şekil
            // ({"max_rating": N}, dizi değil) ama AYNI 'options' anahtarını kullanır,
            // JS tarafı field_type'a göre yorumlar.
            $ratingJsonOpts = is_string($f['options']) ? json_decode($f['options'], true) : null;
            $choices = array('max_rating' => (is_array($ratingJsonOpts) && isset($ratingJsonOpts['max_rating'])) ? (int) $ratingJsonOpts['max_rating'] : 5);
        } else {
            $choices = array();
        }

        // 'attachment': "raw" tek bir değer değil (birden fazla dosya olabilir) —
        // panel (grid-row-detail.js) bu alanı 'files' üzerinden, ayrı bir
        // yükle/sil arayüzüyle yönetir, buildInput()'a hiç girmez.
        $files = ($f['field_type'] === 'attachment' && isset($attachmentsByRecord[$record['id']][$f['id']]))
            ? $attachmentsByRecord[$record['id']][$f['id']]
            : null;

        $out[] = array(
            'id' => (int) $f['id'],
            'name' => $f['name'],
            'field_type' => $f['field_type'],
            'options' => $choices ? $choices : null,
            'raw' => $rawValue,
            'files' => $files,
        );
    }

    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

function bcc_render_grid_data_row($record, $rowNum, $visibleFields, $cellsByRecord, $canEdit, $tableId, $stateQueryString, $groupPath = null, $usersById = array(), $allFields = null, $attachmentsByRecord = array())
{
    if ($allFields === null) {
        $allFields = $visibleFields;
    }
    ?>
    <tr
        data-record-id="<?php echo (int) $record['id']; ?>"
        <?php echo $groupPath !== null ? 'data-group-path="' . htmlspecialchars($groupPath, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
        data-fields="<?php echo htmlspecialchars(bcc_render_grid_row_fields_json($allFields, $record, $cellsByRecord, $usersById, $attachmentsByRecord), ENT_QUOTES, 'UTF-8'); ?>"
    >
        <td class="grid-rownum">
            <!-- .grid-rownum-inner: flex düzeni (numara/checkbox/genişlet butonunu
                 yan yana dizip dikey ortalamak) BİLEREK <td>'nin kendisinde DEĞİL,
                 bu iç sarmalayıcıda — bkz. style.css'teki "Bulunan gerçek bug" notu:
                 <td>'ye doğrudan display:flex vermek onu tablonun "hücreleri satırın
                 en uzun içeriğine göre otomatik eşitle" mekanizmasının DIŞINA
                 çıkarıyordu. -->
            <div class="grid-rownum-inner">
                <span class="grid-rownum-number"><?php echo (int) $rowNum; ?></span>
                <?php if ($canEdit): ?>
                    <input type="checkbox" class="grid-row-select" aria-label="Satırı seç">
                <?php endif; ?>
                <!-- Genişlet paneli TÜM rollere açık (OpsFlow: kayıt görüntüleme herkese
                     açık) — düzenleme/yorum yetkisi panel İÇİNDE BCC_CAN_EDIT/BCC_CAN_COMMENT
                     ile ayrıca kısıtlanır, bkz. grid-row-detail.js. -->
                <button type="button" class="grid-row-expand" aria-label="Genişlet" title="Genişlet">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M4.5 1.5h-3v3M7.5 10.5h3v-3M1.5 4.5V1.5h3M10.5 7.5v3h-3" stroke="#5f6368" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </td>
        <?php foreach ($visibleFields as $f):
            $cellRow = bcc_cell_row_for_field($f['field_type'], $record, $cellsByRecord, $f['id']);
            $rawValue = cell_raw_value($f['field_type'], $cellRow);
            $displayText = cell_display_text($f['field_type'], $cellRow, $usersById, $f['options']);
            $isSelectType = is_select_field_type($f['field_type']);
            // created_time/created_by: OpsFlow'daki gibi kullanıcı tarafından
            // asla düzenlenemez — grid.js'nin tıkla-düzenle mantığı YALNIZCA
            // 'editable' class'ına bakıyor (bkz. grid.js td.editable), bu class
            // hiç eklenmezse ikinci bir JS kontrolüne gerek kalmaz.
            // Liste artık BCC_READONLY_FIELD_TYPES'ta (yukarıda) — form görünümü
            // de AYNI listeyi okuduğu için satır içi literal tek kaynağa taşındı.
            // Davranış birebir aynı: aynı beş tip.
            $isReadOnlyFieldType = in_array($f['field_type'], $GLOBALS['BCC_READONLY_FIELD_TYPES'], true);
            if ($isSelectType) {
                $choices = select_choices_from_options($f['options']);
            } elseif ($f['field_type'] === 'user') {
                $choices = bcc_user_choices_from_map($usersById);
            } elseif ($f['field_type'] === 'rating') {
                // data-options="{"max_rating":N}" — select/user ile AYNI mekanizma,
                // detay panelinin (grid-row-detail.js) liveTd varsa buradan, yoksa
                // data-fields JSON'undan (bcc_render_grid_row_fields_json, AYNI şekil) okuması için.
                $ratingJsonOptsForTd = is_string($f['options']) ? json_decode($f['options'], true) : null;
                $choices = array('max_rating' => (is_array($ratingJsonOptsForTd) && isset($ratingJsonOptsForTd['max_rating'])) ? (int) $ratingJsonOptsForTd['max_rating'] : 5);
            } else {
                $choices = array();
            }
            // Color: her seçeneğin renk KEY'i, alanın TÜM seçenek listesi üzerinden
            // (indeks tabanlı geri dönüş doğru çalışsın diye) önceden hesaplanır.
            $choiceColorMap = $isSelectType
                ? bcc_build_choice_color_map($choices, select_choice_colors_from_options($f['options']))
                : array();
            // 'attachment': değer cell_values'ta değil, attachmentsByRecord'da
            // (bkz. bcc_fetch_attachments_by_record) — data-value/data-options
            // burada anlamsız, kendi data-attachments JSON'unu taşır.
            $isAttachmentType = ($f['field_type'] === 'attachment');
            $attachmentFiles = $isAttachmentType && isset($attachmentsByRecord[$record['id']][$f['id']])
                ? $attachmentsByRecord[$record['id']][$f['id']]
                : array();
        ?>
            <td
                class="grid-cell <?php echo ($canEdit && !$isReadOnlyFieldType) ? 'editable' : ''; ?>"
                data-field-id="<?php echo (int) $f['id']; ?>"
                data-field-type="<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"
                data-value="<?php echo htmlspecialchars($rawValue, ENT_QUOTES, 'UTF-8'); ?>"
                <?php if ($choices): ?>data-options="<?php echo htmlspecialchars(json_encode($choices, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                <?php if ($isAttachmentType): ?>data-attachments="<?php echo htmlspecialchars(json_encode($attachmentFiles, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
            >
                <?php if ($f['field_type'] === 'checkbox'): ?>
                    <input type="checkbox" class="cell-checkbox" <?php echo $rawValue === '1' ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
                <?php elseif ($f['field_type'] === 'single_select'): ?>
                    <div class="cell-view"><?php bcc_render_choice_chips($displayText !== '' ? array($displayText) : array(), $choiceColorMap); ?></div>
                <?php elseif ($f['field_type'] === 'multiple_select'): ?>
                    <?php
                        $selectedValues = array();
                        if ($cellRow !== null && $cellRow['value_json'] !== null) {
                            $decodedSelected = json_decode($cellRow['value_json'], true);
                            $selectedValues = is_array($decodedSelected) ? $decodedSelected : array();
                        }
                    ?>
                    <div class="cell-view"><?php bcc_render_choice_chips($selectedValues, $choiceColorMap); ?></div>
                <?php elseif ($f['field_type'] === 'long_text'): ?>
                    <?php /* GÜVENLİ: $displayText burada YAZMA anında bcc_sanitize_rich_text()
                       ile temizlenmiş HTML — htmlspecialchars UYGULANMAZ (uygulansaydı <b>
                       literal &lt;b&gt; olarak görünürdü). Tek istisna, bkz. bcc_sanitize_rich_text(). */ ?>
                    <?php /* bcc_rich_text_grid_html: <br> -> boşluk. Hücre TEK SATIR
                       gösteriyor (bkz. style.css row-h-short kuralı); ham HTML
                       yukarıdaki data-value'da <br>'leriyle DURUYOR, düzenleyici
                       oradan okuyor. */ ?>
                    <div class="cell-view rich-text-view"><?php echo bcc_rich_text_grid_html($displayText); ?></div>
                <?php elseif ($f['field_type'] === 'rating'): ?>
                    <?php
                        // checkbox İLE AYNI desen: her zaman görünür/tıklanabilir gerçek
                        // elemanlar (girmeden-tıkla-kaydet) — startEdit()'in "önce input aç,
                        // sonra blur'da kaydet" akışına GİRMEZ (grid.js'te AYRI bir click
                        // dinleyicisi, checkbox'ın kendi 'change' dinleyicisiyle AYNI ruhta).
                        $ratingDecodedOpts = is_array($f['options']) ? $f['options'] : (json_decode((string) $f['options'], true) ?: array());
                        $ratingMax = isset($ratingDecodedOpts['max_rating']) ? (int) $ratingDecodedOpts['max_rating'] : 5;
                        $ratingVal = $rawValue !== '' ? (int) $rawValue : 0;
                    ?>
                    <div class="cell-view rating-view<?php echo $canEdit ? ' rating-view-editable' : ''; ?>">
                        <?php for ($ratingI = 1; $ratingI <= $ratingMax; $ratingI++): ?>
                            <span class="rating-star<?php echo $ratingI <= $ratingVal ? ' rating-star-filled' : ''; ?>" data-rating-star="<?php echo $ratingI; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                <?php elseif ($isAttachmentType): ?>
                    <div class="cell-view attachment-cell-view">
                        <?php foreach ($attachmentFiles as $file):
                            $isImage = strpos($file['mime'], 'image/') === 0;
                        ?>
                            <a
                                class="attachment-chip"
                                href="/api/attachment_download.php?id=<?php echo (int) $file['id']; ?>"
                                target="_blank" rel="noopener noreferrer"
                                title="<?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <?php if ($isImage): ?>
                                    <img src="/api/attachment_download.php?id=<?php echo (int) $file['id']; ?>" class="attachment-thumb" alt="">
                                <?php else: ?>
                                    <span class="attachment-badge"><?php echo htmlspecialchars(bcc_attachment_type_badge($file['mime']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="attachment-name"><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (in_array($f['field_type'], $GLOBALS['BCC_LINKIFIED_FIELD_TYPES'], true)): ?>
                    <?php /* Grup A (url/email/phone): metin + (linkleştirilebiliyorsa)
                       hover'da beliren "yeni sekmede aç" ikonu. Kaçırma
                       bcc_render_linkified_cell()'in İÇİNDE yapılır — burada
                       htmlspecialchars UYGULANMAZ (uygulansaydı ikonun kendi
                       <svg>'si literal metne dönerdi). Değerin kendisi orada
                       htmlspecialchars'tan geçiyor, href ise whitelist'ten. */ ?>
                    <?php echo bcc_render_linkified_cell($f['field_type'], $displayText); ?>
                <?php else: ?>
                    <div class="cell-view"><?php echo htmlspecialchars($displayText, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </td>
        <?php endforeach; ?>
    </tr>
    <?php
}

// Grid gruplama (Grid araçları Adım 2a): bir grup başlığının ham hücre değerini
// cell_display_text() ile biçimlendirebilmek için, o fonksiyonun beklediği
// cell_values satırı şeklinde sahte bir dizi üretir (GROUP BY sorgusu tek bir kolon
// SELECT ettiği için diğer üç kolon her zaman null'dur) — cell_update.php'deki
// aynı desenin tekrarı.
function bcc_group_cell_row($column, $rawValue)
{
    $row = array('value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null);
    $row[$column] = $rawValue;

    return $row;
}

// Kardeş kayıtlar arasında sıra değiştirme (yukarı/aşağı taşı) — DÖRT çağrı yeri:
// base_tables.php (move_table), table_fields.php (move_field),
// api/view_reorder.php (görünüm sırası), slack_settings.php (kural sırası).
//
// GÜVENLİK: $tableName ve $parentColumn prepared statement ile bağlanamaz, doğrudan
// SQL'e gömülür — bu yüzden KESİNLİKLE aşağıdaki sabit whitelist'ten gelmeli, asla
// kullanıcı girdisinden (ör. $_POST) türememeli. Uyuşmayan bir çift verilirse (kod
// hatası anlamına gelir) istisna fırlatılır.
//
// ⚠️ TRANSACTION: bu fonksiyon ARTIK KENDİ transaction'ını AÇMAZ — çağıran taraf
// bcc_begin_transaction() açmış OLMALI (bcc_assign_autonumbers() ile AYNI
// sözleşme). Önceden içeride begin/commit vardı ve iki UPDATE'i doğru sarıyordu,
// AMA çağıranların log_audit()'i o commit'ten SONRA kalıyordu: sıra kalıcı olarak
// değişmiş ama audit yazması patlamışsa geriye "sırası değişmiş, hiçbir izi
// olmayan" bir satır kalıyordu.
// İçerideki commit KALDIRILMASAYDI çağıran bir transaction açtığında iç içe
// transaction oluşurdu — mysqli bunu desteklemez, İÇTEKİ commit DIŞTAKİNİ
// erkenden commit eder ve rollback koruması sessizce kaybolurdu (düzeltmeden
// beter). Bu yüzden sorumluluk çağırana taşındı ve DÖRT çağrı yerinin dördü de
// güncellendi.
//
// Dönüş: takas yapıldıysa true; ilk/son eleman, geçersiz yön ya da öge bulunamadıysa false.
function bcc_reorder_sibling($tableName, $parentColumn, $parentId, $itemId, $direction)
{
    $allowedParents = array(
        'tables_meta' => 'base_id',
        'fields' => 'table_id',
        'views' => 'table_id',
        'slack_routing_rules' => 'table_id',
    );

    if (!isset($allowedParents[$tableName]) || $allowedParents[$tableName] !== $parentColumn) {
        throw new InvalidArgumentException('bcc_reorder_sibling: izin verilmeyen tablo/kolon.');
    }

    $siblings = bcc_fetch_all(
        "SELECT id, position FROM {$tableName} WHERE {$parentColumn} = :parent_id ORDER BY position, id",
        array('parent_id' => $parentId)
    );

    $index = null;
    foreach ($siblings as $i => $row) {
        if ((int) $row['id'] === (int) $itemId) {
            $index = $i;
            break;
        }
    }

    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

    if ($index === null || $swapWith < 0 || $swapWith >= count($siblings)) {
        return false;
    }

    $a = $siblings[$index];
    $b = $siblings[$swapWith];

    // İki UPDATE, çağıranın transaction'ı İÇİNDE (yukarıdaki sözleşmeye bkz.).
    // Yarım kalan bir takas iki satırı AYNI position'da bırakırdı — bu ikilinin
    // atomikliği hâlâ garanti, yalnızca garantiyi sağlayan transaction artık
    // çağıranın (böylece log_audit() de aynı sınırın içinde kalıyor).
    bcc_execute("UPDATE {$tableName} SET position = :pos WHERE id = :id", array('pos' => $b['position'], 'id' => $a['id']));
    bcc_execute("UPDATE {$tableName} SET position = :pos WHERE id = :id", array('pos' => $a['position'], 'id' => $b['id']));

    return true;
}

// long_text alanları için whitelist tabanlı HTML temizleyici (F6, "ilk aşama").
// GÜVENLİK MODELİ — bu, projenin geri kalanındaki "her zaman render'da
// htmlspecialchars" kuralının TEK istisnasıdır: long_text hücreleri YAZMA
// anında burada temizlenir ve DB'ye zaten güvenli HTML olarak yazılır; render
// tarafında (bcc_render_grid_data_row) bu alan için AYRICA htmlspecialchars
// uygulanmaz (uygulansaydı <b> yerine literal &lt;b&gt; görünür, özellik bozulurdu).
// Regex tabanlı etiket silme KULLANILMAZ (bilinen şekilde atlatılabilir) —
// bunun yerine DOMDocument ile ağaç yeniden inşa edilir: yalnızca whitelist'teki
// etiket+attribute çiftleri korunur, GERİ KALAN HER ŞEY (script/style/img/on*
// event handler'lar dahil TÜM attribute'lar) düşer. Bir etiket whitelist dışıysa
// yalnızca etiket silinir, iç metni/izinli alt etiketleri korunur (kullanıcının
// yazdığı metin kaybolmaz) — script/style istisna, onların içeriği de atılır.
// Bilinen sınır: girdi tarayıcı DIŞINDA (doğrudan API çağrısıyla) çıplak "<"/">"
// gibi HTML'e benzeyen ama etiket olmayan karakterler içeriyorsa, libxml'in
// hoşgörülü ayrıştırıcısı bunları yanlış yorumlayıp o kısmı düşürebilir —
// GÜVENLİK sorunu değildir (yine hiçbir etiket/script sızmaz), yalnızca metin
// sadakati düşer. Gerçek yol (grid.js'in contenteditable düzenleyicisi) bu
// karakterleri tarayıcı serileştirmesi sayesinde zaten &lt;/&gt; olarak
// kaçırılmış gönderir, bu yüzden pratikte karşılaşılmaz.
// PROJEDEKİ TEK <a> KURMA NOKTASI. İki farklı çağıran var ve ikisi de buradan
// geçer — Grup A'da hücre linkleri eklenirken zengin metnin link bloğu
// KOPYALANMADI, bu fonksiyona çıkarıldı:
//   (1) bcc_sanitize_rich_text_node()  — long_text'in kullanıcı yazdığı <a>'ları
//   (2) bcc_render_linkified_cell()    — url/email/phone hücrelerinin ikonu
//
// $labelHtml: <a> içine GİRECEK içerik. ÇAĞIRAN tarafından zaten güvenli hale
//   getirilmiş HTML olmalı — düz metinse htmlspecialchars'tan GEÇMİŞ olmalı.
//   Bu fonksiyon $labelHtml'i KAÇIRMAZ (zengin metinde içerik sanitize edilmiş
//   alt HTML olabiliyor, tekrar kaçırmak <b>'yi &lt;b&gt; yapardı).
// $schemeRegex: izin verilen şemalar. VARSAYILAN yalnızca http/https — zengin
//   metinde href'i KULLANICI yazdığı için whitelist dar tutulur. Hücre linkleri
//   (BCC_CELL_LINK_SCHEMES) mailto/tel'i de içerir, çünkü orada href'i kullanıcı
//   değil bcc_cell_link_href() KURAR.
// $extraAttrHtml: <a>'ya eklenecek ham attribute metni. SABİT LİTERAL OLMALI —
//   buraya ASLA kullanıcı verisi geçirilmez (kaçırılmaz).
//
// Şema whitelist'ten geçmezse null döner; çağıran linki soyup düz metne döner.
function bcc_build_safe_link($href, $labelHtml, $schemeRegex = '#^https?://#i', $extraAttrHtml = '')
{
    if (!preg_match($schemeRegex, (string) $href)) {
        return null;
    }

    return '<a ' . ($extraAttrHtml !== '' ? $extraAttrHtml . ' ' : '')
        . 'href="' . htmlspecialchars((string) $href, ENT_QUOTES, 'UTF-8') . '"'
        . ' target="_blank" rel="noopener noreferrer">' . $labelHtml . '</a>';
}

// Grup A — bir url/email/phone hücresinin href'ini üretir; linkleştirilemiyorsa null.
//
// ⚠️ Bu bir DOĞRULAYICI DEĞİL. Değer HER ZAMAN saklanır (normalize_cell_value()
// bu üç tipi single_line_text ile aynı dalda işler, hiçbir giriş reddedilmez) —
// buradaki kontroller yalnızca "bu metin linke çevrilebilir mi" sorusunu
// yanıtlar. Çevrilemiyorsa hücre düz metin olarak, ikonsuz görünür.
function bcc_cell_link_href($fieldType, $text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return null;
    }

    if ($fieldType === 'url') {
        // Çıplak alan adına ("ornek.com") otomatik https:// EKLENMEZ — kapsam
        // dışı bırakılan bilinçli bir karar; eşleşmeyen değer düz metin kalır.
        return preg_match('#^https?://#i', $text) === 1 ? $text : null;
    }

    if ($fieldType === 'email') {
        // filter_var YALNIZCA linkleştirme kapısı — yazmayı ASLA reddetmez.
        return filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:' . $text : null;
    }

    if ($fieldType === 'phone') {
        // Serbest format kabul edilir ("0212 555 00 00", "+90 (212) 555-0000").
        // tel: hedefi için YALNIZCA + ve rakamlar bırakılır; SAKLANAN değer
        // kullanıcının yazdığı hâliyle kalır (bu indirgeme sadece href içindir).
        // 7 rakam eşiği: "555" gibi anlamsız kısa girdiler linkleşmesin diye.
        $digits = preg_replace('/[^0-9]/', '', $text);
        if (strlen($digits) < 7) {
            return null;
        }

        return 'tel:' . (substr($text, 0, 1) === '+' ? '+' : '') . $digits;
    }

    return null;
}

// Grup A — grid <td>'sinin HTML gövdesi (dıştaki <div class="cell-view"> DAHİL).
//
// ⚠️ YALNIZCA GERÇEKTEN HTML ÜRETEN YERDEN ÇAĞRILIR (bcc_render_grid_data_row).
// view_export_xlsx.php / src/slack.php / grid.php'nin grup başlığı /
// trash_records_list.php cell_display_text()'in DÜZ METİN çıktısını kullanmaya
// DEVAM EDER — Excel'e ve Slack'e HTML gitmemeli. Bu ayrım bu grubun mimari
// ucuzluğunun sebebi: cell_display_text() imzası hiç değişmedi.
//
// TASARIM: metnin KENDİSİ <a> DEĞİL, link ayrı bir ikon olarak sağda durur.
// Böylece hücreye tıklamak HER ZAMAN düzenlemeyi açar (diğer tüm hücrelerle
// tutarlı) — metin <a> olsaydı tıklama linke gider, hücre düzenlenemez olurdu.
// İkon normalde görünmez (CSS visibility:hidden), td hover'ında belirir.
function bcc_render_linkified_cell($fieldType, $displayText)
{
    $escaped = htmlspecialchars((string) $displayText, ENT_QUOTES, 'UTF-8');
    $href = bcc_cell_link_href($fieldType, $displayText);

    if ($href === null) {
        // Linkleştirilemiyor ("abc", "javascript:alert(1)", boş) -> ikon YOK,
        // sıradan bir metin hücresi. XSS yolu burada kapanır: değer hiçbir
        // zaman href'e girmez, yalnızca kaçırılmış metin olarak basılır.
        return '<div class="cell-view">' . $escaped . '</div>';
    }

    // $extraAttrHtml sabit literal — kullanıcı verisi İÇERMEZ (bkz. bcc_build_safe_link).
    $link = bcc_build_safe_link(
        $href,
        bcc_external_link_icon_svg(),
        BCC_CELL_LINK_SCHEMES,
        'class="cell-link-icon" title="Yeni sekmede aç" aria-label="Yeni sekmede aç"'
    );

    if ($link === null) {
        // bcc_cell_link_href() zaten yalnızca whitelist'teki şemaları üretiyor,
        // yani buraya normalde hiç düşülmez — ikinci savunma katmanı.
        return '<div class="cell-view">' . $escaped . '</div>';
    }

    return '<div class="cell-view cell-view-linkified">'
        . '<span class="cell-link-text">' . $escaped . '</span>'
        . $link
        . '</div>';
}

// "Yeni sekmede aç" ikonu — mevcut ikon setiyle AYNI çizim dili (24x24 viewBox,
// fill=none, stroke=currentColor, stroke-width=2, yuvarlak uçlar; theme.css'teki
// alan tipi ikonlarıyla aynı aile). Yeni bir kütüphane/ikon fontu EKLENMEDİ.
// Sabit literal — hiçbir kullanıcı verisi içermez.
function bcc_external_link_icon_svg()
{
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'
        . '<polyline points="15 3 21 3 21 9"/>'
        . '<line x1="10" y1="14" x2="21" y2="3"/>'
        . '</svg>';
}

function bcc_sanitize_rich_text($html)
{
    $html = trim((string) $html);
    if ($html === '') {
        return null;
    }
    if (mb_strlen($html, 'UTF-8') > 20000) {
        $html = mb_substr($html, 0, 20000, 'UTF-8');
    }

    // tag => izinli attribute listesi. strong/b/em/i/br: attribute yok.
    $allowedTags = array(
        'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
        'br' => array(), 'a' => array('href'), 'span' => array('style'),
    );

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // UTF-8 meta bildirimi olmadan loadHTML Türkçe karakterleri (ş/ç/ğ/ı/ö/ü)
    // bozar (F7) — XML encoding ön eki (aşağıda) bunu önler, çıktıya karışmaz.
    $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
        return null;
    }

    $output = trim(bcc_sanitize_rich_text_children($body, $allowedTags));

    return $output === '' ? null : $output;
}

function bcc_sanitize_rich_text_children($node, $allowedTags)
{
    $out = '';
    foreach (iterator_to_array($node->childNodes) as $child) {
        $out .= bcc_sanitize_rich_text_node($child, $allowedTags);
    }

    return $out;
}

function bcc_sanitize_rich_text_node($node, $allowedTags)
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return htmlspecialchars($node->textContent, ENT_QUOTES, 'UTF-8');
    }

    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return '';
    }

    $tag = strtolower($node->nodeName);

    if ($tag === 'script' || $tag === 'style') {
        return ''; // etiket + İÇERİK tamamen atılır
    }

    $childrenHtml = bcc_sanitize_rich_text_children($node, $allowedTags);

    if ($tag === 'div' || $tag === 'p') {
        // contenteditable'ın satır sonu için ürettiği kapsayıcılar -> <br>'a indirgenir.
        return $childrenHtml . '<br>';
    }

    if (!isset($allowedTags[$tag])) {
        return $childrenHtml; // whitelist dışı: etiket silinir, iç metin kalır
    }

    if ($tag === 'br') {
        return '<br>';
    }

    if ($tag === 'a') {
        // Link kurma tek yerde (bcc_build_safe_link) — Grup A'da hücre linkleri
        // eklenirken bu blok KOPYALANMADI, ortak fonksiyona çıkarıldı.
        // VARSAYILAN şema whitelist'i (yalnızca http/https) BİLEREK korundu:
        // burada href'i KULLANICI yazıyor, bu yüzden mailto/tel'e izin verilmez.
        $link = bcc_build_safe_link($node->getAttribute('href'), $childrenHtml);

        // null = güvensiz şema (javascript:, data: vb.) -> link soyulur, metin kalır.
        return $link === null ? $childrenHtml : $link;
    }

    if ($tag === 'span') {
        $style = trim($node->getAttribute('style'));
        $sizes = implode('|', $GLOBALS['BCC_RICH_TEXT_FONT_SIZES']);
        if (!preg_match('/^font-size:(' . $sizes . ')px$/', $style)) {
            return $childrenHtml; // whitelist dışı stil -> span soyulur
        }

        return '<span style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">' . $childrenHtml . '</span>';
    }

    // strong/b/em/i: attribute'suz, yalnızca etiketin kendisi korunur.
    return '<' . $tag . '>' . $childrenHtml . '</' . $tag . '>';
}

// KAPALI grid hücresinde gösterilecek zengin metin HTML'i (OpsFlow davranışı:
// hücre HER ZAMAN TEK SATIR, taşan kısım "..." ile kesilir).
//
// TEK İŞİ: <br> etiketlerini BOŞLUĞA indirmek. Neden CSS değil de burası —
// tarayıcıda ölçüldü: white-space:nowrap bir <br>'yi DURDURMUYOR, satır atlatmayı
// engelleyen tek yol `br { display: none }` (display:inline, display:inline-block
// + width, content:"" hepsi denendi, üçü de atlatmaya devam etti). Ama
// display:none olan bir öğe ::before/::after ÜRETEMEZ, yani ayırıcı boşluğu CSS
// koyamaz: "Merhaba<br>Dünya" ekranda "MerhabaDünya" olurdu. Bu yüzden iş
// bölüşümü şöyle: SATIR ATLAMAYI CSS engelliyor (style.css'teki br{display:none},
// eski/atlanmış bir render yolu için de güvenlik ağı), AYIRICI BOŞLUĞU burası
// koyuyor.
//
// ⚠️ YALNIZCA HÜCRE GÖRÜNÜMÜ (.cell-view) için. Düzenleyicinin okuduğu ham HTML
// (td'nin data-value'su / cell_update.php'nin 'raw' alanı) bu fonksiyondan
// GEÇMEZ — orada <br>'ler AYNEN durur, yoksa hücreyi açıp kaydeden kullanıcı
// kendi satır sonlarını kaybederdi.
//
// /u modifikatörü BİLEREK YOK: geçersiz UTF-8 girdide preg_replace() null döner
// ve içerik SESSİZCE SİLİNİRDİ (aynı tuzağa normalize_cell_value()'nun phone
// dalında düşülmüştü) — burada zaten bayt düzeyinde bir etiket eşleşmesi yapılıyor,
// yine de null dönüşü ham değere düşerek karşılanıyor.
function bcc_rich_text_grid_html($html)
{
    $out = preg_replace('#<br\s*/?>#i', ' ', (string) $html);

    return $out === null ? (string) $html : $out;
}

// Kullanıcıdan gelen ham değeri (POST'tan) fields.field_type'a göre doğrular ve
// cell_values'a yazılacak kolon + normalize edilmiş değeri döndürür.
// Dönüş: array('ok' => bool, 'error' => string|null, 'column' => string|null, 'value' => mixed)
// $usersById: bcc_team_users_by_id() — yalnızca 'user' tipi için whitelist olarak
// kullanılır (single_select'in $optionsJson'dan gelen $choices'ıyla AYNI rol:
// gönderilen id bu haritada yoksa KVKK/veri bütünlüğü gereği reddedilir), diğer
// tipler bu parametreyi görmezden gelir.
function normalize_cell_value($fieldType, $optionsJson, $rawValue, $usersById = array())
{
    $columnMap = $GLOBALS['BCC_FIELD_VALUE_COLUMN'];

    if (!isset($columnMap[$fieldType])) {
        return array('ok' => false, 'error' => 'Bilinmeyen alan tipi.');
    }

    $column = $columnMap[$fieldType];

    switch ($fieldType) {
        // url/email/phone (Grup A): single_line_text ile BİREBİR AYNI işlem —
        // YUMUŞAK doğrulama, hiçbir giriş REDDEDİLMEZ. Bilinçli karar:
        //   * Katı doğrulamanın bedeli import'a biner — table_import_xlsx.php
        //     normalize reddini "N hücre atlandı" diye SESSİZCE düşürür; makul
        //     görünen veriyi kullanıcıya sormadan kaybetmek en kötü sonuç.
        //   * Güvenlik için de gerekmez: linkleştirme WHITELIST tabanlı
        //     (bcc_cell_link_href + bcc_build_safe_link), yani "javascript:..."
        //     metin olarak saklansa bile ASLA href olmaz, düz metin kalır.
        //   * filter_var(FILTER_VALIDATE_EMAIL) IDN/Unicode adresleri
        //     ("üye@örnek.com") reddeder — Türkçe bir üründe gerçek bir kayıp.
        //     Bu yüzden yalnızca LİNKLEŞTİRME kapısı olarak kullanılıyor.
        // Telefonda ayrıca fazla boşluklar sadeleştirilir (yalnızca kozmetik
        // normalizasyon; "0212  555" -> "0212 555", hiçbir karakter ATILMAZ —
        // tel: linki için rakam indirgemesi bcc_cell_link_href()'te ve SADECE
        // link için yapılır, saklanan değer kullanıcının yazdığıdır).
        case 'phone':
            // ⚠️ /u modifikatörü GEÇERSİZ UTF-8 girdide preg_replace()'i null
            // döndürür (PREG_BAD_UTF8_ERROR). Null'a düşülürse trim((string) null)
            // = '' olur ve kullanıcının değeri SESSİZCE SİLİNİRDİ. Bu yüzden
            // dönüş null ise ham değer olduğu gibi korunur — sadeleştirme
            // kozmetik bir iyileştirme, veri kaybetme gerekçesi değil.
            $phoneNormalized = preg_replace('/\s+/u', ' ', (string) $rawValue);
            $rawValue = ($phoneNormalized === null) ? (string) $rawValue : $phoneNormalized;
            // devam: aşağıdaki ortak metin dalı
        case 'single_line_text':
        case 'url':
        case 'email':
            $text = trim((string) $rawValue);

            return array('ok' => true, 'column' => $column, 'value' => $text === '' ? null : $text);

        case 'long_text':
            return array('ok' => true, 'column' => $column, 'value' => bcc_sanitize_rich_text($rawValue));

        // currency: number ile BİREBİR AYNI doğrulama — format (sembol/ondalık
        // basamak) yalnızca GÖRÜNTÜLEMEYİ etkiler, DB'ye ham sayı olarak yazılır.
        case 'number':
        case 'currency':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!is_numeric($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz sayı.');
            }

            return array('ok' => true, 'column' => $column, 'value' => (float) $raw);

        // percent: OpsFlow davranışı (araştırılıp netleştirilen karar) —
        // kullanıcı "45" yazar (%45 niyetiyle), DB'ye ONDALIK (0.45) yazılır.
        // cell_display_text() bunun TERSİNİ yapıp ×100 + "%" ile gösterir.
        case 'percent':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!is_numeric($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz sayı.');
            }

            return array('ok' => true, 'column' => $column, 'value' => ((float) $raw) / 100);

        // rating: number'dan FARKLI — [0, max_rating] aralığına sınırlı, tam
        // sayıya yuvarlanır. max_rating $optionsJson'dan okunur (fonksiyon
        // zaten alıyor — 'user' tipi $usersById'i nasıl kullanıyorsa AYNI şekilde).
        case 'rating':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!is_numeric($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz değerlendirme.');
            }

            $maxRating = 5;
            $decodedOptions = ($optionsJson !== null && $optionsJson !== '') ? json_decode($optionsJson, true) : null;
            if (is_array($decodedOptions) && isset($decodedOptions['max_rating'])) {
                $maxRating = (int) $decodedOptions['max_rating'];
            }

            $rounded = (int) round((float) $raw);
            if ($rounded < 0 || $rounded > $maxRating) {
                return array('ok' => false, 'error' => 'Değerlendirme 0 ile ' . $maxRating . ' arasında olmalı.');
            }

            return array('ok' => true, 'column' => $column, 'value' => $rounded);

        case 'checkbox':
            return array('ok' => true, 'column' => $column, 'value' => ($rawValue === '1' || $rawValue === 1) ? 1 : 0);

        case 'date':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }

            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                return array('ok' => false, 'error' => 'Geçersiz tarih (YYYY-AA-GG).');
            }

            return array('ok' => true, 'column' => $column, 'value' => $raw . ' 00:00:00');

        case 'single_select':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }

            $choices = select_choices_from_options($optionsJson);
            if (!in_array($raw, $choices, true)) {
                return array('ok' => false, 'error' => 'Geçersiz seçenek.');
            }

            return array('ok' => true, 'column' => $column, 'value' => $raw);

        case 'multiple_select':
            $decoded = json_decode((string) $rawValue, true);
            if ($decoded === null) {
                $decoded = array();
            }
            if (!is_array($decoded)) {
                return array('ok' => false, 'error' => 'Geçersiz veri.');
            }

            $choices = select_choices_from_options($optionsJson);
            $valid = array();
            foreach ($decoded as $item) {
                if (is_string($item) && in_array($item, $choices, true) && !in_array($item, $valid, true)) {
                    $valid[] = $item;
                }
            }

            return array('ok' => true, 'column' => $column, 'value' => empty($valid) ? null : json_encode($valid, JSON_UNESCAPED_UNICODE));

        case 'time':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }

            $t = DateTime::createFromFormat('H:i', $raw);
            if (!$t || $t->format('H:i') !== $raw) {
                return array('ok' => false, 'error' => 'Geçersiz saat (SS:DD).');
            }

            return array('ok' => true, 'column' => $column, 'value' => $raw);

        case 'user':
            $raw = trim((string) $rawValue);

            if ($raw === '') {
                return array('ok' => true, 'column' => $column, 'value' => null);
            }
            if (!ctype_digit($raw)) {
                return array('ok' => false, 'error' => 'Geçersiz kullanıcı.');
            }

            $userId = (int) $raw;
            if (!isset($usersById[$userId])) {
                return array('ok' => false, 'error' => 'Geçersiz kullanıcı (bu ekibin üyesi değil).');
            }

            return array('ok' => true, 'column' => $column, 'value' => $userId);

        // created_time/created_by/last_modified_time/last_modified_by:
        // OpsFlow'daki gibi kullanıcı tarafından ASLA düzenlenemez — backend'de
        // son söz burası (grid-row-detail.js'in buildFieldWidget() dalı zaten
        // frontend'de engelliyor, ama bypass ihtimaline karşı gerçek karar
        // burada). $columnMap'te (BCC_FIELD_VALUE_COLUMN) bu dört tip VAR (aksi
        // halde fonksiyon başındaki isset() kontrolü "Bilinmeyen alan tipi" ile
        // reddederdi) — burada AYRI, doğru mesajla reddedilir. last_modified_time/by
        // zaten YALNIZCA bcc_touch_record_modified() ile (madde b'deki "içerik
        // değişikliği" yazma noktalarından) dolaylı olarak güncellenir, hiçbir
        // zaman doğrudan bir cell_update.php isteğiyle YAZILAMAZ.
        // autonumber (Grup C2): B1/B2 ile AYNI red. Farkı, değerin records'tan
        // TÜRETİLMEMESİ — cell_values'ta gerçekten yaşıyor ama YALNIZCA
        // bcc_assign_autonumbers()/bcc_backfill_autonumber_field() yazabilir,
        // hiçbir zaman doğrudan bir cell_update.php isteğiyle YAZILAMAZ.
        case 'created_time':
        case 'created_by':
        case 'last_modified_time':
        case 'last_modified_by':
        case 'autonumber':
            return array('ok' => false, 'error' => 'Bu alan otomatik doldurulur, düzenlenemez.');

        default:
            return array('ok' => false, 'error' => 'Bilinmeyen alan tipi.');
    }
}

// Grid'in çoklu sıralama panelinden gelen sort_field_N / sort_dir_N (N=1..3) GET
// parametrelerini doğrular. Yalnızca $fieldsById içindeki (yani bu tabloya ait)
// alan id'lerini kabul eder — team_id/tablo her zaman DB satırından gelir.
function parse_grid_sort_rules($params, $fieldsById)
{
    $rules = array();

    for ($i = 1; $i <= $GLOBALS['BCC_SORT_MAX_SLOTS']; $i++) {
        $fieldKey = 'sort_field_' . $i;

        if (empty($params[$fieldKey])) {
            continue;
        }

        $fieldId = (int) $params[$fieldKey];

        if (!isset($fieldsById[$fieldId])) {
            continue;
        }

        $fieldType = $fieldsById[$fieldId]['field_type'];

        // 'attachment' gibi cell_values'ta karşılığı olmayan tipler (bkz.
        // BCC_FIELD_VALUE_COLUMN yorumu) sıralanamaz — URL'e elle yazılsa bile
        // sessizce atlanır (boş SQL kolon adına düşüp sorgu hatası vermez).
        if (!isset($GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType])) {
            continue;
        }

        $dirKey = 'sort_dir_' . $i;
        $dir = (isset($params[$dirKey]) && $params[$dirKey] === 'desc') ? 'DESC' : 'ASC';

        $rules[] = array(
            'slot' => $i,
            'field_id' => $fieldId,
            // field_type: bcc_build_grid_records_query()'nin created_time/created_by
            // için LEFT JOIN cell_values'ı atlayıp records'un kendi kolonunu
            // kullanması gerektiğini anlaması için (BCC_RECORD_COLUMN_FIELD_TYPES).
            'field_type' => $fieldType,
            'dir' => $dir,
            'column' => $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType],
        );
    }

    return $rules;
}

// Grid'in Group panelinden gelen group_field_1..3 / group_dir_1..3 GET
// parametrelerini doğrular (çok seviyeli gruplama, en fazla 3 kural). Yalnızca
// $fieldsById'e ait (bu tabloya ait) bir alan id'si kabul edilir — gizli (Hide
// fields ile kapatılmış) bir alan da gruplama için geçerlidir, whitelist kaynağı
// her zaman $fieldsById'in tamamıdır. Yön parse_grid_sort_rules ile aynı şekilde
// ele alınır ve aynı biçimde döner: yalnızca tam olarak "desc" DESC'e karşılık
// gelir, başka her şey (eksik dahil) ASC sayılır; dönüş değerindeki 'dir' de
// parse_grid_sort_rules ile birebir aynı biçimde büyük harf 'ASC'/'DESC' olur
// (ORDER BY'a doğrudan gömülür, ayrıca panel <select>'lerindeki karşılaştırmalar
// da bu biçimi bekler — grid.php URL state'ine yazarken strtolower() ile küçük
// harfe çevirir, tıpkı sort kurallarında olduğu gibi).
//
// Geriye dönük uyum: yeni group_field_1..3 parametrelerinden hiçbiri istekte
// YOKSA (isset ile kontrol edilir — boş gönderilmiş olması "yeni format
// kullanılıyor" sayılır), eski tekil group_field / group_dir parametreleri
// varsa 1. seviye olarak okunur. Eski parametre adları hiçbir zaman üretilmez,
// yalnızca okunur (bkz. grid.php'deki $groupState).
//
// Geçersiz/silinmiş/whitelist dışı alan id'leri o slotu sessizce eler; sonuç
// dizisi yalnızca geçerli kuralları, orijinal slot sırasına göre, BOŞLUK
// BIRAKMADAN içerir — yani 2. seviye silinip 3. seviye kalırsa, 3. seviyenin
// kuralı dizide 2. sıraya (index 1) düşer. Bu sıkıştırma ayrı bir adım değil,
// doğrudan 1..3 taramasının bir sonucudur.
//
// Aynı alan iki seviyede birden seçilemez (OpsFlow davranışı): FAZ 4'teki
// panel zaten kullanılmış alanları dropdown'dan düşürecek, ama URL elle
// değiştirilebildiği için burada da bir güvenlik ağı var — bir field_id daha
// önceki (daha düşük) bir seviyede zaten kullanıldıysa, sonraki tekrarı
// sessizce elenir (o slot dizide yer almaz, altındaki seviyeler yine kayar).
function parse_grid_group_rules($params, $fieldsById)
{
    $maxLevels = 3;
    $hasNewParams = false;

    for ($i = 1; $i <= $maxLevels; $i++) {
        if (isset($params['group_field_' . $i])) {
            $hasNewParams = true;
            break;
        }
    }

    $sources = array();
    if ($hasNewParams) {
        for ($i = 1; $i <= $maxLevels; $i++) {
            $sources[] = array('field_key' => 'group_field_' . $i, 'dir_key' => 'group_dir_' . $i);
        }
    } else {
        $sources[] = array('field_key' => 'group_field', 'dir_key' => 'group_dir');
    }

    $rules = array();
    $usedFieldIds = array();

    foreach ($sources as $source) {
        if (empty($params[$source['field_key']])) {
            continue;
        }

        $fieldId = (int) $params[$source['field_key']];

        if (!isset($fieldsById[$fieldId]) || isset($usedFieldIds[$fieldId])) {
            continue;
        }

        $fieldType = $fieldsById[$fieldId]['field_type'];

        // parse_grid_sort_rules'daki AYNI savunma — 'attachment' gibi cell_values
        // karşılığı olmayan tipler gruplanamaz (bkz. BCC_FIELD_VALUE_COLUMN yorumu).
        if (!isset($GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType])) {
            continue;
        }

        $dir = (isset($params[$source['dir_key']]) && $params[$source['dir_key']] === 'desc') ? 'DESC' : 'ASC';

        $rules[] = array(
            'slot' => count($rules) + 1,
            'field_id' => $fieldId,
            'field_type' => $fieldType,
            'dir' => $dir,
            'column' => $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType],
            // 'options': grup başlığı render'ının (grid.php bcc_build_grouped_tree)
            // cell_display_text()'e Currency/Percent/Rating (Grup C1) formatı için
            // geçirmesi gerekiyor — bcc_build_grouped_tree()'nin imzasına YENİ bir
            // $fieldsById parametresi eklemek yerine, zaten var olan bu rule
            // dizisi bir anahtar daha taşıyor (field_type'ın Grup B2'de eklenme
            // deseniyle AYNI).
            'options' => $fieldsById[$fieldId]['options'],
        );
        $usedFieldIds[$fieldId] = true;
    }

    return $rules;
}

// Grid'in filtre panelinden gelen filter_field_N / filter_cond_N / filter_value_N
// (N=1..5) GET parametrelerini doğrular. Yalnızca $fieldsById'e ait alan id'leri VE
// o alan tipi için whitelist'te tanımlı operatörler kabul edilir; geri kalanı
// sessizce yok sayılır. Değerin (sayı/tarih formatı vb.) doğrulanması
// filter_condition_sql() içinde, SQL'e bağlanma anında yapılır.
function parse_grid_filter_rules($params, $fieldsById)
{
    $maxSlots = $GLOBALS['BCC_FILTER_MAX_SLOTS'];
    $rules = array();

    for ($i = 1; $i <= $maxSlots; $i++) {
        $fieldKey = 'filter_field_' . $i;

        if (empty($params[$fieldKey])) {
            continue;
        }

        $fieldId = (int) $params[$fieldKey];

        if (!isset($fieldsById[$fieldId])) {
            continue;
        }

        $fieldType = $fieldsById[$fieldId]['field_type'];
        $allowedOps = isset($GLOBALS['BCC_FILTER_OPERATORS'][$fieldType]) ? $GLOBALS['BCC_FILTER_OPERATORS'][$fieldType] : array();

        $condKey = 'filter_cond_' . $i;
        $operator = isset($params[$condKey]) ? $params[$condKey] : '';

        if (!isset($allowedOps[$operator])) {
            continue;
        }

        $valueKey = 'filter_value_' . $i;

        $rules[] = array(
            'slot' => $i,
            'field_id' => $fieldId,
            'field_type' => $fieldType,
            'operator' => $operator,
            'raw_value' => isset($params[$valueKey]) ? $params[$valueKey] : '',
        );
    }

    return $rules;
}

// Grid'in "Hide fields" panelinden gelen görünürlük tercihini doğrular ve gizlenecek
// alan id'lerini döndürür. Birincil alan ($primaryFieldId — position/id'ye göre bu
// tablonun ilk alanı) HİÇBİR ZAMAN gizlenemez, URL'e elle yazılsa bile (OpsFlow'daki
// gibi) — bu fonksiyon onu iki yolda da sonuçtan düşürür.
// İki girdi şekli kabul edilir:
//  - visible_fields[]=ID&visible_fields[]=ID...: panelin kendi formu (toggle'lar
//    "işaretli = görünür") tarayıcı tarafından böyle gönderilir; işaretli olmayan
//    alanlar gizli sayılır. visible_fields_submitted=1 imleyicisi, "form gönderildi
//    ama hiçbir kutu işaretli değil" durumunu "bu istek panel formundan hiç gelmedi"
//    durumundan ayırt etmek için gerekli (aksi halde ikisi de "parametre yok" gibi görünür).
//  - hidden_fields=ID,ID,...: diğer bağlantılar/formlar (Tümünü gizle kısayolu,
//    Filter/Sort formlarındaki durum input'u) doğrudan bu biçimi üretir.
// Yalnızca $fieldsById'e ait (bu tabloya ait) alan id'leri kabul edilir, sahte/yabancı
// id'ler sessizce yok sayılır — parse_grid_sort_rules / parse_grid_filter_rules ile
// aynı yaklaşım. Gizli alan veriden çıkmaz; yalnızca grid.php'nin render ettiği sütun
// listesinden düşer (filtre/sıralama etkilenmez).
// Dönüş: gizlenecek alan id'lerinin (int) dizisi.
function parse_grid_hidden_fields($params, $fieldsById, $primaryFieldId)
{
    $primaryFieldId = (int) $primaryFieldId;

    if (isset($params['visible_fields_submitted'])) {
        $visible = array();

        if (isset($params['visible_fields']) && is_array($params['visible_fields'])) {
            foreach ($params['visible_fields'] as $rawId) {
                $visible[(int) $rawId] = true;
            }
        }

        $hidden = array();
        foreach ($fieldsById as $fieldId => $field) {
            if ($fieldId !== $primaryFieldId && !isset($visible[$fieldId])) {
                $hidden[] = $fieldId;
            }
        }

        return $hidden;
    }

    if (empty($params['hidden_fields'])) {
        return array();
    }

    $hidden = array();

    foreach (explode(',', (string) $params['hidden_fields']) as $rawId) {
        $fieldId = (int) trim($rawId);

        if ($fieldId > 0 && $fieldId !== $primaryFieldId && isset($fieldsById[$fieldId]) && !in_array($fieldId, $hidden, true)) {
            $hidden[] = $fieldId;
        }
    }

    return $hidden;
}

// Grid'in Row height panelinden gelen row_height GET parametresini doğrular
// (whitelist, BCC_ROW_HEIGHT_LABELS'in anahtarları). Geçersiz/eksikse 'short' döner.
function parse_grid_row_height($params)
{
    $value = isset($params['row_height']) ? (string) $params['row_height'] : 'short';

    return isset($GLOBALS['BCC_ROW_HEIGHT_LABELS'][$value]) ? $value : 'short';
}

// Grid'in Row height panelinden gelen wrap_headers GET parametresini doğrular.
// Yalnızca tam olarak "1" açık sayılır — eksik ya da başka her şey kapalı demektir.
function parse_grid_wrap_headers($params)
{
    return isset($params['wrap_headers']) && $params['wrap_headers'] === '1';
}

// $_GET'te hiç grid state parametresi yoksa true döner (yalnızca table_id ile
// açılmış "çıplak" istek) — grid.php bu durumda view'ın kayıtlı grid_state'ine
// (varsa) yönlendirir. Doğrulama yapmaz, yalnızca varlık kontrolüdür.
function bcc_grid_state_is_empty($params)
{
    $keys = array('hidden_fields', 'visible_fields_submitted', 'row_height', 'wrap_headers', 'filter_logic');

    for ($i = 1; $i <= $GLOBALS['BCC_SORT_MAX_SLOTS']; $i++) {
        $keys[] = 'sort_field_' . $i;
        $keys[] = 'group_field_' . $i;
    }
    for ($i = 1; $i <= $GLOBALS['BCC_FILTER_MAX_SLOTS']; $i++) {
        $keys[] = 'filter_field_' . $i;
    }

    foreach ($keys as $key) {
        if (isset($params[$key]) && $params[$key] !== '') {
            return false;
        }
    }

    return true;
}

// Doğrulanmış sort/group/filter/hidden/row-height/wrap-headers durumunu grid.php'nin
// $_GET'te beklediği anahtar biçimine (sort_field_1, filter_cond_2, ... ) çevirir —
// view_save_state.php bunu views.config['grid_state']'e yazar, grid.php de aynı
// diziyi http_build_query() ile redirect URL'ine çevirir. Varsayılan değerde olan
// alanlar (row_height=short, wrap_headers kapalı, boş filtre/hidden) hiç yazılmaz.
function bcc_grid_state_to_array($sortRules, $groupRules, $filterRules, $filterLogic, $hiddenFieldIds, $rowHeight, $wrapHeaders)
{
    $state = array();

    foreach ($sortRules as $rule) {
        $state['sort_field_' . $rule['slot']] = $rule['field_id'];
        $state['sort_dir_' . $rule['slot']] = strtolower($rule['dir']);
    }

    foreach ($groupRules as $rule) {
        $state['group_field_' . $rule['slot']] = $rule['field_id'];
        $state['group_dir_' . $rule['slot']] = strtolower($rule['dir']);
    }

    foreach ($filterRules as $rule) {
        $state['filter_field_' . $rule['slot']] = $rule['field_id'];
        $state['filter_cond_' . $rule['slot']] = $rule['operator'];
        $state['filter_value_' . $rule['slot']] = $rule['raw_value'];
    }
    if (!empty($filterRules)) {
        $state['filter_logic'] = (strtolower((string) $filterLogic) === 'or') ? 'or' : 'and';
    }

    if (!empty($hiddenFieldIds)) {
        $state['hidden_fields'] = implode(',', $hiddenFieldIds);
    }

    if ($rowHeight !== 'short') {
        $state['row_height'] = $rowHeight;
    }

    if ($wrapHeaders) {
        $state['wrap_headers'] = '1';
    }

    return $state;
}

// views.config'in grid_state anahtarını SAVUNMACI biçimde okur (bcc_get_frozen_column_count
// ile aynı yaklaşım): NULL/bozuk JSON/eksik anahtar/beklenmedik tip -> boş dizi.
function bcc_get_view_grid_state($configJson)
{
    if ($configJson === null || $configJson === '') {
        return array();
    }

    $decoded = json_decode($configJson, true);
    if (!is_array($decoded) || !isset($decoded['grid_state']) || !is_array($decoded['grid_state'])) {
        return array();
    }

    return $decoded['grid_state'];
}

// Doğrulanmış tek bir filtre kuralını SQL WHERE parçasına çevirir.
// $alias: bu kural için LEFT JOIN edilmiş cell_values takma adı (ör. "fv0").
// $paramName: SQL'de kullanılacak bind parametre adı (ör. ":fval0"), kolonu içerir.
// Dönüş: array('sql' => string, 'params' => array($paramName => $value)) veya
// değer geçersiz/eksikse null (bu durumda kural sessizce filtreden düşer).
function filter_condition_sql($fieldType, $operator, $rawValue, $alias, $paramName)
{
    $allowedOps = isset($GLOBALS['BCC_FILTER_OPERATORS'][$fieldType]) ? $GLOBALS['BCC_FILTER_OPERATORS'][$fieldType] : array();
    if (!isset($allowedOps[$operator])) {
        return null;
    }

    // created_time/created_by: gerçek SQL kolonu records'un KENDİ kolonu
    // (created_at/created_by) — BCC_FIELD_VALUE_COLUMN'daki 'value_date'/
    // 'value_number' yalnızca render fonksiyonları içindir, SQL'e ASLA gömülmez.
    $column = isset($GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'][$fieldType])
        ? $GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'][$fieldType]
        : $GLOBALS['BCC_FIELD_VALUE_COLUMN'][$fieldType];
    // url/email/phone (Grup A) de metin benzeri — bu diziye EKLENMEZSE
    // 'empty'/'not_empty' yalnızca IS NULL bakar ve boş string ('') olarak
    // kaydedilmiş hücreleri SESSİZCE kaçırırdı.
    $isTextLike = in_array($fieldType, array('single_line_text', 'long_text', 'single_select', 'url', 'email', 'phone'), true);

    if (in_array($operator, $GLOBALS['BCC_FILTER_NO_VALUE_OPS'], true)) {
        switch ($operator) {
            case 'empty':
                if ($isTextLike) {
                    return array('sql' => "({$alias}.{$column} IS NULL OR {$alias}.{$column} = '')", 'params' => array());
                }
                return array('sql' => "{$alias}.{$column} IS NULL", 'params' => array());
            case 'not_empty':
                if ($isTextLike) {
                    return array('sql' => "({$alias}.{$column} IS NOT NULL AND {$alias}.{$column} <> '')", 'params' => array());
                }
                return array('sql' => "{$alias}.{$column} IS NOT NULL", 'params' => array());
            case 'checked':
                return array('sql' => "{$alias}.{$column} = 1", 'params' => array());
            case 'unchecked':
                return array('sql' => "({$alias}.{$column} = 0 OR {$alias}.{$column} IS NULL)", 'params' => array());
        }
    }

    $raw = trim((string) $rawValue);

    // currency/rating: number ile BİREBİR AYNI karşılaştırma (DB'deki ham sayı
    // ile). percent İSTİSNAİ: kullanıcı filtre kutusuna "50" yazınca (%50
    // niyetiyle) bunu da normalize_cell_value() ile AYNI kuralla 100'e bölüp
    // DB'deki ondalıkla (0.5) karşılaştırıyoruz — aksi halde kullanıcı hücreye
    // yazarken "50", filtrelerken "0.5" yazmak zorunda kalırdı (tutarsız UX).
    // autonumber da AYNI dalda — düz tamsayı karşılaştırması, percent'in ÷100
    // istisnası ona uygulanmaz (aşağıdaki koşul yalnızca 'percent'i yakalar).
    if ($fieldType === 'number' || $fieldType === 'currency' || $fieldType === 'percent' || $fieldType === 'rating' || $fieldType === 'autonumber') {
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $map = array('eq' => '=', 'neq' => '<>', 'gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<=');
        if (!isset($map[$operator])) {
            return null;
        }

        $value = (float) $raw;
        if ($fieldType === 'percent') {
            $value = $value / 100;
        }

        if ($operator === 'neq') {
            return array('sql' => "({$alias}.{$column} <> {$paramName} OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => $value));
        }

        return array('sql' => "{$alias}.{$column} {$map[$operator]} {$paramName}", 'params' => array($paramName => $value));
    }

    if ($fieldType === 'user' || $fieldType === 'created_by' || $fieldType === 'last_modified_by') {
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $value = (int) $raw;

        if ($operator === 'not_equals') {
            return array('sql' => "({$alias}.{$column} <> {$paramName} OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => $value));
        }
        if ($operator === 'equals') {
            return array('sql' => "{$alias}.{$column} = {$paramName}", 'params' => array($paramName => $value));
        }

        return null;
    }

    if ($fieldType === 'time') {
        $t = DateTime::createFromFormat('H:i', $raw);
        if (!$t || $t->format('H:i') !== $raw) {
            return null;
        }

        $map = array('before' => '<', 'after' => '>', 'equals' => '=');
        if (!isset($map[$operator])) {
            return null;
        }

        return array('sql' => "{$alias}.{$column} {$map[$operator]} {$paramName}", 'params' => array($paramName => $raw));
    }

    if ($fieldType === 'date' || $fieldType === 'created_time' || $fieldType === 'last_modified_time') {
        $d = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) {
            return null;
        }

        if ($operator === 'before') {
            return array('sql' => "{$alias}.{$column} < {$paramName}", 'params' => array($paramName => $raw . ' 00:00:00'));
        }
        if ($operator === 'after') {
            return array('sql' => "{$alias}.{$column} > {$paramName}", 'params' => array($paramName => $raw . ' 23:59:59'));
        }
        if ($operator === 'equals') {
            return array('sql' => "DATE({$alias}.{$column}) = {$paramName}", 'params' => array($paramName => $raw));
        }

        return null;
    }

    if ($fieldType === 'multiple_select') {
        if ($raw === '') {
            return null;
        }

        if ($operator === 'contains') {
            return array('sql' => "JSON_CONTAINS({$alias}.{$column}, JSON_QUOTE({$paramName}))", 'params' => array($paramName => $raw));
        }
        if ($operator === 'not_contains') {
            return array('sql' => "(NOT JSON_CONTAINS({$alias}.{$column}, JSON_QUOTE({$paramName})) OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => $raw));
        }

        return null;
    }

    // Metin benzeri: single_line_text, long_text, single_select, url, email, phone
    if ($raw === '' && $operator !== 'equals' && $operator !== 'not_equals') {
        return null;
    }

    switch ($operator) {
        case 'contains':
            return array('sql' => "{$alias}.{$column} LIKE {$paramName}", 'params' => array($paramName => '%' . $raw . '%'));
        case 'not_contains':
            return array('sql' => "({$alias}.{$column} NOT LIKE {$paramName} OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => '%' . $raw . '%'));
        case 'equals':
            return array('sql' => "{$alias}.{$column} = {$paramName}", 'params' => array($paramName => $raw));
        case 'not_equals':
            return array('sql' => "({$alias}.{$column} <> {$paramName} OR {$alias}.{$column} IS NULL)", 'params' => array($paramName => $raw));
    }

    return null;
}

// grid.php'nin kayıt sorgusunu (sort/filter/group JOIN'leri + WHERE + ORDER BY)
// kuran mantık — grid.php'nin KENDİSİ VE public/api/view_export_xlsx.php (Excel
// indirme, aktif sort/filter'ı AYNEN uygulamalı) tarafından çağrılır. Grup
// desteği Excel export'ta kullanılmaz ($groupRules = array() geçilir), fonksiyon yine de
// grid.php'nin tam ihtiyacını (3 seviyeye kadar grup) karşılar — paralel bir
// sorgu-kurma mantığı YOK, grid.php'nin ZATEN çalışan/test edilmiş satırları
// buraya taşındı.
function bcc_build_grid_records_query($tableId, $groupRules, $sortRules, $filterRules, $filterLogic)
{
    // created_time/created_by (BCC_RECORD_COLUMN_FIELD_TYPES): değer records'un
    // KENDİ kolonunda (r.created_at/r.created_by) — cell_values'a hiç LEFT JOIN
    // atılmaz, field_id eşleştirmesi gerekmez, doğrudan alias 'r' kullanılır.
    $recordColumnTypes = $GLOBALS['BCC_RECORD_COLUMN_FIELD_TYPES'];

    $groupSelectExtra = '';
    foreach ($groupRules as $gIdx => $gRule) {
        if (isset($recordColumnTypes[$gRule['field_type']])) {
            $groupSelectExtra .= ", r.{$recordColumnTypes[$gRule['field_type']]} AS group_raw_value_{$gIdx}";
        } else {
            $groupSelectExtra .= ", gv{$gIdx}.{$gRule['column']} AS group_raw_value_{$gIdx}";
        }
    }
    // r.created_by/updated_at/updated_by: created_time/created_by/last_modified_time/
    // last_modified_by alan tiplerinin render'ı için (bcc_render_grid_data_row vb.)
    // — her zaman gerekli (yalnızca bir group/sort/filter kuralı varken değil).
    $recordsSql = "SELECT r.id, r.position, r.created_at, r.created_by, r.updated_at, r.updated_by{$groupSelectExtra} FROM records r";
    $recordsParams = array(':table_id' => $tableId);
    $orderParts = array();

    foreach ($groupRules as $gIdx => $gRule) {
        if (isset($recordColumnTypes[$gRule['field_type']])) {
            $col = 'r.' . $recordColumnTypes[$gRule['field_type']];
            $orderParts[] = "({$col} IS NULL) DESC";
            $orderParts[] = "{$col} {$gRule['dir']}";
            continue;
        }
        $alias = 'gv' . $gIdx;
        $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :gfid{$gIdx}";
        $recordsParams[':gfid' . $gIdx] = $gRule['field_id'];
        $orderParts[] = "({$alias}.{$gRule['column']} IS NULL) DESC";
        $orderParts[] = "{$alias}.{$gRule['column']} {$gRule['dir']}";
    }

    foreach ($sortRules as $idx => $rule) {
        if (isset($recordColumnTypes[$rule['field_type']])) {
            $orderParts[] = "r.{$recordColumnTypes[$rule['field_type']]} {$rule['dir']}";
            continue;
        }
        $alias = 'sv' . $idx;
        $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :sfid{$idx}";
        $recordsParams[':sfid' . $idx] = $rule['field_id'];
        $orderParts[] = "{$alias}.{$rule['column']} {$rule['dir']}";
    }

    $filterConds = array();
    foreach ($filterRules as $idx => $rule) {
        $paramName = ':fval' . $idx;

        if (isset($recordColumnTypes[$rule['field_type']])) {
            $frag = filter_condition_sql($rule['field_type'], $rule['operator'], $rule['raw_value'], 'r', $paramName);
            if ($frag === null) {
                continue;
            }
            foreach ($frag['params'] as $pName => $pValue) {
                $recordsParams[$pName] = $pValue;
            }
            $filterConds[] = $frag['sql'];
            continue;
        }

        $alias = 'fv' . $idx;
        $frag = filter_condition_sql($rule['field_type'], $rule['operator'], $rule['raw_value'], $alias, $paramName);

        if ($frag === null) {
            continue;
        }

        $recordsSql .= " LEFT JOIN cell_values {$alias} ON {$alias}.record_id = r.id AND {$alias}.field_id = :ffid{$idx}";
        $recordsParams[':ffid' . $idx] = $rule['field_id'];
        foreach ($frag['params'] as $pName => $pValue) {
            $recordsParams[$pName] = $pValue;
        }
        $filterConds[] = $frag['sql'];
    }

    $orderParts[] = 'r.position ASC';
    $orderParts[] = 'r.id ASC';

    // Adım 3c: soft-delete edilmiş kayıtlar (bkz. migrations/012) hiçbir grid
    // görünümünde (sıralama/filtre/gruplama/arama — arama client-side ama bu
    // sonuçtan besleniyor, ikinci bir sorgu yok) görünmemeli. TEK filtre noktası
    // burası — public/grid.php VE view_export_xlsx.php (Excel indir) İKİSİ DE
    // bu fonksiyonu çağırıyor, paralel bir sorgu yolu yok.
    $recordsSql .= ' WHERE r.table_id = :table_id AND r.deleted_at IS NULL';
    if (!empty($filterConds)) {
        $joinWord = ($filterLogic === 'OR') ? ' OR ' : ' AND ';
        $recordsSql .= ' AND (' . implode($joinWord, $filterConds) . ')';
    }
    $recordsSql .= ' ORDER BY ' . implode(', ', $orderParts);

    return array($recordsSql, $recordsParams);
}

// ---------------------------------------------------------------------------
// İSİM BENZERSİZLİĞİ — SCOPE HARİTASI VE TEK KONTROL NOKTASI
// ---------------------------------------------------------------------------
// Kural: bir isim GLOBAL olarak değil, YALNIZCA ait olduğu üst yapı içinde
// benzersizdir. "Müşteriler" tablosu Base A'da da Base B'de de olabilir; ama
// AYNI base içinde iki kez olamaz.
//
// Scope'lar veri modelindeki gerçek sahiplik zincirinden türetildi:
//   bases        -> team_id   (bir çalışma alanı içinde)
//   tables_meta  -> base_id   (bir base içinde)
//   fields       -> table_id  (bir tablo içinde)
//   views        -> table_id  (bir tablo içinde)
//
// KAPSAM DIŞI — bilinçli:
//   teams.name  GLOBAL kalır. teams veri modelindeki EN ÜST yapıdır, üstünde
//               scope alınacak bir şey yok; uq_teams_name (name) indeksi ve
//               admin/create_team.php'deki kontrol DOĞRU. Ekip adı KVKK
//               izolasyonunun kimliği, iki "TY" ayırt edilemez olurdu.
//   users.email GLOBAL kalır — e-posta kimlik bilgisidir, giriş anahtarıdır.
//   records     İSMİ YOKTUR (EAV modeli, birincil alan bir hücre değeridir).
//
// ⚠️ BÜYÜK/KÜÇÜK HARF: tablolar utf8mb4_unicode_ci ile karşılaştırır, yani
// "Users" ile "users" AYNI sayılır ve çakışır. Bu bilinçli — kullanıcı için
// ayırt edilemez iki isim ayrı sayılmamalı. Uygulama kontrolü ile veritabanı
// indeksi AYNI kuralı uygular, ikisi ayrışamaz.
$GLOBALS['BCC_NAME_SCOPES'] = array(
    'bases'       => array('scope' => 'team_id',  'soft_delete' => true,  'label' => 'çalışma alanında'),
    'tables_meta' => array('scope' => 'base_id',  'soft_delete' => false, 'label' => "base'de"),
    'fields'      => array('scope' => 'table_id', 'soft_delete' => false, 'label' => 'tabloda'),
    'views'       => array('scope' => 'table_id', 'soft_delete' => false, 'label' => 'tabloda'),
);

// $entity: BCC_NAME_SCOPES anahtarı. $scopeId: üst yapının id'si. $excludeId:
// GÜNCELLEMEDE kaydın KENDİSİ — verilmezse "Tablo 1"i yine "Tablo 1" yapmak
// kendi kendine çakışır ve yeniden adlandırma hiç kaydedilemezdi.
//
// GÜVENLİK: tablo ve kolon adları SQL'e gömülür, ama YALNIZCA yukarıdaki
// whitelist'ten gelir — istekten gelen hiçbir değer buraya ulaşmaz (view_type
// whitelist'iyle AYNI desen). İsim ve id'ler prepared statement'a bağlanır.
function bcc_name_taken($entity, $scopeId, $name, $excludeId = null)
{
    if (!isset($GLOBALS['BCC_NAME_SCOPES'][$entity])) {
        // Programlama hatası (whitelist dışı varlık) — sessizce "boşta" demek
        // benzersizliği SESSİZCE kapatırdı, o yüzden gürültülü başarısızlık.
        throw new InvalidArgumentException('Bilinmeyen isim scope\'u: ' . $entity);
    }

    $cfg = $GLOBALS['BCC_NAME_SCOPES'][$entity];

    $sql = 'SELECT id FROM ' . $entity . ' WHERE ' . $cfg['scope'] . ' = :scope_id AND name = :name';
    $params = array('scope_id' => $scopeId, 'name' => trim((string) $name));

    // Çöp kutusundaki bir base ismi BLOKE ETMEZ: kullanıcı sildiği bir base'in
    // adını yeniden kullanabilmeli. (Bu yüzden bases'te veritabanı indeksi de
    // YOK — MySQL'de kısmi/koşullu UNIQUE index desteklenmiyor, indeks silinmiş
    // satırları da sayardı. Bkz. migrations/019.)
    if ($cfg['soft_delete']) {
        $sql .= ' AND deleted_at IS NULL';
    }

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = (int) $excludeId;
    }

    return bcc_fetch_one($sql . ' LIMIT 1', $params) !== false;
}

// Duplicate durumunda gösterilecek mesaj. Scope'u AÇIKÇA söyler, çünkü asıl
// kafa karışıklığı "ama başka base'de bu isim var, neden şimdi olmuyor?"
// sorusudur — mesaj kuralın kapsamını da öğretmeli.
function bcc_name_taken_error($entity, $what)
{
    $label = isset($GLOBALS['BCC_NAME_SCOPES'][$entity]) ? $GLOBALS['BCC_NAME_SCOPES'][$entity]['label'] : 'kapsamda';

    return 'Bu ' . $what . ' adı aynı ' . $label . ' zaten kullanılıyor.';
}

// Base oluşturmanın TEK yolu: public/bases.php'nin klasik form POST'u VE
// Home'daki "+ Yeni Base Oluştur" kartının AJAX uçnoktası (api/base_create.php)
// ikisi de burayı çağırır — doğrulama, INSERT ve audit kaydı tek yerdedir.
//
// YETKİ KONTROLÜ BURADA YAPILMAZ: çağıran taraf, kendi hata biçimine uygun
// şekilde (HTML sayfası -> require_role()'un 403 die'ı; JSON uçnoktası ->
// json_fail()) bcc_can_manage_bases() ile ÖNCEDEN reddetmiş olmalıdır. Bu
// fonksiyon yalnızca yetkisi doğrulanmış istekle çağrılır.
//
// Dönüş: array('ok' => bool, 'error' => string|null, 'id' => int|null)
function bcc_create_base($teamId, $name, $description, $userId)
{
    $name = trim((string) $name);
    $description = trim((string) $description);

    if ($name === '') {
        return array('ok' => false, 'error' => 'Base adı boş olamaz.', 'id' => null);
    }

    // bases.name VARCHAR(150) / description VARCHAR(500) — bu kontroller olmadan
    // uzun bir ad, sql_mode'da STRICT_TRANS_TABLES kapalı olduğu için hatasız
    // SESSİZCE kırpılıyordu (aynı gerekçe base_tables.php'de de yazılı).
    if (mb_strlen($name, 'UTF-8') > 150) {
        return array('ok' => false, 'error' => 'Base adı en fazla 150 karakter olabilir.', 'id' => null);
    }

    if (mb_strlen($description, 'UTF-8') > 500) {
        return array('ok' => false, 'error' => 'Açıklama en fazla 500 karakter olabilir.', 'id' => null);
    }

    // Aynı çalışma alanında aynı base adı olamaz — ama BAŞKA bir çalışma
    // alanında aynı ad serbesttir (bkz. bcc_name_taken() scope haritası).
    if (bcc_name_taken('bases', $teamId, $name)) {
        return array('ok' => false, 'error' => bcc_name_taken_error('bases', 'base'), 'id' => null);
    }

    bcc_execute(
        'INSERT INTO bases (team_id, name, description, created_by) VALUES (:team_id, :name, :description, :created_by)',
        array(
            'team_id' => $teamId,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'created_by' => $userId,
        )
    );

    $newId = (int) bcc_last_insert_id();
    log_audit('base.create', 'base', $newId, array('name' => $name), $teamId);

    return array('ok' => true, 'error' => null, 'id' => $newId);
}

// dashboard.php (Home) ve starred.php (Starred) ortak kullanır — "kod tekrarı
// yok" kuralı gereği iki ayrı sayfada aynı satır/kart döngüsü YAZILMAZ.
function bcc_home_relative_date($datetimeStr)
{
    $ts = strtotime((string) $datetimeStr);
    if ($ts === false) {
        return '';
    }

    $days = intdiv(time() - $ts, 86400);

    if ($days <= 0) {
        return 'Bugün';
    }
    if ($days === 1) {
        return 'Dün';
    }
    if ($days < 30) {
        return $days . ' gün önce';
    }

    $months = intdiv($days, 30);
    if ($months < 12) {
        return $months . ' ay önce';
    }

    return intdiv($months, 12) . ' yıl önce';
}

// Base kimlik paleti — TEK kaynak. Her satır AYNI rengin üç sunumu:
//   solid   : küçük kroma yerlerinde (grid.php üst barı, interface.php nav'ı)
//             kullanılan dolu çip rengi — beyaz glif üstünde.
//   bg/fg   : kart ikonu için açık temadaki pastel zemin + üstüne okunan koyu
//             mürekkep. bgDark/fgDark koyu temanın karşılıkları (aynı pastelin
//             #e8f0fe gibi bir değeri koyu temada göz alıyordu; bu yüzden renk
//             sabit basılmaz, aşağıdaki bcc_base_icon_style_attr() DÖRDÜNÜ DE
//             CSS değişkeni olarak basar, tema seçimini home.css yapar).
// solid ve bg AYNI satırda durduğu için ikisi ASLA ayrışamaz: bir base
// dashboard kartında "mavi" ise grid üst barında da "mavi"dir.
$GLOBALS['BCC_BASE_ICON_THEMES'] = array(
    array('solid' => '#2D7FF9', 'bg' => '#e8f0fe', 'fg' => '#1a56db', 'bgDark' => '#1b2a4a', 'fgDark' => '#8ab4f8'),
    array('solid' => '#8b5cf6', 'bg' => '#f3ebfd', 'fg' => '#6b3fa0', 'bgDark' => '#2b2140', 'fgDark' => '#c4a7ec'),
    array('solid' => '#f59e0b', 'bg' => '#fff4e0', 'fg' => '#a35c00', 'bgDark' => '#3a2c14', 'fgDark' => '#e8b96a'),
    array('solid' => '#10b981', 'bg' => '#e4f6ec', 'fg' => '#1b7e3c', 'bgDark' => '#18321f', 'fgDark' => '#7ac98d'),
    array('solid' => '#ef4444', 'bg' => '#fdeaea', 'fg' => '#c62828', 'bgDark' => '#3a1e1e', 'fgDark' => '#ef9a9a'),
    array('solid' => '#06b6d4', 'bg' => '#e2f4f8', 'fg' => '#0e6c80', 'bgDark' => '#14313a', 'fgDark' => '#7fd0e0'),
);

// Base ikonunun rengi — base'in KENDİ id'sinden deterministik türetilir, bu
// yüzden AYNI base dashboard/starred/interface.php dahil HER YERDE HER ZAMAN
// aynı renkte görünür. Önceki hâl (listedeki sıraya göre $i % count(...))
// kaldırıldı — o yöntemde aynı base, listede farklı bir sırada göründüğünde
// (ör. farklı kullanıcı, farklı sıralama) FARKLI renk gösterebiliyordu.
function bcc_base_icon_theme($baseId)
{
    $themes = $GLOBALS['BCC_BASE_ICON_THEMES'];
    return $themes[(int) $baseId % count($themes)];
}

// Geriye dönük uyumlu sarmalayıcı (grid.php / interface.php dolu çip rengini
// bununla basar) — artık kendi paletini TAŞIMAZ, üstteki tek tablodan okur.
function bcc_base_icon_color($baseId)
{
    $theme = bcc_base_icon_theme($baseId);
    return $theme['solid'];
}

// Kart ikonunun zemin/mürekkep çiftini CSS değişkeni olarak basar. Renk
// doğrudan `background:` olarak basılmaz — koyu temada pastel zeminin
// değişmesi gerekiyor ve bir inline `background` her CSS kuralını yenerdi.
// Karşılığı home.css'teki .home-base-icon kuralı (üç tema durumu: açık,
// data-theme="dark", prefers-color-scheme).
function bcc_base_icon_style_attr($baseId)
{
    $t = bcc_base_icon_theme($baseId);

    // --bi-solid: paletin CANLI ana rengi. Home kartındaki ikon rozeti zemini
    // artık hazır pastelden (--bi-bg) değil, bu rengin YARI SAYDAM bir
    // karışımından üretiliyor (home.css, color-mix) — böylece rozet base'in
    // marka rengini taşır ve kartın zeminine göre kendini ayarlar. --bi-bg
    // yine basılıyor: color-mix desteklemeyen tarayıcıda yedek o.
    return '--bi-solid: ' . $t['solid']
        . '; --bi-bg: ' . $t['bg'] . '; --bi-fg: ' . $t['fg']
        . '; --bi-bg-dark: ' . $t['bgDark'] . '; --bi-fg-dark: ' . $t['fgDark'] . ';';
}

// Base adından ikon kategorisi. Not: bases tablosunda "tip"/"kategori" diye bir
// KOLON YOK (bkz. schema.sql) ve bu iş için DDL eklenmedi — kategori, base'in
// ADINDAN türetilir. Kural: aşağıdaki liste SIRAYLA taranır, İLK eşleşen kazanır;
// bu yüzden alana özgü sözcükler (rol, export, finans...) genel olan 'test'ten
// ÖNCE gelir — "Export Test" dışa aktarma ikonunu alır, "RoleTest Base" yetki
// ikonunu, hiçbiri eşleşmeyen "Arşiv" ise varsayılan veritabanı ikonunu.
// Eşleşme küçük harfe indirgenmiş ad üzerinde substring'dir; Türkçe sözcüklerin
// hem şapkalı hem şapkasız yazımı listede vardır (kullanıcı "butce" de yazar).
function bcc_base_icon_category($baseName)
{
    $name = mb_strtolower((string) $baseName, 'UTF-8');

    $rules = array(
        'shield' => array('rol', 'role', 'yetki', 'izin', 'permission', 'admin', 'personel', 'calisan', 'çalışan'),
        'export' => array('export', 'import', 'aktar', 'csv', 'xlsx', 'yedek'),
        'users' => array('crm', 'musteri', 'müşteri', 'satis', 'satış', 'sales', 'customer', 'lead', 'uye', 'üye'),
        'receipt' => array('finans', 'fatura', 'muhasebe', 'butce', 'bütçe', 'budget', 'invoice', 'gider', 'masraf', 'odeme', 'ödeme'),
        'package' => array('stok', 'envanter', 'inventory', 'urun', 'ürün', 'product', 'depo', 'katalog'),
        'calendar' => array('takvim', 'calendar', 'etkinlik', 'event', 'toplanti', 'toplantı', 'randevu', 'ajanda'),
        'layout' => array('proje', 'project', 'gorev', 'görev', 'task', 'sprint', 'roadmap', 'plan'),
        'flask' => array('test', 'deneme', 'demo', 'sandbox', 'qa'),
    );

    foreach ($rules as $category => $needles) {
        foreach ($needles as $needle) {
            if (mb_strpos($name, $needle, 0, 'UTF-8') !== false) {
                return $category;
            }
        }
    }

    return 'database';
}

// Kategori -> Lucide (lucide.dev, ISC) çizim yolları. TAMAMI 24x24 viewBox'ta,
// dolgu değil ÇİZGİ (stroke) — bu yüzden renk `currentColor`dan gelir ve tek bir
// glif hem pastel zeminli kartta (koyu mürekkep) hem grid.php'nin dolu çipinde
// (beyaz, bkz. grid-shell.css .gs-base-icon { color: #fff }) doğru görünür,
// ikinci bir kopya gerekmez.
$GLOBALS['BCC_BASE_ICON_PATHS'] = array(
    'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'export' => '<path d="M12 17V3"/><path d="m6 11 6 6 6-6"/><path d="M19 21H5"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
    'package' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'layout' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
    'flask' => '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
);

// Dashboard/Starred kartındaki ikonun SVG'si — grid.php üst barı ve
// interface.php'nin base menüsünde de AYNEN kullanılır, ikinci bir
// kopya YOK. $baseName verilmezse (veya kategori bilinmiyorsa) varsayılan
// veritabanı gliftir — eski tek-argümanlı çağrılar bu yüzden bozulmaz.
function bcc_base_icon_svg($size = 20, $baseName = null)
{
    $category = $baseName === null ? 'database' : bcc_base_icon_category($baseName);
    $paths = $GLOBALS['BCC_BASE_ICON_PATHS'];
    $d = isset($paths[$category]) ? $paths[$category] : $paths['database'];

    // stroke-width ikon küçüldükçe orantılı incelmesin diye 24'lük ızgarada
    // sabit 1.8'dir; 14px'te de 20px'te de aynı optik ağırlıkta görünür.
    return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true">' . $d . '</svg>';
}

// Bir base'in glif ÇİZİM YOLLARI (dış <svg> kabuğu olmadan). bcc_base_icon_svg()
// ile AYNI kategori tablosunu okur — favicon'u istemcide kurabilmek için
// (assets/page-identity.js) yalnızca iç yollar gerekiyor, kabuğu o kendi
// boyutunda/renginde yeniden çiziyor.
function bcc_base_icon_paths($baseName = null)
{
    $category = $baseName === null ? 'database' : bcc_base_icon_category($baseName);
    $paths = $GLOBALS['BCC_BASE_ICON_PATHS'];

    return isset($paths[$category]) ? $paths[$category] : $paths['database'];
}

// ---- Sekme kimliği: <title> + favicon ------------------------------------
//
// Sekme biçimi: "[Base]: [Tablo/Görünüm] — opsflow.bcccrm.com".
//
// Marka metni burada LİTERAL YAZILMAZ: bcc_brand_domain() (config/app.php)
// tek kaynaktır. Bu dosya bootstrap'ta config/app.php'den ÖNCE include edilir,
// ama sorun değil — fonksiyon gövdesi ancak istek anında, bootstrap bittikten
// sonra çalışır.
//
// Başlık SUNUCUDA basılır (JS'siz de doğrudur ve sayfa açılırken yanlış bir
// başlığın bir an görünüp düzelmesi — "title flash" — hiç yaşanmaz). Favicon
// ise istemcide üretilir: rengi (base id'sinden) ve glifi (base adından)
// birleştirip bir data: URI kurmak gerekiyor, ayrıca aynı yardımcı ileride
// sayfa yenilenmeden base/tablo değiştirmede tekrar çağrılabilsin.
//
// DİKKAT: aşağıdaki biçim page-identity.js'teki updatePageTitle() ile
// birebir AYNI olmalı — biri değişirse diğeri de değişmeli.
function bcc_page_title($baseName, $contextName = null)
{
    $base = trim((string) $baseName);
    $ctx = trim((string) $contextName);
    $brand = bcc_brand_domain();

    if ($base === '') {
        return $ctx !== '' ? $ctx . ' — ' . $brand : $brand;
    }

    return $ctx !== ''
        ? $base . ': ' . $ctx . ' — ' . $brand
        : $base . ' — ' . $brand;
}

// page-identity.js'in okuduğu <meta> etiketleri. Base ikonu VERİTABANINDA
// TUTULMUYOR: glif base ADINDAN (bcc_base_icon_category), renk base ID'sinden
// (bcc_base_icon_theme) deterministik olarak türetilir — bu yüzden favicon,
// Home kartındaki ve grid üst barındaki çiple HER ZAMAN aynı çıkar, ayrıca
// senkronlanacak ikinci bir veri yoktur.
function bcc_page_identity_meta($baseId, $baseName, $contextName = null)
{
    $esc = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };

    // bcc-brand: page-identity.js başlığı sayfa yenilenmeden yeniden kurarken
    // marka metnini KENDİ İÇİNE yazmasın diye. Marka tek kaynak config/app.php;
    // JS onu buradan okur, ikinci bir literal kopya oluşmaz.
    return '<meta name="bcc-brand" content="' . $esc(bcc_brand_domain()) . '">' . "\n"
        . '<meta name="bcc-base-name" content="' . $esc($baseName) . '">' . "\n"
        . '<meta name="bcc-context-name" content="' . $esc($contextName) . '">' . "\n"
        . '<meta name="bcc-base-color" content="' . $esc(bcc_base_icon_color($baseId)) . '">' . "\n"
        . '<meta name="bcc-base-icon" content="' . $esc(bcc_base_icon_paths($baseName)) . '">';
}

// Tek bir base kartı (Home'daki .home-base-grid VE Starred sayfasında AYNI
// şekilde kullanılır). $isStarred true ise yıldız butonu hover'dan bağımsız
// hep görünür kalır (CSS: .home-base-star-btn[aria-pressed="true"]).
// $canDelete: bu base'in takımında 'owner' rolündeyse true — Trash özelliği
// (OpsFlow davranışı: yalnızca Owner silebilir/geri yükleyebilir), "⋯"
// menüsündeki "Sil" öğesi buna göre gösterilir/gizlenir.
// $role: kullanıcının bu base'in çalışma alanındaki rolü ('owner'|'editor'|
// 'commenter'|'viewer'|null) — kartın sağ üstündeki rol rozeti için. null ise
// rozet hiç basılmaz.
// Sol paneldeki "Yıldızlı base'ler" listesi — [['id'=>.., 'name'=>..], ...],
// alfabetik.
//
// TEK KAYNAK. Bu sorgu ONBİR ayrı sayfada (dashboard, starred, workspaces,
// account, bases, base_tables, form_edit, slack_settings, table_fields ve dört
// admin sayfası) BİREBİR kopyalanmıştı; kopyalar yalnızca yerel değişken adında
// ($teamIds / $teamIdsForStar) ve boşlukta ayrışıyordu.
//
// Bulunan gerçek bug: kopyalamanın kaçınılmaz sonucu, bloğu EKLEMEYİ UNUTAN bir
// sayfaydı. form_edit.php'de tam olarak bu oldu — kabuk partial'ı $starredBases
// üzerinde foreach çalıştırdı, değişken tanımsızdı ve sol paneldeki liste
// SESSİZCE boş kaldı (display_errors kapalı olduğu için ekranda hiçbir uyarı
// yok; Apache error.log'unda 11-12 Ağustos boyunca "Undefined variable:
// starredBases" + "Invalid argument supplied for foreach()" satırları birikti).
// Partial'a eklenen array() varsayılanı SEMPTOMU susturur, NEDENİ değil: liste
// yine boş kalırdı. Asıl çözüm bu fonksiyonun kabuğun İÇİNDEN çağrılmasıdır
// (bkz. src/partials/home_shell_top.php) — böylece bir sayfanın "unutması"
// artık MÜMKÜN DEĞİL.
//
// Takım süzgeci HER SEFERİNDE yeniden uygulanır: user_starred_bases.base_id
// CASCADE'i yalnızca BASE silinince temizler, kullanıcının takımdan AYRILMASINI
// değil. Bu yüzden b.team_id IN (...) koşulu şart — DB'de saklı bir erişim
// bayrağına güvenilmez (dashboard.php'nin ana base sorgusuyla AYNI ilke).
// deleted_at IS NULL: Trash'e taşınmış base sol panelde görünmemeli.
//
// Takım listesi current_user_team_ids()'ten gelir (auth.php'de zaten statik
// olarak önbelleklenir), çağıranın kendi $teams sorgusundan DEĞİL — her sayfada
// o liste zaten "bu kullanıcının üyelikleri" ile birebir aynıydı.
//
// Sonuç istek başına bir kez hesaplanır: kabuk her sayfada bir kez çağırıyor,
// ama dashboard.php yıldız durumunu kartlara dağıtmak için AYNI veriyi kabuktan
// ÖNCE de istiyor — statik önbellek ikinci bir sorguyu engeller.
function bcc_starred_bases_for_current_user($forceReload = false)
{
    static $cache = null;

    if ($cache !== null && !$forceReload) {
        return $cache;
    }

    $user = current_user();
    $teamIds = current_user_team_ids();

    if ($user === null || empty($teamIds)) {
        $cache = array();

        return $cache;
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $cache = bcc_fetch_all(
        "SELECT b.id, b.name
         FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($placeholders) AND b.deleted_at IS NULL
         WHERE usb.user_id = ?
         ORDER BY b.name",
        array_merge($teamIds, array((int) $user['id']))
    );

    return $cache;
}

// bcc_starred_bases_for_current_user()'ın id => true haritası hâli — kart
// grid'i "bu base yıldızlı mı?" sorusunu O(1) sorar. Ayrı bir sorgu AÇMAZ,
// yukarıdaki önbellekten türetir.
function bcc_starred_base_ids_for_current_user()
{
    $ids = array();
    foreach (bcc_starred_bases_for_current_user() as $row) {
        $ids[(int) $row['id']] = true;
    }

    return $ids;
}

// Base kartındaki "N tablo" rozeti için tablo sayıları. TEK sorgu, GROUP BY —
// kart başına ayrı sorgu (N+1) AÇILMAZ; çağıran bütün base id'lerini bir kerede
// verir ve haritayı kartlara dağıtır. tables_meta'da soft-delete kolonu yok
// (bkz. SHOW COLUMNS), bu yüzden düz COUNT(*) doğru sayıdır.
function bcc_base_table_counts($baseIds)
{
    if (empty($baseIds)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($baseIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT base_id, COUNT(*) AS c FROM tables_meta WHERE base_id IN ($placeholders) GROUP BY base_id",
        array_map('intval', $baseIds)
    );

    // Hiç tablosu OLMAYAN base GROUP BY sonucunda HİÇ SATIR ÜRETMEZ. Sıfırlar
    // burada doldurulmazsa o kart rozetsiz kalır ve kartlar arasında tutarsız
    // görünür ("1 tablo" olan var, hiç olmayan var). İstenen id kümesini
    // bildiğimiz için boşluklar 0 ile kapatılıyor — bu bir varsayım değil,
    // sorgunun kesin sonucu.
    $out = array();
    foreach ($baseIds as $id) {
        $out[(int) $id] = 0;
    }
    foreach ($rows as $r) {
        $out[(int) $r['base_id']] = (int) $r['c'];
    }

    return $out;
}

// $variant: 'feature' (bento'daki 2x2 büyük kart) | 'standard' (1x1).
// Varsayılan 'standard' — starred.php/workspaces.php gibi çağıranlar imzayı
// değiştirmeden aynı kartı almaya devam eder.
// $tableCount: bcc_base_table_counts() haritasından gelen sayı; null ise rozet
// hiç basılmaz (uydurma sayı YOK).
function bcc_render_home_base_card($base, $iconColor, $isStarred, $workspaceName, $canDelete = false, $role = null, $variant = 'standard', $tableCount = null)
{
    // $workspaceName artık BASILMIYOR (kasıtlı) — OpsFlow davranışı Workspace
    // kolonunun başlığını korur ama hücreyi hep boş bırakıyor, bizde de aynı;
    // parametre imzası geriye dönük uyumluluk için duruyor (çağıranlar hâlâ
    // $teamNamesById hesaplayıp geçiriyor), yalnızca çıktı kaldırıldı.
    // $iconColor de artık DOĞRUDAN basılmıyor: zemin/mürekkep çifti (koyu tema
    // dahil) bcc_base_icon_style_attr()'dan CSS değişkeni olarak gelir, çünkü
    // inline bir `background` koyu tema kuralını yenerdi. Parametre yine imzada
    // duruyor — çağıranlar değişmesin diye.
    unset($workspaceName, $iconColor);
    $isFeature = ($variant === 'feature');
    $description = isset($base['description']) ? trim((string) $base['description']) : '';
    ?>
    <a class="home-base-card<?php echo $isStarred ? ' is-starred' : ''; ?><?php echo $isFeature ? ' home-base-card--feature' : ''; ?>" href="/base.php?base_id=<?php echo (int) $base['id']; ?>" data-base-id="<?php echo (int) $base['id']; ?>" style="<?php echo htmlspecialchars(bcc_base_icon_style_attr($base['id']), ENT_QUOTES, 'UTF-8'); ?>">
        <?php
        // "Kapak": base'lerde görsel YOK (DB'de böyle bir kolon hiç olmadı), bu
        // yüzden Framer'ın önizleme görselinin karşılığı kartın KENDİ deterministik
        // renginden (--bi-solid) color-mix ile türetiliyor + kategori glifi filigran.
        // Uydurma bir görsel/stok resim kullanılmadı; renk zaten o base'i her yerde
        // temsil eden değerin ta kendisi.
        ?>
        <span class="home-base-cover" aria-hidden="true">
            <span class="home-base-cover-glyph"><?php echo bcc_base_icon_svg($isFeature ? 64 : 34, $base['name']); ?></span>
        </span>
        <div class="home-base-icon">
            <?php echo bcc_base_icon_svg(20, $base['name']); ?>
        </div>
        <div class="home-base-info">
            <div class="home-base-name"><?php echo htmlspecialchars($base['name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($isFeature && $description !== ''): ?>
                <?php // Açıklama SUNUCUDA zaten sorgulanıyordu ama hiç basılmıyordu. ?>
                <div class="home-base-desc"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($tableCount !== null): ?>
                <div class="home-base-stats"><span class="home-base-stat"><?php echo (int) $tableCount; ?> tablo</span></div>
            <?php endif; ?>
            <div class="home-base-meta">
                <span class="home-base-meta-star" aria-hidden="true">
                    <svg width="11" height="11" viewBox="0 0 20 20"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" fill="#f5b400"/></svg>
                </span>
                <?php
                // "Açıldı" — base.open audit_log kayıtlarından gelen GERÇEK son
                // açılma zamanı (bkz. dashboard.php/starred.php'nin al.last_opened
                // LEFT JOIN'i) — hiç açılmamışsa (NULL, kimse log_base_open()
                // tetiklememiş) tek geriye düşüş b.created_at'tir. Önceden burada
                // her zaman created_at kullanılıyordu — bu yüzden bugün açılan bir
                // base bile eski oluşturulma tarihini gösteriyordu, filtre
                // (üstteki Bugün/7 gün/30 gün) doğru veriyi kullandığı için o
                // taraf hep doğruydu, yalnızca bu metin yanlıştı.
                $lastOpenedDisplay = !empty($base['last_opened']) ? $base['last_opened'] : $base['created_at'];
                ?>
                Açıldı: <?php echo htmlspecialchars(bcc_home_relative_date($lastOpenedDisplay), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div class="home-base-workspace"></div>
        <?php if ($role !== null && isset($GLOBALS['BCC_ROLE_LABELS'][$role])): ?>
            <?php
            // Rol rozeti — kullanıcının BU base'in çalışma alanındaki yetkisi.
            // OpsFlow'un "assigned permission level"ının kart üstünde görünür
            // karşılığı: aynı listede farklı çalışma alanlarından base'ler yan
            // yana durabildiği için, hangisinde neyi yapabildiği (ör. yalnızca
            // Owner'da "Sil" çıkması) kartın kendisinden okunabilsin diye.
            // Renkler settings-page.css'teki rol hapıyla AYNI token'lardan
            // gelir ama sınıf ADI ayrıdır: o kurallar ayarlar sayfası
            // kabuğunun altına kapsanmış, Home o kabuğu kullanmıyor.
            // Liste modunda BASILIR ama gizlenir (home.css) — kolon başlıklarıyla
            // piksel hizası bozulmasın diye.
            ?>
            <span class="home-base-role home-base-role--<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($GLOBALS['BCC_ROLE_LABELS'][$role], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        <?php endif; ?>
        <div class="home-base-card-actions">
            <button type="button" class="home-base-star-btn" aria-label="Favorilere ekle/çıkar" aria-pressed="<?php echo $isStarred ? 'true' : 'false'; ?>">
                <svg width="16" height="16" viewBox="0 0 20 20" class="home-base-star-icon"><path d="M10 2.5l2.3 4.9 5.2.7-3.8 3.8.9 5.4L10 14.7l-4.6 2.6.9-5.4-3.8-3.8 5.2-.7L10 2.5z" stroke-width="1.4" stroke-linejoin="round"/></svg>
            </button>
            <details class="home-base-more-menu">
                <summary class="home-base-more-btn" aria-label="Diğer aksiyonlar">
                    <svg width="16" height="16" viewBox="0 0 20 20"><circle cx="4" cy="10" r="1.6" fill="#5f6368"/><circle cx="10" cy="10" r="1.6" fill="#5f6368"/><circle cx="16" cy="10" r="1.6" fill="#5f6368"/></svg>
                </summary>
                <div class="home-base-more-panel">
                    <details class="home-base-more-submenu">
                        <summary class="home-base-more-item home-base-more-item-parent">
                            <span>Aç</span>
                            <svg class="home-base-more-caret" width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M4.5 2.5l3.5 3.5-3.5 3.5" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="home-base-more-submenu-panel">
                            <button type="button" class="home-base-more-item" data-nav-href="/interface.php?base_id=<?php echo (int) $base['id']; ?>">Duyuru</button>
                        </div>
                    </details>
                    <button type="button" class="home-base-more-item" disabled>Çoğalt</button>
                    <?php if ($canDelete): ?>
                    <div class="home-base-more-divider"></div>
                    <button type="button" class="home-base-more-item home-base-more-item-danger" data-base-delete="<?php echo (int) $base['id']; ?>">Sil</button>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </a>
    <?php
}

// "+ Yeni Base Oluştur" kutucuğu — grid'in SON hücresi olarak, yalnızca
// kullanıcının base ekleyebildiği (bcc_can_manage_bases) EN AZ BİR çalışma alanı
// varsa basılır. Bu bir <button>'dır, <a> değil: hedefi bir sayfa değil,
// dashboard.php'nin altındaki modal (home.js #home-create-base-modal'ı açar).
// JS kapalıysa bile bir çıkış yolu kalsın diye modal formunun kendisi normal bir
// POST hedefi (/bases.php) taşır — bkz. dashboard.php.
function bcc_render_home_create_base_tile()
{
    ?>
    <button type="button" class="home-base-card home-base-create" id="home-create-base-btn">
        <span class="home-base-icon home-base-create-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
        </span>
        <span class="home-base-info">
            <span class="home-base-name">Yeni Base Oluştur</span>
            <span class="home-base-meta">Boş bir base ile başlayın</span>
        </span>
    </button>
    <?php
}

// Base grid'in TAMAMI (boş durum VEYA liste-başlığı + kartlar) — Home ve
// Starred sayfaları AYNI fonksiyonu çağırır, yalnızca $bases/$emptyMessage
// farklıdır. $teamNamesById: team_id => takım adı (liste modu "Workspace"
// kolonu için).
// $canCreateBase: çağıran sayfa, kullanıcının base ekleyebildiği bir çalışma
// alanı olup olmadığını hesaplayıp geçer (bkz. dashboard.php). Varsayılan false
// — starred.php gibi "oluştur" akışı olmayan sayfalar imzalarını değiştirmeden
// eskisi gibi çalışır.
// $bento: true ise ilk kart 'feature' (2x2) olarak basılır ve ızgara bento
// kurallarına göre yerleşir. Varsayılan false — starred.php gibi çağıranlar
// bugünkü düz ızgarada kalır, imzalarını değiştirmeden.
//
// Kural TABANLI, sabit 3 karta göre DEĞİL: tek base'de feature + oluştur yan
// yana, 2-3'te 1 feature + kalanlar 1x1, 4+'ta yalnızca ilki feature ve geri
// kalanı auto-fill ile akar. Böylece 0/1/2/12 base'te de bozulmaz.
function bcc_render_home_base_grid($bases, $starredBaseIds, $teamNamesById, $emptyMessage, $roleByTeamId = array(), $canCreateBase = false, $bento = false, $tableCounts = array())
{
    if (empty($bases)) {
        ?>
        <div class="home-empty">
            <p><?php echo htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($canCreateBase): ?>
                <?php
                // Hiç base'i olmayan ama yetkisi olan kullanıcı için çıkmaz sokak
                // olmasın: boş durumun İÇİNDE de aynı tetikleyici (aynı id, aynı
                // modal) — grid hiç basılmadığı için oradaki kutucuk görünmezdi.
                ?>
                <button type="button" class="home-empty-create-btn" id="home-create-base-btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    Yeni Base Oluştur
                </button>
            <?php endif; ?>
        </div>
        <?php
        return;
    }
    ?>
    <div class="home-base-grid<?php echo $bento ? ' home-base-grid--bento' : ''; ?>" id="home-base-grid">
        <div class="home-list-header" aria-hidden="true">
            <div class="home-list-header-icon"></div>
            <div class="home-list-header-info">
                <div class="home-list-header-name">Ad</div>
                <div class="home-list-header-meta">Son açılma</div>
            </div>
            <div class="home-list-header-workspace">Çalışma alanı</div>
        </div>
        <?php foreach ($bases as $bIdx => $b):
            $isStarred = isset($starredBaseIds[(int) $b['id']]);
            $workspaceName = isset($teamNamesById[(int) $b['team_id']]) ? $teamNamesById[(int) $b['team_id']] : '';
            $iconColor = bcc_base_icon_color($b['id']);
            $role = isset($roleByTeamId[(int) $b['team_id']]) ? $roleByTeamId[(int) $b['team_id']] : null;
            // OpsFlow davranışı: base silme de ekleme ile AYNI yetki satırında
            // ("Add and delete bases…") — eşik tek yerde, bkz. src/auth.php.
            $canDelete = $role !== null && bcc_can_manage_bases($role);
            $variant = ($bento && $bIdx === 0) ? 'feature' : 'standard';
            $tc = isset($tableCounts[(int) $b['id']]) ? (int) $tableCounts[(int) $b['id']] : null;
            bcc_render_home_base_card($b, $iconColor, $isStarred, $workspaceName, $canDelete, $role, $variant, $tc);
        endforeach; ?>
        <?php if ($canCreateBase) { bcc_render_home_create_base_tile(); } ?>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// F3 — Duyuru (Interface / yayınlanmış görünüm), salt-okunur. public/interface.php
// VE public/api/interface_search.php AYNI bcc_interface_fetch_records()'u
// çağırır (paralel sorgu YOK) — arama yalnızca WHERE'e bir EXISTS koşulu ekler.
// ---------------------------------------------------------------------------

// "Özet" alanı sabit bir kurala göre seçilir (view/field ayarı YOK, DB'de
// böyle bir kavram hiç yoktu): tablodaki İLK long_text tipli alan — F3'ün
// detay panelinde zaten "Notes (içerik)" olarak aynı alan tam hâliyle
// gösteriliyor, listedeki özet onun kırpılmış önizlemesi. Yoksa null (özet
// satırı boş kalır, hata OLMAZ).
function bcc_interface_summary_field($fields)
{
    foreach ($fields as $f) {
        if ($f['field_type'] === 'long_text') {
            return $f;
        }
    }
    return null;
}

function bcc_fetch_cells_by_record($recordIds)
{
    if (empty($recordIds)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $cellRows = bcc_fetch_all(
        "SELECT record_id, field_id, value_text, value_number, value_date, value_json FROM cell_values WHERE record_id IN ($placeholders)",
        $recordIds
    );

    $cellsByRecord = array();
    foreach ($cellRows as $cell) {
        $cellsByRecord[$cell['record_id']][$cell['field_id']] = $cell;
    }

    return $cellsByRecord;
}

// bcc_fetch_cells_by_record() ile AYNI toplu-sorgu deseni ('attachment' alanları
// cell_values'ta değil, ayrı attachments tablosunda yaşadığı için paralel bir
// fonksiyon). Dönüş: $byRecord[record_id][field_id] = [{id,name,mime,size}, ...]
// — grid.php/record_add.php/view_export_xlsx.php/interface.php hepsi bunu çağırır,
// paralel bir sorgu yazılmaz.
function bcc_fetch_attachments_by_record($recordIds)
{
    if (empty($recordIds)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $rows = bcc_fetch_all(
        "SELECT id, record_id, field_id, original_name, mime_type, file_size
         FROM attachments WHERE record_id IN ($placeholders) ORDER BY id",
        $recordIds
    );

    $byRecord = array();
    foreach ($rows as $row) {
        $byRecord[$row['record_id']][$row['field_id']][] = array(
            'id' => (int) $row['id'],
            'name' => $row['original_name'],
            'mime' => $row['mime_type'],
            'size' => (int) $row['file_size'],
        );
    }

    return $byRecord;
}

// "Last Update" / "en yeni en üstte" sıralaması records.updated_at'e DEĞİL
// (yalnızca records satırının KENDİSİ değişince — ör. pozisyon — bumps olur,
// hücre düzenlemesi cell_values'a yazar, records'a DOKUNMAZ) MAX(cell_values.
// updated_at)'e dayanır — hücre içeriği değişince gerçekten "son güncelleme"
// ilerlesin diye. Hiç hücresi olmayan (yeni, boş) kayıt records.created_at'e düşer.
// Çok seviyeli gruplama: $records üzerinde TEK geçişte, sıralı gelen kayıtları
// iç içe bir ağaca böler. Segmentleme HAM DEĞER (group_raw_value_N, SQL'in
// GROUP BY değil ORDER BY ile getirdiği ham kolon) üzerinden karşılaştırılır;
// cell_display_text() yalnızca başlıkta GÖSTERİM için çağrılır, karşılaştırmaya
// hiç girmez. Bir seviyenin ham değeri bir önceki kayıttan farklıysa, o seviye
// VE ondan sonraki (daha iç) tüm seviyeler için yeni segment açılır — iç
// sayaçlar bu noktada sıfırlanır (bkz. $counters), böylece dıştaki bir grup
// değişince içteki "0-1" gibi bir path yanlışlıkla eski sayaçtan devam etmez.
// Tüm tipler (checkbox dahil — cell_display_text() artık 'İşaretli'/'İşaretsiz'
// döndürüyor) cell_display_text() ile (tarih formatı, seçim etiketleri vb.
// doğru çıksın diye) biçimlendirilir. (Empty) davranışı: tek seviyeli
// gruplamadaki gibi.
//
// Dönüş: düğüm dizisi. Her düğüm:
//   'level'    => 0 tabanlı seviye
//   'path'     => hiyerarşik segment yolu, ör. "0-2-1" (data-group-path'e gider)
//   'display'  => başlıkta gösterilecek metin
//   'count'    => bu düğümün altındaki TOPLAM kayıt sayısı (iç içe seviyelerde
//                 tüm alt dallardaki kayıtların toplamı)
//   'is_leaf'  => bu, gruplamanın en iç (son) seviyesi mi
//   'children' => is_leaf değilse, alt düğüm dizisi (aksi halde null)
//   'records'  => is_leaf ise, bu segmentteki kayıt dizisi (aksi halde null)
function bcc_build_grouped_tree($records, $groupRules, $usersById = array())
{
    $levelCount = count($groupRules);
    $tree = array();

    if ($levelCount === 0) {
        return $tree;
    }

    $openNodes = array();
    $counters = array_fill(0, $levelCount, -1);
    $prevKeys = null;

    foreach ($records as $record) {
        $keys = array();
        for ($lvl = 0; $lvl < $levelCount; $lvl++) {
            $keys[$lvl] = $record['group_raw_value_' . $lvl];
        }

        $divergeLevel = 0;
        if ($prevKeys !== null) {
            $divergeLevel = $levelCount; // sentinel: hiçbir seviye değişmedi
            for ($lvl = 0; $lvl < $levelCount; $lvl++) {
                if ($keys[$lvl] !== $prevKeys[$lvl]) {
                    $divergeLevel = $lvl;
                    break;
                }
            }
        }

        for ($lvl = $divergeLevel; $lvl < $levelCount; $lvl++) {
            $counters[$lvl] = ($lvl === $divergeLevel) ? $counters[$lvl] + 1 : 0;

            $rule = $groupRules[$lvl];
            $rawValue = $keys[$lvl];

            if ($rawValue === null) {
                $display = '(Boş)';
            } else {
                $display = cell_display_text($rule['field_type'], bcc_group_cell_row($rule['column'], $rawValue), $usersById, $rule['options']);
            }

            $isLeaf = ($lvl === $levelCount - 1);
            $node = array(
                'level' => $lvl,
                'path' => implode('-', array_slice($counters, 0, $lvl + 1)),
                'display' => $display,
                'count' => 0,
                'is_leaf' => $isLeaf,
                'children' => $isLeaf ? null : array(),
                'records' => $isLeaf ? array() : null,
            );

            if ($lvl === 0) {
                $tree[] = $node;
                $openNodes[0] = &$tree[count($tree) - 1];
            } else {
                $openNodes[$lvl - 1]['children'][] = $node;
                $openNodes[$lvl] = &$openNodes[$lvl - 1]['children'][count($openNodes[$lvl - 1]['children']) - 1];
            }
        }

        $openNodes[$levelCount - 1]['records'][] = $record;

        for ($lvl = 0; $lvl < $levelCount; $lvl++) {
            $openNodes[$lvl]['count']++;
        }

        $prevKeys = $keys;
    }

    unset($openNodes);

    return $tree;
}

function bcc_interface_fetch_records($tableId, $primaryFieldId, $summaryFieldId, $searchTerm = null)
{
    $sql = "SELECT r.id, r.created_at,
                   COALESCE((SELECT MAX(cv2.updated_at) FROM cell_values cv2 WHERE cv2.record_id = r.id), r.created_at) AS last_update
            FROM records r
            WHERE r.table_id = ?";
    $params = array($tableId);

    if ($searchTerm !== null && $searchTerm !== '') {
        // Arama, listede görünen KIRPILMIŞ önizlemede değil TAM içerikte
        // eşleşmeli — bu yüzden client-side DOM filtreleme değil, DB'deki
        // ham value_text üzerinde LIKE. Yalnızca birincil + özet alanı
        // taranır (F3: "hem başlıkta hem içerikte").
        $fieldIds = array_values(array_filter(array($primaryFieldId, $summaryFieldId)));
        if (empty($fieldIds)) {
            return array();
        }
        $fieldPlaceholders = implode(',', array_fill(0, count($fieldIds), '?'));
        // LIKE'ın kendi joker karakterleri (%, _) ve kaçış karakteri (\)
        // kullanıcı arama metninde LİTERAL kabul edilmeli — bulunan gerçek bug:
        // bu escape olmadan "50%off" araması, o metni hiç içermeyen ama "50" ve
        // "off"u ayrı yerlerde geçiren kayıtları da (% joker karakteri araya
        // her şeyi kabul ettiği için) yanlışlıkla eşleştiriyordu.
        $escapedTerm = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $searchTerm);
        $sql .= " AND EXISTS (
                SELECT 1 FROM cell_values cv
                WHERE cv.record_id = r.id
                  AND cv.field_id IN ($fieldPlaceholders)
                  AND cv.value_text LIKE ? ESCAPE '\\\\'
            )";
        $params = array_merge($params, $fieldIds, array('%' . $escapedTerm . '%'));
    }

    $sql .= ' ORDER BY last_update DESC, r.id DESC';

    return bcc_fetch_all($sql, $params);
}

// ---------------------------------------------------------------------------
// Çalışma alanı panosu (workspaces.php) — veri katmanı
// ---------------------------------------------------------------------------
// Sayfanın SORGULARI burada, HTML'i orada: workspaces.php şablon olarak kalsın
// ve bu sayaçlar ileride başka bir ekrandan (ör. dashboard) da okunabilsin.
// Hepsi $teamId ile sınırlı — KVKK izolasyonu çağıranın require_team_access()
// kontrolünün ARDINDAN, ikinci bir katman olarak burada da korunuyor.

// "Kullanım & Limitler" kartındaki doluluk çubuklarının REFERANS değerleri.
//
// ⚠️ BUNLAR ZORLANAN BİR KOTA DEĞİLDİR. Bu projede çalışma alanı başına kayıt/
// depolama/base sınırı uygulayan hiçbir kod YOK (gerçekten zorlanan tek
// sınırlar dosya başına 20MB yükleme ve 10MB xlsx içe aktarmadır — bkz.
// api/attachment_upload.php ve api/table_import_xlsx.php). Buradaki sayılar
// yalnızca "ne kadar doluyum" sorusuna görsel bir ölçek verir; kartın altında
// da kullanıcıya bunun zorlanmadığı AÇIKÇA yazılır. Bir gün gerçek kota
// gelirse, uygulayan kod da bu diziyi okumalı — o zaman tek kaynak olur.
$GLOBALS['BCC_WORKSPACE_SOFT_LIMITS'] = array(
    'records' => 50000,
    'storage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
    'bases' => 25,
);

// Yukarıdakiler ÇALIŞMA ALANI BAŞINA; bu ise KULLANICI BAŞINA (kaç çalışma
// alanına üye) — workspaces.php sol panelindeki kullanım rozeti için. Ayrı
// bir sabit, çünkü ölçtüğü eksen farklı; aynı diziye konsaydı "workspace
// başına workspace sayısı" gibi okunurdu.
//
// ⚠️ BU DA ZORLANAN BİR KOTA DEĞİL (yukarıdaki notun aynısı): kullanıcıyı
// N çalışma alanıyla sınırlayan hiçbir kod yok. Rozet bunu açıkça yazıyor ve
// üyelik eşiği aşarsa kesir hiç gösterilmiyor.
$GLOBALS['BCC_USER_WORKSPACE_SOFT_LIMIT'] = 5;

// Baytları okunabilir birime çevirir. account.php'den BURAYA TAŞINDI
// (bcc_account_format_bytes idi): artık iki sayfa da (hesap özeti + çalışma
// alanı kullanım kartı) aynı biçimlendirmeyi kullanıyor, ikinci bir kopya YOK.
function bcc_format_bytes($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = array('KB', 'MB', 'GB', 'TB');
    $value = $bytes / 1024;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return number_format($value, $value >= 10 ? 0 : 1, ',', '.') . ' ' . $units[$i];
}

// Bu kurulumda PHP ile MySQL AYNI SAATİ GÖSTERMİYOR: php.ini'de date.timezone
// boş (PHP UTC varsayıyor), MySQL ise yerel saati (UTC+3) döndürüyor. Bu
// yüzden bir DB zaman damgasını PHP'nin time()'ı ile karşılaştırmak 3 saatlik
// bir kayma üretiyordu — ölçüldü: en son yazılan audit satırı bile "-10228 sn"
// yani GELECEKTE görünüyor ve göreli ifade mutlak tarihe düşüyordu.
//
// Çözüm: referans saat de VERİTABANINDAN alınır, böylece iki taraf aynı
// saat diliminde. İstek başına TEK sorgu (static önbellek). php.ini'ye ya da
// global date_default_timezone_set()'e DOKUNULMADI — mevcut sayfalar
// (team_members.php, account.php...) DB damgalarını zaten date() ile
// basıyor ve global bir kaydırma onların hepsini sessizce değiştirirdi.
function bcc_db_now()
{
    static $now = null;

    if ($now === null) {
        $raw = bcc_fetch_column('SELECT NOW()');
        $now = $raw !== null && $raw !== false ? strtotime((string) $raw) : time();
    }

    return $now;
}

// "2 saat önce" — Türkçe göreli zaman. Gelecek tarihler ve 30 günden eskiler
// mutlak tarihe düşer (göreli ifade orada bilgi vermez, "412 gün önce" gibi).
//
// $now dışarıdan verilebilir (test edilebilirlik); verilmezse DB saati
// kullanılır — bkz. bcc_db_now() üstündeki kayma notu.
function bcc_time_ago($datetime, $now = null)
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }

    $ts = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
    if ($ts === false || $ts <= 0) {
        return '—';
    }

    $diff = ($now === null ? bcc_db_now() : (int) $now) - $ts;

    if ($diff < 0) {
        return date('d.m.Y', $ts);
    }
    if ($diff < 60) {
        return 'az önce';
    }
    if ($diff < 3600) {
        return ((int) floor($diff / 60)) . ' dakika önce';
    }
    if ($diff < 86400) {
        return ((int) floor($diff / 3600)) . ' saat önce';
    }
    if ($diff < 2592000) {
        return ((int) floor($diff / 86400)) . ' gün önce';
    }

    return date('d.m.Y', $ts);
}

// Çalışma alanındaki base'ler + kart ızgarası için gereken her şey.
//
// "Son değişiklik" TÜRETİLİYOR: bases tablosunda updated_at YOK (DDL
// eklenmiyor). Gerçek düzenleme sinyali cell_values.updated_at'tir
// (ON UPDATE CURRENT_TIMESTAMP) — hücre düzenlemesi olmayan yeni bir base
// için bases.created_at'e düşülür. Böylece "son değişiklik" uydurma değil,
// gerçekten yazılmış bir zaman damgasıdır.
//
// $userId verilirse her satıra is_starred bayrağı eklenir (yıldızlılar
// bölümü için) — ayrı bir sorgu açılmaz.
function bcc_workspace_bases($teamId, $userId = null)
{
    $teamId = (int) $teamId;

    return bcc_fetch_all(
        'SELECT
             b.id,
             b.name,
             b.description,
             b.created_at,
             (SELECT COUNT(*) FROM tables_meta tm WHERE tm.base_id = b.id) AS table_count,
             (SELECT COUNT(*)
                FROM records r
                INNER JOIN tables_meta tm2 ON tm2.id = r.table_id
               WHERE tm2.base_id = b.id AND r.deleted_at IS NULL) AS record_count,
             (SELECT MAX(cv.updated_at)
                FROM cell_values cv
                INNER JOIN records r2 ON r2.id = cv.record_id AND r2.deleted_at IS NULL
                INNER JOIN tables_meta tm3 ON tm3.id = r2.table_id
               WHERE tm3.base_id = b.id) AS last_edit_at,
             (SELECT COUNT(*) FROM user_starred_bases usb
               WHERE usb.base_id = b.id AND usb.user_id = :uid) AS is_starred
         FROM bases b
         WHERE b.team_id = :team_id AND b.deleted_at IS NULL
         ORDER BY b.name',
        array('team_id' => $teamId, 'uid' => (int) $userId)
    );
}

// Çalışma alanı kullanım sayaçları. account.php'nin hesap geneli sayaçlarıyla
// AYNI sorgu deseni, yalnızca tek ekiple sınırlı.
//
// slack_webhook_count: "aktif entegrasyon" göstergesi — gerçek bir satır
// sayısı, tahmin değil (slack_webhooks tablosu, bkz. slack_settings.php).
//
// DİKKAT: slack_webhooks'un KENDİ team_id'si var ve table_id NULL OLABİLİR
// (çalışma alanı geneli webhook). Bu yüzden tables_meta üzerinden JOIN
// YAPILMIYOR — yapılsaydı table_id'si NULL olan webhook'lar sessizce
// sayılmazdı. Yalnızca is_active = 1 olanlar "aktif entegrasyon" sayılır.
function bcc_workspace_usage($teamId)
{
    $teamId = (int) $teamId;
    $params = array('team_id' => $teamId);

    return array(
        'base_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM bases WHERE team_id = :team_id AND deleted_at IS NULL',
            $params
        ),
        'table_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM tables_meta tm
             INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
             WHERE b.team_id = :team_id',
            $params
        ),
        'record_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM records r
             INNER JOIN tables_meta tm ON tm.id = r.table_id
             INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
             WHERE b.team_id = :team_id AND r.deleted_at IS NULL',
            $params
        ),
        'storage_bytes' => (int) bcc_fetch_column(
            'SELECT COALESCE(SUM(a.file_size), 0) FROM attachments a
             INNER JOIN records r ON r.id = a.record_id AND r.deleted_at IS NULL
             INNER JOIN tables_meta tm ON tm.id = r.table_id
             INNER JOIN bases b ON b.id = tm.base_id AND b.deleted_at IS NULL
             WHERE b.team_id = :team_id',
            $params
        ),
        'slack_webhook_count' => (int) bcc_fetch_column(
            'SELECT COUNT(*) FROM slack_webhooks WHERE team_id = :team_id AND is_active = 1',
            $params
        ),
    );
}

// Çalışma alanı hareket akışı (workspaces.php "Son Hareketler" paneli).
//
// KAYNAK GERÇEK: audit_log — bu proje zaten her anlamlı eylemde log_audit()
// çağırıyor (record.create, cell.update, team_member.assign, view.create...).
// Uydurma/örnek satır YOK; tablo boşsa panel boş durumunu gösterir.
//
// GÜRÜLTÜ FİLTRESİ: 'base.open' (her sayfa açılışında yazılıyor, yüzlerce
// satır), 'user.login'/'user.logout' (çalışma alanına ait bir hareket değil)
// ve export izleri DIŞARIDA BIRAKILIR — akış "kim neyi DEĞİŞTİRDİ" sorusuna
// cevap versin, "kim ne zaman baktı"ya değil.
//
// N+1 YOK: sonuç sayfası (varsayılan 12 satır) toplandıktan SONRA içindeki
// base/tablo/kullanıcı id'leri TEK seferde toplu çözülür — satır başına sorgu
// açılmaz.
function bcc_workspace_activity($teamId, $limit = 12)
{
    $teamId = (int) $teamId;
    // LIMIT'e değişken bağlanamadığı için değer önce int'e çevrilip
    // 1..50 aralığına sıkıştırılıyor — dizgeye giren şey her zaman bir sayı.
    $limit = max(1, min(50, (int) $limit));

    $rows = bcc_fetch_all(
        "SELECT al.id, al.action, al.entity_type, al.entity_id, al.details, al.created_at,
                u.full_name AS actor_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.team_id = :team_id
           AND al.action NOT IN ('base.open', 'user.login', 'user.logout', 'view.export_xlsx', 'team_member.export_xlsx')
         ORDER BY al.id DESC
         LIMIT " . $limit,
        array('team_id' => $teamId)
    );

    if (empty($rows)) {
        return array();
    }

    // ---- Adları TOPLU çöz (N+1 yok) --------------------------------------
    $baseIds = array();
    $tableIds = array();
    $userIds = array();

    foreach ($rows as $r) {
        $d = bcc_audit_details($r['details']);
        if ($r['entity_type'] === 'base' && $r['entity_id']) {
            $baseIds[(int) $r['entity_id']] = true;
        }
        if (isset($d['base_id'])) {
            $baseIds[(int) $d['base_id']] = true;
        }
        if (isset($d['table_id'])) {
            $tableIds[(int) $d['table_id']] = true;
        }
        if ($r['entity_type'] === 'table' && $r['entity_id']) {
            $tableIds[(int) $r['entity_id']] = true;
        }
        if (isset($d['user_id'])) {
            $userIds[(int) $d['user_id']] = true;
        }
    }

    $baseNames = bcc_ids_to_names('bases', array_keys($baseIds));
    $tableNames = bcc_ids_to_names('tables_meta', array_keys($tableIds));
    $userNames = bcc_ids_to_names('users', array_keys($userIds), 'full_name');

    // ---- Satırları insan diline çevir ------------------------------------
    $out = array();
    foreach ($rows as $r) {
        $d = bcc_audit_details($r['details']);

        // Hedef adı: en belirgin olandan en genele doğru ilk dolu olan.
        $target = null;
        if (isset($d['name']) && is_string($d['name']) && $d['name'] !== '') {
            $target = $d['name'];
        } elseif (isset($d['field_name']) && is_string($d['field_name']) && $d['field_name'] !== '') {
            $target = $d['field_name'];
        } elseif (isset($d['table_id']) && isset($tableNames[(int) $d['table_id']])) {
            $target = $tableNames[(int) $d['table_id']];
        } elseif ($r['entity_type'] === 'table' && isset($tableNames[(int) $r['entity_id']])) {
            $target = $tableNames[(int) $r['entity_id']];
        } elseif ($r['entity_type'] === 'base' && isset($baseNames[(int) $r['entity_id']])) {
            $target = $baseNames[(int) $r['entity_id']];
        } elseif (isset($d['base_id']) && isset($baseNames[(int) $d['base_id']])) {
            $target = $baseNames[(int) $d['base_id']];
        } elseif (isset($d['user_id']) && isset($userNames[(int) $d['user_id']])) {
            $target = $userNames[(int) $d['user_id']];
        }

        $out[] = array(
            'id' => (int) $r['id'],
            'action' => $r['action'],
            'actor' => ($r['actor_name'] !== null && $r['actor_name'] !== '') ? $r['actor_name'] : 'Sistem',
            'label' => bcc_audit_action_label($r['action']),
            'kind' => bcc_audit_action_kind($r['action']),
            'target' => $target,
            'created_at' => $r['created_at'],
            'ago' => bcc_time_ago($r['created_at']),
        );
    }

    return $out;
}

// audit_log.details her zaman geçerli JSON olmayabilir (NULL, boş dizge veya
// eski bir satırdan bozuk içerik) — savunmacı çözüm, her zaman dizi döner.
function bcc_audit_details($raw)
{
    if ($raw === null || $raw === '') {
        return array();
    }
    $decoded = json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : array();
}

// id -> ad haritası, TEK sorguda. Boş id listesinde sorgu HİÇ açılmaz.
function bcc_ids_to_names($table, array $ids, $nameColumn = 'name')
{
    // $table/$nameColumn çağıran tarafından SABİT veriliyor (kullanıcı girdisi
    // değil) ama yine de whitelist'ten geçiyor — bcc_reorder_sibling() ile AYNI
    // disiplin: dinamik tanımlayıcı asla doğrudan SQL'e yazılmaz.
    $allowed = array(
        'bases' => 'name',
        'tables_meta' => 'name',
        'users' => 'full_name',
    );
    if (!isset($allowed[$table]) || $allowed[$table] !== $nameColumn) {
        throw new InvalidArgumentException('bcc_ids_to_names: izin verilmeyen tablo/kolon.');
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        return array();
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = bcc_fetch_all("SELECT id, {$nameColumn} AS nm FROM {$table} WHERE id IN ($ph)", $ids);

    $map = array();
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row['nm'];
    }

    return $map;
}

// audit_log action -> Türkçe etiket. Bilinmeyen action için action'ın KENDİSİ
// döner (sessizce boş bırakmak yerine) — yeni bir eylem eklendiğinde akış
// bozulmaz, yalnızca ham adı görünür ve buraya eklenmesi gerektiği anlaşılır.
function bcc_audit_action_label($action)
{
    $labels = array(
        'base.create' => 'yeni base oluşturdu',
        'base.delete' => 'base\'i çöpe taşıdı',
        'base.restore' => 'base\'i geri yükledi',
        'base.update' => 'base\'i güncelledi',
        'table.create' => 'yeni tablo oluşturdu',
        'table.update' => 'tabloyu güncelledi',
        'table.delete' => 'tabloyu sildi',
        'table.reorder' => 'tabloları yeniden sıraladı',
        'table.import_xlsx' => 'Excel\'den veri aktardı',
        'table.clear_data' => 'tablo verilerini temizledi',
        'field.create' => 'yeni alan ekledi',
        'field.update' => 'alanı güncelledi',
        'field.delete' => 'alanı sildi',
        'field.reorder' => 'alanları yeniden sıraladı',
        'record.create' => 'kayıt ekledi',
        'record.duplicate' => 'kaydı çoğalttı',
        'record.delete_soft' => 'kaydı çöpe taşıdı',
        'record.restore' => 'kaydı geri yükledi',
        'record.delete' => 'kaydı kalıcı sildi',
        'record.form_submit' => 'form üzerinden kayıt geldi',
        'record.send' => 'kaydı e-posta ile gönderdi',
        'cell.update' => 'hücre güncelledi',
        'comment.add' => 'yorum ekledi',
        'comment.update' => 'yorumu düzenledi',
        'comment.delete' => 'yorumu sildi',
        'view.create' => 'yeni görünüm oluşturdu',
        'view.delete' => 'görünümü sildi',
        'view.rename' => 'görünümü yeniden adlandırdı',
        'view.duplicate' => 'görünümü çoğalttı',
        'view.reorder' => 'görünümleri yeniden sıraladı',
        'view.config_update' => 'görünüm düzenini değiştirdi',
        'view.kanban_config' => 'kanban ayarını değiştirdi',
        'view.form_config' => 'form ayarını değiştirdi',
        'view.description_update' => 'görünüm açıklamasını değiştirdi',
        'view.favorite_toggle' => 'görünümü favoriledi',
        'view.save_state' => 'görünüm durumunu kaydetti',
        'team_member.assign' => 'çalışma alanına katılımcı ekledi',
        'team_member.role_change' => 'katılımcı rolünü değiştirdi',
        'team_member.remove' => 'katılımcıyı çıkardı',
        'attachment.upload' => 'dosya ekledi',
        'attachment.delete' => 'dosya ekini sildi',
        'slack.notify_sent' => 'Slack bildirimi gönderdi',
        'slack.notify_failed' => 'Slack bildirimi başarısız oldu',
        'slack.webhook_create' => 'Slack entegrasyonu ekledi',
        'slack.webhook_delete' => 'Slack entegrasyonunu kaldırdı',
        'slack.routing_rule_reorder' => 'Slack kurallarını sıraladı',
        'user.account_updated' => 'hesap bilgilerini güncelledi',
    );

    return isset($labels[$action]) ? $labels[$action] : $action;
}

// Akış satırının kategori sınıfı — eylem AİLESİNE göre (ayrı ayrı ikon seti
// taşımak yerine tek kategori). CSS .wsx-act-dot--<kategori> ile renklendirir.
function bcc_audit_action_kind($action)
{
    $byPrefix = array(
        'base' => 'base',
        'table' => 'table',
        'field' => 'field',
        'record' => 'record',
        'cell' => 'record',
        'comment' => 'comment',
        'view' => 'view',
        'team_member' => 'member',
        'attachment' => 'file',
        'slack' => 'slack',
    );

    $prefix = strtok((string) $action, '.');

    return isset($byPrefix[$prefix]) ? $byPrefix[$prefix] : 'other';
}
