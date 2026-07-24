(function () {
    'use strict';

    // Sütun genişletme (Bölüm C, madde 12) — sürükleme iskeleti (mousedown/
    // mousemove rAF throttle/mouseup) assets/grid-column-drag.js'de,
    // grid-freeze-columns.js (dondurma tutamacı) ile PAYLAŞILIYOR, ikinci bir
    // kopya YOK. Kalıcılık da aynı uçnoktayı (view_config_update.php) kullanır,
    // yalnızca frozen_column_count yerine field_id/width gönderir.
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.querySelector('table.grid');
        if (!table) {
            return;
        }

        function cellsForField(fieldId) {
            return Array.prototype.slice.call(table.querySelectorAll('[data-field-id="' + fieldId + '"]'));
        }

        function applyWidth(fieldId, width) {
            cellsForField(fieldId).forEach(function (cell) {
                cell.style.width = width + 'px';
                cell.style.maxWidth = width + 'px';
            });
        }

        // Sunucu yalnızca <th>'a genişlik basar (bkz. grid.php $columnWidths) —
        // gövde hücrelerine (tbody td) EŞLENMESİ burada, TEK yerde yapılır; hem
        // editor hem viewer için (dondurma deseninde applyFreeze()'in HERKES için
        // çalışması, yalnızca tutamacın canEdit'e bağlı olması ile AYNI ayrım).
        Array.prototype.forEach.call(table.querySelectorAll('thead .grid-resizable-th[style*="width"]'), function (th) {
            var fieldId = th.getAttribute('data-field-id');
            if (fieldId) {
                applyWidth(fieldId, th.offsetWidth);
            }
        });

        if (!window.BCC_CAN_EDIT) {
            return;
        }

        var viewId = window.BCC_VIEW_ID || '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        function persistWidth(fieldId, width) {
            if (!viewId) {
                return;
            }
            fetch('/api/view_config_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    view_id: viewId,
                    field_id: fieldId,
                    width: width,
                }).toString(),
            }).catch(function () {
                // Sessiz başarısızlık: genişlik bu oturumda görsel olarak
                // uygulanmış kalır, yalnızca kalıcı olmaz (F5'te eski değere döner).
            });
        }

        var MIN_WIDTH = 80;
        var MAX_WIDTH = 800;

        Array.prototype.forEach.call(document.querySelectorAll('[data-grid-resize-handle]'), function (handle) {
            var th = handle.closest('th');
            if (!th) {
                return;
            }

            var fieldId = null;
            var startX = 0;
            var startWidth = 0;
            var finalWidth = 0;

            window.bcc_bindColumnDrag(handle, {
                onStart: function (e) {
                    fieldId = th.getAttribute('data-field-id');
                    startX = e.clientX;
                    startWidth = th.offsetWidth;
                    finalWidth = startWidth;
                },
                onMove: function (clientX) {
                    var delta = clientX - startX;
                    var newWidth = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, startWidth + delta));
                    finalWidth = newWidth;
                    applyWidth(fieldId, newWidth);
                },
                onEnd: function () {
                    persistWidth(fieldId, finalWidth);
                },
            });
        });
    });
})();
