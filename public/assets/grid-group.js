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

        // "+ Add subgroup": en fazla 3 seviyeye kadar, DOM'da zaten sunucu
        // tarafından basılmış (ama `hidden`) bir sonraki seviye satırını açar —
        // yeni bir form elemanı YOK, yalnızca görünürlük değişir. O satırdaki
        // alan seçilince yukarıdaki genel "select değişince submit et" davranışı
        // (aynı dinleyiciler, sayfa yüklenirken zaten bağlandı) devreye girer.
        var addSubgroupBtn = document.getElementById('group-add-subgroup');
        if (addSubgroupBtn) {
            addSubgroupBtn.addEventListener('click', function () {
                var hiddenRow = document.querySelector('.group-level-row[hidden]');
                if (!hiddenRow) {
                    return;
                }

                hiddenRow.hidden = false;

                if (!document.querySelector('.group-level-row[hidden]')) {
                    addSubgroupBtn.style.display = 'none';
                }

                var select = hiddenRow.querySelector('select');
                if (select) {
                    select.focus();
                }
            });
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

        // Bir yolun (path) verilen üst grubun içinde olup olmadığını kontrol eder:
        // ya üst grubun ta kendisi (yaprak seviyenin kendi satırları için) ya da
        // "üst-" önekiyle başlayan bir alt yol (iç içe alt gruplar/satırlar için).
        function isWithinGroup(path, parentPath) {
            return path === parentPath || path.indexOf(parentPath + '-') === 0;
        }

        // Grup başlığına tıkla -> o grubun altındaki TÜM iç başlıkları ve satırları
        // (kaç seviye iç içe olursa olsun) aç/kapa. Kapatılan bir dış grubun içindeki
        // alt grup başlıkları da "genişletilmiş" durumuna sıfırlanır (aç/kapa
        // hafızası seviye başına ayrı tutulmuyor — tek dış toggle basitçe kapsar).
        function setGroupCollapsed(headerRow, collapsed) {
            var toggle = headerRow.querySelector('[data-group-toggle]');
            var groupPath = headerRow.getAttribute('data-group-path');

            headerRow.setAttribute('data-group-collapsed', collapsed ? 'true' : 'false');
            if (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }

            document.querySelectorAll('[data-group-path]').forEach(function (el) {
                if (el === headerRow) {
                    return;
                }

                var elPath = el.getAttribute('data-group-path');
                if (!isWithinGroup(elPath, groupPath)) {
                    return;
                }

                el.style.display = collapsed ? 'none' : '';

                if (el.hasAttribute('data-group-header')) {
                    el.setAttribute('data-group-collapsed', 'false');
                    var innerToggle = el.querySelector('[data-group-toggle]');
                    if (innerToggle) {
                        innerToggle.setAttribute('aria-expanded', 'true');
                    }
                }
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
