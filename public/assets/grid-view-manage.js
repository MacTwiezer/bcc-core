(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        function post(url, params) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params).toString(),
            }).then(function (res) {
                return res.json().catch(function () {
                    return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                }).then(function (data) {
                    return { httpOk: res.ok, data: data };
                });
            });
        }

        function closeOptionsMenu() {
            var menu = document.querySelector('.gs-view-options-menu');
            if (menu) {
                menu.removeAttribute('open');
            }
        }

        // .gs-view-drawer overflow-y:auto taşıyor — panel position:absolute
        // olsaydı liste kaydırıldığında kırpılırdı (bkz. grid-shell.css yorumu).
        // position:fixed + burada hesaplanan konum bunu atlıyor.
        Array.prototype.forEach.call(document.querySelectorAll('.gs-view-row-menu'), function (menu) {
            var summary = menu.querySelector(':scope > summary');
            var panel = menu.querySelector(':scope > .gs-view-row-menu-panel');

            if (!summary || !panel) {
                return;
            }

            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    return;
                }
                var rect = summary.getBoundingClientRect();
                panel.style.top = (rect.bottom + 4) + 'px';
                panel.style.left = 'auto';
                panel.style.right = (window.innerWidth - rect.right) + 'px';
            });
        });

        function activeViewId() {
            var el = document.querySelector('[data-view-id]');
            if (el) {
                return el.getAttribute('data-view-id');
            }
            return new URLSearchParams(window.location.search).get('view_id');
        }

        // "Rename view" menü öğesi — YENİ bir düzenleme akışı YAZILMADI, mevcut
        // dblclick mekanizmasını (grid-table-tabs.js) sentetik bir dblclick
        // event'iyle tetikliyor, aynı kod yolu.
        var renameItem = document.getElementById('gs-view-rename-item');
        if (renameItem) {
            renameItem.addEventListener('click', function () {
                closeOptionsMenu();
                var viewNameEl = document.querySelector('[data-view-id]');
                if (viewNameEl) {
                    viewNameEl.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
                }
            });
        }

        // ---- Edit view description ----
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
            // Backdrop'a (modal içeriğine değil) tıklayınca / Escape ile kapanma —
            // assets/dismissable-panel.js, isOpen/close .hidden'a göre override edilir.
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

        // ---- Duplicate view ----
        var duplicateItem = document.getElementById('gs-view-duplicate-item');
        if (duplicateItem) {
            duplicateItem.addEventListener('click', function () {
                closeOptionsMenu();
                var tableId = new URLSearchParams(window.location.search).get('table_id');
                post('/api/view_duplicate.php', { csrf_token: CSRF, view_id: activeViewId() }).then(function (result) {
                    if (result.httpOk && result.data && result.data.ok) {
                        window.location.href = '/grid.php?table_id=' + encodeURIComponent(tableId) + '&view_id=' + encodeURIComponent(result.data.view_id);
                    } else {
                        window.alert((result.data && result.data.error) || 'Görünüm kopyalanamadı.');
                    }
                }).catch(function () {
                    window.alert('Görünüm kopyalanamadı (bağlantı hatası).');
                });
            });
        }

        // ---- Download CSV — aktif URL state'i (sort/filter/hidden_fields) AYNEN
        // /api/view_export_csv.php'ye taşınır, ikinci bir state okuma/parse YOK.
        var downloadItem = document.getElementById('gs-view-download-csv-item');
        if (downloadItem) {
            downloadItem.addEventListener('click', function () {
                closeOptionsMenu();
                window.location.href = '/api/view_export_csv.php' + window.location.search;
            });
        }

        // ---- Print view ----
        var printItem = document.getElementById('gs-view-print-item');
        if (printItem) {
            printItem.addEventListener('click', function () {
                closeOptionsMenu();
                window.print();
            });
        }

        // ---- Delete view ----
        var deleteItem = document.getElementById('gs-view-delete-item');
        if (deleteItem) {
            deleteItem.addEventListener('click', function () {
                closeOptionsMenu();
                if (!window.confirm('Bu görünümü silmek istediğinize emin misiniz?')) {
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
        }

        // ---- Sol panel: "Find a view" — tamamen client-side (view'lar zaten az
        // sayıda ve DOM'da), Ctrl+K/bildirim aramasıyla AYNI gerekçe.
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

        // ---- Sol panel: favori (yıldız) — star_base.php ile AYNI toggle deseni.
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

        // ---- Sol panel: yukarı/aşağı taşı — bcc_reorder_sibling() (mevcut,
        // 'views' eklenerek genişletildi) çağıran view_reorder.php.
        Array.prototype.forEach.call(document.querySelectorAll('[data-view-move]'), function (btn) {
            btn.addEventListener('click', function () {
                post('/api/view_reorder.php', {
                    csrf_token: CSRF,
                    view_id: btn.getAttribute('data-view-id'),
                    direction: btn.getAttribute('data-view-move'),
                }).then(function (result) {
                    if (result.httpOk && result.data && result.data.ok && result.data.moved) {
                        window.location.reload();
                    } else if (result.httpOk && result.data && result.data.ok) {
                        // moved:false — sınırdaki view (en üst/en alt), sessizce yok say.
                        return;
                    } else {
                        window.alert((result.data && result.data.error) || 'Taşınamadı.');
                    }
                }).catch(function () {
                    window.alert('Taşınamadı (bağlantı hatası).');
                });
            });
        });
    });
})();
