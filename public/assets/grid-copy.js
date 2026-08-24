// Grid'den KOPYALAMA / KESME / TEMİZLEME.
//
//   Ctrl/Cmd + C        -> seçili alanı panoya yaz
//   Ctrl/Cmd + X        -> panoya yaz + hücreleri boşalt
//   Delete / Backspace  -> hücreleri boşalt
//
// ⚠️ PANOYA İKİ FORMAT BİRDEN YAZILIR — bu, bu dosyanın en önemli kararı:
//
//   text/plain -> GÖRÜNEN metin. Excel/Sheets/Not Defteri bunu alır; insan
//                 için okunaklı olan budur ("12.03.2000", "%45").
//   text/html  -> Kendi yüksek-sadakat kanalımız: gerçek bir <table>, her
//                 <td>'de data-bcc-raw ile HAM değer.
//
// NEDEN İKİSİ: sunucunun kabul ettiği biçim ile ekranda görünen biçim AYNI
// DEĞİL (ölçüldü):
//     date     görünen "12.03.2000"  ham "2000-03-12"  sunucu YALNIZCA Y-m-d
//     percent  görünen "%45"         ham "0.45"        sunucu 45 bekler
//     checkbox görünen ☑             ham "1"           sunucu yalnızca "1"/1
// Yalnızca görünen metni kopyalasaydık, KENDİ grid'imizden kopyalayıp yine
// KENDİ grid'imize yapıştırmak "Geçersiz tarih" ile reddedilirdi. Yalnızca ham
// değeri kopyalasaydık Excel'e "0.45" ve "2000-03-12" düşerdi. İkisi birden
// yazılınca: grid -> grid kusursuz, grid -> Excel okunaklı.
//
// grid-paste.js kendi işaretimizi (data-bcc-grid) görürse HAM değeri kullanır.
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        var SELECT = window.BCC_GRID_SELECT;

        if (!grid || !SELECT) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';
        // table_id URL'den okunur — grid-paste.js ile AYNI kaynak. (Sayfada
        // bunu taşıyan bir data-* özniteliği YOK; ikinci bir kaynak uydurmak
        // iki yolun ayrışmasına açık kapı bırakırdı.)
        var TABLE_ID = new URLSearchParams(window.location.search).get('table_id') || '';

        // Düzenleme yetkisi yoksa kes/temizle kapalı; KOPYALAMA yine açık
        // (okuyabilen kopyalayabilir — viewer için de doğal olan bu).
        var CAN_EDIT = !!grid.querySelector('td.grid-cell.editable');

        function toast(msg) {
            if (window.BCC_GRID && window.BCC_GRID.showToast) {
                window.BCC_GRID.showToast(msg);
            }
        }

        // ---- Hücre değerlerini okuma ---------------------------------------
        function cellRaw(td) {
            var v = td.getAttribute('data-value');
            return v === null ? '' : v;
        }

        // Görünen metin. Checkbox'ın .cell-view'i YOKTUR (doğrudan <input>),
        // bu yüzden ayrı ele alınır — textContent onda boş string dönerdi.
        function cellDisplay(td) {
            var type = td.getAttribute('data-field-type');

            if (type === 'checkbox') {
                var box = td.querySelector('input[type="checkbox"]');
                return (box && box.checked) ? 'Evet' : '';
            }

            var view = td.querySelector('.cell-view');
            var text = view ? view.textContent : td.textContent;
            // Çip/etiket düzenlerinde satır sonu ve fazla boşluk birikir; TSV
            // hücresi TEK satır olmalı, yoksa tablo kayardı.
            return String(text || '').replace(/\s+/g, ' ').trim();
        }

        // ---- Pano biçimleri --------------------------------------------------
        // TSV: hücrede sekme, satır sonu veya çift tırnak varsa Excel kuralına
        // göre tırnaklanır ve içteki tırnak ikilenir.
        function tsvCell(s) {
            if (/[\t\n\r"]/.test(s)) {
                return '"' + s.replace(/"/g, '""') + '"';
            }
            return s;
        }

        function buildTsv(matrix) {
            return matrix.map(function (row) {
                return row.map(function (td) { return tsvCell(cellDisplay(td)); }).join('\t');
            }).join('\n');
        }

        function esc(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        // Kenarlık/biçim bilgisi — pano HTML'ine satır içi (inline) yazılır.
        //
        // ⚠️ ÖLÇÜLEN SONUÇ, ABARTMA YOK: kullanıcı Excel'e yapıştırdı ve
        // KENARLIKLAR GELMEDİ. Veri doğru yapışıyor, biçim yapışmıyor —
        // Excel bu parçayı büyük olasılıkla text/html yerine text/plain (TSV)
        // dalından alıyor. Yani bu stiller Excel için ÇALIŞTIĞI DOĞRULANMIŞ
        // DEĞİLDİR; burada durmalarının sebebi zararsız olmaları ve HTML
        // dalını gerçekten kullanan hedeflerde (Word, LibreOffice Writer,
        // tarayıcı tabanlı tablolar) biçimi taşımalarıdır.
        //
        // DENENMEMİŞ SONRAKİ ADIM (istenirse): Excel'in HTML ayrıştırıcısı
        // çıplak bir <table> parçasında stilleri yok sayabiliyor; parçayı
        // <html><body> ile sarmalamak bilinen bir çözüm. Bu makinede tarayıcı
        // olmadığı için körlemesine denenmedi.
        //
        // Renk/dolgu SABİT yazıldı, tasarım token'ı KULLANILMADI: --bcc-*
        // değişkenleri hedef programda tanımsızdır.
        var CLIP_TABLE_ATTRS = ' border="1" style="border-collapse:collapse"';
        var CLIP_CELL_STYLE = 'border:1px solid #9aa0a6;padding:2px 6px';
        var CLIP_HEAD_STYLE = CLIP_CELL_STYLE + ';font-weight:bold;background:#f1f3f4';

        function buildHtml(matrix) {
            var out = '<table data-bcc-grid="1"' + CLIP_TABLE_ATTRS + '><tbody>';
            matrix.forEach(function (row) {
                out += '<tr>';
                row.forEach(function (td) {
                    out += '<td data-bcc-raw="' + esc(cellRaw(td)) + '"'
                        + ' data-bcc-type="' + esc(td.getAttribute('data-field-type') || '') + '"'
                        + ' style="' + CLIP_CELL_STYLE + '">'
                        + esc(cellDisplay(td)) + '</td>';
                });
                out += '</tr>';
            });
            return out + '</tbody></table>';
        }

        // ---- Panoya yazma ----------------------------------------------------
        // ⚠️ navigator.clipboard.write() BİLEREK BİRİNCİL YOL DEĞİL: izin
        // istiyor, yalnızca güvenli bağlamda çalışıyor ve belge odakta değilse
        // sessizce reddediyor. Gizli bir contenteditable'ı seçip
        // execCommand('copy') çalıştırmak her tarayıcıda çalışır VE bize bir
        // 'copy' olayı verir — iki formatı da orada KENDİMİZ yazarız.
        // (Tarayıcının HTML'den kendi türeteceği düz metin tablo yapısını
        // bozuyor: hücreler boşlukla birleşiyor, sekme kalmıyor.)
        function writeClipboard(tsv, html) {
            var holder = document.createElement('div');
            holder.setAttribute('contenteditable', 'true');
            holder.setAttribute('aria-hidden', 'true');
            // Ekran dışına alınıyor ama display:none DEĞİL — görünmeyen bir
            // düğümün içeriği seçilemez, execCommand kopyalayacak bir şey
            // bulamazdı.
            holder.style.cssText = 'position:fixed;left:-99999px;top:0;opacity:0;white-space:pre;';
            holder.innerHTML = html;
            document.body.appendChild(holder);

            var range = document.createRange();
            range.selectNodeContents(holder);
            var sel = window.getSelection();
            var saved = [];
            for (var i = 0; i < sel.rangeCount; i++) { saved.push(sel.getRangeAt(i)); }
            sel.removeAllRanges();
            sel.addRange(range);

            function onCopy(e) {
                e.clipboardData.setData('text/plain', tsv);
                e.clipboardData.setData('text/html', html);
                e.preventDefault();
            }

            var ok = false;
            document.addEventListener('copy', onCopy, true);
            try {
                ok = document.execCommand('copy');
            } catch (err) {
                ok = false;
            }
            document.removeEventListener('copy', onCopy, true);

            sel.removeAllRanges();
            // Kullanıcının kendi metin seçimi varsa geri verilir.
            saved.forEach(function (r) { sel.addRange(r); });
            if (holder.parentNode) { holder.parentNode.removeChild(holder); }

            return ok;
        }

        function copySelection() {
            var matrix = SELECT.getMatrix();
            if (!matrix.length) {
                return 0;
            }
            var count = matrix.length * matrix[0].length;
            var ok = writeClipboard(buildTsv(matrix), buildHtml(matrix));
            return ok ? count : -1;
        }

        // ---- Hücreleri boşaltma ---------------------------------------------
        // Sunucuya BOŞ değer gönderilir; normalize_cell_value her tipte boşu
        // NULL'a çevirir. Zorunlu alanlar sunucuda REDDEDİLİR (cell_update.php
        // ile aynı kural) — o hücreler "atlandı" olarak geri döner.
        function clearCells(matrix) {
            var updates = [];
            matrix.forEach(function (row) {
                row.forEach(function (td) {
                    if (!td.classList.contains('editable')) {
                        return; // salt-okunur alan (autonumber vb.)
                    }
                    var tr = td.closest('tr[data-record-id]');
                    if (!tr) { return; }
                    updates.push({
                        r: parseInt(tr.getAttribute('data-record-id'), 10),
                        f: parseInt(td.getAttribute('data-field-id'), 10),
                        v: ''
                    });
                });
            });

            if (!updates.length) {
                toast('Temizlenebilecek hücre yok.');
                return;
            }

            var payload = JSON.stringify({ updates: updates, creates: [] });

            fetch('/api/cells_bulk_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    table_id: TABLE_ID,
                    payload: payload
                }).toString()
            }).then(function (res) {
                return res.json().catch(function () { return { ok: false }; });
            }).then(function (data) {
                if (!data || !data.ok) {
                    toast((data && data.error) || 'Hücreler temizlenemedi.');
                    return;
                }

                // ⚠️ Alan adı 'skipped' DEĞİL 'skipped_cells' (bkz.
                // api/cells_bulk_update.php dönüşü) — yanlış ad her zaman 0
                // okunur ve reddedilen hücreler sessizce "başarılı" görünürdü.
                var skipped = data.skipped_cells ? parseInt(data.skipped_cells, 10) : 0;
                if (skipped > 0) {
                    // Sunucu bazı hücreleri reddetti (ör. zorunlu alan). Ekranı
                    // iyimser güncellemek YANLIŞ bilgi gösterirdi — sunucudan
                    // gelen gerçek duruma dönmek için sayfa yenilenir.
                    toast(skipped + ' hücre temizlenemedi (zorunlu alan olabilir). Sayfa yenileniyor…');
                    setTimeout(function () { window.location.reload(); }, 1200);
                    return;
                }

                // Tamamı geçti: DOM yerinde güncellenir, sayfa YENİLENMEZ —
                // seçim ve kaydırma konumu korunsun diye.
                matrix.forEach(function (row) {
                    row.forEach(function (td) {
                        if (!td.classList.contains('editable')) { return; }
                        td.setAttribute('data-value', '');
                        var box = td.querySelector('input[type="checkbox"]');
                        if (box) { box.checked = false; return; }
                        var view = td.querySelector('.cell-view');
                        if (view) { view.textContent = ''; }
                    });
                });
                toast(updates.length + ' hücre temizlendi.');
            }).catch(function () {
                toast('Hücreler temizlenemedi (bağlantı hatası).');
            });
        }

        // ---- Klavye ----------------------------------------------------------
        document.addEventListener('keydown', function (e) {
            // Düzenleyici açıkken / grid dışı bir alana yazarken karışma:
            // orada Ctrl+C normal metin kopyalaması olmalı.
            if (!SELECT.keyboardBelongsToGrid()) {
                return;
            }
            if (!SELECT.getAnchor()) {
                return;
            }

            var mod = e.ctrlKey || e.metaKey;

            if (mod && (e.key === 'c' || e.key === 'C')) {
                var n = copySelection();
                if (n === 0) { return; }
                e.preventDefault();
                toast(n > 0 ? (n + ' hücre kopyalandı.') : 'Kopyalanamadı.');
                return;
            }

            if (mod && (e.key === 'x' || e.key === 'X')) {
                if (!CAN_EDIT) { return; }
                var matrix = SELECT.getMatrix();
                if (!matrix.length) { return; }
                e.preventDefault();
                var copied = copySelection();
                if (copied < 0) {
                    toast('Kopyalanamadı — kesme iptal edildi.');
                    return;
                }
                clearCells(matrix);
                return;
            }

            if (e.key === 'Delete' || e.key === 'Backspace') {
                if (!CAN_EDIT) { return; }
                var m = SELECT.getMatrix();
                if (!m.length) { return; }
                e.preventDefault();
                clearCells(m);
            }
        });

        // ---- TÜM TABLOYU KOPYALA (paylaşılan yüzey) --------------------------
        // "Görünümü kaydet" (grid.js) kaydettikten sonra bunu çağırıyor: tablo
        // panoya da yazılsın ki Excel / Airtable / LibreOffice'e Ctrl+V VEYA
        // sağ tık → Yapıştır ile doğrudan geçsin.
        //
        // ⚠️ İKİNCİ BİR PANO MANTIĞI YAZILMADI: buradaki buildTsv/buildHtml/
        // writeClipboard AYNEN kullanılıyor — yani Ctrl+C ile bu yolun ürettiği
        // pano içeriği BİREBİR aynı sözleşmeye uyuyor (text/plain okunaklı,
        // text/html'de data-bcc-raw ham değer). Ayrı bir uygulama yazsaydım
        // tarih/yüzde/checkbox dönüşümleri iki yerde ayrışmaya açık olurdu.
        //
        // SEÇİMDEN FARKI — BAŞLIK SATIRI: Ctrl+C seçili dikdörtgeni kopyalar ve
        // başlık İSTENMEZ (kullanıcı veri hücrelerini seçmiştir). "Tabloyu
        // kopyala" ise Excel'de kullanılabilir bir TABLO üretmeli, o yüzden
        // sütun adları ilk satır olarak eklenir.
        //
        // Sağ tık → Yapıştır'ın çalışmasının sebebi: writeClipboard GERÇEK
        // sistem panosuna yazıyor (execCommand('copy') + clipboardData), uygulama
        // içi bir tampona değil. Hedef program neyi desteklerse onu alır.
        function copyWholeTable() {
            // Başlıklar: yalnızca VERİ sütunları. data-col-key satır no, "+"
            // (alan ekle) ve diğer arayüz sütunlarını dışarıda bırakır —
            // gövdedeki td.grid-cell kümesiyle birebir eşleşen tek işaret bu.
            var headThs = Array.prototype.slice.call(
                grid.querySelectorAll('thead th[data-col-key]')
            );
            var headers = headThs.map(function (th) {
                var label = th.querySelector('.grid-th-label');
                return String((label ? label.textContent : th.textContent) || '').replace(/\s+/g, ' ').trim();
            });

            // Gövde: DOM'da ne varsa o. Gizli alanlar ve filtrelenmiş satırlar
            // sunucuda zaten basılmıyor, yani "ekranda görünen tablo" ile
            // kopyalanan tablo AYNI olur (kullanıcının beklentisi bu).
            var rows = Array.prototype.slice.call(grid.querySelectorAll('tbody tr[data-record-id]'));
            var matrix = rows.map(function (tr) {
                return Array.prototype.slice.call(tr.querySelectorAll('td.grid-cell'));
            }).filter(function (line) { return line.length > 0; });

            if (!headers.length && !matrix.length) {
                return { ok: false, rows: 0, cols: 0, empty: true };
            }

            // TSV: başlık satırı + buildTsv'nin ürettiği gövde.
            var tsvBody = buildTsv(matrix);
            var tsv = headers.map(tsvCell).join('\t');
            if (tsvBody !== '') { tsv += '\n' + tsvBody; }

            // HTML: buildHtml'in <tbody>'sinin ÖNÜNE <thead> eklenir. Dizgi
            // birleştirme yerine işaretli yere yazmak, buildHtml değişirse
            // burasının sessizce bozulmasını önler.
            // data-bcc-head="1": başlık satırı KENDİ grid'imize geri
            // yapıştırılırken VERİ sanılmasın diye işaretleniyor (grid-paste.js
            // bunu atlıyor). İşaretlemeseydik "Görünümü kaydet" ile kopyalanan
            // tabloyu bir grid'e yapıştırmak sütun ADLARINI ilk kayıt olarak
            // yazardı. Excel/LibreOffice bu özniteliği görmezden gelir.
            var htmlBody = buildHtml(matrix);
            var thead = '<thead><tr data-bcc-head="1">' + headers.map(function (h) {
                return '<th style="' + CLIP_HEAD_STYLE + '">' + esc(h) + '</th>';
            }).join('') + '</tr></thead>';
            var html = htmlBody.replace('<tbody>', thead + '<tbody>');

            return {
                ok: writeClipboard(tsv, html),
                rows: matrix.length,
                cols: headers.length,
                empty: false,
            };
        }

        // window.BCC_GRID / BCC_GRID_SELECT ile AYNI desen.
        window.BCC_GRID_COPY = { copyWholeTable: copyWholeTable };
    });
})();
