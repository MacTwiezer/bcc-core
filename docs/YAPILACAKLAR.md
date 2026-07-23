# BCC-Core — Yapılacaklar Planı

> `PROJE-DURUM.md` ile birlikte kullanılır.
> Her iş için: Airtable davranışı → teknik yaklaşım → dikkat → test.
> Bir iş bitince buradan sil, `PROJE-DURUM.md`'nin "Biten İşler" bölümüne ekle.

---

## Genel Kurallar (her iş için geçerli)

- Yeni panel eklerken **eski işlevsiz butonu SİLMEK ilk adım** (üç kez unutuldu)
- Panel deseni: mevcut `<details class="... gs-tool-details">` + `<summary class="gs-tool-btn">`
- Aktif vurgu sınıfı: `hide-fields-btn-active` (isim yanıltıcı ama üç panelde ortak)
- CSS `style.css`'e, mevcut ortak seçicilere ekleyerek (tekrar yazma)
- **JS'siz de çalışsın** — form/link tabanlı; JS sadece hızlandırma katmanı
- Durum URL'de taşınır; yeni parametre eklenirse **beş panelin hepsinin** gizli input'larına ve `$stateQueryString` / `$clearXQueryString` dizelerine katılmalı
- Whitelist: alan id'leri `$fieldsById`'den, tablo/kolon adları sabit diziden
- Bitince: `php -l` lint + üç regresyon betiği

---

## 1. Add subgroup (çok seviyeli gruplama)

**Airtable davranışı:**
- Group paneli aktifken altta "Add subgroup" bağlantısı
- Yeni bir satır eklenir: alan dropdown + yön dropdown + çöp kutusu
- En fazla 3 seviye
- Buton etiketi: "Grouped by 2 fields" / "Grouped by 3 fields"
- Grid'de iç içe başlıklar: dış grup başlığı, altında iç grup başlıkları girintili

**Teknik:**
- URL: `group_field_1..3` / `group_dir_1..3` (mevcut `group_field`/`group_dir` yerine, sort deseniyle aynı)
- `parse_grid_group_rule()` → `parse_grid_group_rules()` olarak çoğullaştır, dizi döndürsün (max 3)
- SQL: her seviye için ayrı `LEFT JOIN cell_values gv0/gv1/gv2`, ORDER BY sırayla:
  `(gv0.col IS NULL) DESC, gv0.col, (gv1.col IS NULL) DESC, gv1.col, ...` sonra sort kuralları
- Segmentleme: `$records` tek geçişte iç içe yapıya bölünmeli — dış grup değeri değişince yeni dış segment, iç grup değeri değişince yeni iç segment
- Render: her seviye için başlık satırı; iç seviyeler `padding-left` ile girintili
- Aç/kapa: dış grup kapanınca içindeki tüm iç gruplar ve satırlar gizlenmeli

**Dikkat:**
- Bu, tek seviyeden belirgin şekilde karmaşık — özyinelemeli (recursive) render düşünülebilir
- `data-group-index` yerine `data-group-path="0-2-1"` gibi hiyerarşik bir anahtar gerekebilir
- Mevcut tek seviye davranışı bozulmamalı (geriye dönük uyum)
- Grup + sort + filter + hidden + row_height hepsi birlikte çalışmalı

**Test:**
1. İki seviye grupla (ör. Kategori → Durum) → iç içe başlıklar
2. Üçüncü seviye ekle
3. Dış grubu kapat → içindeki her şey gizleniyor mu
4. Bir seviyenin çöp kutusuna bas → sadece o seviye kalkıyor mu
5. Collapse all / Expand all tüm seviyelerde
6. Grup + filtre + sıralama + gizli alan birlikte
7. Satır numaraları gruplar arasında sürekli mi

---

## 2. Arama iyileştirmesi (Airtable tarzı)

**Airtable davranışı:**
- Eşleşen kelimeler **sarı ile vurgulanır** (hücre içinde), eşleşen satır hafif sarı zeminli
- Sağda **"1 of 219"** sayacı (eşleşme sayısı, kayıt sayısı değil)
- **Yukarı/aşağı okları** ile eşleşmeler arasında gezinme; aktif eşleşme daha koyu vurgulu
- **X** ile aramayı kapatma
- Satırlar **gizlenmez**, sadece vurgulanır — bağlam korunur
- Her tuş vuruşunda anında çalışır, Enter gerekmez

**Teknik:**
- Tamamen istemci tarafı, sunucu değişmez
- `public/assets/grid-toolbar.js` baştan yazılacak
- Yaklaşım: her `.cell-view` içindeki metinde eşleşmeleri bul, `<mark>` ile sarmala
- Eşleşmeleri bir dizide tut (`[{cell, index}]`), oklarla o hücreye `scrollIntoView`
- Aktif eşleşmeye ayrı sınıf (`mark.is-active`)
- Arama temizlenince tüm `<mark>` etiketleri kaldırılmalı (orijinal metin geri yüklenmeli)

**Dikkat:**
- **`innerHTML` ile metin değiştirmek tehlikeli** — hücre içeriği zaten `htmlspecialchars`'lı ama yine de `textContent` üzerinden çalışıp DOM düğümü oluşturmak daha güvenli
- Hücre düzenleme (`grid.js`) aynı DOM'a dokunuyor — vurgulama açıkken hücreye tıklanırsa çakışma olmamalı; düzenlemeye geçerken o hücrenin vurgusu temizlenmeli
- Grup başlıkları da aranabilir mi? Airtable'da aranmıyor — biz de dahil etmeyelim
- Büyük tablolarda performans: 500+ satırda her tuş vuruşunda tüm DOM'u taramak yavaşlar; gerekirse `debounce` (150ms)
- Türkçe büyük/küçük harf: `toLocaleLowerCase('tr')` kullanılmalı (I/ı, İ/i)

**Test:**
1. Bir kelime yaz → eşleşmeler sarı vurgulanıyor mu, satırlar gizlenmiyor mu
2. Sayaç "1 of N" doğru mu
3. Yukarı/aşağı okları → eşleşmeler arası geziniyor, sayfa kayıyor mu
4. Aktif eşleşme farklı renkte mi
5. X ile kapat → tüm vurgular temizleniyor mu
6. Türkçe: "İ" arayınca "i" bulunuyor mu (ve tersi)
7. Vurgu varken bir hücreyi düzenle → bozulma var mı
8. Gruplama açıkken arama

---

## 3. Color (satır renklendirme)

**Airtable davranışı (Team planında, görselden okunamadı — tarif):**
- İki yöntem: (a) tek bir select alanına göre, (b) koşullara göre
- (a) select seçeneklerinin kendi renkleri satırın soluna ince şerit olarak yansır
- (b) filtre benzeri kurallar: "Miktar > 100 → kırmızı". İlk eşleşen kural kazanır

**Ön koşul (a için):**
Bizde select seçeneklerinin rengi yok. `fields.options` şu an `{"choices":["Kırmızı","Yeşil"]}`.
Renk desteği için `{"choices":[{"label":"Kırmızı","color":"#e74c3c"}]}` gibi bir yapıya geçmek
ve `table_fields.php`'deki seçenek düzenleme arayüzünü güncellemek gerekir. **Geriye dönük
uyum şart** — eski düz string dizisi de okunabilmeli.

**Önerilen sıra:**
- Önce (b) koşullu renklendirme — filtre altyapısı (`filter_condition_sql`) zaten var, select renklerine ihtiyaç yok
- (a) daha sonra, seçenek renkleri eklendiğinde

**Teknik (b için):**
- URL: `color_field_N` / `color_cond_N` / `color_value_N` / `color_hex_N` (max 3-5 kural)
- `parse_grid_color_rules()` — filtre parse'ıyla aynı desen
- Renk değerlendirmesi **PHP'de render sırasında** yapılabilir (kayıt zaten hücre değerleriyle birlikte elde), SQL'e dokunmaya gerek yok
- Satıra `style="border-left: 4px solid #xxx"` veya bir sınıf

**Dikkat:**
- Renk hex değeri kullanıcıdan geliyorsa doğrula (`/^#[0-9a-f]{6}$/i`), yoksa CSS injection
- Sabit bir palet sunmak (Airtable gibi) hem daha güvenli hem daha tutarlı

---

## 4. Yeni alan tipleri: User ve Saat

**User (@kullanıcı):**
- Airtable'da hücrede avatar + isim, tıklayınca kullanıcı listesi açılır
- Bizde: o base'in **ekibindeki** kullanıcılar listelenmeli (KVKK — başka ekibin kullanıcısı seçilemez)
- Depolama: `value_number` = `users.id` (tek kullanıcı) veya `value_json` (çoklu)
- Öneri: önce tek kullanıcı (`user`), çoklu sonra

**Saat (`time`):**
- `value_text` içinde `HH:MM` olarak saklanabilir, ya da `value_date` içinde tam zaman damgası
- Öneri: `value_text` + `HH:MM` — basit ve yeterli
- Girdi: `<input type="time">`

**Değişecek yerler (her iki tip için):**
- `src/schema.php`: `BCC_FIELD_TYPES`, `BCC_FIELD_VALUE_COLUMN`, `BCC_FIELD_TYPE_BADGE`,
  `BCC_FILTER_OPERATORS`, `BCC_GROUP_DIR_LABELS`, `normalize_cell_value()`, `cell_display_text()`
- `public/table_fields.php`: alan tipi seçim listesi
- `public/grid.php`: hücre render
- `public/assets/grid.js`: hücre düzenleme girdisi
- `public/api/cell_update.php`: doğrulama (tip haritası zaten otomatik çalışır)

**Dikkat:**
- User tipinde silinmiş kullanıcı: `users` tablosundan kayıt gidince hücrede ne görünecek? (FK yok, id kalır) — "Bilinmeyen kullanıcı" gibi bir yedek metin
- Saat tipinde filtre operatörleri: önce/sonra/eşit — tarih ile aynı mantık
- Gruplama: User tipinde grup başlığı kullanıcının adını göstermeli, id'sini değil

**Test:**
- Yeni tip alan oluştur, hücreye değer gir, F5'te kalıyor mu
- Filtre, sıralama, gruplama bu tipte çalışıyor mu
- User: başka ekibin kullanıcısı listede çıkmıyor mu (KVKK)

---

## 5. Sütun ekleme akışı (Airtable gibi)

**Airtable davranışı:**
- Grid'de en sağdaki `+` butonu → küçük panel açılır
- Önce **alan tipi** seçilir (ikonlu liste, aranabilir)
- Sonra **alan adı** girilir
- Select tipiyse seçenekler eklenir
- Tek akışta biter, ayrı sayfaya gidilmez

**Şu anki durum:** `table_fields.php` ayrı bir sayfa; tip + ad + seçenekler tek formda.

**Teknik:**
- Grid'in `thead`'ine en sağa bir `+` sütunu ekle
- Tıklayınca `<details>` paneli: tip listesi → seçilince ad girişi
- Form `table_fields.php`'ye POST etsin (mevcut `create_field` action'ı), sonra grid'e geri dönsün (`Location: grid.php?...` + state korunarak)
- `table_fields.php` sayfası kalmaya devam etsin (düzenleme/silme/sıralama orada)

**Dikkat:**
- `require_role('editor')` — viewer'da `+` görünmemeli
- Yeni alan eklendikten sonra grid'e dönerken tüm state (hidden/sort/filter/group/row_height) korunmalı
- Yeni alan varsayılan olarak görünür olmalı (hidden_fields'a eklenmemeli)

---

## 6. Zengin metin editörü

**En büyük iş.** Tek başına bir proje.

**İhtiyaç:** `long_text` alanlarında kalın/italik/altçizgi, punto boyutu, link ekleme, liste.

**Yaklaşım seçenekleri:**
- (a) Hazır kütüphane (Quill, TinyMCE, Trix) — hızlı ama dış bağımlılık
- (b) `contenteditable` + `document.execCommand` — bağımlılık yok ama `execCommand` artık önerilmiyor
- (c) Minimal kendi çözümümüz — sadece gerekli düğmeler

**Kritik güvenlik noktası:**
Zengin metin = HTML depolamak demek. Şu an her şey `htmlspecialchars` ile kaçırılıyor.
HTML'i olduğu gibi basmak **XSS riski** yaratır. Mutlaka:
- Sunucu tarafında **whitelist tabanlı temizleme** (sadece `<b> <i> <u> <a> <ul> <li> <br> <p> <span style="font-size">`)
- `<script>`, `onclick`, `javascript:` gibi her şey temizlenmeli
- PHP 7.3'te `HTMLPurifier` gibi bir kütüphane veya elle yazılmış katı bir temizleyici

**Depolama:** `value_text` içinde HTML. Mevcut `long_text` verileri düz metin — geriye dönük uyumlu olmalı.

**Etkilenen yerler:** grid hücre render (HTML basılacak), `cell_display_text()`, arama (HTML etiketleri aranmamalı), filtre (`contains` HTML içinde arama yapmamalı — düz metin sürümü de saklanabilir).

**Öneri:** Bu işe başlamadan önce ayrı bir tasarım kararı oturumu yap. Aceleye gelmemeli.

---

## 7. Slack otomasyonu

**Hedef:** Yeni duyuru kaydı eklendiğinde ilgili Slack kanalına otomatik mesaj.

**Ön koşul:** Apache'nin PHP 7.3'ünde **curl uzantısı** açık olmalı.
`C:\php73\php.ini` → `extension=curl` + `C:\php73\ext\php_curl.dll` mevcut olmalı.
(Kurulumda "Unable to load" uyarısı vermişti, o zaman gerek yoktu.)
Doğrulama: `diag.php`'ye `extension_loaded('curl')` satırı eklenebilir.

**Şema:** `slack_webhooks` tablosu zaten var (kullanılmıyor).
Muhtemel kolonlar: `team_id`, `table_id`, `webhook_url`, `is_active`.

**Teknik:**
- Admin panelinde webhook yönetimi (ekle/düzenle/sil, ekip bazlı)
- Kayıt eklendiğinde (`grid.php` create_record) veya belirli bir alan dolduğunda tetikle
- `curl` ile Slack Incoming Webhook'a POST (JSON)
- **Hata durumunda kayıt işlemi başarısız olmamalı** — Slack'e ulaşılamazsa kayıt yine eklenmeli, hata `audit_log`'a düşmeli
- Zaman aşımı koy (5 sn), yoksa Slack yavaşsa sayfa asılır

**Dikkat:**
- Webhook URL'i gizli bilgi — `audit_log`'a veya ekrana basma
- KVKK: hangi ekibin verisi hangi kanala gidiyor, admin kontrolünde olmalı
- Test için gerçek Slack workspace'i gerekir

---

## 8. Kaydedilebilir görünümler

**Şu an:** Tüm ayarlar (hidden/sort/filter/group/row_height) URL'de. Sayfa kapanınca gider.

**Hedef:** Airtable'daki gibi "Grid view" / "Create new view" — bir kombinasyonu isimle kaydet.

**Şema:** `views` tablosu zaten var. `config` JSON kolonu tüm ayarları tutabilir.

**Teknik:**
- Sol açılır panelde (`Create new... / Find a view / Grid view`) gerçek liste
- "Save view" → mevcut URL state'i JSON'a çevirip `views.config`'e yaz
- Görünüme tıklayınca config'ten URL üret, grid'e git
- Varsayılan görünüm kavramı

**Dikkat:** KVKK — görünüm tablo bazlı, tablo da ekip bazlı; `require_team_access` zinciri korunmalı.
