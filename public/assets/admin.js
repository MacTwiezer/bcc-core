(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.addEventListener('click', function (e) {
            document.querySelectorAll('details.admin-menu[open]').forEach(function (menu) {
                if (!menu.contains(e.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

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

        var searchInput = document.getElementById('admin-users-search');
        var usersTable = document.getElementById('admin-users-table');
        if (searchInput && usersTable) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim().toLowerCase();
                usersTable.querySelectorAll('tbody tr').forEach(function (row) {
                    var haystack = row.getAttribute('data-user-search') || '';
                    row.hidden = q !== '' && haystack.indexOf(q) === -1;
                });
                syncSelectAllState(usersTable);
            });
        }

        function checkedCountFor(formId) {
            return document.querySelectorAll('input.admin-row-checkbox[form="' + formId + '"]:checked').length;
        }

        function isBulkForm(formId) {
            return !!formId && document.querySelectorAll('input.admin-row-checkbox[form="' + formId + '"]').length > 0;
        }

        function warnNoSelection() {
            if (window.bcc_alert) {
                window.bcc_alert({
                    title: 'Seçim yapılmadı',
                    message: 'Lütfen önce listeden en az bir kullanıcı seçiniz.',
                });
            }
        }

        document.querySelectorAll('.admin-bulk-bar details.admin-menu > summary').forEach(function (summary) {
            summary.addEventListener('click', function (e) {
                var menu = summary.parentNode;
                var button = menu.querySelector('button[form]');
                var formId = button ? button.getAttribute('form') : '';
                if (!menu.open && isBulkForm(formId) && checkedCountFor(formId) === 0) {
                    e.preventDefault();
                    warnNoSelection();
                }
            });
        });

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.id || !isBulkForm(form.id)) {
                return;
            }
            if (checkedCountFor(form.id) === 0) {
                e.preventDefault();
                e.stopImmediatePropagation();
                document.querySelectorAll('details.admin-menu[open]').forEach(function (menu) {
                    menu.removeAttribute('open');
                });
                warnNoSelection();
            }
        }, true);

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
