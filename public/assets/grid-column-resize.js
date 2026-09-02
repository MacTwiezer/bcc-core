(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.querySelector('.grid-wrap');
        var table = wrap ? wrap.querySelector('table.grid') : null;
        if (!table || !window.bcc_bindColumnDrag) {
            return;
        }

        var viewId = window.BCC_VIEW_ID || '';
        var canEdit = !!window.BCC_CAN_EDIT;
        var MIN_WIDTH = parseInt(window.BCC_MIN_COLUMN_WIDTH, 10) || 80;
        var MAX_WIDTH = parseInt(window.BCC_MAX_COLUMN_WIDTH, 10) || 800;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        var STRIP_WIDTH = 9;
        var FREEZE_CLEARANCE = 12;

        var STORAGE_KEY = viewId ? 'bcc.grid.column_widths.v' + viewId : '';

        function headerCells() {
            var row = table.querySelector('thead tr');
            return row ? Array.prototype.slice.call(row.children) : [];
        }

        function isResizable(th) {
            return !!th.getAttribute('data-col-key');
        }

        function clampWidth(width) {
            if (width < MIN_WIDTH) {
                return MIN_WIDTH;
            }
            if (width > MAX_WIDTH) {
                return MAX_WIDTH;
            }
            return width;
        }

        function ensureFixedLayout(overrides) {
            if (table.classList.contains('grid-has-col-widths')) {
                return;
            }

            var heads = headerCells();
            var colgroup = document.createElement('colgroup');
            var total = 0;

            heads.forEach(function (th) {
                var col = document.createElement('col');
                var key = th.getAttribute('data-col-key');
                var storeKey = key || (th.classList.contains('grid-rownum') ? 'row' : '');
                var width = Math.round(th.getBoundingClientRect().width);

                if (overrides && storeKey && typeof overrides[storeKey] === 'number' && isFinite(overrides[storeKey])) {
                    width = key ? clampWidth(Math.round(overrides[storeKey])) : Math.round(overrides[storeKey]);
                }

                if (key) {
                    col.setAttribute('data-col-key', key);
                }
                col.style.width = width + 'px';
                total += width;
                colgroup.appendChild(col);
            });

            table.insertBefore(colgroup, table.firstChild);
            table.classList.add('grid-has-col-widths');
            table.style.width = total + 'px';
        }

        function colFor(key) {
            if (!key) {
                return null;
            }
            return table.querySelector('colgroup > col[data-col-key="' + key + '"]');
        }

        function syncTableWidth() {
            var total = 0;
            Array.prototype.forEach.call(table.querySelectorAll('colgroup > col'), function (col) {
                total += parseInt(col.style.width, 10) || 0;
            });
            table.style.width = total + 'px';
        }

        function currentWidthMap() {
            var map = {};
            var cols = table.querySelectorAll('colgroup > col');
            var heads = headerCells();

            Array.prototype.forEach.call(cols, function (col, i) {
                var width = parseInt(col.style.width, 10);
                if (!width) {
                    return;
                }
                var th = heads[i];
                if (!th) {
                    return;
                }
                if (th.classList.contains('grid-rownum')) {
                    map.row = width;
                    return;
                }
                var key = th.getAttribute('data-col-key');
                if (key) {
                    map[key] = width;
                }
            });

            return map;
        }

        function readStored() {
            if (!STORAGE_KEY) {
                return null;
            }
            try {
                var raw = window.localStorage.getItem(STORAGE_KEY);
                if (!raw) {
                    return null;
                }
                var parsed = JSON.parse(raw);
                return (parsed && typeof parsed === 'object') ? parsed : null;
            } catch (e) {
                return null;
            }
        }

        function writeStored(map) {
            if (!STORAGE_KEY) {
                return;
            }
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
            } catch (e) {
            }
        }

        function persist() {
            var map = currentWidthMap();
            writeStored(map);

            if (!canEdit || !viewId) {
                return;
            }

            fetch('/api/view_config_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    view_id: viewId,
                    column_widths: JSON.stringify(map),
                }).toString(),
            }).catch(function () {
            });
        }

        if (table.classList.contains('grid-has-col-widths')) {
            writeStored(currentWidthMap());
        } else {
            var stored = readStored();
            if (stored) {
                ensureFixedLayout(stored);
                if (window.BCC_reapplyFreeze) {
                    window.BCC_reapplyFreeze();
                }
            }
        }

        var layer = document.createElement('div');
        layer.className = 'grid-col-resize-layer';
        layer.setAttribute('aria-hidden', 'true');
        wrap.appendChild(layer);

        var strips = [];

        headerCells().forEach(function (th) {
            if (!isResizable(th)) {
                strips.push(null);
                return;
            }

            var strip = document.createElement('div');
            strip.className = 'grid-col-resize-handle';
            strip.setAttribute('data-col-key', th.getAttribute('data-col-key'));
            layer.appendChild(strip);
            strips.push(strip);
        });

        function frozenGroupWidth(heads) {
            var w = 0;
            for (var i = 0; i < heads.length; i++) {
                if (i === 0 || heads[i].classList.contains('grid-frozen-cell')) {
                    w += heads[i].offsetWidth;
                } else {
                    break;
                }
            }
            return w;
        }

        function layout() {
            var heads = headerCells();
            var top = table.offsetTop;
            var height = table.offsetHeight;
            var scrollLeft = wrap.scrollLeft;
            var frozenW = frozenGroupWidth(heads);
            var acc = table.offsetLeft;

            heads.forEach(function (th, i) {
                acc += th.offsetWidth;

                var strip = strips[i];
                if (!strip) {
                    return;
                }

                var frozen = th.classList.contains('grid-frozen-cell');
                var x = frozen ? (scrollLeft + acc) : acc;

                var isFrozenEdge = th.classList.contains('grid-frozen-edge');
                strip.classList.toggle('is-frozen-edge', isFrozenEdge);
                if (isFrozenEdge) {
                    x -= FREEZE_CLEARANCE;
                }

                if (!frozen && x <= scrollLeft + frozenW) {
                    strip.style.display = 'none';
                    return;
                }

                strip.style.display = '';
                strip.style.left = (x - Math.floor(STRIP_WIDTH / 2)) + 'px';
                strip.style.top = top + 'px';
                strip.style.height = height + 'px';
            });
        }

        window.BCC_relayoutColumnResize = layout;

        layout();
        window.addEventListener('resize', layout);

        var scrollPending = false;
        wrap.addEventListener('scroll', function () {
            if (scrollPending) {
                return;
            }
            scrollPending = true;
            requestAnimationFrame(function () {
                scrollPending = false;
                layout();
            });
        });

        if (window.ResizeObserver) {
            new window.ResizeObserver(layout).observe(table);
        }

        strips.forEach(function (strip) {
            if (!strip) {
                return;
            }

            var key = strip.getAttribute('data-col-key');
            var startX = 0;
            var startWidth = 0;
            var col = null;

            window.bcc_bindColumnDrag(strip, {
                onStart: function (e) {
                    ensureFixedLayout();
                    col = colFor(key);
                    startX = e.clientX;
                    var th = table.querySelector('thead th[data-col-key="' + key + '"]');
                    startWidth = th ? Math.round(th.getBoundingClientRect().width) : 0;
                    document.body.classList.add('is-col-resizing');
                },
                onMove: function (clientX) {
                    if (!col) {
                        return;
                    }
                    var next = clampWidth(startWidth + (clientX - startX));
                    col.style.width = next + 'px';
                    syncTableWidth();

                    if (window.BCC_reapplyFreeze) {
                        window.BCC_reapplyFreeze();
                    }
                    layout();
                },
                onEnd: function () {
                    document.body.classList.remove('is-col-resizing');
                    if (!col) {
                        return;
                    }
                    col = null;
                    layout();
                    persist();
                },
            });
        });
    });
})();
