# BCC-Core

Airtable benzeri iç araç — PHP 7.3 + MariaDB (XAMPP MySQL) + mysqli.

Gereksinimler ve veri modeli için: `docs/GEREKSINIMLER.md`.

## Ortam

- Proje klasörü: `C:\xampp\htdocs\bcc-core` (Apache DocumentRoot = `public/`)
- PHP: `C:\php73\php.exe` → PHP 7.3.33 (thread-safe, VC15)
- Veritabanı: MariaDB 10.4 (XAMPP MySQL), `127.0.0.1:3306`, user `root`, şifre yok
- Veritabanı adı: `bcc_core` (utf8mb4_unicode_ci, önceden oluşturulmuş olmalı)
- Apache: XAMPP'in kendi PHP 8.2 modülü yerine PHP 7.3 (VC15) kullanılıyor. Bu,
  `C:\xampp\apache\conf\extra\httpd-xampp.conf` içine eklenen iki satırla sağlandı:
  ```
  LoadModule php7_module "C:/php73/php7apache2_4.dll"
  PHPIniDir "C:/php73"
  ```
  Ayrıca `httpd.conf`'ta `DocumentRoot "C:/xampp/htdocs/bcc-core/public"` olarak ayarlı.

## Kurulum — şemayı içe aktar

XAMPP MySQL çalışırken, proje klasöründe:

```
C:\xampp\mysql\bin\mysql.exe -u root bcc_core < schema.sql
```

Bu, şu tabloları oluşturur: `teams`, `users`, `team_members`, `bases`, `tables_meta`,
`fields`, `records`, `cell_values`, `record_links`, `views`, `attachments`,
`slack_webhooks`, `audit_log`.

## Çalıştırma

XAMPP Control Panel'den **Apache** ve **MySQL**'i başlatın (Start).

Tarayıcıda: http://localhost/

## Test — Faz 0 tanı sayfası

`public/index.php` şunları gösterir:

- Veritabanı bağlantı durumu ve sunucu sürümü
- Aktif veritabanı adı ve bağlantı karakter seti (utf8mb4 olmalı)
- Mevcut tabloların listesi (schema.sql içe aktarıldıysa 13 tablo görünür)
- Türkçe karakter round-trip testi (ş ç ğ ı İ ö ü yazılıp okunuyor mu)

Sayfa yeşil "OK" mesajları gösteriyorsa Faz 0 tamamlanmış demektir.

## Klasör yapısı

```
bcc-core/
  config/
    database.php    # mysqli bağlantısı (bcc_get_mysqli(), bcc_query ve yardımcılar)
  public/
    index.php        # Faz 0 tanı sayfası
  docs/
    GEREKSINIMLER.md
  schema.sql
  README.md
```

## Faz durumu

- **Faz 0 — Kurulum:** TAMAM (schema.sql, config/database.php, tanı sayfası)
- Faz 1 — Kimlik + ekipler: bekliyor
- Faz 2 — Tablo + alanlar: bekliyor
- Faz 3 — Kayıtlar + Grid görünüm: bekliyor
