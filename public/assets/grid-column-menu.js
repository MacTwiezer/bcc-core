(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.grid-th-menu'), function (menu) {
            var summary = menu.querySelector(':scope > summary');
            var panel = menu.querySelector(':scope > .grid-th-menu-panel');

            if (!summary || !panel) {
                return;
            }

            window.bcc_bindFloatingPanel(menu, panel, summary);
        });
    });
})();
