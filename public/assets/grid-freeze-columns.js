(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.querySelector('table.grid');
        if (!table) {
            return;
        }

        // Satır no kolonu (.grid-rownum) zaten her zaman sticky/left:0 (style.css) —
        // buraya hiç dokunulmuyor. frozenCount, satır no dahil TOPLAM dondurulmuş
        // kolon sayısıdır; index 0 (rownum) bu yüzden aşağıdaki döngüde hep atlanır,
        // yalnızca index >= 1 için .grid-frozen-cell eklenir/kaldırılır.
        var frozenCount = Math.max(1, parseInt(window.BCC_FROZEN_COLUMN_COUNT, 10) || 1);
        var maxFrozen = Math.max(1, parseInt(window.BCC_MAX_FROZEN_COLUMNS, 10) || 1);
        var viewId = window.BCC_VIEW_ID || '';
        var canEdit = !!window.BCC_CAN_EDIT;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        function headerCells() {
            var row = table.querySelector('thead tr');
            return row ? Array.prototype.slice.call(row.children) : [];
        }

        // Grup başlığı satırları (colspan'lı, tüm genişliği kaplar) KASITLI OLARAK
        // hariç tutulur — dondurma onlarla çakışmaz, tam genişlikte kalırlar.
        function bodyRows() {
            return Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-record-id], tbody tr.grid-add-row'));
        }

        var handle = null;
        if (canEdit) {
            handle = document.createElement('div');
            handle.className = 'grid-freeze-handle';
            handle.setAttribute('data-tooltip-host', '');
            handle.setAttribute('tabindex', '-1');
            var tip = document.createElement('span');
            tip.className = 'gs-kbd-tooltip';
            tip.textContent = 'Drag to adjust the number of frozen columns';
            handle.appendChild(tip);
        }

        function applyFreeze() {
            var heads = headerCells();
            var offsets = [];
            var acc = 0;

            heads.forEach(function (cell, idx) {
                offsets[idx] = acc;
                acc += cell.offsetWidth;
            });

            function styleCell(cell, idx) {
                if (idx === 0) {
                    return; // .grid-rownum — statik CSS zaten hallediyor, dokunma
                }

                if (idx < frozenCount) {
                    cell.style.left = offsets[idx] + 'px';
                    cell.classList.add('grid-frozen-cell');
                    cell.classList.toggle('grid-frozen-edge', idx === frozenCount - 1);
                } else {
                    cell.style.left = '';
                    cell.classList.remove('grid-frozen-cell', 'grid-frozen-edge');
                }
            }

            heads.forEach(styleCell);
            bodyRows().forEach(function (tr) {
                Array.prototype.forEach.call(tr.children, styleCell);
            });

            if (handle) {
                var edgeIdx = Math.min(frozenCount - 1, heads.length - 1);
                var edgeCell = heads[edgeIdx];
                if (edgeCell && edgeCell !== handle.parentNode) {
                    edgeCell.style.position = edgeCell.style.position || 'sticky';
                    edgeCell.appendChild(handle);
                }
            }
        }

        // grid.js (kayıt ekleme) yeni bir satır DOM'a eklediğinde bu satırın da
        // dondurma stilini alması için çağırır — ikinci bir mekanizma yazılmaz.
        window.BCC_reapplyFreeze = applyFreeze;

        applyFreeze();
        window.addEventListener('resize', applyFreeze);

        if (!handle || !viewId) {
            return;
        }

        var dragging = false;
        var rafPending = false;
        var pendingClientX = null;

        function computeFrozenCountForX(clientX) {
            var rect = table.getBoundingClientRect();
            var x = clientX - rect.left;
            var heads = headerCells();
            var acc = 0;
            var count = 1;

            for (var i = 0; i < heads.length; i++) {
                acc += heads[i].offsetWidth;
                if (x >= acc) {
                    count = i + 1;
                }
            }

            if (count < 1) {
                count = 1;
            }
            if (count > maxFrozen) {
                count = maxFrozen;
            }

            return count;
        }

        function persistFrozenCount(count) {
            var stateQueryString = window.location.search.replace(/^\?/, '');

            fetch('/api/view_config_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    view_id: viewId,
                    frozen_column_count: count,
                    state_query_string: stateQueryString,
                }).toString(),
            }).catch(function () {
                // Sessiz başarısızlık: dondurma bu oturumda görsel olarak uygulanmış
                // kalır, yalnızca kalıcı olmaz (F5'te eski değere dönebilir).
            });
        }

        function endDrag() {
            if (!dragging) {
                return;
            }
            dragging = false;
            document.body.style.userSelect = '';
            handle.classList.remove('is-dragging');
            persistFrozenCount(frozenCount);
        }

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            dragging = true;
            document.body.style.userSelect = 'none';
            handle.classList.add('is-dragging');
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) {
                return;
            }

            // Fare tuşu bırakılmış (ör. pencere dışında bırakılmış) — dinleyicileri
            // temizlemek için sürüklemeyi hemen bitir.
            if (e.buttons === 0) {
                endDrag();
                return;
            }

            pendingClientX = e.clientX;
            if (rafPending) {
                return;
            }
            rafPending = true;
            requestAnimationFrame(function () {
                rafPending = false;
                if (!dragging) {
                    return;
                }
                var newCount = computeFrozenCountForX(pendingClientX);
                if (newCount !== frozenCount) {
                    frozenCount = newCount;
                    applyFreeze();
                }
            });
        });

        document.addEventListener('mouseup', endDrag);
        // Fare pencere dışına çıkarsa (mouseup hiç tetiklenmeyebilir) temizle.
        document.addEventListener('mouseleave', endDrag);
    });
})();
