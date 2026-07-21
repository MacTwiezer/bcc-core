(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var input = document.getElementById('grid-search');
        var countEl = document.getElementById('grid-row-count');
        var tbody = document.querySelector('table.grid tbody');

        if (!input || !tbody) {
            return;
        }

        var rows = Array.prototype.filter.call(tbody.querySelectorAll('tr'), function (tr) {
            return tr.hasAttribute('data-record-id');
        });
        var total = rows.length;

        function updateCount(visible) {
            if (!countEl) {
                return;
            }
            countEl.textContent = (visible === total) ? (total + ' kayıt') : (visible + ' / ' + total + ' kayıt');
        }

        updateCount(total);

        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            var visible = 0;

            rows.forEach(function (tr) {
                var match = q === '' || tr.textContent.toLowerCase().indexOf(q) !== -1;
                tr.style.display = match ? '' : 'none';
                if (match) {
                    visible++;
                }
            });

            updateCount(visible);
        });
    });
})();
