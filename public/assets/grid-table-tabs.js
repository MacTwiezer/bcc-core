(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var allTablesMenu = document.querySelector('.gs-all-tables-menu');
        var searchInput = document.querySelector('[data-all-tables-search]');
        var rows = document.querySelectorAll('[data-all-tables-row]');
        var emptyRow = document.querySelector('[data-all-tables-empty]');

        if (allTablesMenu && searchInput) {
            allTablesMenu.addEventListener('toggle', function () {
                if (allTablesMenu.open) {
                    searchInput.focus();
                }
            });
        }

        if (/Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '')) {
            document.documentElement.classList.add('is-mac');
        }

        document.addEventListener('keydown', function (e) {

            if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'j') {
                return;
            }

            var targetTag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
            if (targetTag === 'input' || targetTag === 'textarea' || (e.target && e.target.isContentEditable)) {
                return;
            }

            if (!allTablesMenu) {
                return;
            }

            e.preventDefault();
            allTablesMenu.open = !allTablesMenu.open;
        });

        if (searchInput && rows.length) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim().toLocaleLowerCase('tr');
                var anyVisible = false;

                rows.forEach(function (row) {
                    var name = row.textContent.trim().toLocaleLowerCase('tr');
                    var match = q === '' || name.indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) {
                        anyVisible = true;
                    }
                });

                if (emptyRow) {
                    emptyRow.hidden = anyVisible;
                }
            });
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        window.bcc_startViewRename = function (nameEl, viewId) {
            var editing = false;
            var cancelled = false;

            if (nameEl.getAttribute('data-view-renaming') === '1') {
                return;
            }
            nameEl.setAttribute('data-view-renaming', '1');
            editing = true;

            var originalName = nameEl.textContent;
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'gs-view-name-input';
            input.value = originalName;

            nameEl.replaceWith(input);
            input.focus();
            input.select();

            function applyName(name) {
                nameEl.textContent = name;
                Array.prototype.forEach.call(document.querySelectorAll('[data-view-sync-id="' + viewId + '"]'), function (el) {
                    el.textContent = name;
                });
            }

            function finishEditing(save) {
                if (!editing) {
                    return;
                }
                editing = false;
                nameEl.removeAttribute('data-view-renaming');

                var newValue = input.value.trim();
                input.replaceWith(nameEl);

                if (!save || newValue === '' || newValue === originalName) {
                    nameEl.textContent = originalName;
                    return;
                }

                nameEl.textContent = newValue;

                fetch('/api/view_rename.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: CSRF, view_id: viewId, name: newValue }).toString(),
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                    }).then(function (data) {
                        return { httpOk: res.ok, data: data };
                    });
                }).then(function (result) {
                    if (result.httpOk && result.data && result.data.ok) {
                        applyName(result.data.name);
                    } else {
                        nameEl.textContent = originalName;
                        window.alert((result.data && result.data.error) || 'Görünüm adı kaydedilemedi.');
                    }
                }).catch(function () {
                    nameEl.textContent = originalName;
                    window.alert('Görünüm adı kaydedilemedi (bağlantı hatası).');
                });
            }

            input.addEventListener('keydown', function (ke) {
                if (ke.key === 'Enter') {
                    ke.preventDefault();
                    finishEditing(true);
                } else if (ke.key === 'Escape') {
                    ke.preventDefault();
                    cancelled = true;
                    finishEditing(false);
                }
            });

            input.addEventListener('blur', function () {
                if (cancelled) {
                    return;
                }
                finishEditing(true);
            });
        };

        var viewNameEl = document.querySelector('.gs-view-name[data-view-id]');
        if (viewNameEl) {
            var toolbarViewId = viewNameEl.getAttribute('data-view-id');
            viewNameEl.addEventListener('dblclick', function () {
                window.bcc_startViewRename(viewNameEl, toolbarViewId);
            });
        }
    });
})();
