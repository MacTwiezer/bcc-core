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
// Neden text/plain: Excel panoya hem text/plain (TSV) hem text/html koyar.
// HTML'i ayrıştırmak birleşik hücreleri (colspan/rowspan) ve biçimlendirmeyi de
// getirir; TSV hem yeterli hem çok daha öngörülebilir — Airtable de düz
// yapıştırmada TSV'yi baz alır.
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
                        // Salt-okunur alanlara (autonumber, oluşturulma zamanı…)
                        // yazılmaz; sunucu da ayrıca reddeder (iki katman).
                        writable: readonly.indexOf(type) === -1
                    };
                }
            );
        }

        // ---- Plan -----------------------------------------------------------
        // Ne nereye yazılacak, kaç yeni satır açılacak, kaç hücre atlanacak.
        function buildPlan(data) {
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
            var maxRows = range ? (range.row2 - range.row1 + 1) : data.length;
            var maxCols = range ? (range.col2 - range.col1 + 1) : Infinity;

            var updates = [];
            var creates = [];
            var skippedReadonly = 0;
            var clippedCols = 0;

            for (var r = 0; r < data.length && r < maxRows; r++) {
                var targetRow = anchorRow + r;
                var isNew = targetRow >= rows.length;
                var newRowCells = [];

                for (var c = 0; c < data[r].length && c < maxCols; c++) {
                    var targetCol = anchorCol + c;

                    // Tablonun sağ kenarını aşan sütunlar SESSİZCE kırpılır —
                    // yapıştırma yeni ALAN AÇMAZ (alan oluşturmak şema işidir ve
                    // owner yetkisi ister; yapıştırma editor yetkisiyle çalışır).
                    if (targetCol >= cols.length) { clippedCols++; continue; }
                    if (!cols[targetCol].writable) { skippedReadonly++; continue; }

                    var value = data[r][c];

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
                rowsUsed: Math.min(data.length, maxRows),
                colsUsed: Math.min(data[0] ? data[0].length : 0, maxCols === Infinity ? data[0].length : maxCols)
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

            var text = cd.getData('text/plain');
            if (!text) { return; }

            // TABLO değilse karışma — açık düzenleyiciye normal yapıştırma.
            if (text.indexOf('\t') === -1 && text.indexOf('\n') === -1) { return; }

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

            var data = parseTsv(text);
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

            var plan = buildPlan(data);
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
