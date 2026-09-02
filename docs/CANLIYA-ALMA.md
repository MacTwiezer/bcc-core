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

- **Sıfırdan kurulum:** `schema.sql` dosyasını içe aktar. Güncel şemanın
  tamamını üretir (doğrulandı: 21 tablo, 147 kolon, 76 index, 40 yabancı
  anahtar — canlı veritabanıyla farksız).
- **Mevcut veriyi taşıyorsan:** canlı veritabanının `mysqldump` yedeğini
  yükle. Ayrı bir yükseltme adımı YOK; şema zaten güncel gelir.

### 3.3 Yapılandırma dosyalarını oluştur

Bu dosyalar git'e **girmez** (`.gitignore`), her sunucuda elle oluşturulur:

Şablon dosyası yoktur; dördü de aşağıdaki bloklardan elle yazılır.

| Dosya | Zorunlu mu? |
|---|---|
| `config/database.local.php` | **Evet** — yoksa uygulama veritabanına bağlanamaz |
| `config/mail.local.php` | **Evet** — yoksa hiç mail gitmez (sessizce) |
| `config/mail_record_send.local.php` | **Evet** — SMTP hesabı burada |
| `config/app.local.php` | Yalnızca adres `opsflow.bcccrm.com` değilse |

`config/mail.local.php` — mail gönderimini açar. Tek satır:

```php
<?php
$MAIL_MODE = 'smtp';   // 'log' = gönderme, dosyaya yaz | 'smtp' = gerçekten gönder
```

`config/mail_record_send.local.php` elle oluşturulur — SMTP hesabı burada.
`mail.local.php` `'smtp'` moduna alınmışsa **zorunlu**, yoksa mail gitmez:

```php
<?php
return array(
    'host'       => 'smtp.office365.com',
    'port'       => 587,
    'encryption' => 'tls',          // 587 ile 'tls', 465 ile 'ssl'
    'username'   => 'gonderen@sirketiniz.com',
    'password'   => 'hesap-sifresi',
    'from_email' => 'gonderen@sirketiniz.com',   // username ile AYNI olmalı
    'from_name'  => 'Şirket Adı',
);
```

> Office 365, kimlik doğrulanan kutudan farklı bir `from_email` reddeder —
> `username` ile aynı yazın. Görünen ad `config/mail.php`'deki
> `$MAIL_FROM_NAME`'den gelir, buradaki `from_name` yalnızca yedektir.

`config/database.local.php` elle oluşturulur (**zorunlu** — yoksa uygulama
veritabanına bağlanamaz). Yalnızca değiştirmek istediğiniz satırları yazın;
yazmadıklarınız `config/database.php`'deki varsayılanda kalır:

```php
<?php
$DB_HOST    = '127.0.0.1';
$DB_PORT    = '3306';
$DB_NAME    = 'bcc_core';
$DB_USER    = 'bcc_app';        // root DEĞİL — sadece bu veritabanına yetkili
$DB_PASS    = 'guclu-bir-sifre';
$DB_CHARSET = 'utf8mb4';
```

Dördüncüsü koşullu: `config/app.php` varsayılanı `https://opsflow.bcccrm.com`.
Sunucu BAŞKA bir adreste yayınlanacaksa `config/app.local.php` elle oluşturulur:

```php
<?php
$APP_BASE_URL = 'https://opsflow.sirketiniz.com';   // sonunda / OLMASIN
```

Yazılmazsa doğrulama e-postalarındaki bağlantılar yanlış alan adına gider ve
kullanıcılar hesaplarını etkinleştiremez.

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

Giriş denemesi sınırı uygulamada **var** (`login_attempts` tablosu, `schema.sql`):
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
      denemesi" uyarısı çıkıyor (`login_attempts` tablosu çalışıyor demektir)
- [ ] Ters vekil varsa `REMOTE_ADDR` gerçek istemciyi gösteriyor (bkz. §4)

---

## 9. Geliştirme araçları canlıya gitmez

`scripts/` altındaki betikler yalnızca komut satırından çalışır
(`PHP_SAPI` kontrolü) ve web kökü dışındadır — kalmaları zararsızdır, ama
istenirse canlı kopyadan çıkarılabilir. `docs/` de aynı şekilde.
