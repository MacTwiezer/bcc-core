# BCC-Core — Proje Durumu

> Bu dosya, yeni bir sohbete başlarken bağlam olarak yapıştırılır.
> Her özellik bittiğinde güncellenir ve commit'lenir.

---

## 1. Proje Nedir

Airtable'ın birebir kopyası (arayüz + işlev) + Airtable'ın çözemediği eksikler.
BCC şirketi için iç araç. Geliştiren: Yiğit Aslantaş.

**Airtable'da olmayan, bizde olması gerekenler:**
- KVKK ekip izolasyonu (TY / GULF / ATP ekipleri birbirinin verisini göremez)
- Slack'e otomatik duyuru
- Zengin metin editörü (Word gibi punto/link)
- Editör sayısı sınırsız (Airtable free 5 editörle sınırlı)
- Türkçe karakter desteği

**Pusula:** "Bu, Airtable taklidi mi, yoksa Airtable'ın çözemediği bir eksik mi?"

---

## 2. Ortam

| | |
|---|---|
| Proje yolu | `C:\xampp\htdocs\bcc-core` |
| Web sunucusu | Apache (XAMPP), DocumentRoot = `public/` |
| Adres | `http://localhost/` |
| PHP | **7.3.33** — `C:\php73\php.exe` (typed properties / 7.4+ özellik YOK) |
| DB | MariaDB 10.4 (XAMPP MySQL), `127.0.0.1:3306`, root, şifresiz, `bcc_core`, utf8mb4 |
| Erişim katmanı | **mysqli** (PDO tamamen kaldırıldı) |
| Git repo | `github.com/MacTwiezer/bcc-core` — her özellik ayrı commit |
| Claude Code | `cd C:\xampp\htdocs\bcc-core` → `claude` |

**Apache'ye PHP 7.3 nasıl bağlandı** (bir daha yaşanmasın diye):
- `httpd-xampp.conf`: PHP 8.2 satırları `#` ile kapatıldı, altına eklendi:
  ```
  LoadModule php7_module "C:/php73/php7apache2_4.dll"
  PHPIniDir "C:/php73"
  ```
- `C:\php73\php.ini`: `extension_dir = "C:\php73\ext"` (TAM YOL), eklentiler kısa ad (`extension=mysqli`)
- `C:\php73\ext` DLL'leri **7.3.33 için derlenmiş** olmalı (thread-safe, VC15)
- `httpd.conf`: `DocumentRoot "C:/xampp/htdocs/bcc-core/public"`

**Başlatma:** XAMPP Control Panel → Apache Start + MySQL Start.

---

## 3. Kalıcı Kurallar

1. **Partials** — tekrar eden HTML/mantık tek yerde (`src/partials/`)
2. **htdocs** — proje Apache'nin göreceği yerde
3. **Apache** — `php -S` KULLANILMAZ
4. **mysqli** — PDO yok, `bcc_query` / `bcc_fetch_all` / `bcc_fetch_one` / `bcc_fetch_column` / `bcc_execute` / `bcc_last_insert_id` / `bcc_begin_transaction` / `bcc_commit` / `bcc_rollback`
5. **Kod tekrarı yok** — aynı şey iki yerde yazılmaz, ortak fonksiyon/partial'a alınır
6. **Özelliğini yazmadığım şeyi ekleme** — belirtilmemiş butonlar sadece görünüm olarak durur

**Güvenlik değişmezleri:**
- Tüm çıktı `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
- Tüm sorgular prepared statement
- Tüm formlar CSRF'li
- Her veri erişiminde `require_team_access()` / `require_role()`
- `team_id` her zaman satırdan alınır, URL'den değil
- `audit_log`'a kayıt
- SQL'e gömülen tablo/kolon adları whitelist'ten (prepared statement ile bağlanamazlar)
- `json_encode(..., JSON_UNESCAPED_UNICODE)` — Türkçe için

---

## 4. Mimari

**EAV (Entity-Attribute-Value)** — 13 tablo, hepsi InnoDB + utf8mb4:

`teams`, `users`, `team_members`, `bases`, `tables_meta`, `fields`, `records`,
`cell_values`, `record_links`, `views`, `attachments`, `slack_webhooks`, `audit_log`

- `cell_values`: `value_text` / `value_number` / `value_date` / `value_json`
- Ekipler: TY, GULF, ATP
- Roller: owner / editor / commenter / viewer
- Alan tipleri (7): `single_line_text`, `long_text`, `number`, `checkbox`, `date`, `single_select`, `multiple_select`

**Dosya haritası:**
```
config/database.php        mysqli bağlantısı + yardımcılar
src/
  bootstrap.php            csrf → auth → audit → schema yükler
  auth.php                 current_user, require_login, require_team_access,
                           require_role, attempt_login, bcc_user_initial
  schema.php               find_base/table/field_or_404, bcc_find_field,
                           parse_grid_* (sort/filter/hidden/group/row_height),
                           filter_condition_sql, bcc_reorder_sibling,
                           normalize_cell_value, cell_display_text
  csrf.php  audit.php
  partials/                header.php, top_nav.php, footer.php,
                           flash.php, account_menu.php
public/
  login.php register.php terms.php privacy.php
  dashboard.php            Airtable Home ekranı
  bases.php base_tables.php table_fields.php
  grid.php                 Airtable Data ekranı (en büyük dosya)
  api/cell_update.php      AJAX hücre kaydetme
  admin/                   index, create_user, create_team, assign_team
  assets/                  style.css, login.css, home.css, grid-shell.css,
                           grid.js, grid-toolbar.js, grid-filter.js,
                           grid-hide-fields.js, grid-group.js,
                           account-menu.js, bcc-logo.svg
scripts/                   create_admin, test_isolation, _isolation_case,
                           _seed/_cleanup_phase2/3, _verify_phase4_*
```

---

## 5. Biten İşler

**Faz 0-4 (çekirdek)**
- Şema, bağlantı, tanı sayfası (`diag.php`)
- Kimlik: login/logout/dashboard, roller, ilk admin, admin paneli, CSRF
- **KVKK ekip izolasyonu — 6/6 testle kanıtlı** (`scripts/test_isolation.php`)
- Base/tablo/alan yönetimi, 7 alan tipi
- Grid + hücre düzenleme (AJAX)
- Arama, sıralama (3 slot), filtreleme (5 kural, VE/VEYA) — **19/19 test**

**Airtable arayüz kopyası**
- `login.php` — ortada beyaz kart, BCC logo, "Hoş geldiniz", beyaz arka plan
- `register.php` — aynı tasarım; kayıt `is_active=0` + ekipsiz → admin onaylar (KVKK)
- `dashboard.php` — Airtable Home (üst bar, sol panel, base kartları)
- `grid.php` — Airtable Data ekranı (üst bar, sol dikey şerit, tablo sekmeleri, araç şeridi)
- `base.php` — `dashboard.php` → `grid.php` arasındaki `base_tables.php` ara adımını atlayan köprü sayfası (base'in ilk tablosuna yönlendirir, boşsa `base_tables.php`'ye düşer)
- Tablo sekmeleri barı (`grid.php`) — ortak `bcc_list_base_tables()` (base.php ile paylaşılır), "+ Add table" artık `$canEdit`'e bağlı, uzun tablo adında ellipsis
- Sol üst logo/Base adı (`grid.php`) — küp ikonu zaten dashboard linkiydi; Base adı artık yönlendirmesiz `<div>`, yanıltıcı hover kaldırıldı
- Sekme seçenekleri menüsü (`grid.php`) — her sekmede `<details name="gs-table-tab-menu">` ok, Import/Clear data pasif buton, `grid-table-tabs.js` dışarı tık/Escape ile kapatır
- "All tables" arama/geçiş menüsü (`grid.php`) — $siblingTables tek kaynaktan, TR-locale arama, aktif tabloda tik; grid-table-tabs.js'e toggle-tabanlı "tek menü açık" + açılınca arama kutusuna odak eklendi
- `Ctrl+J`/`⌘+J` kısayolu (`grid-table-tabs.js`) — "All tables" panelini aç/kapat, hücre düzenlerken pasif; koyu tooltip + platforma göre Ctrl J/⌘ J rozeti
- Görünüm bilgi popover'ı (`grid.php`) — `.gs-view-trigger` saf CSS hover (JS'siz, ~300ms gecikme); "Created by" atlandı, `views.created_by` kolonu yok (uydurma veri yok) — migration için YAPILACAKLAR-UI.md maddesi açıldı
- Görünüm seçenekleri dropdown'ı (`grid.php`) — `.gs-view-trigger`'a kardeş `<details name="gs-table-tab-menu">` (ok tetikleyici), tüm öğeler pasif; "Rename view" bağlanacağı yer tek satır yorumla işaretli; kapatma mevcut grid-table-tabs.js mekanizmasıyla
- Görünüm adını satır içi düzenleme (`grid.php`+`grid-table-tabs.js`+`public/api/view_rename.php`) — `bcc_get_or_create_default_view()` (views tablosu, yarış-güvenli get-or-create, DDL yok) her table_id için tek satır garanti eder; dblclick yalnızca editor'de render edilir (`data-view-id`), sunucu da `require_role('editor')` ile ayrıca reddeder; Escape/blur yarışı bayrakla çözüldü, 9/9 uçtan uca doğrulandı (geçici script silindi)
- Dashboard görünüm değiştirici — liste/kart (`dashboard.php`+`home.js`) — önceden var olan işlevsiz `.home-icon-btn` çifti gerçek `data-view-mode-btn` tetikleyicisine bağlandı; `localStorage` tek doğrulama noktası `<head>`'teki senkron script (FOUC yok), `.view-mode-list`/`html.home-view-list` aynı kurala akar; "Çalışma alanı" kolonu $teams'ten (yeni sorgu yok), 8/8 uçtan uca doğrulandı (geçici script silindi)
- Dashboard tarih filtresi (`dashboard.php`+`src/audit.php`+`base.php`) — "son açılma" ne `bases`'te ne `audit_log`'da vardı, `log_base_open()` ile audit_log'a `base.open` yazılmaya başlandı (aynı kullanıcı+base 5 dk içinde tekrar açılırsa güncellenir, F5 satır biriktirmez); işlevsiz eski `.home-filter` div/li stub'ı gerçek `<details>`+whitelist'e (`timeframe`) bağlandı; index eksikliği (~167 satırda maliyetsiz) YAPILACAKLAR-UI.md'ye not düşüldü; 13/13 uçtan uca doğrulandı (geçici script silindi)
- Profil dropdown'ı (`src/partials/account_menu.php`, dashboard.php+grid.php ile paylaşılır) — mevcut avatar/toggle/logout iskeletine Account/Manage groups/Notification/Language/Appearance/Contact sales/Upgrade/Tell a friend/Integrations/Builder hub/Trash pasif öğeleri + Business/Beta rozetleri eklendi; tek gerçek işlev "Log out" (adı "Çıkış"tan değiştirildi) korundu; ikinci menü/kapatma mekanizması açılmadı, `max-height`+scroll eklendi, 9/9 uçtan uca doğrulandı (geçici script silindi)
- Kayıt ekleme — (a) yuvarlak buton, (b) tablo tabanı + satırı, (c) Shift+Enter (`grid.php`+`grid.js`+`public/api/record_add.php`) — üçü de TEK uç noktayı çağırır; `bcc_render_grid_data_row()` grid.php'den `src/schema.php`'ye taşınıp AJAX yanıtıyla paylaşıldı; sort/group aktifken `after_record_id` gönderilmez (sona ekler), filtre/sort/group aktifken tek toast (`.ok` sınıfı yeniden kullanıldı, ikinci bildirim sistemi yok); araya ekleme `position` kaydırmasıyla, istek kilidi ile, viewer'da hem buton gizli hem `require_role('editor')` sunucuda; 13/13 + 9/9 uçtan uca doğrulandı (geçici scriptler silindi)
- Sütun dondurma (`grid.php`+`grid-freeze-columns.js`+`public/api/view_config_update.php`) — `views.config` JSON tek kaynak (localStorage yok, DDL yok); `bcc_get_frozen_column_count()` savunmacı okuma (NULL/bozuk/beklenmedik tip → sessizce 1), read-modify-write diğer config anahtarlarını EZMEZ; satır no kolonu (`.grid-rownum`) zaten sticky olduğundan dokunulmadı, yeni dondurma yalnızca EK kolonlara uygulanır (frozen_column_count=1 = eski davranışla birebir aynı); alt sınır 1, üst sınır `bcc_max_frozen_columns()` (görünür kolonların yarısı, hem istemci hem sunucuda); viewer'da tutamaç yok + sunucuda `require_role('editor')`; mousemove `requestAnimationFrame`'e alındı, pencere dışında bırakma `mouseleave`+`buttons===0` ile temizleniyor; kayıt ekleme ile `window.BCC_reapplyFreeze` üzerinden entegre; 28/28 uçtan uca doğrulandı (geçici script silindi)

**PDO → mysqli geçişi (8 faz)**
- 21 dosya, ~113 sorgu, 2 transaction
- PDO projede tek satır kalmadı (kod + yorum + dokümantasyon)
- Regresyonlar (o zamanki durum): **6/6 izolasyon, 8/8 sıralama, 19/19 filtre**.
  Güncel durum: **sıralama 7/8** — row-height özelliği eklenince testin aradığı
  tam `<table class="grid">` dizesi hiç oluşmaz oldu (`row-h-*` sınıfı yüzünden);
  ilgisiz, `field_id` doğrulamasıyla alakasız, bu oturumda defalarca doğrulandı.

**Kod tekrarı temizliği**
- `flash.php` partial (7 dosya)
- `bcc_find_field()` — KVKK sorgusu tek yerde
- `teams.php` → `terms.php`, partials'a geçti
- `account_menu.php` + `account-menu.js` + `bcc_user_initial()`
- `bcc_reorder_sibling()` — sıra değiştirme mantığı

**Grid araçları**
- **Hide fields** — Airtable tarzı toggle, birincil alan gizlenemez, "Find a field", Hide all/Show all
- **Group** — backend çok seviyeli (en fazla 3): parser (`parse_grid_group_rules`), SQL (JOIN+ORDER BY seviye başına), tek geçişte ağaç segmentasyonu, hiyerarşik `data-group-path` ile aç/kapa (iç içe gruplar dahil), `(Empty)` grubu, Collapse/Expand all — hepsi tamam. Panel arayüzü (dropdown, "Add subgroup" linki, seviye silme, durum taşıma) HENÜZ YOK — bkz. Kalan İşler #1; şu an ikinci/üçüncü seviye yalnızca URL'e `group_field_2`/`group_field_3` elle yazılarak kurulabiliyor
- **Row height** — Short/Medium/Tall/Extra Tall + Wrap headers
- **Görünüm listesi paneli (hamburger) — hata düzeltmesi** — `.gs-view-drawer` `.gs-view-toolbar`'ın kardeşiydi, konumlandırma referansı (`position:relative`) toolbar'daydı; drawer toolbar'ın içine taşındı, aç/kapat artık çalışıyor

**Not:** Beş aracın durumu (hidden_fields, sort_*, filter_*, group_*, row_height/wrap_headers) URL'de taşınır ve birbirini korur.

---

## 6. Kalan İşler

| # | İş | Not |
|---|---|---|
| 1 | **Add subgroup — yalnızca panel UI** | Backend (parser/SQL/segmentasyon/aç-kapa, 3 seviyeye kadar) TAMAM — bkz. Biten İşler. Kalan: Group paneli arayüzü (seviye satırları, "Add subgroup" linki, seviye silme) + beş panelin gizli input tekrarını `bcc_grid_state_inputs()` gibi ortak fonksiyona çıkarma |
| 2 | **Arama iyileştirmesi** | Airtable gibi: eşleşmeyi sarı vurgula, "1 of 219" sayacı, yukarı/aşağı ok ile gezinme. Satırları gizleme yerine vurgulama. Tamamen istemci tarafı. |
| 3 | **Color** | İki yöntem: select seçeneğine göre (önce seçeneklere renk desteği gerekir) veya koşula göre (filtre altyapısı kullanılabilir) |
| 4 | **Yeni alan tipleri** | User (@kullanıcı), Saat |
| 5 | **Sütun ekleme akışı** | Airtable gibi: önce tür sor, sonra başlık |
| 6 | **Zengin metin editörü** | Word gibi punto/kalın/link — tek başına büyük iş |
| 7 | **Slack otomasyonu** | Hiç başlanmadı. Apache'ye `curl` uzantısı gerekecek. |
| 8 | Kaydedilebilir görünümler (çoklu) | `views` tablosu artık gerçekten kullanılıyor ama her tabloda TEK varsayılan satır var (`bcc_get_or_create_default_view`) — adı ve `config.frozen_column_count` kalıcı. Kalan: birden fazla adlandırılmış görünüm oluşturma/arasında geçiş; filtre/sort/group hâlâ URL'de, view'e kaydedilmiyor |

**Bilerek yapılmayanlar:** Automations / Interfaces / Forms / Launch / Share and sync → sadece görünüm. `login`/`register` auth shell refactoru (kazanç/risk oranı düşük).

**Bilinen küçük kusur:** İstemci araması grup başlıklarını dikkate almıyor — bir grubun tüm satırları gizlenirse başlık yine görünür.

---

## 7. Çalışma Yöntemi

- Kod **Claude Code** yazar; Claude (bu sohbet) danışman — brifing hazırlar, onay ekranlarını inceler
- Komutlar **tek tek** onaylanır: hep `1 = Yes`. Toplu izin (`2`) **ASLA**
- Silme öncesi: "bunu kullanan kaldı mı?" grep kontrolü
- Onay ekranlarında kod sık **kırpık** görünür (önizleme) → şüphede `php -l`
- Tüm PHP komutlarında `C:/php73/php.exe` — düz `php` XAMPP'in 8.2 CLI'ı
- **Yeni panel eklerken eski işlevsiz butonu SİLMEYİ ilk adım yap** (üç kez unutuldu)
- CSS değişince tarayıcıda **Ctrl+Shift+R** (önbellek iki kez yanılttı)

**Test betikleri** (her büyük değişiklikten sonra):
```
C:/php73/php.exe scripts/test_isolation.php            → 6/6
C:/php73/php.exe scripts/_verify_phase4_sort_search.php → 8/8
C:/php73/php.exe scripts/_verify_phase4_filter.php      → 19/19
```

**Git akışı** (her özellik sonunda, ayrı cmd'den):
```
cd C:\xampp\htdocs\bcc-core
git add .
git commit -m "kisa aciklama"
git push
```
Geri alma: `git checkout .` (son commit'e döner)

### Yeni sohbet akışı (token tasarrufu)

Uzun sohbetlerde her mesaj tüm geçmişi taşır. Bu yüzden **her özellik için yeni sohbet**:

1. Yeni sohbet aç, `docs/PROJE-DURUM.md` ve `docs/YAPILACAKLAR.md` dosyalarını **ekle** (ataç ikonu)
2. "Şimdi şu maddeyi yapacağız, Claude Code için brifing hazırla" de
3. Özellik bitince Claude Code'a dokümanları güncellettir:
   ```
   [Özellik] tamamlandı ve test edildi. İki dokümanı güncelle:
   1) docs/YAPILACAKLAR.md — ilgili bölümü tamamen sil, kalan maddeleri yeniden numaralandır.
   2) docs/PROJE-DURUM.md — "Biten İşler"e bir satır ekle, "Kalan İşler" tablosundan sil.
   Başka hiçbir şeye dokunma.
   ```
4. Commit'le, sohbeti kapat

**Sohbet içinde tasarruf:**
- Lint / grep / "sunucu ayakta mı" gibi rutin onaylarda doğrudan `1` de, sorma
- Sadece **silme içeren**, **şüphelendiğin** veya **anlamadığın** edit'leri danış
- Onay ekranlarındaki uzun SVG kodlarını kırpabilirsin

**Airtable görselleri:** Saklamaya gerek yok. Bir ekranı Airtable'a benzetirken
o an ekran görüntüsü alıp sohbete at — hesaba zaten erişim var.

---

## 8. Sorun Giderme

**"Sonsuz yüklenme" / bağlantı hatası** → MySQL takılmıştır.
XAMPP'te **Stop → 5 sn bekle → Start**. "MySQL yeşil" ≠ "MySQL sağlıklı".
Doğrulama: tarayıcıdan `localhost/diag.php` → "Bağlantı başarılı".

**Şifre sıfırlama** (kullanıcı kendi çalıştırır, şifre paylaşılmaz):
```
cd C:\xampp\htdocs\bcc-core
C:\php73\php.exe -r "require 'config/database.php'; $e='EPOSTA'; $p='YENI_SIFRE'; $h=password_hash($p,PASSWORD_DEFAULT); bcc_execute('UPDATE users SET password_hash=:h WHERE email=:e', array(':h'=>$h,':e'=>$e)); echo 'ok';"
```

**Test kullanıcısı oluşturma:** Admin paneli → Yeni Kullanıcı (aktif gelir) → Ekibe Ata.
Temizlik: `DELETE FROM users WHERE email LIKE '%@bcc-test.local'`

**Klasik hata:** `cd C:\xampp\htdocs\bcc-core` yapmadan komut çalıştırmak.
