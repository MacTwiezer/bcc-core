# OpsFlow — opsflow.bcccrm.com

İç araç — PHP 7.3 + MariaDB (XAMPP MySQL) + mysqli. BCC İletişim için
geliştirilen, KVKK ekip izolasyonlu (TY / GULF / ATP), Slack entegrasyonlu
tablo/veritabanı platformu.

> **Ad ve dizin ayrımı:** ürün adı **OpsFlow**, canlı adres
> **opsflow.bcccrm.com**. Depo dizini (`bcc-core`), veritabanı adı (`bcc_core`),
> PHP fonksiyon önekleri (`bcc_*`) ve CSS değişkenleri (`--bcc-*`) İÇ
> TANIMLAYICILARDIR; kullanıcıya hiçbir yerde görünmezler ve bilerek
> değiştirilmemiştir (bkz. `config/app.php` marka bölümü).

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

Bu, **21 tablo** oluşturur (hepsi InnoDB + utf8mb4): `teams`, `users`,
`team_members`, `bases`, `user_starred_bases`, `tables_meta`, `fields`,
`records`, `cell_values`, `attachments`, `comments`, `views`,
`user_favorite_views`, `record_view_log`, `slack_webhooks`,
`slack_routing_rules`, `slack_watched_fields`, `audit_log`,
`user_read_notifications`, `login_attempts`, `password_reset_attempts`.

`schema.sql` **otoriter kaynaktır** — ayrı bir `migrations/` klasörü YOKTUR
(eski migration dosyaları, içerikleri şemaya işlendikten sonra tek tek
kaldırıldı). Şu an canlı veritabanındaki tablo kümesiyle birebir aynıdır.

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

Büyük bir değişiklikten sonra çalıştırılır. Her biri kendi test verisini kurup
sonunda temizler; koşu sonrası kullanıcı/ekip/base/kayıt sayıları değişmemelidir.

```
C:\php73\php.exe scripts\test_isolation.php            → KVKK ekip izolasyonu
C:\php73\php.exe scripts\_verify_phase4_sort_search.php → Sıralama + arama
C:\php73\php.exe scripts\_verify_phase4_filter.php      → Filtreleme
```

Tam paket (**63** `_verify_*` betiği) ve beklenen sonuçlar:
`docs/PROJE-DURUM.md` §7.

> Bilinen iki kasıtlı istisna: `_verify_mail_verification.php` ve
> `_verify_mail_dispatch_icons.php` `storage/mail/_onizleme_*.html` bırakır —
> sabit adlı, her koşuda üzerine yazılır (birikmez), mailin gerçekten nasıl
> göründüğünü gözle kontrol etmek içindir.

## Klasör yapısı

```
bcc-core/
  config/database.php      mysqli bağlantısı + yardımcılar (bcc_query, bcc_fetch_*, ...)
  src/                     ortak PHP mantığı — bootstrap, api_bootstrap, auth,
                           csrf, schema, audit, slack, validation, errors,
                           error_handler, mailer, mail_template, xlsx_reader,
                           xlsx_writer, note_view_report, share_modal_payload,
                           demo_accounts, partials/ (paylaşılan HTML parçaları)
  public/                  Apache DocumentRoot
    *.php                  login/register/dashboard/grid/interface/account/...
    admin/                 platform admin paneli (kullanıcı/ekip yönetimi)
    api/                   AJAX uçnoktaları (hücre kaydetme, kayıt/görünüm
                           yönetimi, dosya eki, XLSX içe/dışa aktarma, ...)
    assets/                CSS/JS/statik dosyalar
  scripts/                 CLI araçları (create_admin, regresyon test betikleri)
  docs/
    PROJE-DURUM.md          GÜNCEL proje durumu (otoriter kaynak, sık güncellenir)
    GEREKSINIMLER.md        orijinal (dondurulmuş) istekler
    CANLIYA-ALMA.md         sunucuya alma adımları + yerel yapılandırma şablonları
  schema.sql               veritabanı şeması (otoriter kaynak, migrations/ YOK)
```

## Proje durumu

Faz 0-7 (çekirdek: kimlik/roller, tablo/alan yönetimi, Grid + AJAX hücre
düzenleme, filtre/sıralama/gruplama, Duyuru arayüzü, zengin metin, Slack
entegrasyonu) tamamlandı — artı yol haritasında hiç olmayan onlarca özellik
(Trash, koyu/açık tema, sürükle-bırak sıralama, XLSX içe/dışa aktarma, Kanban
görünümü, yorumlar, bildirim paneli, hesap yönetimi, favicon...). **Ayrıntılı ve
güncel ilerleme takibi için:** `docs/PROJE-DURUM.md` → "Biten İşler" / "Kalan
İşler" bölümlerine bakın.

> **Kaldırılan özellikler** (README'de eskiden vardı, artık YOK): CSV içe/dışa
> aktarma ve herkese açık Form görünümü. İkisi de kod tabanından tamamen
> çıkarıldı; ayrıntı `docs/PROJE-DURUM.md` §10'da.
