# Değişen Dosyalar ve Veritabanı Tabloları

**Kapsam:** 2026-09-08, 2026-09-09 ve 2026-09-14 oturumları (canlıya alma
sonrası). Günlük anlatı `docs/gunluk/` altındaki aynı tarihli dosyalarda;
**bu dosya yalnızca envanter** — "neye dokunuldu" sorusunun tek bakışta
cevabı.

Son güncelleme: 2026-09-14 (Tab ile hücre gezinmesi, uyarı kutularındaki metin
kayması, kanban ayrılma pingi, 08-09 Eylül kodunun commit edilmesi).

---

## 1. Özet

| | Kod dosyası | Veritabanı |
|---|---|---|
| **2026-09-08** | 16 dosya (12 değiştirildi, 4 yeni) + 3 belge | `records` tablosuna **1 yeni kolon** |
| **2026-09-09** | 11 dosya (hepsi değiştirildi) + 2 belge | Yapısal değişiklik **yok**; 3 tabloya **veri/ayar** yazıldı |
| **2026-09-14** | **8 dosya** (6 değiştirildi, 2 yeni) + 3 belge | **Hiçbir değişiklik yok** — yalnızca `SELECT` |

---

## 2. Kod dosyaları — 2026-09-08

### 2.1 Commit'lenmiş: `2d8dd65`

"Oluşturan" sütunu düzeltmesi (günlük §4) + günlük not sisteminin kuralı.

| Dosya | Satır | Ne değişti |
|---|---|---|
| `src/schema.php` | +20 / -1 | **YENİ** `bcc_actor_name_by_id()`; `cell_display_text()` içinde `created_by` / `last_modified_by` dalı bu fonksiyona düşüyor |
| `CLAUDE.md` | +5 | Günlük not sistemine yönlendirme |
| `docs/PROJE-DURUM.md` | +5 | "Biten İşler"e satır |
| `docs/gunluk/README.md` | +38 | **YENİ** — günlük not kuralı ve şablonu |

### 2.2 Commit'lenmemiş — çalışma ağacında duruyor

Slack toplu bildirimi (günlük §5) + gözden geçirme düzeltmeleri (§6).
**Tarayıcıda doğrulanmadığı için bilerek commit'lenmedi.**

#### Değiştirilen (12 dosya)

| Dosya | Satır | Ne değişti |
|---|---|---|
| `src/slack.php` | +229 | **En büyük değişiklik.** Yeni sabitler `BCC_SLACK_BATCH_IDLE_SECONDS` (180), `BCC_SLACK_BATCH_MAX_RECORDS` (20), `BCC_SLACK_BATCH_MAX_FIELDS` (25), `BCC_SLACK_BATCH_TIME_BUDGET` (6 sn); yeni fonksiyonlar `bcc_slack_mark_records_notified()`, `bcc_slack_pending_records()`, `bcc_slack_changed_field_lines()`, `bcc_slack_flush_table()`; ekip kullanıcı haritası statik önbelleğe alındı (N+1 giderildi) |
| `public/api/cell_update.php` | +10 / -29 | Anında `bcc_notify_slack_cell_change()` **kaldırıldı** → `bcc_slack_flush_table()`. Eski değeri okuyan sorgu da silindi |
| `public/api/cells_bulk_update.php` | +24 | Kendi toplu özet mesajı **korundu**; dokunduğu/oluşturduğu satırlar damgalanıyor (çift duyuru olmasın) |
| `public/api/record_add.php` | +15 / -3 | Anında `bcc_notify_slack_new_record()` kaldırıldı; `count > 1` (toplu satır ekleme) damgalanıyor — tek satır **bilerek damgasız** |
| `public/api/record_duplicate.php` | +2 / -1 | Anında gönderim yerine boşaltma |
| `public/api/table_import_xlsx.php` | +13 | İçe aktarılan satır id'leri toplanıp damgalanıyor (500 satır → 500 mesaj hatası) |
| `public/grid.php` | +10 / -1 | JS'siz form fallback'inde anında gönderim kaldırıldı; sayfa yüklemesinde boşaltma (`:107`); yeni script etiketi (`:1759`) |
| `public/interface.php` | +7 | Sayfa yüklemesinde boşaltma (`:50`) |
| `public/kanban.php` | +6 / -1 | Sayfa yüklemesinde boşaltma (`:82`) — `kanban.js` de `cell_update.php` çağırıyor |
| `src/schema.php` | +9 | `bcc_duplicate_table()`: kopyalanan satırlar damgalanıyor (158 satırlık kopya → 158 mesaj hatası). **§2.1'deki değişikliğin ÜSTÜNE** |
| `schema.sql` | +8 | `records.slack_notified_at` kolonu (`:269`) + gerekçe yorumu |
| `scripts/_verify_slack_integration.php` | +19 / -3 | Eski "anında gönderiyor mu" iddiaları yeni tasarıma göre güncellendi |

#### Yeni, henüz git'e eklenmemiş (4 dosya)

| Dosya | Ne |
|---|---|
| `public/api/slack_flush.php` | Tarayıcı pingi için uç nokta. ⚠️ **`:37`'de bilinen hata** — bkz. §5 |
| `public/assets/grid-slack-flush.js` | Ayrılırken `sendBeacon` + boşta kalınca yoklama (60 sn) |
| `scripts/_verify_slack_batch.php` | 41 testlik regresyon paketi |
| `docs/gunluk/2026-09-08.md` | O günün notu |

---

## 3. Kod dosyaları — 2026-09-09

Oturumun ilk yarısı teşhisti (kod değişikliği yok). İkinci yarıda teşhisin
bulduğu hata düzeltildi: "kullanıcı sayfadan ayrılınca özet gönder"
tetikleyicisi hiç çalışmıyordu.

### 3.1 Değiştirilen — 11 dosya (commit'lenmedi)

| Dosya | Ne değişti |
|---|---|
| `public/api/slack_flush.php` | `:37-59` — istemciden gelen `idle` (bekleme) ipucu okunuyor, `0 <= idle <= BCC_SLACK_BATCH_IDLE_SECONDS` aralığına sınırlanıyor ve `bcc_slack_flush_table((int) $table['id'], $idle)` çağrısına geçiriliyor. **Eskiden ikinci parametre hiç verilmiyordu** — hatanın kendisi buydu |
| `public/assets/grid-slack-flush.js` | `LEAVE_IDLE` (0) / `HIDDEN_IDLE` (30) sabitleri; `payload(idle)` ve `flushBeacon(idle, sonPing)` imzaları; `pagehide` → `(0, true)`, `visibilitychange` → `(30, false)`. Bayrak (`dirty`) artık yalnızca son pingde temizleniyor — yoksa önce gelen `visibilitychange`, asıl gönderen `pagehide` pingini bastırıyordu |
| `src/slack.php` | `BCC_SLACK_BATCH_MAX_VALUE_CHARS` (200) ve `BCC_SLACK_BATCH_MAX_TITLE_CHARS` (120) sabitleri; yeni `bcc_slack_shorten_value()`; özet satırlarına ve kayıt başlığına uygulanıyor. Önceden alan başına sınır **yoktu** — 20.004 karakterlik tek bir hücre kanala olduğu gibi düşüyordu |
| `public/api/record_add.php` | Toplu satır damgalaması **koşullu** oldu: `if ($count > 1)` → `if ($count > 1 && !bcc_slack_watched_field_ids($table['id']))`. Böylece toplu eklenen boş satırlar damgasız kalıp ilk içerik girildiğinde 📢 olarak duyuruluyor |
| `public/api/record_delete.php` | Kutucukla **toplu silme** artık kalıcı silmiyor, çöp kutusuna taşıyor (`deleted_at`). Ek dosyalarına bilerek dokunmuyor — kayıt geri yüklenebilir; ekleri silme işi çöp kutusunun süreli temizliğinde |
| `public/assets/grid-row-detail.js` | Toplu silme onay metnine "Çöp kutusundan geri yükleyebilirsiniz." eklendi |
| `scripts/_verify_trash_purge_attachments.php` | Toplu silmenin artık kalıcı silmediğini ve dosyalara dokunmadığını sabitleyen iki kontrol |
| `docs/CANLIYA-ALMA.md` | **§3.2.1 ZORUNLU yükseltme** bölümü — `records.slack_notified_at` için `ALTER TABLE` + damgalama `UPDATE`'i; atlanırsa hangi uç noktaların 500 döneceği |
| `src/partials/error_page.php` | Hata sayfası (404/403/500): satır içi `style` yamaları kaldırıldı, `.error-*` sınıflarına geçildi. Açıklama metni yüklenmeyen `style.css`'teki `.hint` sınıfına güveniyordu — kural hiç uygulanmıyordu |
| `public/assets/login.css` | Hata sayfası kuralları eklendi (`.error-body/-title/-message/-action/-code`); buton tam genişlikten çıkarıldı (`width:auto`) |
| `scripts/_verify_error_pages.php` | 11 yeni hizalama kontrolü — 23'ten 33'e |
| `scripts/_verify_slack_batch.php` | Yeni **L** (10) + **M** (10) + **N** (6) + **O** (13) bölümleri, **F bölümü yeniden yazıldı**, C/D çift biçimine uyarlandı, **K1c** eklendi, fikstür webhook'u aktif bırakıldı; **O bölümü kaldırıldı** (test ettiği kod silindi) — 41'den 103'e |

⚠️ İlk beşi **dünkü listede zaten vardı** (§2.2): ikisi henüz git'e eklenmemiş
yeni dosya, biri yeni test betiği, ikisi dün de değiştirilen `src/slack.php` ve
`public/api/record_add.php`. Son üçü (hata sayfası, `login.css`,
`_verify_error_pages.php`) **Slack işiyle ilgisiz**, bugün ayrıca gelen bir
rapordan çıktı. Bugün de git'in gördüğü yeni bir kod dosyası oluşmadı.

`src/slack.php`'de 180 saniyelik varsayılan **değişmedi**; eklenenler mesaj
metnindeki uzunluk sınırı ve "yeni kayıt ancak içerik girilince duyurulur"
kuralı (`bcc_slack_pending_records()`); ayrıca mesaj modeli kayıt başına
bildirimden **boşaltma başına TEK bildirime** geçti
(`bcc_slack_build_batch_message()`, `bcc_slack_deleted_records()`,
`bcc_slack_row_numbers()`). §7'de yazılan `bcc_slack_build_record_message()` ve
`BCC_SLACK_MESSAGE_STYLE` bu geçişle **kullanım dışı kaldı** — kullanıcının
"yedek kalsın" isteği üzerine silinmedi, kaynakta uyarı başlığıyla duruyor.

### 3.2 Belgeler — 2 dosya (yeni)

| Dosya | Ne |
|---|---|
| `docs/gunluk/2026-09-09.md` | O günün notu |
| `docs/DEGISEN-DOSYALAR-VE-TABLOLAR.md` | Bu dosya |


---

## 3b. Kod dosyaları — 2026-09-14

Günün üç kod işi: gridde **Tab ile hücre gezinmesi**, **uyarı kutularındaki
metin kayması** ve **kanban'a ayrılma pingi**. Diğer iki iş (Slack teşhisi ve 08-09 Eylül kodunun commit
edilmesi) **hiçbir dosyayı değiştirmedi**.

### 3b.1 Değiştirilen — 6 dosya

| Dosya | Satır | Ne değişti |
|---|---|---|
| `public/assets/grid-cell-select.js` | +34 | Yeni `wrapCell(row, step)` yardımcısı (`cellAt`'in hemen altına); `keydown` dinleyicisinde `ARROWS` bloğunun **ÖNÜNE** `Tab` dalı. `Tab` bir sağa, `Shift+Tab` bir sola; satır sonunda alt satırın ilk, satır başında üst satırın son hücresine sarmalıyor; tablonun iki ucunda **yerinde kalıyor**. Dal `keyboardBelongsToGrid()` guard'ının ALTINDA, yani hücre düzenleme modundayken Tab'a dokunulmuyor |
| `public/assets/home.css` | +7 | **YENİ** `.home-modal-message` kuralı (`:2365`), `.home-modal-optional`'ın hemen altında. `.home-modal-label`'a **dokunulmadı** — 13 gerçek form etiketi onu kullanıyor |
| `public/assets/confirm-modal.js` | 1 satır | `:43` — uyarı paragrafı `home-modal-label` → `home-modal-message`. `<p>`'nin tarayıcıdan miras aldığı `margin-top: 1em` sıfırlanmadığı için metin kutunun içinde aşağı kayıp düğmelere yapışıyordu |
| `public/grid.php` | 2 satır | `:1688` tablo silme özeti, `:1709` yapıştırma onayı özeti — aynı sınıf değişikliği |
| `public/kanban.php` | +1 | `:263` — `grid-slack-flush.js` etiketi. Sunucu tarafı boşaltma (`:73`) yalnızca SONRAKI sayfa yüklemesinde çalışıyordu; "çıkınca hemen gönder" tetikleyicisi kanban'da yoktu. Kanban `bcc_post` kullandığı için sarmalanan `window.fetch`'in devreye girdiği ayrıca tarayıcıda ölçüldü |
| `scripts/_verify_slack_integration.php` | +13 | İki sayfanın da ayrılma pingini yüklediği (döngüyle — üçüncü sayfa eklenirse aynı yerden bakılır) ve kanban'ın hücre yazma yolunun `cell_update.php` olduğu. A+B 19 → 22 |

### 3b.2 Yeni — 2 dosya

| Dosya | Ne |
|---|---|
| `scripts/_verify_grid_tab_nav.php` | 18 kontrol. A) Tab dalı + `preventDefault` + `shiftKey` yönü + sarmalama, B) guard sırası ve `Escape` çıkış yolu, C) ok tuşları / `Ctrl+A` bozulmadı, D) `grid.php` betiği gerçekten basıyor. JS yorumlarını ayıklayarak tarıyor (09-09'da dört kez düşülen tuzak) |
| `scripts/_verify_modal_message_style.php` | 13 kontrol. A) margin sıfırlaması + punto + satır aralığı + renk, B) `.home-modal-label`'ın bozulmadığı (altı form onu kullanıyor), C) üç uyarı paragrafının yeni sınıfa geçtiği, D) `.home-modal-form` kullanan altı dosyada `<p class="home-modal-label">` kalmadığı — aynı hata bir daha sessizce girmesin |

### 3b.3 Belgeler — 3 dosya

| Dosya | Ne |
|---|---|
| `docs/gunluk/2026-09-14.md` | **YENİ** — günün notu |
| `docs/PROJE-DURUM.md` | §5 "Biten İşler"e 08-14 Eylül turunun özeti |
| `docs/DEGISEN-DOSYALAR-VE-TABLOLAR.md` | Bu dosya — kapsam satırı, §1 özeti ve bu bölüm |

### 3b.4 Commit'ler

| Commit | Kapsam |
|---|---|
| `26f0d44` | Slack toplu bildirim sistemi (16 dosya) — **08-09 Eylül'de yazıldı**, bugün commit'lendi |
| `23d27d5` | Toplu silme → çöp kutusu (3 dosya) — aynı şekilde |
| `cbcae46` | Hata sayfası hizalaması (3 dosya) — aynı şekilde |
| `7982efe` | Günlük notlar + envanter + PROJE-DURUM (5 dosya) |
| `7447bac` | **Bugün yazılan tek kod:** Tab gezinmesi (3 dosya) |

`git add .` kullanılmadı; her commit öncesi `git status` + `git diff --cached`
ve sır taraması yapıldı (takip edilmeyen dosyalar ayrıca — `git diff` onları
göstermez). Gerçek webhook URL'i hiçbir dosyaya sızmadı.

### 3b.5 Veritabanı — 2026-09-14'te HİÇBİR DEĞİŞİKLİK YOK

Ne DDL ne veri. Günün bütün sorguları `SELECT`: `audit_log`, `records`,
`cell_values`, `slack_webhooks`, `slack_watched_fields`.

⚠️ `bcc_slack_flush_table()` teşhis sırasında **bilerek çağrılmadı** — o
fonksiyon `records.slack_notified_at` damgası yazar, yani ölçüm aracı değil;
onun yerine alt katmandaki `bcc_slack_pending_records()` okundu.

⚠️ 08:55 ve 08:56'da `records.slack_notified_at` gerçekten yazıldı ve
`audit_log`'a iki `slack.notify_sent` satırı düştü — **bunu teşhis yapmadı**,
kullanıcı gridde çalışırken uygulamanın kendi boşaltma akışı yazdı. Teşhisin
kanıtı zaten budur.

Tab işinin testi de veritabanına dokunmuyor: `_verify_grid_tab_nav.php` saf
kaynak taraması, tarayıcı ölçümü ise statik bir HTML fikstürü üzerinde yapıldı
(oturum açılmadı).

---

## 4. Veritabanı

Aşağıdaki her şey **2026-09-08 ve 2026-09-09**'dan. **2026-09-14'te
veritabanına hiçbir şey yazılmadı** — bkz. §3b.5.

### 4.1 Yapısal değişiklik — `records` tablosu (2026-09-08)

**Tek bir kolon eklendi. Başka hiçbir tablonun yapısı değişmedi.**

```sql
ALTER TABLE records ADD COLUMN slack_notified_at DATETIME NULL DEFAULT NULL;
```

- Kaynakta: `schema.sql:269`
- Anlamı: `NULL` = "bu kayıt Slack'e hiç duyurulmadı". Dolu ise, o damgadan
  **sonra** yazılan hücreler bir sonraki özette listelenir.
- **Neden ayrı kuyruk tablosu yazılmadı:** "hangi alan değişti" bilgisi bu
  damga ile `cell_values.updated_at` (zaten vardı, `ON UPDATE
  CURRENT_TIMESTAMP`, `schema.sql:289`) karşılaştırmasından çıkıyor.
- ⚠️ Damga yazılırken **`updated_at = updated_at`** kullanılıyor
  (`src/slack.php`, `bcc_slack_mark_records_notified()`): `records.updated_at`
  `ON UPDATE CURRENT_TIMESTAMP` taşıdığı için, bu atama olmasa damga yazmak
  "Son değişiklik zamanı" alanını bildirim saatine kaydırırdı.

**Yerelde durum (2026-09-09 itibarıyla ölçüldü):** kolon mevcut
(`datetime`, `NULL`, varsayılan `NULL`); 178 aktif kayıttan **2'si damgasız**
(bugün kullanıcının denemesinde oluşanlar).

**Canlıda durum: UYGULANMADI.** Deploy sırasında koddan **önce** iki adım
zorunlu — bkz. §6.

### 4.2 Veri değişikliği (yapısal değil) — 2026-09-08, yalnızca yerel

```sql
UPDATE records SET slack_notified_at = NOW(), updated_at = updated_at
WHERE slack_notified_at IS NULL;
```

**19 kayıt** damgalandı. Gerekçe: damgasız kayıt "hiç duyurulmadı" demek;
damgalamadan grid açılsaydı kolon eklenmeden önce var olan **tüm eski
kayıtlar** için tek tek mesaj giderdi. Kayıt sayısı değişmedi, yalnızca yeni
kolon dolduruldu.

### 4.3 Veri/ayar değişikliği — 2026-09-09, yalnızca yerel

Kullanıcının açık isteği üzerine tablo 2992 (TAKVİM / Tablo, ekip 214) Slack'e
bağlandı. **Hiçbir tablonun yapısı değişmedi**, üç tabloya satır yazıldı:

| Tablo | Değişiklik |
|---|---|
| `slack_webhooks` | **+1 satır** — id 193, `team_id=214`, `table_id=NULL` (ekip geneli), etiket `#social`. URL ekip 1'in mevcut gerçek webhook'undan (id 18) kopyalandı |
| `slack_watched_fields` | **+7 satır** — tablo 2992'nin kullanıcı tarafından doldurulan alanları. Hesaplanan alanlar (`last_modified_time`, `created_time`, `created_by`) ve `attachment` bilerek dışarıda |
| `records` | Tablo 2992'nin **152 kaydının** `slack_notified_at` değeri tazelendi (`updated_at = updated_at` ile). Kayıt sayısı değişmedi |

⚠️ Üçüncü satır şart: ölçüm, izlenen alanlar açılınca **18 kaydın** "bekleyen"
sayılacağını gösterdi (damgadan sonra yazılmış hücreleri vardı). Damga
tazelenmeseydi grid ilk açılışta kanala 18 mesaj birden düşerdi. Sonuç:
bekleyen **18 → 0**.

Üçü de tek transaction içinde yapıldı; `audit_log`'a `slack.webhook_create` ve
`slack.watched_fields_update` satırları yazıldı. Yeni webhook
`bcc_slack_send_test()` ile denendi: **HTTP 200**.

Ayrıca **2026-09-09 canlı testinden** (bkz. `docs/gunluk/2026-09-09.md` §3)
kalan tek kalıcı iz: `audit_log`'da bir `slack.notify_sent` satırı
(2387 → 2388). Test kaydı, o test için açılan izlenen alanlar ve geçici oturum
dosyası temizlendi.

---

### 4.4 Okunan ama DEĞİŞTİRİLMEYEN tablolar

Teşhis sırasında yalnızca `SELECT` ile bakıldı; hiçbirinin yapısı ya da
verisi değişmedi:

| Tablo | Neden bakıldı | Bulunan |
|---|---|---|
| `slack_webhooks` | mesaj nereye gidiyor | 3 aktif kayıt; **ekip 214'ün hiç webhook'u yok**; tablo 8'in iki webhook'u **sahte test URL'i** |
| `slack_watched_fields` | hücre bildirimi açık mı | **0 kayıt** — özellik fiilen kapalı |
| `slack_routing_rules` | koşullu yönlendirme | dokunulmadı |
| `audit_log` | gönderim denendi mi | bugün `slack.*` **hiç olay yok**; `slack.notify_sent` toplamı 2387 (değişmedi) |
| `records`, `cell_values` | hangi kayıt ne zaman değişti | bugünkü 16 hücre değişikliğinin hepsi tablo 2992'de |
| `tables_meta`, `bases` | tablo hangi ekibe ait | tablo 2992 → base 2126 → **ekip 214 (`ABC`)** |

---

## 5. 2026-09-09'da bulunan hata — DÜZELTİLDİ

**Neydi:** `public/api/slack_flush.php` ikinci parametreyi geçirmiyordu, bu
yüzden `bcc_slack_flush_table()` (`src/slack.php:566-576`) varsayılan **180
saniyelik** bekleme kuralını uyguluyordu. Sonuç: "kullanıcı sayfadan ayrılınca
gönder" tetikleyicisi **hiç çalışmıyordu** — ping gidiyor, sunucu "kayıt hâlâ
taze" deyip reddediyordu.

**Nasıl düzeltildi:** İstemci hangi olaydan geldiğini söylüyor (`pagehide` → 0,
`visibilitychange` → 30); sunucu bu değeri sınırlayıp boşaltmaya geçiriyor.
Ayrıntı ve zamanlama kararının gerekçesi: `docs/gunluk/2026-09-09.md` §2.

**Test:** `_verify_slack_batch.php` 41 → **51/51**; yeni L bölümü davranış
testiyle başlıyor (aynı taze kayıt: varsayılan beklemeyle gitmiyor, bekleme 0
ile hemen gidiyor). Kirlilik ölçütlerinin altısı da değişmedi.

**Canlı doğrulama:** Uç nokta gerçek HTTP istekleriyle denendi (tablo 2135,
susturma olmadan): üç hücre yazıldı → mesaj yok; `idle=180` → `sent:0`
(düzeltmeden önceki hâl); `idle=0` → `sent:1` ve `#social` kanalına **gerçek
mesaj gitti** (`slack.notify_sent` 2387→2388). Test kaydı, izlenen alanlar ve
geçici oturum temizlendi; diğer beş kirlilik ölçütü değişmedi.

**Kalan:** Tarayıcının `pagehide` / `visibilitychange` olaylarında `sendBeacon`
tetiklediği gerçek bir tarayıcıda görülmedi.

---

## 6. Canlıya çıkarken ZORUNLU iki SQL adımı

§2.2'deki kod canlıya alınırsa, **koddan ÖNCE**:

```sql
ALTER TABLE records ADD COLUMN slack_notified_at DATETIME NULL DEFAULT NULL;
UPDATE records SET slack_notified_at = NOW(), updated_at = updated_at;
```

- **Birinci satır atlanırsa:** grid **hiç açılmaz** (sorgular hata verir).
- **İkinci satır atlanırsa:** canlıdaki tüm kayıtlar "hiç duyurulmamış"
  sayılır ve grid ilk açıldığında kanala **yüzlerce mesaj** düşer.
