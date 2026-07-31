# BCC-Core

Airtable benzeri iç araç — PHP 7.3 + MariaDB (XAMPP MySQL) + mysqli. BCC şirketi
için geliştirilen, KVKK ekip izolasyonlu (TY / GULF / ATP), Slack entegrasyonlu
bir Airtable klonu.

- **Projenin güncel/canlı durumu için:** `docs/PROJE-DURUM.md` — her özellik
  bittiğinde orası güncellenir, bu README güncellenmez. Yeni bir sohbete
  başlarken bağlam olarak o dosya yapıştırılır.
- Orijinal (dondurulmuş) istekler ve veri modeli gerekçesi: `docs/GEREKSINIMLER.md`.

## Ortam

- Proje klasörü: `C:\xampp\htdocs\bcc-core` (Apache DocumentRoot = `public/`)
- PHP: `C:\php73\php.exe` → PHP 7.3.33 (thread-safe, VC15)
- Veritabanı: MariaDB 10.4 (XAMPP MySQL), `127.0.0.1:3306`, user `root`, şifre yok
- Veritabanı adı: `bcc_core` (utf8mb4_unicode_ci, önceden oluşturulmuş olmalı)
- Erişim katmanı: mysqli + prepared statement (`config/database.php`) — PDO kullanılmaz
- Apache: XAMPP'in kendi PHP 8.2 modülü yerine PHP 7.3 (VC15) kullanılıyor. Bu,
  `C:\xampp\apache\conf\extra\httpd-xampp.conf` içine eklenen iki satırla sağlandı:
  ```
  LoadModule php7_module "C:/php73/php7apache2_4.dll"
  PHPIniDir "C:/php73"
  ```
  Ayrıca `httpd.conf`'ta `DocumentRoot "C:/xampp/htdocs/bcc-core/public"` olarak ayarlı.

## Kurulum

XAMPP MySQL çalışırken, proje klasöründe şemayı içe aktarın:

```
C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root bcc_core < schema.sql
```

Bu, 15 tablo oluşturur (hepsi InnoDB + utf8mb4): `teams`, `users`, `team_members`,
`bases`, `user_starred_bases`, `tables_meta`, `fields`, `records`, `cell_values`,
`attachments`, `views`, `user_favorite_views`, `slack_webhooks`,
`slack_routing_rules`, `audit_log`.

Farklı bir MySQL/MariaDB kurulumu (başka kullanıcı/şifre/port) kullanıyorsanız
`config/database.php`'yi DEĞİŞTİRMEYİN — yanına `config/database.local.php`
oluşturup (`.gitignore`'da, commit'lenmez) `$DB_*` değişkenlerini orada yeniden
atayın.

İlk (ve yalnızca ilk) admin kullanıcıyı oluşturmak için:

```
C:\php73\php.exe scripts\create_admin.php
```

## Çalıştırma

XAMPP Control Panel'den **Apache** ve **MySQL**'i başlatın (Start). Tarayıcıda:
http://localhost/

## Tanı sayfası

`public/diag.php` (yalnızca platform admini erişebilir) veritabanı bağlantı
durumunu, sunucu/PHP sürümünü, tablo listesini ve Türkçe karakter round-trip
testini gösterir. Sorun giderme (ör. "sonsuz yükleniyor" hatası) için
`docs/PROJE-DURUM.md` → "Sorun Giderme" bölümüne bakın.

## Test betikleri

Büyük bir değişiklikten sonra çalıştırılır (her biri kendi test verisini kurup
sonunda temizler, kalıcı iz bırakmaz):

```
C:\php73\php.exe scripts\test_isolation.php            → KVKK ekip izolasyonu
C:\php73\php.exe scripts\_verify_phase4_sort_search.php → Sıralama + arama
C:\php73\php.exe scripts\_verify_phase4_filter.php      → Filtreleme
```

## Klasör yapısı

```
bcc-core/
  config/database.php      mysqli bağlantısı + yardımcılar (bcc_query, bcc_fetch_*, ...)
  src/                     ortak PHP mantığı — bootstrap, auth, schema, audit,
                           csrf, slack, validation, xlsx_writer, error_handler,
                           api_bootstrap, partials/ (paylaşılan HTML parçaları)
  public/                  Apache DocumentRoot
    *.php                  login/register/dashboard/grid/interface/account/...
    admin/                 platform admin paneli (kullanıcı/ekip yönetimi)
    api/                   AJAX uçnoktaları (hücre kaydetme, kayıt/görünüm
                           yönetimi, dosya eki, CSV içe/dışa aktarma, ...)
    assets/                CSS/JS/statik dosyalar
  scripts/                 CLI araçları (create_admin, regresyon test betikleri)
  migrations/              tarihsel DDL script'leri (çoğu zaten schema.sql'e
                           katlanmış, kayıt/referans amaçlı tutuluyor)
  docs/
    PROJE-DURUM.md          GÜNCEL proje durumu (otoriter kaynak, sık güncellenir)
    GEREKSINIMLER.md        orijinal (dondurulmuş) istekler
    YAPILACAKLAR-UI.md      aktif UI iş listesi
  schema.sql               veritabanı şeması (otoriter kaynak)
```

## Proje durumu

Faz 0-7 (çekirdek: kimlik/roller, tablo/alan yönetimi, Grid + AJAX hücre
düzenleme, filtre/sıralama/gruplama, Duyuru arayüzü, zengin metin, Slack
entegrasyonu) tamamlandı — artı yol haritasında hiç olmayan onlarca özellik
(Trash, koyu/açık tema, sürükle-bırak sıralama, CSV içe/dışa aktarma, bildirim
paneli, hesap yönetimi, favicon...). **Ayrıntılı ve güncel ilerleme takibi
için:** `docs/PROJE-DURUM.md` → "Biten İşler" / "Kalan İşler" bölümlerine bakın.
