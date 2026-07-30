(function () {
    'use strict';

    // D4 — sütun başlığı "▾" menüsü. .grid-wrap { overflow: auto } taşıdığı
    // için panel position:fixed — grid-add-field.js/.grid-add-field-menu ile
    // AYNI teknik (açılışta konum hesaplanır), burada birden fazla menü olduğu
    // için grid-view-manage.js'deki .gs-view-row-menu forEach deseni izlenir.
    // Kapanma tamamen native <details name="gs-table-tab-menu"> mutual-exclusion
    // ile — diğer araç çubuğu panelleriyle AYNI grup, dışarı-tık dinleyicisi
    // eklenmedi (bu grup zaten hiçbirinde yok).
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.grid-th-menu'), function (menu) {
            var summary = menu.querySelector(':scope > summary');
            var panel = menu.querySelector(':scope > .grid-th-menu-panel');

            if (!summary || !panel) {
                return;
            }

            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    return;
                }
                var rect = summary.getBoundingClientRect();
                panel.style.top = (rect.bottom + 4) + 'px';
                panel.style.left = (rect.left) + 'px';
            });
        });
    });
})();
