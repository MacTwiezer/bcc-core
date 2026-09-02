(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        var SELECT = window.BCC_GRID_SELECT;

        if (!grid || !SELECT) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';
        var TABLE_ID = new URLSearchParams(window.location.search).get('table_id') || '';

        var CAN_EDIT = !!grid.querySelector('td.grid-cell.editable');

        function toast(msg) {
            if (window.BCC_GRID && window.BCC_GRID.showToast) {
                window.BCC_GRID.showToast(msg);
            }
        }

        function cellRaw(td) {
            var v = td.getAttribute('data-value');
            return v === null ? '' : v;
        }

        function cellDisplay(td) {
            var type = td.getAttribute('data-field-type');

            if (type === 'checkbox') {
                var box = td.querySelector('input[type="checkbox"]');
                return (box && box.checked) ? 'Evet' : '';
            }

            var view = td.querySelector('.cell-view');
            var text = view ? view.textContent : td.textContent;
            return String(text || '').replace(/\s+/g, ' ').trim();
        }

        function richTextToLines(rawHtml) {
            var withBreaks = String(rawHtml).replace(/<br\s*\/?>/gi, '\n');
            var text;
            try {
                text = new DOMParser().parseFromString(withBreaks, 'text/html').body.textContent;
            } catch (err) {
                text = withBreaks.replace(/<[^>]*>/g, '');
            }

            var lines = String(text || '').split('\n').map(function (line) {
                return line.replace(/[ \t ]+/g, ' ').trim();
            });

            while (lines.length && lines[0] === '') { lines.shift(); }
            while (lines.length && lines[lines.length - 1] === '') { lines.pop(); }

            return lines.join('\n');
        }

        function cellCopyText(td) {
            if (td.getAttribute('data-field-type') === 'long_text') {
                var raw = td.getAttribute('data-value');
                if (raw !== null && raw !== '') {
                    return richTextToLines(raw);
                }
            }

            return cellDisplay(td);
        }

        function tsvCell(s) {
            if (/[\t\n\r"]/.test(s)) {
                return '"' + s.replace(/"/g, '""') + '"';
            }
            return s;
        }

        function buildTsv(matrix) {
            return matrix.map(function (row) {
                return row.map(function (td) { return tsvCell(cellCopyText(td)); }).join('\t');
            }).join('\n');
        }

        function esc(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        var CLIP_TABLE_ATTRS = ' border="1" style="border-collapse:collapse"';
        var CLIP_CELL_STYLE = 'border:1px solid #9aa0a6;padding:2px 6px'
            + ';mso-data-placement:same-cell;vertical-align:top;white-space:nowrap';
        var CLIP_HEAD_STYLE = CLIP_CELL_STYLE + ';font-weight:bold;background:#f1f3f4';

        function buildHtml(matrix) {
            var out = '<table data-bcc-grid="1"' + CLIP_TABLE_ATTRS + '><tbody>';
            matrix.forEach(function (row) {
                out += '<tr>';
                row.forEach(function (td) {
                    var text = cellCopyText(td);
                    out += '<td data-bcc-raw="' + esc(cellRaw(td)) + '"'
                        + ' data-bcc-type="' + esc(td.getAttribute('data-field-type') || '') + '"'
                        + ' style="' + CLIP_CELL_STYLE + '">'
                        + esc(text).replace(/\n/g, '<br>') + '</td>';
                });
                out += '</tr>';
            });
            return out + '</tbody></table>';
        }

        function wrapHtmlDocument(html) {
            return '<html><head><meta charset="utf-8">'
                + '<style>br{mso-data-placement:same-cell;}</style>'
                + '</head><body>' + html + '</body></html>';
        }

        function writeClipboard(tsv, html) {
            var holder = document.createElement('div');
            holder.setAttribute('contenteditable', 'true');
            holder.setAttribute('aria-hidden', 'true');
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
                e.clipboardData.setData('text/html', wrapHtmlDocument(html));
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

        function clearCells(matrix) {
            var updates = [];
            matrix.forEach(function (row) {
                row.forEach(function (td) {
                    if (!td.classList.contains('editable')) {
                        return;
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

                var skipped = data.skipped_cells ? parseInt(data.skipped_cells, 10) : 0;
                if (skipped > 0) {
                    toast(skipped + ' hücre temizlenemedi (zorunlu alan olabilir). Sayfa yenileniyor…');
                    setTimeout(function () { window.location.reload(); }, 1200);
                    return;
                }

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

        document.addEventListener('keydown', function (e) {
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

        function copyWholeTable() {
            var headThs = Array.prototype.slice.call(
                grid.querySelectorAll('thead th[data-col-key]')
            );
            var headers = headThs.map(function (th) {
                var label = th.querySelector('.grid-th-label');
                return String((label ? label.textContent : th.textContent) || '').replace(/\s+/g, ' ').trim();
            });

            var rows = Array.prototype.slice.call(grid.querySelectorAll('tbody tr[data-record-id]'));
            var matrix = rows.map(function (tr) {
                return Array.prototype.slice.call(tr.querySelectorAll('td.grid-cell'));
            }).filter(function (line) { return line.length > 0; });

            if (!headers.length && !matrix.length) {
                return { ok: false, rows: 0, cols: 0, empty: true };
            }

            var tsvBody = buildTsv(matrix);
            var tsv = headers.map(tsvCell).join('\t');
            if (tsvBody !== '') { tsv += '\n' + tsvBody; }

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

        window.BCC_GRID_COPY = { copyWholeTable: copyWholeTable };
    });
})();
