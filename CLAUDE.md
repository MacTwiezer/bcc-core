# OpsFlow (bcc-core)

## ÖNCE BUNU OKU

**`docs/PROJE-DURUM.md` bu projenin otoriter bağlam kaynağıdır.** Mimari, biten
işler, kalan işler, kalıcı kurallar, güvenlik değişmezleri, test betikleri, git
akışı ve sorun giderme — hepsi orada ve güncel tutuluyor. Herhangi bir işe
başlamadan önce oku.

Veri modelinin gerekçesi: `docs/GEREKSINIMLER.md` (dondurulmuş orijinal
istekler). Açık UI iş listesi: `docs/YAPILACAKLAR-UI.md`.

Bir iş bitip **test edildikten sonra** `docs/PROJE-DURUM.md` güncellenir:
"Biten İşler"e bir satır eklenir, "Kalan İşler"den ilgili madde çıkarılır.
Proje kendi kendini belgeler; bir sonraki oturum (başka bir araç/model dahil)
o dosyayı okuyarak devam edebilmeli.

## İlk komuttan ÖNCE bilinmesi gereken dört şey

Bu dört madde bilerek burada tekrarlanıyor: `docs/PROJE-DURUM.md` okunmadan
atılan bir ilk adım bunlardan birini ihlal ederse geri dönüşü pahalı olur.
Geri kalan HİÇBİR kural buraya kopyalanmaz — tek kaynak o dosyadır.

1. **PHP her zaman `C:/php73/php.exe`** — bu kod tabanı 7.3.33 hedefler (typed
   property / 7.4+ dil özelliği YOK). Makinede **iki PHP kurulu**: `C:/php73`
   (7.3.33) ve `C:/xampp/php` (8.2.12). Düz `php` bugün 7.3'ü çözüyor (PATH'te
   önce geliyor) ama buna GÜVENME — PATH sırası bir XAMPP kurulumuyla ya da
   başka bir aracın kurulumuyla değişir ve o gün `php` sessizce 8.2 olur.
   Tam yol yazmak bu belirsizliği tamamen ortadan kaldırır.
2. **`php -S` KULLANILMAZ.** Uygulama Apache (XAMPP) üzerinden servis edilir,
   DocumentRoot = `public/`, adres `http://localhost/`.
3. **MySQL'i kendin başlatma.** Yalnızca XAMPP Control Panel'den
   (Stop → 5 sn bekle → Start). Elle başlatılan/temiz kapatılmayan mysqld
   InnoDB tablo alanlarını bozdu (geçmişte `attachments` kayboldu).
4. **`git add .` / `git add -A` KULLANILMAZ.** Değişen dosyalar tek tek
   eklenir; her commit öncesi `git status` + `git diff --cached` ile sır
   sızıntısı kontrol edilir (takip edilmeyen dosyalar `git diff`'te GÖRÜNMEZ,
   ayrıca taranmalı).

## Veritabanı erişimi

**mysqli, PDO yok.** `config/database.php`: `bcc_query` / `bcc_fetch_all` /
`bcc_fetch_one` / `bcc_fetch_column` / `bcc_execute` / `bcc_last_insert_id` /
`bcc_begin_transaction` / `bcc_commit` / `bcc_rollback`.

## Bitirmeden önce

- Değişen her PHP dosyasında `C:/php73/php.exe -l <dosya>`
- İlgili `scripts/_verify_*.php` regresyon betikleri — çalıştırma ve beklenen
  sonuçlar `docs/PROJE-DURUM.md` §7'de. Her biri kendi test verisini kurup
  temizler; koşu sonrası kullanıcı/ekip/base/kayıt sayıları ve
  `slack.notify_sent` **değişmemelidir** (kirlilik ölçütü).
  Bilinen iki istisna, ikisi de kasıtlı: `_verify_mail_verification.php` ve
  `_verify_mail_dispatch_icons.php` `storage/mail/_onizleme_*.html` bırakır —
  sabit adlı, her koşuda üzerine yazılır (birikmez) ve mailin gerçekten nasıl
  göründüğünü gözle kontrol etmek içindir; betik yolu ekrana basar.
- UI değişikliğiyse tarayıcıda gerçekten dene (CSS önbelleği: Ctrl+Shift+R)
