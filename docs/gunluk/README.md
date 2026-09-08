# Günlük Çalışma Notları

**2026-09-08 tarihinden itibaren** her çalışma günü için bu klasörde **ayrı bir
dosya** tutulur: `docs/gunluk/YYYY-AA-GG.md`.

## Neden ayrı dosya

Proje canlıya alındı (2026-09-07). Bundan sonraki değişiklikler artık yeni
özellik geliştirme değil, **kullanıcı raporlarına göre düzeltme**. Bu iş türünde
"hangi gün, hangi rapor yüzünden, hangi dosyaya dokunduk" sorusunun cevabı
gerekiyor — `PROJE-DURUM.md`'nin uzun anlatı biçimi buna uygun değil.

## Kural

- Her gün kendi dosyasına yazılır, **eski günün dosyası değiştirilmez**.
- Her madde şunları içerir: **ne istendi → ne yapıldı → hangi dosyalar değişti →
  nasıl test edildi**.
- Dokunulan **her dosya yolu** yazılır (satır numarasıyla birlikte olursa daha iyi).
- Henüz çözülmemiş gözlemler "Açık maddeler" başlığı altında bırakılır.
- Bir iş bitip test edilince ayrıca `docs/PROJE-DURUM.md` "Biten İşler"e tek
  satır özet eklenir — günlük dosya **detayı**, PROJE-DURUM **özeti** tutar.

## Şablon

```markdown
# YYYY-AA-GG

## Yapılanlar
### 1. <Başlık>
- **İstek/Rapor:** ...
- **Kök neden:** ...
- **Yapılan:** ...
- **Değişen dosyalar:** `yol/dosya.php:satır` — ne değişti
- **Test:** ...

## Açık maddeler
- ...
```
