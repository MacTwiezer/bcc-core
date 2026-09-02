(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.querySelector('table.grid');
        if (!table) {
            return;
        }

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
            tip.textContent = 'Dondurulan sütun sayısını ayarlamak için sürükleyin';
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
                    return;
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
                var cells = tr.children;
                for (var i = 0; i < cells.length; i++) {
                    if (cells[i].colSpan > 1) {
                        cells[i].style.left = '';
                        cells[i].classList.remove('grid-frozen-cell', 'grid-frozen-edge');
                        break;
                    }
                    styleCell(cells[i], i);
                }
            });

            table.classList.toggle('grid-has-frozen-data', frozenCount > 1 && heads.length > 1);

            if (handle) {
                var edgeIdx = Math.min(frozenCount - 1, heads.length - 1);
                var edgeCell = heads[edgeIdx];
                if (edgeCell && edgeCell !== handle.parentNode) {
                    edgeCell.style.position = edgeCell.style.position || 'sticky';
                    edgeCell.appendChild(handle);
                }
            }

            if (window.BCC_relayoutColumnResize) {
                window.BCC_relayoutColumnResize();
            }
        }

        window.BCC_reapplyFreeze = applyFreeze;

        applyFreeze();
        window.addEventListener('resize', applyFreeze);

        if (!handle || !viewId) {
            return;
        }

        function computeFrozenCountForX(clientX) {
            var rect = table.getBoundingClientRect();
            var s = window.bcc_uiScale ? window.bcc_uiScale() : 1;
            var x = (clientX - rect.left) / s;
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
            });
        }

        window.bcc_bindColumnDrag(handle, {
            onMove: function (clientX) {
                var newCount = computeFrozenCountForX(clientX);
                if (newCount !== frozenCount) {
                    frozenCount = newCount;
                    applyFreeze();
                }
            },
            onEnd: function () {
                persistFrozenCount(frozenCount);
            },
        });
    });
})();
