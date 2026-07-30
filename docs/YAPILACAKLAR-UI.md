# BCC-Core — UI & Navigasyon Yapılacaklar Planı

> `PROJE-DURUM.md` ile birlikte kullanılır.
> Her iş için: hedef → mevcut durum → teknik → dikkat → test.
> Bir iş bitince buradan sil, `PROJE-DURUM.md`'nin "Biten İşler" bölümüne ekle.
> Bu belge Claude Code'a doğrudan prompt olarak verilebilir: bir maddeyi kopyala,
> başına "Aşağıdaki işi uygula" yaz, yeterli.
>
> **[2026-07-30 temizliği]** Bu dosyada daha önce planlanmış 15 iş vardı, hepsi
> bitip `PROJE-DURUM.md`'ye işlendi (kod ile tek tek doğrulandı) — dosya bu yüzden
> şu an boş. Aşağıdaki "Genel Kurallar" hâlâ geçerli/yeniden kullanılabilir;
> yeni bir UI işi planlanınca buraya `# Başlık` + iş detayları eklenir.

---

## Genel Kurallar (her iş için geçerli)

- **Dış kütüphane yok.** Tamamen Vanilla JS; mevcut CSS mimarisine eklenerek yazılır
- **PHP 7.3.** `bcc_query` / `bcc_fetch_all` + `mysqli` prepared statement. **PDO kullanılmaz**
- Tüm dinamik çıktılar `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` ile kaçırılır
- `base_id` / `table_id` / `view_id` girdilerinde `intval()` zorlaması
- KVKK: `require_team_access()` zinciri hiçbir yeni sorguda atlanmaz
- Yazma işlemlerinde CSRF token + oturum kontrolü
- **"Özelliğini yazmadığım şeyi ekleme"** — belirtilmemiş butonlar yalnızca görünüm
  olarak durur; tıklanınca sayfayı bozmaz, sessizce hiçbir şey yapmaz
- Yeni panel eklerken **eski işlevsiz butonu SİLMEK ilk adım**
- Aç/kapa panellerde ortak desen: `<details>` + dışarı tıklayınca kapanma
- Bitince: `php -l` lint + üç regresyon betiği (`scripts/test_isolation.php`,
  `scripts/_verify_phase4_sort_search.php`, `scripts/_verify_phase4_filter.php`)

**[2026-07-30] Çözülmüş çelişki (bilgi amaçlı):** Eskiden burada "JS'siz de
çalışsın" ilkesinin popover/dropdown/`localStorage`/sürükle-bırak ile çelişip
çelişmediği "başlamadan netleşmeli" diye not düşülmüştü. Fiiliyatta şu şekilde
çözüldü: **veri katmanı** (filtre/sort/group/hidden_fields/row_height) URL'de
kalmaya devam ediyor, **salt görsel kabuk özellikleri** (popover, dropdown,
sürükle-bırak, `localStorage` tema tercihi vb.) JS'e bağlı olabiliyor — proje
genelinde bu ayrım tutarlı şekilde uygulanmış durumda.

---

*(Şu an planlanmış bir UI işi yok — yeni bir madde geldiğinde buraya eklenir.)*
