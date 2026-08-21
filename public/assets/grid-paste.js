// Pano yapıştırma — Excel / Google Sheets / Airtable / LibreOffice'ten
// kopyalanan bir tabloyu grid'e yazar (api/cells_bulk_update.php).
//
// AKIŞ
//   1. Kullanıcı bir hücreye tıklar  -> grid-cell-select.js onu ÇAPA yapar
//   2. Ctrl+V                        -> bu modül panoyu ayrıştırır
//   3. Hedef alan kesik çizgiyle boyanır + onay penceresi açılır
//   4. Onay                          -> tek istek, sonra sayfa yenilenir
//
// ⚠️ TEK HÜCRELİK YAPIŞTIRMAYA KARIŞMAZ. Panoda ne sekme ne satır sonu varsa
// olay HİÇ ele alınmaz: açık bir düzenleyiciye normal metin yapıştırmak eskisi
// gibi çalışır. Yalnızca TABLO şeklindeki içerik grid tarafından yakalanır.
//
// KAYNAK SEÇİMİ — üç kademe, sırayla denenir:
//
//   1. KENDİ HTML'imiz (<table data-bcc-grid="1">, grid-copy.js üretir)
//      -> her hücrenin data-bcc-raw'ındaki HAM değer kullanılır. Grid'den
//         grid'e kopyalama böylece KUSURSUZ gidiş-dönüş yapar. Bu şart:
//         görünen metin ile sunucunun kabul ettiği biçim aynı değil
//         (date "12.03.2000" vs "2000-03-12", percent "%45" vs 45).
//   2. YABANCI text/html (Excel, Sheets, Airtable, web sayfasındaki <table>)
//      -> gerçek tablo yapısı okunur. TSV'den ÜSTÜN: hücre içindeki satır
//         sonları ve sekmeler tabloyu kaydırmaz, birleşik hücreler
//         (colspan/rowspan) doğru yayılır.
//   3. text/plain (TSV) -> en son çare; hâlâ tam desteklenir.
//
// Kademe 2 ve 3'te değerler HEDEF ALAN TİPİNE göre dönüştürülür
// (coerceForField): Excel'den gelen "12.03.2000", "1.234,56", "%45", "Evet"
// gibi insan biçimleri sunucunun beklediği kanonik biçime çevrilir. Bu
// olmadan Türkçe biçimli bir tarih/sayı sütunu yapıştırıldığında sunucu
// hücreleri sessizce ATLIYORDU.
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        var modal = document.getElementById('gs-paste-modal');
        var SELECT = window.BCC_GRID_SELECT;

        // Salt-okunur grid (viewer/commenter) veya seçim modülü yoksa no-op.
        if (!grid || !modal || !SELECT) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        // Sunucudaki tavanlarla AYNI değerler (api/cells_bulk_update.php).
        // Burada da kontrol edilmesi kullanıcıya ANLAMLI bir mesaj vermek için:
        // 60.000 satırlık bir yapıştırmayı sunucuya gönderip reddettirmek yerine
        // daha tarayıcıda durduruyoruz.
        var MAX_ROWS = 5000;
        var MAX_COLS = 500;
        var MAX_CELLS = 100000;

        var pendingPlan = null;

        // ---- TSV ayrıştırma -------------------------------------------------
        // Elektronik tablolar hücre içinde satır sonu veya sekme varsa hücreyi
        // ÇİFT TIRNAĞA alır ve içteki tırnağı ikiler ("" -> "). Bu yüzden düz
        // split('\t') YETMEZ — çok satırlı bir not hücresi tabloyu kaydırırdı.
        function parseTsv(text) {
            var rows = [];
            var row = [];
            var cell = '';
            var inQuotes = false;
            var i = 0;

            while (i < text.length) {
                var ch = text.charAt(i);

                if (inQuotes) {
                    if (ch === '"') {
                        if (text.charAt(i + 1) === '"') { cell += '"'; i += 2; continue; }
                        inQuotes = false; i++; continue;
                    }
                    cell += ch; i++; continue;
                }

                // Tırnak yalnızca hücrenin BAŞINDA açıcıdır; ortadaki tırnak
                // düz karakterdir (Excel de böyle üretir).
                if (ch === '"' && cell === '') { inQuotes = true; i++; continue; }
                if (ch === '\t') { row.push(cell); cell = ''; i++; continue; }
                if (ch === '\r') { i++; continue; }
                if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; i++; continue; }

                cell += ch; i++;
            }

            row.push(cell);
            rows.push(row);

            // Metin \n ile bittiyse sonda boş bir satır kalır — atılır.
            if (rows.length > 1) {
                var last = rows[rows.length - 1];
                if (last.length === 1 && last[0] === '') { rows.pop(); }
            }

            return rows;
        }

        // ---- HTML tablo ayrıştırma -------------------------------------------
        // DOMParser kullanılır, innerHTML DEĞİL: ayrıştırılan belge ayrı bir
        // bağlamda kalır, içindeki <script>/<img onerror> ÇALIŞMAZ. Pano
        // içeriği güvenilmez veridir (kullanıcı herhangi bir siteden
        // kopyalayabilir) — sayfaya hiç enjekte edilmez, yalnızca metni okunur.
        function parseHtmlTable(html) {
            var doc;
            try {
                doc = new DOMParser().parseFromString(html, 'text/html');
            } catch (err) {
                return null;
            }
            var table = doc.querySelector('table');
            if (!table) {
                return null;
            }

            var isOurs = table.hasAttribute('data-bcc-grid');
            var trs = Array.prototype.slice.call(table.querySelectorAll('tr'));
            if (!trs.length) {
                return null;
            }

            // colspan/rowspan yayılımı: birleşik hücreler ızgarada gerçekten
            // kapladıkları yere yazılır, yoksa o satırdan sonrası KAYARDI.
            var gridOut = [];
            var pending = {}; // "r:c" -> değer (rowspan'dan taşan hücreler)

            trs.forEach(function (tr, r) {
                var cells = Array.prototype.slice.call(tr.querySelectorAll('td, th'));
                var row = gridOut[r] || (gridOut[r] = []);
                var c = 0;

                cells.forEach(function (cell) {
                    while (pending[r + ':' + c] !== undefined) {
                        row[c] = pending[r + ':' + c];
                        c++;
                    }

                    var display = String(cell.textContent || '').replace(/\s+/g, ' ').trim();
                    // Kendi kopyalamamızda hücre bir NESNE olarak taşınır:
                    // ham değer + görünen metin + KAYNAK alan tipi. Hedef
                    // sütunun tipi kaynakla aynıysa ham değer kullanılır
                    // (kusursuz gidiş-dönüş); FARKLIYSA görünen metin
                    // dönüştürülür — bkz. buildPlan. Bu ayrım olmadan bir
                    // checkbox sütununu metin sütununa yapıştırmak "Evet"
                    // yerine "1" yazardı.
                    var value = (isOurs && cell.hasAttribute('data-bcc-raw'))
                        ? {
                            raw: cell.getAttribute('data-bcc-raw'),
                            display: display,
                            type: cell.getAttribute('data-bcc-type') || ''
                        }
                        : display;

                    var cs = parseInt(cell.getAttribute('colspan'), 10) || 1;
                    var rs = parseInt(cell.getAttribute('rowspan'), 10) || 1;

                    for (var dr = 0; dr < rs; dr++) {
                        for (var dc = 0; dc < cs; dc++) {
                            if (dr === 0) {
                                row[c + dc] = value;
                            } else {
                                pending[(r + dr) + ':' + (c + dc)] = value;
                            }
                        }
                    }
                    c += cs;
                });

                while (pending[r + ':' + c] !== undefined) {
                    row[c] = pending[r + ':' + c];
                    c++;
                }
            });

            // Delikleri boş string yap: undefined sunucuya "atla" değil, boş
            // değer olarak gitmeli ki hizalama korunsun.
            var width = 0;
            gridOut.forEach(function (row) { if (row.length > width) { width = row.length; } });
            gridOut = gridOut.map(function (row) {
                var out = [];
                for (var i = 0; i < width; i++) { out.push(row[i] === undefined ? '' : row[i]); }
                return out;
            }).filter(function (row) {
                // Tamamen boş satırlar (ör. HTML'deki ayraç <tr>'leri) atılır.
                return row.some(function (v) { return v !== ''; });
            });

            if (!gridOut.length) {
                return null;
            }

            return { rows: gridOut, raw: isOurs };
        }

        // ---- Değer dönüşümü (hedef alan tipine göre) --------------------------
        // ⚠️ YALNIZCA YABANCI KAYNAKTA çalışır. Kendi HTML'imizden gelen değer
        // zaten kanonik biçimdedir; ona dokunmak "0.45" yüzdesini tekrar
        // bölmek gibi hatalara yol açardı.
        //
        // Amaç: Excel'den yapıştırırken kullanıcının hiçbir şeyi elle
        // düzeltmek zorunda kalmaması. Tanınmayan bir biçim OLDUĞU GİBİ
        // bırakılır — sunucu son sözü söyler, burada veri UYDURULMAZ.
        function coerceForField(value, type) {
            var s = String(value === null || value === undefined ? '' : value).trim();
            if (s === '') {
                return '';
            }

            if (type === 'date') {
                // Zaten kanonik.
                if (/^\d{4}-\d{2}-\d{2}$/.test(s)) { return s; }
                // gg.aa.yyyy / gg/aa/yyyy / gg-aa-yyyy — Türkçe Excel'in
                // varsayılanı. AY/GÜN sırası BİLEREK gün-önce kabul edilir:
                // uygulama Türkçe ve tarih sütunları gg.aa.yyyy gösteriyor.
                var m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/);
                if (m) {
                    return m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2);
                }
                // yyyy/aa/gg
                m = s.match(/^(\d{4})[.\/](\d{1,2})[.\/](\d{1,2})$/);
                if (m) {
                    return m[1] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[3]).slice(-2);
                }
                // Saat kısmı varsa at ("2000-03-12 00:00:00" / ISO).
                m = s.match(/^(\d{4}-\d{2}-\d{2})[T ]/);
                if (m) { return m[1]; }
                return s;
            }

            if (type === 'checkbox') {
                var low = s.toLocaleLowerCase('tr');
                if (['1', 'evet', 'e', 'true', 'doğru', 'dogru', 'x', 'var', '✓', '✔', 'yes'].indexOf(low) !== -1) {
                    return '1';
                }
                return '0';
            }

            if (type === 'number' || type === 'currency' || type === 'percent' || type === 'rating') {
                // Para birimi simgeleri, yüzde işareti ve boşluklar atılır.
                var n = s.replace(/[%\s ₺$€£]/g, '');
                // Binlik/ondalık ayracı: Türkçe "1.234,56" ile İngilizce
                // "1,234.56" ayırt edilir — SON görülen ayraç ondalıktır.
                var lastComma = n.lastIndexOf(',');
                var lastDot = n.lastIndexOf('.');
                if (lastComma !== -1 && lastDot !== -1) {
                    if (lastComma > lastDot) {
                        n = n.replace(/\./g, '').replace(',', '.');   // 1.234,56
                    } else {
                        n = n.replace(/,/g, '');                      // 1,234.56
                    }
                } else if (lastComma !== -1) {
                    // Tek ayraç virgül: "1,5" ondalık; "1,234" belirsiz —
                    // ondalık kabul edilir (Türkçe bağlam), çünkü binlik
                    // ayracının tek başına kullanılması nadirdir.
                    n = n.replace(',', '.');
                }
                return /^-?\d*\.?\d+$/.test(n) ? n : s;
            }

            if (type === 'multiple_select') {
                // Zaten JSON ise dokunma.
                if (/^\s*\[/.test(s)) { return s; }
                var parts = s.split(/\s*[,;]\s*/).filter(function (p) { return p !== ''; });
                return JSON.stringify(parts);
            }

            return s;
        }

        // ---- Sütun haritası -------------------------------------------------
        // Sütun sırası <thead>'deki data-col-key="fN" sırasıdır — tabloda HİÇ
        // satır olmasa bile çalışır (boş tabloya yapıştırma senaryosu).
        function columnMap() {
            var readonly = (typeof BCC_READONLY_FIELD_TYPES !== 'undefined') ? BCC_READONLY_FIELD_TYPES : [];
            var types = (typeof BCC_FIELD_TYPES_BY_ID !== 'undefined') ? BCC_FIELD_TYPES_BY_ID : {};

            return Array.prototype.map.call(
                grid.querySelectorAll('thead th[data-col-key]'),
                function (th) {
                    var fieldId = parseInt(th.getAttribute('data-col-key').replace('f', ''), 10);
                    var type = types[fieldId];
                    return {
                        fieldId: fieldId,
                        // Tip, yabancı kaynaktan gelen değeri dönüştürmek için
                        // gerekli (coerceForField) — sütun haritası zaten
                        // kuruluyor, ikinci bir tip aramasına gerek yok.
                        type: type,
                        // Salt-okunur alanlara (autonumber, oluşturulma zamanı…)
                        // yazılmaz; sunucu da ayrıca reddeder (iki katman).
                        writable: readonly.indexOf(type) === -1
                    };
                }
            );
        }

        // ---- Plan -----------------------------------------------------------
        // Ne nereye yazılacak, kaç yeni satır açılacak, kaç hücre atlanacak.
        // $isRaw: kaynak KENDİ kopyalamamız mı (data-bcc-raw taşıyan HTML).
        // Öyleyse değerler kanonik biçimdedir ve dönüştürülmez.
        function buildPlan(data, isRaw) {
            var anchor = SELECT.getAnchor();
            if (!anchor) {
                return { error: 'Önce yapıştırmak istediğiniz hücreye tıklayın.' };
            }

            var rows = SELECT.visibleRows();
            var cols = columnMap();
            var anchorTr = anchor.closest('tr[data-record-id]');
            var anchorRow = rows.indexOf(anchorTr);
            var anchorCol = SELECT.rowCells(anchorTr).indexOf(anchor);

            if (anchorRow === -1 || anchorCol === -1) {
                return { error: 'Seçili hücre bulunamadı. Tekrar tıklayıp deneyin.' };
            }

            // Kullanıcı Shift ile bir ALAN çizdiyse yapıştırma o alana KIRPILIR
            // ("seçilen alan kadar"); çizmediyse panonun tamamı yazılır ve
            // taşan satırlar yeni kayıt olur ("tüm hepsi").
            var range = SELECT.hasRange() ? SELECT.getRange() : null;

            // ---- "Tek değeri seçili alana yay" (Excel'in klasik davranışı) --
            // Panoda TEK hücre varsa ve kullanıcı bir ALAN seçtiyse, o değer
            // alanın tamamına yazılır. Bu olmadan tek bir değeri 40 satıra
            // yazmak 40 ayrı düzenleme demekti.
            var isSingle = (data.length === 1 && data[0].length === 1);
            var fillRange = isSingle && !!range;

            var maxRows = range ? (range.row2 - range.row1 + 1) : data.length;
            var maxCols = range ? (range.col2 - range.col1 + 1) : Infinity;

            var rowCount = fillRange ? maxRows : Math.min(data.length, maxRows);
            var srcWidth = 0;
            data.forEach(function (row) { if (row.length > srcWidth) { srcWidth = row.length; } });
            var colCount = fillRange ? maxCols : Math.min(srcWidth, maxCols === Infinity ? srcWidth : maxCols);

            function valueAt(r, c) {
                if (fillRange) {
                    return data[0][0];
                }
                var row = data[r];
                if (!row || row[c] === undefined) {
                    return null; // kaynak satırı bu sütuna kadar uzanmıyor
                }
                return row[c];
            }

            var updates = [];
            var creates = [];
            var skippedReadonly = 0;
            var clippedCols = 0;

            for (var r = 0; r < rowCount; r++) {
                var targetRow = anchorRow + r;
                var isNew = targetRow >= rows.length;
                var newRowCells = [];

                for (var c = 0; c < colCount; c++) {
                    var targetCol = anchorCol + c;

                    // Tablonun sağ kenarını aşan sütunlar SESSİZCE kırpılır —
                    // yapıştırma yeni ALAN AÇMAZ (alan oluşturmak şema işidir ve
                    // owner yetkisi ister; yapıştırma editor yetkisiyle çalışır).
                    if (targetCol >= cols.length) { clippedCols++; continue; }
                    if (!cols[targetCol].writable) { skippedReadonly++; continue; }

                    var value = valueAt(r, c);
                    if (value === null) { continue; }

                    var targetType = cols[targetCol].type;

                    if (value && typeof value === 'object') {
                        // Kendi kopyalamamız. Tip AYNIYSA ham değer kanoniktir,
                        // dokunulmaz (percent'in "45"i tekrar bölünmesin,
                        // date'in "2000-03-12"si bozulmasın).
                        // Tip FARKLIYSA ham değer hedefte anlamsız olabilir —
                        // insanın gördüğü metin alınıp hedef tipe dönüştürülür.
                        // (Örn. checkbox -> metin sütunu: "1" değil "Evet".)
                        value = (value.type === targetType)
                            ? value.raw
                            : coerceForField(value.display, targetType);
                    } else if (!isRaw) {
                        // Yabancı kaynak (Excel/Sheets/web tablosu).
                        value = coerceForField(value, targetType);
                    }

                    if (isNew) {
                        newRowCells.push({ f: cols[targetCol].fieldId, v: value });
                    } else {
                        updates.push({
                            r: parseInt(rows[targetRow].getAttribute('data-record-id'), 10),
                            f: cols[targetCol].fieldId,
                            v: value
                        });
                    }
                }

                if (isNew && newRowCells.length > 0) {
                    creates.push(newRowCells);
                }
            }

            return {
                updates: updates,
                creates: creates,
                skippedReadonly: skippedReadonly,
                clippedCols: clippedCols,
                anchorRow: anchorRow,
                anchorCol: anchorCol,
                // Hedef boyamasının ölçüleri: yayma modunda seçilen ALANIN
                // ölçüsü, normalde kaynağın kırpılmış ölçüsü. (Eskiden
                // doğrudan data[0].length okunuyordu — kaynağın ilk satırı
                // diğerlerinden kısaysa boyama eksik kalırdı.)
                rowsUsed: rowCount,
                colsUsed: colCount
            };
        }

        // ---- Hedef alanı boya ------------------------------------------------
        // Onay penceresi açıkken kullanıcı ARKADA nereye yazılacağını görür.
        // Mevcut seçim boyamasıyla AYNI sınıflar (style.css) — ikinci bir stil
        // icat edilmedi.
        function paintTarget(plan) {
            var rows = SELECT.visibleRows();
            for (var r = 0; r < plan.rowsUsed; r++) {
                var tr = rows[plan.anchorRow + r];
                if (!tr) { break; } // yeni satırlar henüz DOM'da yok
                var cells = SELECT.rowCells(tr);
                for (var c = 0; c < plan.colsUsed; c++) {
                    if (cells[plan.anchorCol + c]) {
                        cells[plan.anchorCol + c].classList.add('is-paste-range');
                    }
                }
            }
        }

        // ---- Onay penceresi --------------------------------------------------
        var summaryEl = document.getElementById('gs-paste-summary');
        var errorEl = document.getElementById('gs-paste-error');
        var confirmBtn = document.getElementById('gs-paste-confirm');
        var cancelBtn = document.getElementById('gs-paste-cancel');
        var closeBtn = document.getElementById('gs-paste-close');

        function closeModal() {
            modal.hidden = true;
            pendingPlan = null;
            SELECT.repaint(); // hedef boyamasını kaldır, seçimi geri getir
        }

        function openModal(html, plan) {
            summaryEl.innerHTML = html;
            errorEl.hidden = true;
            pendingPlan = plan;
            // Plan yoksa (hata mesajı) onay butonu anlamsız.
            confirmBtn.hidden = !plan;
            modal.hidden = false;
        }

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) { closeModal(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
        });

        // ---- Yapıştırma olayı ------------------------------------------------
        document.addEventListener('paste', function (e) {
            var cd = e.clipboardData || window.clipboardData;
            if (!cd) { return; }

            var text = cd.getData('text/plain') || '';
            var htmlSrc = '';
            try {
                htmlSrc = cd.getData('text/html') || '';
            } catch (err) {
                htmlSrc = ''; // bazı tarayıcılar text/html okumayı reddedebilir
            }

            // ⚠️ ÖNCE HTML AYRIŞTIRILIR, sonra "tablo mu" kararı verilir.
            // Eskiden yalnızca text/plain'e bakılıyor ve içinde sekme/satır
            // sonu yoksa olay hiç ele alınmıyordu. Bir web sayfasındaki TEK
            // SATIRLIK, TEK SÜTUNLU tablo ya da hücreleri boşlukla ayrılmış
            // kopyalar bu yüzden hiç yakalanmıyordu — oysa HTML'de gerçek bir
            // <table> vardı.
            var parsedHtml = htmlSrc ? parseHtmlTable(htmlSrc) : null;
            var isTable = !!parsedHtml
                || text.indexOf('\t') !== -1
                || text.indexOf('\n') !== -1;

            // Ne HTML tablo ne TSV — karışma, açık düzenleyiciye normal
            // yapıştırma yapılsın.
            if (!isTable) { return; }
            if (!parsedHtml && !text) { return; }

            // Onay penceresi zaten açıksa ikinci yapıştırmayı yok say.
            if (!modal.hidden) { e.preventDefault(); return; }

            // Grid'in dışına (ör. arama kutusuna) yapıştırıyorsa karışma.
            var anchor = SELECT.getAnchor();
            var inGrid = e.target && e.target.closest && e.target.closest('.grid');
            if (!anchor && !inGrid) { return; }

            e.preventDefault();

            // Açık düzenleyici varsa İPTAL et — grid.js'in kendi Escape yolunu
            // kullanıyoruz, ikinci bir iptal mekanizması yazılmadı. (commit()
            // çağırmak değişmemiş değeri boşuna kaydederdi.)
            var active = document.activeElement;
            if (active && active.closest && active.closest('td.editing')) {
                active.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            }

            // Kaynak seçimi (dosya başındaki üç kademe). HTML tablo varsa o
            // kazanır: hücre içi satır sonları ve birleşik hücreler TSV'de
            // tabloyu kaydırır, HTML'de kaydırmaz.
            var data, isRaw;
            if (parsedHtml) {
                data = parsedHtml.rows;
                isRaw = parsedHtml.raw;   // kendi kopyalamamız mı
            } else {
                data = parseTsv(text);
                isRaw = false;
            }

            var cellCount = 0;
            var widest = 0;
            data.forEach(function (r) {
                cellCount += r.length;
                if (r.length > widest) { widest = r.length; }
            });

            if (data.length > MAX_ROWS) {
                openModal('Panodaki içerik <strong>' + data.length + '</strong> satır. Tek seferde en fazla <strong>' + MAX_ROWS + '</strong> satır yapıştırılabilir.', null);
                return;
            }
            if (widest > MAX_COLS) {
                openModal('Panodaki içerik <strong>' + widest + '</strong> sütun. Tek seferde en fazla <strong>' + MAX_COLS + '</strong> sütun yapıştırılabilir.', null);
                return;
            }
            if (cellCount > MAX_CELLS) {
                openModal('Panodaki içerik <strong>' + cellCount + '</strong> hücre. Tek seferde en fazla <strong>' + MAX_CELLS + '</strong> hücre yapıştırılabilir.', null);
                return;
            }

            var plan = buildPlan(data, isRaw);
            if (plan.error) {
                openModal(plan.error, null);
                return;
            }
            if (plan.updates.length === 0 && plan.creates.length === 0) {
                openModal('Yapıştırılabilir hücre bulunamadı. Seçtiğiniz sütunlar salt-okunur olabilir.', null);
                return;
            }

            paintTarget(plan);

            var parts = [];
            parts.push('<strong>' + plan.updates.length + '</strong> hücrenin üzerine yazılacak.');
            if (plan.creates.length > 0) {
                parts.push('<strong>' + plan.creates.length + '</strong> yeni satır eklenecek.');
            }
            if (plan.skippedReadonly > 0) {
                parts.push('<strong>' + plan.skippedReadonly + '</strong> hücre atlanacak (salt-okunur sütun).');
            }
            if (plan.clippedCols > 0) {
                parts.push('<strong>' + plan.clippedCols + '</strong> hücre tablo dışında kaldığı için kırpılacak.');
            }
            parts.push('<em>Bu işlem geri alınamaz.</em>');

            openModal(parts.join('<br>'), plan);
        });

        // ---- Gönderim --------------------------------------------------------
        confirmBtn.addEventListener('click', function () {
            if (!pendingPlan) { return; }

            confirmBtn.disabled = true;
            errorEl.hidden = true;

            var tableId = new URLSearchParams(window.location.search).get('table_id');

            // Hücreler TEK bir 'payload' alanında gidiyor, ayrı ayrı DEĞİL:
            // php.ini'de max_input_vars = 1000 ve binlerce alan gönderilirse PHP
            // fazlasını SESSİZCE atardı — yapıştırmanın bir kısmı kaybolurdu.
            fetch('/api/cells_bulk_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    table_id: tableId,
                    payload: JSON.stringify({
                        updates: pendingPlan.updates,
                        creates: pendingPlan.creates
                    })
                }).toString()
            }).then(function (res) {
                return res.json().catch(function () {
                    return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                });
            }).then(function (data) {
                if (data && data.ok) {
                    // Sayfa yenileniyor: yapıştırma binlerce hücreyi, otomatik
                    // numaraları ve "son değişiklik" damgalarını etkiler —
                    // hepsini istemcide yeniden çizmek yerine sunucunun ürettiği
                    // doğru hâli almak daha güvenli (xlsx içe aktarmada da AYNI
                    // karar, bkz. grid-table-data.js).
                    window.location.reload();
                    return;
                }
                confirmBtn.disabled = false;
                errorEl.textContent = (data && data.error) || 'Yapıştırma kaydedilemedi.';
                errorEl.hidden = false;
            }).catch(function () {
                confirmBtn.disabled = false;
                errorEl.textContent = 'Yapıştırma kaydedilemedi (bağlantı hatası).';
                errorEl.hidden = false;
            });
        });
    });
})();
