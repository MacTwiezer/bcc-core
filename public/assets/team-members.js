(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var rowsBody = document.querySelector('[data-tm-rows]');
        if (!rowsBody) {
            return;
        }

        var searchInput = document.querySelector('[data-tm-search]');
        var roleFilter = document.querySelector('[data-tm-role-filter]');
        var emptyState = document.querySelector('[data-tm-empty]');
        var sortHeader = document.querySelector('[data-tm-sort-created]');
        var selectAll = document.querySelector('[data-tm-select-all]');
        var bulkBar = document.querySelector('[data-tm-bulk-bar]');
        var bulkRemoveBtn = document.querySelector('[data-tm-bulk-remove-btn]');
        var selectedCountEl = document.querySelector('[data-tm-selected-count]');

        function rows() {
            return Array.prototype.slice.call(rowsBody.querySelectorAll('.tm-row'));
        }

        // Arama + rol filtresi — home.js'in Ctrl+K popover'ındaki AYNI desen
        // (substring eşleşme, hidden toggle, boş durum mesajı), yalnızca iki
        // koşulun (isim/e-posta VE rol) AND'i.
        function applyFilters() {
            var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
            var role = roleFilter ? roleFilter.value : '';
            var visibleCount = 0;

            rows().forEach(function (row) {
                var matchesSearch = q === '' || row.getAttribute('data-tm-name').indexOf(q) !== -1;
                var matchesRole = role === '' || row.getAttribute('data-tm-role') === role;
                var visible = matchesSearch && matchesRole;
                row.hidden = !visible;
                if (visible) {
                    visibleCount++;
                }
            });

            if (emptyState) {
                emptyState.hidden = visibleCount !== 0;
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
        if (roleFilter) {
            roleFilter.addEventListener('change', applyFilters);
        }

        // "Eklenme tarihi" sıralaması — tamamen client-side (veri zaten sayfada,
        // DB'ye ikinci bir sorgu gerekmiyor). Tıklamada artan/azalan değişir.
        var sortAscending = true;
        if (sortHeader) {
            var doSort = function () {
                var sorted = rows().sort(function (a, b) {
                    var diff = Number(a.getAttribute('data-tm-created')) - Number(b.getAttribute('data-tm-created'));
                    return sortAscending ? diff : -diff;
                });
                sorted.forEach(function (row) {
                    rowsBody.appendChild(row);
                });
                sortAscending = !sortAscending;
            };
            sortHeader.addEventListener('click', doSort);
            sortHeader.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    doSort();
                }
            });
        }

        // Toplu seçim — "form" özniteliğiyle #tm-bulk-remove-form'a bağlı
        // checkbox'lar (tabloyu bir <form> ile SARMIYORUZ, aynı hücrede rol
        // değişikliği için AYRI bir <form> zaten var — iç içe <form> geçersiz).
        function rowChecks() {
            return Array.prototype.slice.call(rowsBody.querySelectorAll('[data-tm-row-check]'));
        }

        function updateBulkBar() {
            var checked = rowChecks().filter(function (cb) {
                return cb.checked;
            });

            if (selectedCountEl) {
                selectedCountEl.textContent = String(checked.length);
            }
            if (bulkBar) {
                bulkBar.classList.toggle('is-active', checked.length > 0);
            }
            if (bulkRemoveBtn) {
                bulkRemoveBtn.disabled = checked.length === 0;
            }
        }

        rowsBody.addEventListener('change', function (e) {
            if (e.target.matches('[data-tm-row-check]')) {
                updateBulkBar();
            }
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowChecks().forEach(function (cb) {
                    if (!cb.disabled && !cb.hidden && !cb.closest('.tm-row').hidden) {
                        cb.checked = selectAll.checked;
                    }
                });
                updateBulkBar();
            });
        }

        if (bulkRemoveBtn) {
            bulkRemoveBtn.addEventListener('click', function (e) {
                var checked = rowChecks().filter(function (cb) {
                    return cb.checked;
                });
                if (checked.length === 0) {
                    e.preventDefault();
                    return;
                }
                if (!window.confirm(checked.length + ' kişiyi ekipten çıkarmak istediğinize emin misiniz?')) {
                    e.preventDefault();
                }
            });
        }

        updateBulkBar();
    });
})();
