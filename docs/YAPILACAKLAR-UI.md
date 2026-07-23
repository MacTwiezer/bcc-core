# BCC-Core — UI & Navigasyon Yapılacaklar Planı

> `PROJE-DURUM.md` ve `YAPILACAKLAR.md` ile birlikte kullanılır.
> Her iş için: hedef → mevcut durum → teknik → dikkat → test.
> Bir iş bitince buradan sil, `PROJE-DURUM.md`'nin "Biten İşler" bölümüne ekle.
> Bu belge Claude Code'a doğrudan prompt olarak verilebilir: bir maddeyi kopyala,
> başına "Aşağıdaki işi uygula" yaz, yeterli.

---

## Genel Kurallar (her iş için geçerli)

- **Dış kütüphane yok.** Tamamen Vanilla JS; mevcut CSS mimarisine eklenerek yazılır
- **PHP 7.3.** `bcc_query` / `bcc_fetch_all` + `mysqli` prepared statement. **PDO kullanılmaz**
- Tüm dinamik çıktılar `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` ile kaçırılır
- `base_id` / `table_id` / `view_id` girdilerinde `intval()` zorlaması
- KVKK: `require_team_access()` zinciri hiçbir yeni sorguda atlanmaz
- Yazma işlemlerinde CSRF token + oturum kontrolü
- **"Özelliğini yazmadığım şeyi ekleme"** — belirtilmemiş butonlar yalnızca görünüm
  olarak durur; tıklanınca sayfayı bozmaz, sessizce hiçbir şey yapmaz
- Yeni panel eklerken **eski işlevsiz butonu SİLMEK ilk adım**
- Aç/kapa panellerde ortak desen: `<details>` + dışarı tıklayınca kapanma
- Bitince: `php -l` lint + üç regresyon betiği

**Mevcut kuralla çelişki — karar gerekiyor:**
`YAPILACAKLAR.md`'deki "JS'siz de çalışsın" ilkesi bu belgedeki işlerin çoğuyla
(popover, dropdown, `localStorage`, sürükle-bırak) örtüşmüyor. Öneri: bu kural
**veri katmanı** için geçerli sayılsın (filtre/sort/group URL'de kalmaya devam etsin),
salt görsel kabuk özellikleri JS'e bağlı olabilsin. Başlamadan netleştir.

---

# A. Dashboard (Home) Ekranı

# B. Yönlendirme ve Tablo Gezinimi

# C. Grid Kabuğu (Shell)

---

## 1. `audit_log` index migration'ı (opsiyonel, ileride)

Tarih filtresi (dashboard.php) `audit_log`'da `entity_type='base' AND entity_id=... `
üzerinden alt sorgu/JOIN yapıyor ama tabloda bu kolonlar üzerinde index yok (yalnızca
`team_id`/`user_id` indeksli) — şu an ~167 satırda maliyetsiz, ancak tablo büyürse
`(entity_type, entity_id, action)` bileşik index'i eklenmeli (DDL, ayrı bir migration işi).

---

# D. Grid Tablosu

---

## 2. `views.created_by` migration'ı (opsiyonel, ileride)

`views` tablosunda oluşturan kullanıcı bilgisi yok — görünüm bilgi popover'ındaki
"Created by" satırı bu yüzden atlandı (uydurma veri gösterilmedi). Gerçekten
isteniyorsa: `views` tablosuna `created_by INT UNSIGNED NULL` kolonu + FK migration'ı,
sonra popover'a "Created by" satırının geri eklenmesi.

**Test:**
1. (a) butonuna bas → satır ekleniyor, sayaç artıyor mu
2. (b) satır içi `+` → aynı sonuç, tooltip metni doğru mu
3. (c) ortadaki bir kayıtta `Shift + Enter` → hemen altına ekleniyor mu
4. Yeni satırın ilk hücresi odaklanıyor mu
5. F5 → eklenen kayıtlar duruyor mu
6. Butona hızlıca beş kez bas → beş kayıt mı, daha fazla mı
7. Sort / group / filter aktifken üç yoldan da ekle
8. Viewer rolünde `+` görünmüyor, kısayol çalışmıyor mu
9. `textarea` içinde `Shift + Enter` → satır atlıyor, kayıt eklemiyor mu

---

## Sıra Önerisi

1. **İş 9** (sidebar hatası) — mevcut bir hata, en önce
2. **İş 3 → 4** (yönlendirme → sekmeler) — diğer gezinme işlerinin temeli
3. **İş 8** (logo/Base adı) — 3 ve 4 ile aynı header'a dokunuyor
4. **İş 5, 6, 7** (sekme menüleri, arama, kısayol) — sırayla, birbirine bağlı
5. **İş 10 → 11 → 12** (görünüm popover/dropdown/rename) — üçü aynı butonda,
   tetikleyici ayrımı 10'da kararlaştırılıp diğerlerinde korunur
6. **İş 1, 2** (dashboard) — bağımsız, araya girebilir
7. **İş 13** (profil menüsü) — bağımsız
8. **İş 15** (kayıt ekleme) — grid davranışı
9. **İş 14** (sütun dondurma) — en riskli düzen değişikliği, en sona
