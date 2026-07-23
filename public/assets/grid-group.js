(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Aktif panel: alan/yön <select>'i normal bir GET form elemanı; JS olmadan
        // da "Uygula" butonuyla submit edilip doğru çalışır. JS varken değişiklikte
        // anında submit ediyoruz ve artık gereksiz olan "Uygula" butonunu gizliyoruz
        // (Hide fields panelindeki aynı desen).
        var form = document.getElementById('group-form');
        if (form) {
            var selects = form.querySelectorAll('select');
            selects.forEach(function (select) {
                select.addEventListener('change', function () {
                    form.submit();
                });
            });

            var applyBtn = form.querySelector('[data-group-apply]');
            if (applyBtn) {
                applyBtn.style.display = 'none';
            }
        }

        // "Find a field": henüz gruplama yokken gösterilen alan listesini istemci
        // tarafında filtreler (Hide fields panelindeki arama ile aynı desen).
        var searchInput = document.querySelector('[data-group-search]');
        var fieldOptions = document.querySelectorAll('.group-field-option');

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim().toLowerCase();

                fieldOptions.forEach(function (option) {
                    var match = q === '' || option.textContent.toLowerCase().indexOf(q) !== -1;
                    option.style.display = match ? '' : 'none';
                });
            });
        }

        // Grup başlığına tıkla -> o gruptaki satırları aç/kapa.
        function setGroupCollapsed(headerRow, collapsed) {
            var toggle = headerRow.querySelector('[data-group-toggle]');
            var groupIndex = headerRow.getAttribute('data-group-index');

            headerRow.setAttribute('data-group-collapsed', collapsed ? 'true' : 'false');
            if (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }

            document.querySelectorAll('tr[data-group-index="' + groupIndex + '"][data-record-id]').forEach(function (row) {
                row.style.display = collapsed ? 'none' : '';
            });
        }

        var headerRows = document.querySelectorAll('[data-group-header]');
        headerRows.forEach(function (headerRow) {
            var toggle = headerRow.querySelector('[data-group-toggle]');

            if (!toggle) {
                return;
            }

            toggle.addEventListener('click', function () {
                var collapsed = headerRow.getAttribute('data-group-collapsed') === 'true';
                setGroupCollapsed(headerRow, !collapsed);
            });
        });

        var collapseAllBtn = document.querySelector('[data-group-collapse-all]');
        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function () {
                headerRows.forEach(function (headerRow) {
                    setGroupCollapsed(headerRow, true);
                });
            });
        }

        var expandAllBtn = document.querySelector('[data-group-expand-all]');
        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function () {
                headerRows.forEach(function (headerRow) {
                    setGroupCollapsed(headerRow, false);
                });
            });
        }
    });
})();
