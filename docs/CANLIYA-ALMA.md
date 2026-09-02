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
  `migrations/` içindeki dosyaları **numara sırasıyla** (`001` → `024`) uygula.

### 3.3 Yapılandırma dosyalarını oluştur

Bu dosyalar git'e **girmez** (`.gitignore`), her sunucuda elle oluşturulur:

Her birinin yanında şifresiz bir `.example` şablonu var — kopyalayıp doldurun.
**Dördü de zorunludur.**

| Dosya | İçerik |
|---|---|
| `config/database.local.php` | Canlı DB kullanıcı adı/şifresi |
| `config/app.local.php` | `$APP_BASE_URL` (e-postadaki bağlantıların adresi) |
| `config/mail.local.php` | `$MAIL_MODE = 'smtp';` — **atlanırsa hiç mail gitmez** |
| `config/mail_record_send.local.php` | SMTP sunucu + hesap bilgileri |

```bash
cd config
for f in database mail mail_record_send app; do cp $f.local.php.example $f.local.php; done
```

> `config/database.php` XAMPP varsayılanlarını (`root`, boş şifre) taşır.
> Canlıda `.local.php` oluşturulmazsa uygulama bağlanamaz ve her sayfa
> "Bir şeyler ters gitti" döner. DB kullanıcısı **root olmamalı** — yalnızca
> `bcc_core` veritabanına yetkili ayrı bir kullanıcı açın.

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

## 4. Ters vekil (reverse proxy) kullanacaksanız

Giriş denemesi sınırı uygulamada **var** (`migrations/024`, 2026-09-02):
aynı IP + e-posta için 5 hata / 15 dk, aynı IP için 20 hata / 15 dk.
Şifre sıfırlamanın kendi sınırı zaten vardı (`password_reset_attempts`).

Sınır `$_SERVER['REMOTE_ADDR']`'e dayanır; `X-Forwarded-For` bilerek
okunmaz (istemciden gelen bir başlığa güvenmek sınırı tamamen atlatılabilir
yapardı). Nginx/Cloudflare arkasına alırsanız tüm istekler tek bir vekil
IP'sinden geliyor görünür ve 20 hata/15 dk kuralı **tüm kullanıcıları
birlikte kilitler**.

Çözüm: Apache'de `mod_remoteip` + `RemoteIPTrustedProxy` ile `REMOTE_ADDR`'i
gerçek istemciye çevirin. Eşikleri değiştirmek için migration gerekmez —
`src/auth.php`'deki `BCC_LOGIN_MAX_PER_ACCOUNT` / `BCC_LOGIN_MAX_PER_IP` /
`BCC_LOGIN_WINDOW_MINUTES` sabitleri.

---

## 5. İlk kullanıcıyı oluşturma

Sistemde kayıt ekranı var ama ilk **platform admini** komut satırından
oluşturulur:

```bash
php scripts/create_admin.php
```

E-posta / ad / şifre sorar, hesabı doğrudan aktif açar (doğrulama maili
beklemez) ve zaten bir admin varsa çalışmayı reddeder.

Bu kullanıcı `/admin/index.php` üzerinden diğer kullanıcıları ve ekipleri
oluşturur. Roller: `owner` / `editor` / `commenter` / `viewer`
(ayrıntı: `PROJE-DURUM.md` §4).

---

## 6. E-posta (doğrulama + kayıt gönderme)

İki dosya birlikte gerekir:
`config/mail.local.php` → `$MAIL_MODE = 'smtp';` **ve**
`config/mail_record_send.local.php` → sunucu/hesap bilgileri.

`$MAIL_MODE` varsayılanı `'log'`; bu modda SMTP bilgileri dolu olsa bile
mail **gönderilmez**, `storage/mail/` altına dosya yazılır ve hata verilmez
(`src/mailer.php:175`). Kurulumdan sonra gerçekten bir kayıt açıp mailin
geldiğini doğrulayın.

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
- [ ] 6 kez yanlış şifreyle giriş denendi → "Çok fazla başarısız giriş
      denemesi" uyarısı çıkıyor (`migrations/024` uygulanmış demektir)
- [ ] Ters vekil varsa `REMOTE_ADDR` gerçek istemciyi gösteriyor (bkz. §4)

---

## 9. Geliştirme araçları canlıya gitmez

`scripts/` altındaki betikler yalnızca komut satırından çalışır
(`PHP_SAPI` kontrolü) ve web kökü dışındadır — kalmaları zararsızdır, ama
istenirse canlı kopyadan çıkarılabilir. `docs/` de aynı şekilde.
