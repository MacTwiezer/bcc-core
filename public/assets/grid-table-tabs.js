(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Sekme seçenekleri menüsü ve "All tables" menüsü — ikisi de
        // <details class="gs-table-tab-menu">. name="gs-table-tab-menu" modern
        // tarayıcılarda zaten aynı anda tek birini açık tutuyor, ama eski
        // Firefox/Safari bunu desteklemiyor — bu yüzden "toggle" olayıyla da
        // aynı davranış JS tarafında garanti ediliyor. Ayrıca projedeki ortak
        // "dışarı tıklayınca / Escape ile kapanma" deseni burada uygulanıyor.
        var menus = document.querySelectorAll('.gs-table-tab-menu');

        if (!menus.length) {
            return;
        }

        menus.forEach(function (menu) {
            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    return;
                }

                menus.forEach(function (other) {
                    if (other !== menu && other.open) {
                        other.removeAttribute('open');
                    }
                });
            });
        });

        document.addEventListener('click', function (e) {
            menus.forEach(function (menu) {
                if (menu.open && !menu.contains(e.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

        // "All tables" paneli: açılınca odak arama kutusuna gider, yazdıkça
        // istemci tarafında anlık filtreler (sunucuya gitmeden — liste zaten
        // $siblingTables'tan tek kaynaktan basılmış DOM'da).
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

        // Mac'te rozet metni "⌘ J", diğerlerinde "Ctrl J" (bkz. .gs-kbd-mac / .gs-kbd-other CSS'i).
        if (/Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '')) {
            document.documentElement.classList.add('is-mac');
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                menus.forEach(function (menu) {
                    if (menu.open) {
                        menu.removeAttribute('open');
                    }
                });
                return;
            }

            // Ctrl+J (Windows/Linux) veya ⌘+J (Mac): "All tables" panelini aç/kapat.
            // Hücre düzenlerken (input/textarea/contenteditable odaktayken) tetiklenmemeli.
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
                // Türkçe I/ı, İ/i tuzağı: varsayılan toLowerCase() yerine
                // toLocaleLowerCase('tr') kullanılır (bkz. is/İstanbul örneği).
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

        // Görünüm adını satır içi düzenleme: data-view-id yalnızca editor+ rolünde
        // grid.php tarafından basılır (bkz. $canEdit) — viewer'da bu eleman hiç
        // bulunmaz, dblclick dinleyicisi hiç bağlanmaz. Sunucu tarafında da
        // /api/view_rename.php require_role('editor') ile ayrıca reddeder;
        // istemci kontrolü tek başına yeterli sayılmaz.
        var viewNameEl = document.querySelector('[data-view-id]');
        var viewMirrorEl = document.querySelector('[data-view-name-mirror]');
        var viewInfoTitleEl = document.querySelector('.gs-view-info-title');
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        if (viewNameEl) {
            var viewId = viewNameEl.getAttribute('data-view-id');
            var editing = false;
            var cancelled = false;

            viewNameEl.addEventListener('dblclick', function () {
                if (editing) {
                    return;
                }
                editing = true;
                cancelled = false;

                var originalName = viewNameEl.textContent;
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'gs-view-name-input';
                input.value = originalName;

                viewNameEl.replaceWith(input);
                input.focus();
                input.select();

                function applyName(name) {
                    viewNameEl.textContent = name;
                    if (viewMirrorEl) {
                        viewMirrorEl.textContent = name;
                    }
                    if (viewInfoTitleEl) {
                        viewInfoTitleEl.textContent = name;
                    }
                }

                function finishEditing(save) {
                    if (!editing) {
                        return;
                    }
                    editing = false;

                    var newValue = input.value.trim();
                    input.replaceWith(viewNameEl);

                    if (!save || newValue === '' || newValue === originalName) {
                        viewNameEl.textContent = originalName;
                        return;
                    }

                    viewNameEl.textContent = newValue; // iyimser güncelleme

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
                            viewNameEl.textContent = originalName;
                            window.alert((result.data && result.data.error) || 'Görünüm adı kaydedilemedi.');
                        }
                    }).catch(function () {
                        viewNameEl.textContent = originalName;
                        window.alert('Görünüm adı kaydedilemedi (bağlantı hatası).');
                    });
                }

                input.addEventListener('keydown', function (ke) {
                    if (ke.key === 'Enter') {
                        ke.preventDefault();
                        finishEditing(true);
                    } else if (ke.key === 'Escape') {
                        // Escape'te ÖNCE bayrak koy: replaceWith input'u DOM'dan
                        // kaldırırken tetiklediği blur, kaydetmeyi tekrar denememeli.
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
            });
        }
    });
})();
