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

        // Bulunan gerçek eksiklik: "tümünü seç" kutusu yalnızca TEK YÖNLÜ
        // çalışıyordu (üstten satırlara) — tek tek bir satır kutucuğu elle
        // değiştirildiğinde üstteki kutunun durumu hiç güncellenmiyordu (ör.
        // tümünü seçip birini kaldırınca üst kutu hâlâ "seçili" görünüyordu,
        // gerçek seçim durumunu yanıltıcı biçimde yansıtıyordu). Artık satır
        // değişince senkronize edilir; kısmi seçimde "indeterminate" (yarı
        // işaretli) gösterilir.
        function syncSelectAllState(table) {
            var selectAll = table.querySelector('.admin-select-all');
            if (!selectAll) {
                return;
            }
            var visibleBoxes = Array.prototype.filter.call(table.querySelectorAll('.admin-row-checkbox'), function (cb) {
                var row = cb.closest('tr');
                return row && !row.hidden;
            });
            var checkedCount = visibleBoxes.filter(function (cb) {
                return cb.checked;
            }).length;
            selectAll.checked = visibleBoxes.length > 0 && checkedCount === visibleBoxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < visibleBoxes.length;
        }

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
                // Arama, "tümünü seç"in kapsadığı görünür satır kümesini
                // değiştirebilir — kutunun durumu buna göre yeniden hesaplanır.
                syncSelectAllState(usersTable);
            });
        }

        // Her tablonun kendi "tümünü seç" checkbox'ı — sadece o tablonun
        // (arama ile) görünür satırlarını işaretler/kaldırır.
        document.querySelectorAll('.admin-select-all').forEach(function (selectAll) {
            var table = selectAll.closest('table');
            if (!table) {
                return;
            }

            selectAll.addEventListener('change', function () {
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    if (row.hidden) {
                        return;
                    }
                    var cb = row.querySelector('.admin-row-checkbox');
                    if (cb) {
                        cb.checked = selectAll.checked;
                    }
                });
                selectAll.indeterminate = false;
            });

            table.addEventListener('change', function (e) {
                if (e.target.classList.contains('admin-row-checkbox')) {
                    syncSelectAllState(table);
                }
            });
        });
    });
})();
