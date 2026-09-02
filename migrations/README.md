# migrations/

Tarihsel DDL kayıtları. **Sıfırdan kurulumda bu klasör kullanılmaz** — `schema.sql`
tek başına yeterlidir ve güncel şemayı birebir üretir (doğrulandı: 21 tablo,
147 kolon, 76 index, 40 yabancı anahtar; canlı veritabanıyla farksız).

## Hangi yol?

| Durum | Yapılacak |
|---|---|
| Yeni/boş veritabanı | Yalnızca `schema.sql` içe aktarılır |
| Mevcut veriyi taşıma | Önce yedek yüklenir, sonra eksik `migrations/*.sql` numara sırasıyla uygulanır |

**İkisi birlikte çalıştırılmaz.**

## Numara boşlukları normaldir

`001` ve `003`–`007` **yoktur** — içerikleri `schema.sql`'e katlandığı için
2026-07-28'de bilerek silindiler (git geçmişinde duruyorlar). Mevcut en küçük
dosya `002`. Boşluk gördüğünüzde eksik dosya aramayın.

## ⚠️ Bu dosyalar MariaDB gerektirir

10 dosyada toplam 25 ifade MariaDB'ye özgü sözdizimi kullanıyor ve **MySQL
5.7/8.0'da sözdizimi hatası verir**:

```
ALTER TABLE ... ADD COLUMN IF NOT EXISTS
ALTER TABLE ... DROP COLUMN IF EXISTS
ALTER TABLE ... DROP FOREIGN KEY IF EXISTS
CREATE INDEX IF NOT EXISTS / DROP INDEX IF EXISTS
```

(`CREATE TABLE IF NOT EXISTS` bundan farklıdır, iki motorda da geçerlidir.)

Bu, migration'ların tekrar tekrar çalıştırılabilir olması için bilinçli bir
tercihti ve geliştirme MariaDB 10.4 üzerinde yapıldı. Sonucu:

- **MySQL sunucuya sıfırdan kurulum:** sorunsuz — `schema.sql` portatiftir.
- **MySQL sunucuya veri taşıma:** bu dosyalar olduğu gibi çalışmaz. Ya hedef
  MariaDB olmalı, ya da `IF [NOT] EXISTS` ekleri elle çıkarılıp her ifadenin
  yalnızca bir kez uygulandığından emin olunmalı.
