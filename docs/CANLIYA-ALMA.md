# Canlıya Alma Rehberi

Bu doküman projeyi şirket içi kullanıma açmak için gereken adımları anlatır.
Teknik ayrıntılar `PROJE-DURUM.md` §9'da; burada uçtan uca sıra var.

---

## 1. Önce karar: nereye kurulacak?

Uygulama PHP + MySQL çalışan herhangi bir sunucuda çalışır. Şirket içi kullanım
için iki gerçekçi seçenek var:

### A) Şirket içi sunucu (intranet)

Ofisteki bir makine/sunucu. **Yalnızca şirket ağından erişilir.**

- **Artı:** Ek maliyet yok, veri şirket dışına çıkmaz, KVKK açısından en rahat.
- **Eksi:** Evden/dışarıdan erişim yok (VPN kurmadan). Makine kapanırsa sistem
  durur; yedekleme ve kesintisiz güç sizin sorumluluğunuzda.
- **Uygun ise:** Herkes ofisten çalışıyorsa.

### B) Bulut sunucu (VPS)

Aylık kiralanan sunucu (DigitalOcean, Hetzner, Turhost, Natro vb.).

- **Artı:** Her yerden erişim, 7/24 açık, yedekleme sağlayıcıda.
- **Eksi:** Aylık ücret (küçük bir sunucu yeterli), alan adı + SSL kurulumu gerekir.
- **Uygun ise:** Uzaktan/sahadan çalışan varsa. **Çoğu şirket için doğru seçim.**

> Paylaşımlı hosting (cPanel) de teknik olarak çalışır ama `storage/` dizininin
> web kökü **dışında** olması gerektiği için DocumentRoot ayarı yapılamayan
> paketlerde sorun çıkar. Tercih edilmez.

---

## 2. Sunucu gereksinimleri

| Bileşen | Sürüm / not |
|---|---|
| PHP | **7.3 veya üstü** (geliştirme 7.3.33 ile yapıldı) |
| PHP eklentileri | `mysqli`, `mbstring`, `zip` (Excel dışa aktarma), `openssl` (SMTP) |
| Veritabanı | MySQL 5.7+ / MariaDB 10.4+, **utf8mb4 / utf8mb4_unicode_ci** |
| Web sunucusu | Apache (`.htaccess` kullanılıyor) veya Nginx |
| Disk | Kod ~50 MB + dosya ekleri (kullanıma göre büyür) |

**Kritik:** Web sunucusunun kök dizini (DocumentRoot) **`public/`** olmalıdır.
`src/`, `config/`, `storage/`, `scripts/` web'den erişilebilir olmamalıdır —
aksi hâlde yapılandırma dosyaları ve yüklenen belgeler dışarı açılır.

---

## 3. Kurulum adımları

### 3.1 Kodu sunucuya al

```bash
git clone https://github.com/MacTwiezer/bcc-core.git
cd bcc-core
composer install --no-dev
```

`vendor/` git'e girmez, bu yüzden `composer install` şart (PHPMailer buradan gelir).

### 3.2 Veritabanını oluştur

```sql
CREATE DATABASE bcc_core CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Sonra **ikisinden birini** yap — ikisini birden değil:

- **Sıfırdan kurulum:** `schema.sql` dosyasını içe aktar.
- **Mevcut veriyi taşıyorsan:** önce mevcut veritabanının yedeğini yükle, sonra
  `migrations/` içindeki dosyaları **numara sırasıyla** (`001` → `023`) uygula.

### 3.3 Yapılandırma dosyalarını oluştur

Bu dosyalar git'e **girmez** (`.gitignore`), her sunucuda elle oluşturulur:

| Dosya | İçerik | Şablon |
|---|---|---|
| `config/database.local.php` | Canlı DB kullanıcı adı/şifresi | — (aşağıdaki örnek) |
| `config/app.local.php` | `$APP_BASE_URL` (e-postadaki bağlantıların adresi) | `config/app.local.php.example` |
| `config/mail_record_send.local.php` | SMTP hesabı (Office 365) | `config/mail_record_send.local.php.example` |

`config/database.local.php` örneği:

```php
<?php
$DB_HOST = '127.0.0.1';
$DB_NAME = 'bcc_core';
$DB_USER = 'bcc_app';          // root DEĞİL — sadece bu veritabanına yetkili kullanıcı
$DB_PASS = 'buraya-guclu-bir-sifre';
```

> Takip edilen `config/database.php` XAMPP varsayılanlarını (`root`, boş şifre)
> taşır; canlıda **mutlaka** `.local.php` ile ezilmelidir.

`config/app.local.php` içinde `$APP_BASE_URL = 'https://opsflow.sirketiniz.com';`
olmalı — boş bırakılırsa doğrulama e-postalarındaki bağlantı `localhost` çıkar
ve kullanıcılar hesaplarını etkinleştiremez.

### 3.4 Dosya izinleri

```bash
mkdir -p storage/attachments storage/mail storage/backups
chown -R www-data:www-data storage/       # Apache hangi kullanıcı ile çalışıyorsa
chmod -R 755 storage/
```

`storage/` **yazılabilir** olmalı ve **web kökü dışında** kalmalı.

### 3.5 PHP ayarları

`php.ini`:

```ini
display_errors = Off
log_errors = On
upload_max_filesize = 20M     ; dosya eki boyutuna göre
post_max_size = 25M
```

Uygulama `display_errors`'ı kendi de kapatıyor (`src/error_handler.php`) ama
sunucu tarafında da kapalı olmalı.

### 3.6 HTTPS

Bulut sunucuda **zorunlu** (şifreler ağdan geçiyor). Let's Encrypt ücretsiz:

```bash
certbot --apache -d opsflow.sirketiniz.com
```

HTTPS açılınca uygulama oturum çerezini otomatik `secure` işaretler ve
`Strict-Transport-Security` başlığını gönderir — elle ayar gerekmez.

---

## 4. Canlıya almadan önce KAPATILMASI GEREKEN açık

**Giriş denemesi sınırlaması yok.** Şifre sıfırlamada IP bazlı hız sınırı var
(`password_reset_attempts` tablosu) ama giriş ekranında yok: saldırgan sınırsız
şifre deneyebilir.

Seçenekler:

1. **Uygulama katmanında sayaç** — `password_reset_attempts` ile aynı desende
   bir tablo + `attempt_login()` içinde kontrol. (Yapılmadı, DDL gerektiriyor.)
2. **Sunucu katmanında** — fail2ban veya WAF kuralı.
3. **Erişimi kısıtla** — sistem yalnızca şirket ağından/VPN üzerinden
   erişilebiliyorsa risk büyük ölçüde düşer.

En az bir tanesi canlıya çıkmadan uygulanmalı.

---

## 5. İlk kullanıcıyı oluşturma

Sistemde kayıt ekranı var ama ilk **platform admini** elle oluşturulur:

```sql
-- Önce normal kayıt ekranından (register.php) kaydolun, sonra:
UPDATE users SET is_admin = 1, is_active = 1 WHERE email = 'sizin@adresiniz';
```

Bu kullanıcı `/admin/index.php` üzerinden diğer kullanıcıları ve ekipleri
oluşturur. Roller: `owner` / `editor` / `commenter` / `viewer`
(ayrıntı: `PROJE-DURUM.md` §4).

---

## 6. E-posta (doğrulama + kayıt gönderme)

SMTP hesabı `config/mail_record_send.local.php`'de. Office 365 kullanılıyor.

Maillerin spam'e düşmemesi için **DNS kayıtları gerekir** — bunlar kodla
yapılamaz, alan adı yöneticisinin işidir:

- **SPF:** `include:spf.protection.outlook.com`
- **DKIM:** Microsoft 365 Defender → iki CNAME kaydı
- **DMARC:** `_dmarc` TXT kaydı

---

## 7. Yedekleme

Otomatik yedekleme **yok** — kurulması gerekir:

```bash
# Günlük veritabanı yedeği (cron)
0 3 * * * mysqldump -u bcc_app -p'sifre' bcc_core | gzip > /yedek/bcc_$(date +\%F).sql.gz

# Dosya ekleri
0 4 * * * tar czf /yedek/storage_$(date +\%F).tar.gz /yol/bcc-core/storage/
```

Yedeklerin **başka bir makinede** de kopyası olmalı.

---

## 8. Kurulum sonrası kontrol listesi

- [ ] `https://adres/login.php` açılıyor ve HTTPS kilidi var
- [ ] Kayıt ol → doğrulama maili geliyor, bağlantı **canlı adrese** gidiyor (localhost değil)
- [ ] Giriş yapılıyor, tablo açılıyor, satır eklenip düzenlenebiliyor
- [ ] Dosya eki yükleniyor ve indiriliyor (`storage/` izinleri doğru)
- [ ] Excel dışa aktarma çalışıyor (`zip` eklentisi var)
- [ ] `https://adres/../config/database.local.php` **404/403 veriyor** (DocumentRoot doğru)
- [ ] Yanlış adresle `https://adres/grid.php?table_id=999999` → markalı "Tablo bulunamadı" sayfası, 404
- [ ] Giriş denemesi sınırı (bkz. §4) uygulandı

---

## 9. Geliştirme araçları canlıya gitmez

`scripts/` altındaki betikler yalnızca komut satırından çalışır
(`PHP_SAPI` kontrolü) ve web kökü dışındadır — kalmaları zararsızdır, ama
istenirse canlı kopyadan çıkarılabilir. `docs/` de aynı şekilde.
