(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        var modal = document.getElementById('gs-paste-modal');
        var SELECT = window.BCC_GRID_SELECT;

        if (!grid || !modal || !SELECT) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        var MAX_ROWS = 5000;
        var MAX_COLS = 500;
        var MAX_CELLS = 100000;

        var pendingPlan = null;

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

                if (ch === '"' && cell === '') { inQuotes = true; i++; continue; }
                if (ch === '\t') { row.push(cell); cell = ''; i++; continue; }
                if (ch === '\r') { i++; continue; }
                if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; i++; continue; }

                cell += ch; i++;
            }

            row.push(cell);
            rows.push(row);

            if (rows.length > 1) {
                var last = rows[rows.length - 1];
                if (last.length === 1 && last[0] === '') { rows.pop(); }
            }

            return rows;
        }

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

            if (isOurs) {
                trs = trs.filter(function (tr) {
                    return !tr.hasAttribute('data-bcc-head');
                });
            }

            if (!trs.length) {
                return null;
            }

            var gridOut = [];
            var pending = {};

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

            var width = 0;
            gridOut.forEach(function (row) { if (row.length > width) { width = row.length; } });
            gridOut = gridOut.map(function (row) {
                var out = [];
                for (var i = 0; i < width; i++) { out.push(row[i] === undefined ? '' : row[i]); }
                return out;
            }).filter(function (row) {
                return row.some(function (v) { return v !== ''; });
            });

            if (!gridOut.length) {
                return null;
            }

            return { rows: gridOut, raw: isOurs };
        }

        function coerceForField(value, type) {
            var s = String(value === null || value === undefined ? '' : value).trim();
            if (s === '') {
                return '';
            }

            if (type === 'date') {
                if (/^\d{4}-\d{2}-\d{2}$/.test(s)) { return s; }
                var m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/);
                if (m) {
                    return m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2);
                }
                m = s.match(/^(\d{4})[.\/](\d{1,2})[.\/](\d{1,2})$/);
                if (m) {
                    return m[1] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[3]).slice(-2);
                }
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
                var n = s.replace(/[%\s ₺$€£]/g, '');
                var lastComma = n.lastIndexOf(',');
                var lastDot = n.lastIndexOf('.');
                if (lastComma !== -1 && lastDot !== -1) {
                    if (lastComma > lastDot) {
                        n = n.replace(/\./g, '').replace(',', '.');
                    } else {
                        n = n.replace(/,/g, '');
                    }
                } else if (lastComma !== -1) {
                    n = n.replace(',', '.');
                }
                return /^-?\d*\.?\d+$/.test(n) ? n : s;
            }

            if (type === 'multiple_select') {
                if (/^\s*\[/.test(s)) { return s; }
                var parts = s.split(/\s*[,;]\s*/).filter(function (p) { return p !== ''; });
                return JSON.stringify(parts);
            }

            return s;
        }

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
                        type: type,
                        writable: readonly.indexOf(type) === -1
                    };
                }
            );
        }

        function buildPlan(data, isRaw) {
            var anchor = SELECT.getAnchor();
            var rows = SELECT.visibleRows();
            var cols = columnMap();
            var anchorRow, anchorCol;

            if (anchor) {
                var anchorTr = anchor.closest('tr[data-record-id]');
                anchorRow = rows.indexOf(anchorTr);
                anchorCol = anchorTr ? SELECT.rowCells(anchorTr).indexOf(anchor) : -1;

                if (anchorRow === -1 || anchorCol === -1) {
                    return { error: 'Seçili hücre bulunamadı. Tekrar tıklayıp deneyin.' };
                }
            } else if (rows.length === 0 && cols.length > 0) {
                anchorRow = 0;
                anchorCol = 0;
            } else {
                return { error: 'Önce yapıştırmak istediğiniz hücreye tıklayın.' };
            }

            var range = SELECT.hasRange() ? SELECT.getRange() : null;

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
                    return null;
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

                    if (targetCol >= cols.length) { clippedCols++; continue; }
                    if (!cols[targetCol].writable) { skippedReadonly++; continue; }

                    var value = valueAt(r, c);
                    if (value === null) { continue; }

                    var targetType = cols[targetCol].type;

                    if (value && typeof value === 'object') {
                        value = (value.type === targetType)
                            ? value.raw
                            : coerceForField(value.display, targetType);
                    } else if (!isRaw) {
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
                rowsUsed: rowCount,
                colsUsed: colCount
            };
        }

        function paintTarget(plan) {
            SELECT.paintRect(
                plan.anchorRow,
                plan.anchorCol,
                plan.anchorRow + plan.rowsUsed - 1,
                plan.anchorCol + plan.colsUsed - 1
            );
        }

        var summaryEl = document.getElementById('gs-paste-summary');
        var errorEl = document.getElementById('gs-paste-error');
        var confirmBtn = document.getElementById('gs-paste-confirm');
        var cancelBtn = document.getElementById('gs-paste-cancel');
        var closeBtn = document.getElementById('gs-paste-close');

        function closeModal() {
            modal.hidden = true;
            pendingPlan = null;
            SELECT.repaint();
        }

        function openModal(html, plan) {
            summaryEl.innerHTML = html;
            errorEl.hidden = true;
            pendingPlan = plan;
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

        function pasteTargetIsTextEditor(e) {
            var el = (e.target && e.target.nodeType === 1) ? e.target : document.activeElement;
            if (!el || !el.tagName) { return false; }
            if (el.isContentEditable) { return true; }
            var tag = el.tagName.toLowerCase();
            return tag === 'input' || tag === 'textarea';
        }

        document.addEventListener('paste', function (e) {
            if (pasteTargetIsTextEditor(e)) { return; }

            var cd = e.clipboardData || window.clipboardData;
            if (!cd) { return; }

            var text = cd.getData('text/plain') || '';
            var htmlSrc = '';
            try {
                htmlSrc = cd.getData('text/html') || '';
            } catch (err) {
                htmlSrc = '';
            }

            var parsedHtml = htmlSrc ? parseHtmlTable(htmlSrc) : null;
            var isTable = !!parsedHtml
                || text.indexOf('\t') !== -1
                || text.indexOf('\n') !== -1;

            if (!isTable) { return; }
            if (!parsedHtml && !text) { return; }

            if (!modal.hidden) { e.preventDefault(); return; }

            var anchor = SELECT.getAnchor();
            var inGrid = e.target && e.target.closest && e.target.closest('.grid');
            var emptyGrid = !anchor && SELECT.visibleRows().length === 0;
            if (!anchor && !inGrid && !emptyGrid) { return; }

            e.preventDefault();

            var active = document.activeElement;
            if (active && active.closest && active.closest('td.editing')) {
                active.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            }

            var data, isRaw;
            if (parsedHtml) {
                data = parsedHtml.rows;
                isRaw = parsedHtml.raw;
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

        confirmBtn.addEventListener('click', function () {
            if (!pendingPlan) { return; }

            confirmBtn.disabled = true;
            errorEl.hidden = true;

            var tableId = new URLSearchParams(window.location.search).get('table_id');

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
