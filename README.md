# BCC-Core

Airtable benzeri iç araç — PHP 7.3 + MariaDB (XAMPP MySQL) + PDO.

Gereksinimler ve veri modeli için: `docs/GEREKSINIMLER.md`.

## Ortam

- PHP: `C:\php73\php.exe` → PHP 7.3.33 (thread-safe, VC15)
- Veritabanı: MariaDB 10.4 (XAMPP MySQL), `127.0.0.1:3306`, user `root`, şifre yok
- Veritabanı adı: `bcc_core` (utf8mb4_unicode_ci, önceden oluşturulmuş olmalı)
- Apache kullanılmıyor (PHP 7.3 / VC15, XAMPP Apache'sinin PHP 8.2 / VC2019 modül
  yapısıyla uyumsuz). Geliştirme sunucusu olarak `php -S` kullanılır.

## Kurulum — şemayı içe aktar

XAMPP MySQL çalışırken, proje klasöründe:

```
C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root bcc_core < schema.sql
```

Alternatif (mysql istemcisi yoksa, PHP ile):

```
C:\php73\php.exe -r "$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=bcc_core;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $pdo->exec(file_get_contents('schema.sql')); echo 'OK\n';"
```

Bu, şu tabloları oluşturur: `teams`, `users`, `team_members`, `bases`, `tables_meta`,
`fields`, `records`, `cell_values`, `record_links`, `views`, `attachments`,
`slack_webhooks`, `audit_log`.

## Çalıştırma

Proje kök klasöründe:

```
C:\php73\php.exe -S localhost:8000 -t public
```

Tarayıcıda: http://localhost:8000/

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
    database.php    # PDO bağlantısı (bcc_get_pdo())
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
