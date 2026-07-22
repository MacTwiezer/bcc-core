# BCC-Core — Airtable Kopyası / Gereksinim Dokümanı

> Bu doküman, Öykü hanım'ın ekran ekran anlattıklarının ve konuşulan tüm isterlerin
> derli toplu halidir. Emre / Öykü hanım ile üzerinden geçilip onaylanması önerilir.
> Sonundaki "Açık Sorular" kısmı, başlamadan netleşmesi gereken maddeleri içerir.

Son güncelleme: 2026-07-13

---

## 1. Amaç

Şirket içinde (BCC) kullanılan Airtable tabanlı "Bcc-Core" çalışma alanının,
kendi sunucumuzda çalışan bir kopyasını (klon) yazmak. Airtable'ın ücretli plan
sınırlarına (özellikle 5 editör limiti) takılmadan, kendi kontrolümüzde, MySQL
veritabanı üzerinde çalışan bir iç araç.

Teknoloji: **PHP 7.3 + MariaDB (XAMPP MySQL) + mysqli**, Apache (XAMPP) üzerinden.

---

## 2. Kaynak: Mevcut Airtable Yapısı (Bcc-Core)

Airtable'daki mevcut "base" içinde şu tablolar (sekmeler) var. Alan adları ekran
görüntülerinden çıkarıldı; birebir doğrulanmalı.

| Tablo (sekme)     | Alanlar (kolonlar)                                                        |
|-------------------|--------------------------------------------------------------------------|
| **DUYURU**        | Name, Notes (uzun metin), Kategori                                        |
| **ITSM**          | Seller ID, Marka&Kategori, Not, Kimden (kullanıcı), Son Güncelleme (tarih)|
| **EMTİA BİLGİLERİ**| Kategori, Sınıf (sayı), Last Modified                                    |
| **ITSM-GULF**     | Seller ID, Bölge, Marka&Kategori, Not, Kimden, Son Güncelleme            |
| **SÜREÇ**         | Kanal, Talep Nedeni, Kontrol Aşamaları, Ret Nedenleri                    |

Not: Kullanıcı Airtable'da tabloları ve alanları kendi ekliyor. Bu yüzden klon da
**kullanıcı tanımlı tablo/alan** desteklemeli (sabit şema değil) — bkz. Bölüm 5.

---

## 3. Fonksiyonel Gereksinimler

### F1 — Tablolar + tipli alanlar + Grid (tablo) görünümü  *(ÇEKİRDEK)*
Airtable'ın kalbi. Kullanıcı tablo oluşturur, tipli alanlar ekler, satır (kayıt)
girer. Hücreye tıkla-düzenle-kaydet mantığı (AJAX ile anlık kayıt).

### F2 — Alan tipleri
Airtable'da görülen tipler desteklenmeli:
Single line text, Long text (zengin metin), Attachment (dosya eki), Checkbox,
Multiple select, Single select, User (kullanıcı), Date, Phone number, Email, URL,
Number, Currency, Percent, Link (tablolar arası bağlantı), Created/Last modified time.

### F3 — Duyuru (Interface / yayınlanmış görünüm)
Airtable "Interfaces" özelliğinin karşılığı. Ekranlardan çıkan davranış:
- Solda **navigasyon** (SÜREÇ, DUYURU, ITSM, ITSM-GULF, EMTİA BİLGİLERİ).
- Ortada **liste paneli**: duyurular listelenir, **en yeni en üstte** (yeni eklenen
  başa gelir).
- Sağda **detay paneli**: seçili duyurunun **başlığı ayrı**, altında **Last Update**,
  **Notes (içerik)**, **Kategori**.
- **Arama kutusu**: yazılan kelimeyi **hem başlıkta hem içerikte** arar (kullanıcının
  tarifiyle "bizdeki Ctrl+F gibi") ve listeyi anlık filtreler.
- Bir başlığa tıklayınca sağda içeriği açılır.

### F4 — Temsilci görünümü (salt-okunur)
Temsilciler bu yayınlanmış Duyuru arayüzünü **sadece görür** (düzenleyemez).

### F5 — Slack'e otomatik duyuru
Yeni bir duyuru eklendiğinde, seçilen bir Slack kanalına otomatik mesaj gitmeli
(ör. "duyuru-bcc-ty-seller-fraud" kanalı): "şu duyuru eklendi …" gibi. Airtable'da
yapılamayan, özellikle istenen bir özellik. Teknik olarak Slack Incoming Webhook ile.

### F6 — Zengin metin (notlar/uzun metin alanında)
Şu an Airtable uzun metinde punto/font büyütme yok, link gömülemiyor (sadece emoji
eklenebiliyor). İstenen: **kalın/italik**, **font büyüklüğü**, **link gömme** ve
temel biçimlendirme. (Teknik: alanda sınırlı/temizlenmiş HTML saklanır.)

### F7 — Türkçe karakter
ş, ç, ğ, ı, İ, ö, ü her yerde düzgün çalışmalı (giriş, kayıt, arama, gösterim).
Kökten çözüm: veritabanı ve tablolar **utf8mb4**, bağlantı utf8mb4, sayfalar UTF-8.
(Zaten bu şekilde kuruldu.)

### F8 — Çok kullanıcı + roller + KVKK veri ayrımı
Sistem genel (herkese açık) yapılacaksa: her ekibin/kişinin verisi **ayrı** olmalı;
KVKK gereği ekipler birbirinin süreçlerini **göremez**. **ATP** de ayrı tutulmalı.
Öneri: veriler **ekip (team)** bazında izole edilir. Örnek ekipler: **TY, GULF, ATP**.
Kullanıcı yalnızca üyesi olduğu ekiplerin verisini görür.

### F9 — Editör limiti yok
Airtable Free planında maksimum 5 editör var; çoğu kişi "Read only" kalıyor. Kendi
sistemimizde böyle bir sınır olmayacak; herkese uygun yetki verilebilecek.

### F10 — İzin seviyeleri
En az: **owner / editor / commenter / viewer**. (Airtable'daki Editor / Read-only
ayrımının genişletilmiş hali.)

---

## 4. Teknik Mimari (özet)

- **Dil:** PHP 7.3.33 (Apache modülü + CLI).
- **Veritabanı:** MariaDB 10.4 (XAMPP'in MySQL'i), 127.0.0.1:3306, DB `bcc_core`.
- **Erişim katmanı:** mysqli + prepared statements (bcc_query yardımcı katmanı,
  named parametre desteği).
- **Karakter:** utf8mb4 (DB, tablo, bağlantı ve sayfa seviyesinde).
- **Apache:** XAMPP Apache'nin kendi PHP 8.2 (VC2019) modülü yerine PHP 7.3 (VC15)
  `httpd-xampp.conf`'a eklenen `LoadModule php7_module` + `PHPIniDir` satırlarıyla
  bağlandı; DocumentRoot proje kökündeki `public/` klasörü. Detay: `README.md`.

---

## 5. Veri Modeli Yaklaşımı — EAV (Entity-Attribute-Value)

Kullanıcı kendi tablolarını/alanlarını oluşturacağı için, her kullanıcı tablosunu
gerçek bir MySQL tablosu yapmak yerine **meta-şema** kullanıyoruz:

- `teams` → KVKK izolasyonu (TY / GULF / ATP …)
- `users`, `team_members` → kullanıcılar ve ekip+rol üyelikleri
- `bases` → çalışma alanı (ekibe bağlı)
- `tables` → sekmeler (DUYURU, ITSM …)
- `fields` → alanlar (tip + seçenekler)
- `records` → satırlar
- `cell_values` → hücreler (tipe göre value_text / value_number / value_date / value_json)
- `record_links` → tablolar arası bağlantı (link alanı)
- `views` → görünümler (grid ve "interface"/duyuru arayüzü ayarları)
- `attachments` → dosya ekleri
- `slack_webhooks` → duyuru → Slack gönderimi ayarı
- `audit_log` → kim neyi ne zaman değiştirdi (KVKK için de faydalı)

Ayrıntı: `schema.sql`.

---

## 6. Yol Haritası (Fazlar)

- **Faz 0 — Kurulum:** ortam + `schema.sql` içe aktarma + DB bağlantısı. **(TAMAM)**
- **Faz 1 — Kimlik + ekipler:** kayıt/giriş (session), ekip/rol, KVKK izolasyonu.
- **Faz 2 — Tablo + alanlar:** tablo/alan oluştur-düzenle-sil (text, number, checkbox,
  date, single/multiple select önce).
- **Faz 3 — Kayıtlar + Grid görünüm (ÇEKİRDEK / DEMO):** kayıt CRUD + düzenlenebilir
  tablo + AJAX hücre kaydetme. ← Öykü hanım'a gösterilecek asıl demo.
- **Faz 4 — Duyuru arayüzü (F3/F4):** liste + arama (başlık+içerik) + detay + salt-okunur.
- **Faz 5 — Zengin metin (F6):** notlarda biçimlendirme + link gömme.
- **Faz 6 — Slack duyurusu (F5):** webhook ile otomatik gönderim.
- **Faz 7 — Bağlantılar + görünüm özellikleri:** link alanı, filtre/sıralama/gizleme.
- **Faz 8 — Ek görünümler & cila:** form/kanban/takvim, dosya eki, dış API.

**MVP = Faz 0–3.**

---

## 7. Açık Sorular (başlamadan netleşmeli)

1. **Kapsam:** Sadece sana özel mi, yoksa tüm ekiplerin kullanacağı genel sistem mi?
   (Genel ise F8 — KVKK ekip ayrımı — baştan tasarlanmalı.)
2. **Yayın sunucusu:** Proje nerede yayına alınacak, PHP sürümü kaç? (Şu an yerelde
   7.3 kullanılıyor; sunucu farklıysa uyum planlanmalı.)
3. **Slack:** Hangi kanal(lar)a gidecek? Incoming Webhook URL'i alınabiliyor mu?
   Her ekibin ayrı kanalı mı olacak?
4. **ATP:** Tam olarak nedir, hangi verileri kapsar, kimler erişecek?
5. **Ekipler/roller:** Kesin ekip listesi ve kimin hangi ekipte/rolde olduğu.
6. **Alan doğrulaması:** Bölüm 2'deki tablo/alan listesi birebir doğru mu?
