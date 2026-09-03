(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        var post = window.bcc_post;

        function closeOptionsMenu() {
            var menu = document.querySelector('.gs-view-options-menu');
            if (menu) {
                menu.removeAttribute('open');
            }
        }

        Array.prototype.forEach.call(document.querySelectorAll('.gs-view-row-menu'), function (menu) {
            var summary = menu.querySelector(':scope > summary');
            var panel = menu.querySelector(':scope > .gs-view-row-menu-panel');

            if (!summary || !panel) {
                return;
            }

            function positionPanel() {
                var rect = summary.getBoundingClientRect();
                var s = window.bcc_uiScale ? window.bcc_uiScale() : 1;
                panel.style.top = (rect.bottom / s + 4) + 'px';
                panel.style.left = 'auto';
                panel.style.right = ((window.innerWidth - rect.right) / s) + 'px';
            }

            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    window.removeEventListener('scroll', positionPanel, true);
                    return;
                }
                positionPanel();
                window.addEventListener('scroll', positionPanel, true);
            });
        });

        function activeViewId() {
            var el = document.querySelector('.gs-view-name[data-view-id]');
            if (el) {
                return el.getAttribute('data-view-id');
            }
            return new URLSearchParams(window.location.search).get('view_id');
        }

        var renameItem = document.getElementById('gs-view-rename-item');
        if (renameItem) {
            renameItem.addEventListener('click', function () {
                closeOptionsMenu();
                var viewNameEl = document.querySelector('.gs-view-name[data-view-id]');
                if (viewNameEl) {
                    viewNameEl.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
                }
            });
        }

        var descOverlay = document.getElementById('gs-view-desc-overlay');
        var descTextarea = document.getElementById('gs-view-desc-textarea');
        var descSaveBtn = document.getElementById('gs-view-desc-save');
        var descCancelBtn = document.getElementById('gs-view-desc-cancel');
        var editDescItem = document.getElementById('gs-view-edit-desc-item');

        if (editDescItem && descOverlay && descTextarea) {
            editDescItem.addEventListener('click', function () {
                closeOptionsMenu();
                descOverlay.hidden = false;
                descTextarea.focus();
            });
        }
        if (descCancelBtn && descOverlay) {
            descCancelBtn.addEventListener('click', function () {
                descOverlay.hidden = true;
            });
        }
        if (descOverlay) {
            window.bcc_bindDismissable(descOverlay, {
                isOpen: function () { return !descOverlay.hidden; },
                close: function () { descOverlay.hidden = true; },
                isClickOutside: function (target) { return target === descOverlay; },
            });
        }
        if (descSaveBtn && descTextarea && descOverlay) {
            descSaveBtn.addEventListener('click', function () {
                descSaveBtn.disabled = true;
                post('/api/view_description_update.php', {
                    csrf_token: CSRF,
                    view_id: activeViewId(),
                    description: descTextarea.value,
                }).then(function (result) {
                    descSaveBtn.disabled = false;
                    if (result.httpOk && result.data && result.data.ok) {
                        descOverlay.hidden = true;
                    } else {
                        window.alert((result.data && result.data.error) || 'Açıklama kaydedilemedi.');
                    }
                }).catch(function () {
                    descSaveBtn.disabled = false;
                    window.alert('Açıklama kaydedilemedi (bağlantı hatası).');
                });
            });
        }

        var createMenu = document.querySelector('.gs-view-create-menu');
        var createPanel = document.querySelector('.gs-view-create-panel');

        if (createMenu && createPanel) {
            var createSummary = createMenu.querySelector(':scope > summary');
            if (createSummary) {
                window.bcc_bindFloatingPanel(createMenu, createPanel, createSummary);
            }
        }

        if (createPanel) {
            createPanel.addEventListener('click', function (e) {
                var option = e.target.closest('.gs-view-create-option');
                if (!option || !option.hasAttribute('data-view-type')) {
                    return;
                }

                if (createMenu) {
                    createMenu.removeAttribute('open');
                }

                var tableId = new URLSearchParams(window.location.search).get('table_id');
                var viewType = option.getAttribute('data-view-type');
                option.disabled = true;

                post('/api/view_create.php', {
                    csrf_token: CSRF,
                    table_id: tableId,
                    view_type: viewType,
                }).then(function (result) {
                    option.disabled = false;
                    if (result.httpOk && result.data && result.data.ok) {
                        window.location.href = result.data.redirect_url;
                    } else {
                        window.alert((result.data && result.data.error) || 'Görünüm oluşturulamadı.');
                    }
                }).catch(function () {
                    option.disabled = false;
                    window.alert('Görünüm oluşturulamadı (bağlantı hatası).');
                });
            });
        }

        var createTableTriggers = Array.prototype.slice.call(
            document.querySelectorAll('[data-create-table-btn]')
        );
        var createTableModal = document.getElementById('gs-create-table-modal');

        if (createTableTriggers.length && createTableModal) {
            var ctForm = document.getElementById('gs-create-table-form');
            var ctError = document.getElementById('gs-create-table-error');
            var ctNameInput = ctForm.querySelector('input[name="name"]');

            var closeCreateTable = function () {
                createTableModal.hidden = true;
                ctForm.reset();
                ctError.hidden = true;
            };

            createTableTriggers.forEach(function (trigger) {
                trigger.addEventListener('click', function (e) {
                    e.preventDefault();

                    if (createMenu) {
                        createMenu.removeAttribute('open');
                    }
                    ctError.hidden = true;
                    createTableModal.hidden = false;
                    ctNameInput.focus();
                });
            });

            document.getElementById('gs-create-table-close').addEventListener('click', closeCreateTable);
            document.getElementById('gs-create-table-cancel').addEventListener('click', closeCreateTable);

            createTableModal.addEventListener('click', function (e) {
                if (e.target === createTableModal) {
                    closeCreateTable();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !createTableModal.hidden) {
                    closeCreateTable();
                }
            });

            ctForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var submitBtn = ctForm.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                ctError.hidden = true;

                post('/api/table_create.php', {
                    csrf_token: CSRF,
                    base_id: createTableModal.getAttribute('data-base-id'),
                    name: ctNameInput.value,
                    description: ctForm.querySelector('input[name="description"]').value,
                }).then(function (result) {
                    if (result.httpOk && result.data && result.data.ok) {
                        window.location.href = result.data.redirect_url;
                        return;
                    }
                    submitBtn.disabled = false;
                    ctError.textContent = (result.data && result.data.error) || 'Tablo oluşturulamadı.';
                    ctError.hidden = false;
                }).catch(function () {
                    submitBtn.disabled = false;
                    ctError.textContent = 'Tablo oluşturulamadı (bağlantı hatası).';
                    ctError.hidden = false;
                });
            });
        }

        var duplicateItem = document.getElementById('gs-view-duplicate-item');
        if (duplicateItem) {
            duplicateItem.addEventListener('click', function () {
                closeOptionsMenu();
                var tableId = new URLSearchParams(window.location.search).get('table_id');

                post('/api/table_duplicate.php', {
                    csrf_token: CSRF,
                    table_id: tableId,
                    name: '',
                    with_records: '1',
                }).then(function (result) {
                    if (result.httpOk && result.data && result.data.ok && result.data.redirect_url) {
                        window.location.href = result.data.redirect_url;
                    } else {
                        window.alert((result.data && result.data.error) || 'Bağımsız kopya oluşturulamadı.');
                    }
                }).catch(function () {
                    window.alert('Bağımsız kopya oluşturulamadı (bağlantı hatası).');
                });
            });
        }

        var downloadItem = document.getElementById('gs-view-download-xlsx-item');
        if (downloadItem) {
            downloadItem.addEventListener('click', function () {
                closeOptionsMenu();
                window.location.href = '/api/view_export_xlsx.php' + window.location.search;
            });
        }

        var PRINT_LANDSCAPE_MIN_COLUMNS = 6;

        function syncPrintOrientation() {
            var table = document.querySelector('table.grid');
            if (!table) {
                return;
            }
            var headCells = table.querySelectorAll('thead th');
            var dataColumns = headCells.length - 1;
            if (table.querySelector('thead th.grid-add-field-th')) {
                dataColumns -= 1;
            }

            var styleEl = document.getElementById('gs-print-orientation');
            if (dataColumns < PRINT_LANDSCAPE_MIN_COLUMNS) {
                if (styleEl) {
                    styleEl.parentNode.removeChild(styleEl);
                }
                return;
            }
            if (!styleEl) {
                styleEl = document.createElement('style');
                styleEl.id = 'gs-print-orientation';
                document.head.appendChild(styleEl);
            }
            styleEl.textContent = '@media print { @page { size: landscape; margin: 12mm 10mm; } }';
        }

        syncPrintOrientation();
        window.addEventListener('beforeprint', syncPrintOrientation);

        var printItem = document.getElementById('gs-view-print-item');
        if (printItem) {
            printItem.addEventListener('click', function () {
                closeOptionsMenu();
                window.print();
            });
        }

        var deleteItem = document.getElementById('gs-view-delete-item');
        if (deleteItem) {
            deleteItem.addEventListener('click', function () {
                closeOptionsMenu();
                window.bcc_confirm({
                    title: 'Görünümü sil',
                    message: 'Bu görünümü silmek istediğinize emin misiniz?',
                }).then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    var tableId = new URLSearchParams(window.location.search).get('table_id');
                    post('/api/view_delete.php', { csrf_token: CSRF, view_id: activeViewId() }).then(function (result) {
                        if (result.httpOk && result.data && result.data.ok) {
                            window.location.href = '/grid.php?table_id=' + encodeURIComponent(tableId);
                        } else {
                            window.alert((result.data && result.data.error) || 'Görünüm silinemedi.');
                        }
                    }).catch(function () {
                        window.alert('Görünüm silinemedi (bağlantı hatası).');
                    });
                });
            });
        }

        var searchInput = document.getElementById('gs-view-search-input');
        var viewRows = Array.prototype.slice.call(document.querySelectorAll('.gs-view-drawer-row'));
        var emptyEl = document.getElementById('gs-view-drawer-empty');

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim().toLowerCase();
                var visibleCount = 0;
                viewRows.forEach(function (row) {
                    var name = row.getAttribute('data-view-row-name') || '';
                    var visible = q === '' || name.indexOf(q) !== -1;
                    row.hidden = !visible;
                    if (visible) {
                        visibleCount++;
                    }
                });
                if (emptyEl) {
                    emptyEl.hidden = !(q !== '' && visibleCount === 0);
                }
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('.gs-view-star-btn'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (btn.disabled) {
                    return;
                }
                btn.disabled = true;

                post('/api/view_favorite_toggle.php', { csrf_token: CSRF, view_id: btn.getAttribute('data-view-id') }).then(function (result) {
                    btn.disabled = false;
                    if (result.httpOk && result.data && result.data.ok) {
                        btn.setAttribute('aria-pressed', result.data.favorited ? 'true' : 'false');
                    } else {
                        window.alert((result.data && result.data.error) || 'İşlem başarısız.');
                    }
                }).catch(function () {
                    btn.disabled = false;
                });
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-view-rename]'), function (btn) {
            btn.addEventListener('click', function () {
                closeOptionsMenu();
                var row = btn.closest('.gs-view-drawer-row');
                var nameEl = row ? row.querySelector('[data-view-sync-id="' + btn.getAttribute('data-view-id') + '"]') : null;
                if (nameEl && window.bcc_startViewRename) {
                    window.bcc_startViewRename(nameEl, btn.getAttribute('data-view-id'));
                }
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-view-delete]'), function (btn) {
            btn.addEventListener('click', function () {
                closeOptionsMenu();
                window.bcc_confirm({
                    title: 'Görünümü sil',
                    message: 'Bu görünümü silmek istediğinize emin misiniz?',
                }).then(function (ok) {
                    if (!ok) {
                        return;
                    }
                    var viewId = btn.getAttribute('data-view-id');
                    var row = btn.closest('.gs-view-drawer-row');
                    var tableId = new URLSearchParams(window.location.search).get('table_id');
                    var wasActive = row && row.classList.contains('is-selected');

                    post('/api/view_delete.php', { csrf_token: CSRF, view_id: viewId }).then(function (result) {
                        if (result.httpOk && result.data && result.data.ok) {
                            if (wasActive) {
                                window.location.href = '/grid.php?table_id=' + encodeURIComponent(tableId) + '&view_id=' + encodeURIComponent(result.data.fallback_view_id);
                            } else {
                                window.location.reload();
                            }
                        } else {
                            window.alert((result.data && result.data.error) || 'Görünüm silinemedi.');
                        }
                    }).catch(function () {
                        window.alert('Görünüm silinemedi (bağlantı hatası).');
                    });
                });
            });
        });

        var viewList = document.getElementById('gs-view-drawer-list');
        if (viewList && window.bcc_bindColumnDrag) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-view-drag-handle]'), function (handle) {
                var row = handle.closest('.gs-view-drawer-row');
                if (!row) {
                    return;
                }

                var placeholder = null;
                var startIndex = -1;

                function allRows() {
                    return Array.prototype.slice.call(viewList.querySelectorAll('.gs-view-drawer-row'));
                }

                function otherVisibleRows() {
                    return allRows().filter(function (r) {
                        return r !== row && !r.hidden;
                    });
                }

                window.bcc_bindColumnDrag(handle, {
                    onStart: function () {
                        startIndex = allRows().indexOf(row);

                        placeholder = document.createElement('div');
                        placeholder.className = 'gs-view-drag-placeholder';
                        placeholder.style.height = row.offsetHeight + 'px';
                        viewList.insertBefore(placeholder, row.nextSibling);
                        row.classList.add('is-drag-source');
                    },
                    onMove: function (clientX, clientY) {
                        if (!placeholder) {
                            return;
                        }
                        var rows = otherVisibleRows();
                        for (var i = 0; i < rows.length; i++) {
                            var rect = rows[i].getBoundingClientRect();
                            var midpoint = rect.top + rect.height / 2;
                            if (clientY < midpoint) {
                                viewList.insertBefore(placeholder, rows[i]);
                                return;
                            }
                        }
                        var emptyMsg = document.getElementById('gs-view-drawer-empty');
                        viewList.insertBefore(placeholder, emptyMsg || null);
                    },
                    onEnd: function () {
                        if (!placeholder) {
                            return;
                        }
                        viewList.insertBefore(row, placeholder);
                        placeholder.parentNode.removeChild(placeholder);
                        placeholder = null;
                        row.classList.remove('is-drag-source');

                        var endIndex = allRows().indexOf(row);
                        if (endIndex === startIndex || startIndex === -1) {
                            return;
                        }

                        var viewId = row.getAttribute('data-view-row-id');
                        var steps = endIndex - startIndex;
                        var direction = steps > 0 ? 'down' : 'up';
                        var remaining = Math.abs(steps);

                        function stepOnce() {
                            if (remaining <= 0) {
                                return;
                            }
                            remaining--;
                            post('/api/view_reorder.php', { csrf_token: CSRF, view_id: viewId, direction: direction }).then(function (result) {
                                if (result.httpOk && result.data && result.data.ok) {
                                    stepOnce();
                                } else {
                                    window.alert((result.data && result.data.error) || 'Taşınamadı.');
                                }
                            }).catch(function () {
                                window.alert('Taşınamadı (bağlantı hatası).');
                            });
                        }

                        stepOnce();
                    },
                });
            });
        }
    });
})();
