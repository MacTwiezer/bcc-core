(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        if (!grid) {
            return;
        }

        var anchorTd = null;
        var focusTd = null;

        var rowsCache = null;
        var cellsCache = null;

        function invalidateRowCache() {
            rowsCache = null;
            cellsCache = (typeof WeakMap === 'function') ? new WeakMap() : null;
        }
        invalidateRowCache();

        function visibleRows() {
            if (rowsCache) {
                return rowsCache;
            }
            rowsCache = Array.prototype.filter.call(
                grid.querySelectorAll('tr[data-record-id]'),
                function (tr) { return tr.offsetParent !== null; }
            );
            return rowsCache;
        }

        function rowCells(tr) {
            if (cellsCache) {
                var hit = cellsCache.get(tr);
                if (hit) {
                    return hit;
                }
            }
            var cells = Array.prototype.slice.call(tr.querySelectorAll('td.grid-cell'));
            if (cellsCache) {
                cellsCache.set(tr, cells);
            }
            return cells;
        }

        if (typeof MutationObserver === 'function') {
            new MutationObserver(function (records) {
                for (var i = 0; i < records.length; i++) {
                    var m = records[i];
                    if (m.type === 'childList') {
                        invalidateRowCache();
                        return;
                    }
                    var t = m.target;
                    if (t && t.nodeType === 1 && t.tagName === 'TR') {
                        invalidateRowCache();
                        return;
                    }
                }
            }).observe(grid, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['style', 'class', 'hidden']
            });
        }

        function cellCoords(td) {
            var tr = td.closest('tr[data-record-id]');
            if (!tr) {
                return null;
            }
            var rows = visibleRows();
            var r = rows.indexOf(tr);
            var c = rowCells(tr).indexOf(td);
            if (r === -1 || c === -1) {
                return null;
            }
            return { row: r, col: c };
        }

        function cellAt(row, col) {
            var rows = visibleRows();
            if (row < 0 || row >= rows.length) {
                return null;
            }
            var cells = rowCells(rows[row]);
            if (col < 0 || col >= cells.length) {
                return null;
            }
            return cells[col];
        }

        var painted = [];
        var SEL_CLASSES = ['is-paste-range', 'is-paste-anchor', 'is-sel-t', 'is-sel-r', 'is-sel-b', 'is-sel-l'];

        function stripClasses(td) {
            for (var i = 0; i < SEL_CLASSES.length; i++) {
                td.classList.remove(SEL_CLASSES[i]);
            }
        }

        function clearHighlight() {
            for (var i = 0; i < painted.length; i++) {
                stripClasses(painted[i]);
            }
            painted.length = 0;
        }

        function sweepHighlight() {
            clearHighlight();
            Array.prototype.forEach.call(
                grid.querySelectorAll('.is-paste-range, .is-paste-anchor'),
                stripClasses
            );
        }

        function currentRange() {
            if (!anchorTd) {
                return null;
            }
            var a = cellCoords(anchorTd);
            var f = focusTd ? cellCoords(focusTd) : a;
            if (!a || !f) {
                return null;
            }
            return {
                row1: Math.min(a.row, f.row), row2: Math.max(a.row, f.row),
                col1: Math.min(a.col, f.col), col2: Math.max(a.col, f.col)
            };
        }

        function paintRect(row1, col1, row2, col2) {
            var rows = visibleRows();
            for (var r = row1; r <= row2; r++) {
                if (!rows[r]) { continue; }
                var cells = rowCells(rows[r]);
                for (var c = col1; c <= col2; c++) {
                    var td = cells[c];
                    if (!td) { continue; }

                    td.classList.add('is-paste-range');
                    if (r === row1) { td.classList.add('is-sel-t'); }
                    if (r === row2) { td.classList.add('is-sel-b'); }
                    if (c === col1) { td.classList.add('is-sel-l'); }
                    if (c === col2) { td.classList.add('is-sel-r'); }
                    painted.push(td);
                }
            }
        }

        function paintSelection() {
            clearHighlight();
            var rg = currentRange();
            if (!rg) {
                return;
            }

            paintRect(rg.row1, rg.col1, rg.row2, rg.col2);

            anchorTd.classList.add('is-paste-anchor');
            if (painted.indexOf(anchorTd) === -1) {
                painted.push(anchorTd);
            }
        }

        var paintQueued = false;

        function schedulePaint() {
            if (paintQueued) {
                return;
            }
            paintQueued = true;
            window.requestAnimationFrame(function () {
                paintQueued = false;
                paintSelection();
            });
        }

        function clearSelection() {
            anchorTd = null;
            focusTd = null;
            sweepHighlight();
        }

        function reveal(td) {
            if (td && td.scrollIntoView) {
                td.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        function keyboardBelongsToGrid() {
            var el = document.activeElement;
            if (!el) {
                return true;
            }
            if (el.closest && el.closest('td.editing')) {
                return false;
            }
            var tag = el.tagName ? el.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable) {
                return false;
            }
            var openModal = document.querySelector('.home-modal-backdrop:not([hidden])');
            return !openModal;
        }

        grid.addEventListener('click', function (e) {
            var td = e.target.closest('td.grid-cell');

            if (!td) {
                return;
            }

            if (suppressNextClick) {
                suppressNextClick = false;
                e.preventDefault();
                e.stopPropagation();
                return;
            }

            if (e.shiftKey && anchorTd) {
                e.preventDefault();
                e.stopPropagation();
                focusTd = td;
                paintSelection();
                return;
            }

            anchorTd = td;
            focusTd = null;
            paintSelection();
        }, true);

        var DRAG_THRESHOLD = 3;
        var dragStartTd = null;
        var dragStartX = 0;
        var dragStartY = 0;
        var dragging = false;
        var suppressNextClick = false;

        grid.addEventListener('mousedown', function (e) {
            if (e.button !== 0 || e.shiftKey) {
                return;
            }
            var td = e.target.closest('td.grid-cell');
            if (!td) {
                return;
            }
            if (e.target.closest('input, button, a, select, textarea')) {
                return;
            }
            dragStartTd = td;
            dragStartX = e.clientX;
            dragStartY = e.clientY;
            dragging = false;
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragStartTd) {
                return;
            }
            if (!dragging) {
                if (Math.abs(e.clientX - dragStartX) < DRAG_THRESHOLD &&
                    Math.abs(e.clientY - dragStartY) < DRAG_THRESHOLD) {
                    return;
                }
                dragging = true;
                anchorTd = dragStartTd;
                focusTd = dragStartTd;
                document.body.style.userSelect = 'none';
            }

            var over = e.target && e.target.closest ? e.target.closest('td.grid-cell') : null;
            if (over && over !== focusTd) {
                focusTd = over;
                schedulePaint();
            }
        });

        document.addEventListener('mouseup', function () {
            if (dragging) {
                suppressNextClick = true;
                document.body.style.userSelect = '';
                paintSelection();
            }
            dragStartTd = null;
            dragging = false;
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.grid') && !e.target.closest('.home-modal')) {
                clearSelection();
            }
        });

        var ARROWS = {
            ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1],
            Up: [-1, 0], Down: [1, 0], Left: [0, -1], Right: [0, 1]
        };

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && anchorTd) {
                clearSelection();
                return;
            }

            if (!keyboardBelongsToGrid()) {
                return;
            }

            if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
                if (!anchorTd) {
                    return;
                }
                var rows = visibleRows();
                if (!rows.length) {
                    return;
                }
                var lastCells = rowCells(rows[rows.length - 1]);
                if (!lastCells.length) {
                    return;
                }
                e.preventDefault();
                anchorTd = rowCells(rows[0])[0];
                focusTd = lastCells[lastCells.length - 1];
                paintSelection();
                return;
            }

            var delta = ARROWS[e.key];
            if (!delta || !anchorTd) {
                return;
            }

            var moving = (e.shiftKey && focusTd) ? focusTd : (e.shiftKey ? anchorTd : (focusTd || anchorTd));
            var pos = cellCoords(moving);
            if (!pos) {
                return;
            }

            var next = cellAt(pos.row + delta[0], pos.col + delta[1]);
            if (!next) {
                e.preventDefault();
                return;
            }

            e.preventDefault();
            if (e.shiftKey) {
                focusTd = next;
            } else {
                anchorTd = next;
                focusTd = null;
            }
            paintSelection();
            reveal(next);
        });

        window.BCC_GRID_SELECT = {
            getAnchor: function () { return anchorTd; },
            getRange: currentRange,
            hasRange: function () { return anchorTd !== null && focusTd !== null && focusTd !== anchorTd; },
            getMatrix: function () {
                var rg = currentRange();
                if (!rg) {
                    return [];
                }
                var rows = visibleRows();
                var out = [];
                for (var r = rg.row1; r <= rg.row2; r++) {
                    if (!rows[r]) { continue; }
                    var cells = rowCells(rows[r]);
                    var line = [];
                    for (var c = rg.col1; c <= rg.col2; c++) {
                        if (cells[c]) { line.push(cells[c]); }
                    }
                    if (line.length) { out.push(line); }
                }
                return out;
            },
            visibleRows: visibleRows,
            rowCells: rowCells,
            cellAt: cellAt,
            paintRect: paintRect,
            clear: clearSelection,
            repaint: paintSelection,
            keyboardBelongsToGrid: keyboardBelongsToGrid
        };
    });
})();
