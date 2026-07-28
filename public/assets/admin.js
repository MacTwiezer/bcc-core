(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // "..." ve "İşlemler" (bulk) dropdown'ları dışarı tıklanınca kapanır.
        document.addEventListener('click', function (e) {
            document.querySelectorAll('details.admin-menu[open]').forEach(function (menu) {
                if (!menu.contains(e.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

        // Kullanıcı arama kutusu — isim/e-posta içinde client-side filtreler.
        var searchInput = document.getElementById('admin-users-search');
        var usersTable = document.getElementById('admin-users-table');
        if (searchInput && usersTable) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim().toLowerCase();
                usersTable.querySelectorAll('tbody tr').forEach(function (row) {
                    var haystack = row.getAttribute('data-user-search') || '';
                    row.hidden = q !== '' && haystack.indexOf(q) === -1;
                });
            });
        }

        // Her tablonun kendi "tümünü seç" checkbox'ı — sadece o tablonun
        // (arama ile) görünür satırlarını işaretler/kaldırır.
        document.querySelectorAll('.admin-select-all').forEach(function (selectAll) {
            selectAll.addEventListener('change', function () {
                var table = selectAll.closest('table');
                if (!table) {
                    return;
                }
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    if (row.hidden) {
                        return;
                    }
                    var cb = row.querySelector('.admin-row-checkbox');
                    if (cb) {
                        cb.checked = selectAll.checked;
                    }
                });
            });
        });
    });
})();
