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

            function positionPanel() {
                var rect = summary.getBoundingClientRect();
                panel.style.top = (rect.bottom + 4) + 'px';
                panel.style.left = 'auto';
                panel.style.right = (window.innerWidth - rect.right) + 'px';
            }

            // Bulunan gerçek bug: konum yalnızca AÇILIŞTA hesaplanıyordu —
            // grid-column-menu.js/grid-table-data.js'de bulunan AYNI sorun.
            // Menü açıkken sayfa (veya .gs-view-drawer) kaydırılırsa satır
            // kayarken panel ekranda sabit kalıp tamamen kopuyordu. Scroll'da
            // yeniden konumlandırılır, menü kapanınca listener kaldırılır.
            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    window.removeEventListener('scroll', positionPanel, true);
                    return;
                }
                positionPanel();
                window.addEventListener('scroll', positionPanel, true);
            });
        });

        // Bulunan kırılgan kod: "[data-view-id]" tek başına, sol paneldeki her
        // satırın yıldız/yeniden adlandır/sil butonları da AYNI özniteliği
        // taşıdığından yanlış elemanı seçebilirdi (grid-table-tabs.js'de bulunan
        // AYNI sorun, bkz. o dosyadaki düzeltme) — .gs-view-name ile daraltıldı.
        function activeViewId() {
            var el = document.querySelector('.gs-view-name[data-view-id]');
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
                var viewNameEl = document.querySelector('.gs-view-name[data-view-id]');
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

        // ---- Sol panel: "+ Yeni oluştur..." — view_duplicate.php ile AYNI
        // ekleme/yönlendirme deseni, yalnızca kaynak view'ı kopyalamak yerine
        // view_create.php boş bir view oluşturuyor.
        // Tek buton yerine artık TİP SEÇİCİ var (grid.php, BCC_VIEW_TYPES'tan
        // dinamik üretiliyor) — her seçenek kendi data-view-type'ını gönderir.
        // Tek dinleyici, panele delegasyonla: yeni bir görünüm türü eklendiğinde
        // bu JS'e DOKUNMAK GEREKMEZ.
        var createMenu = document.querySelector('.gs-view-create-menu');
        var createPanel = document.querySelector('.gs-view-create-panel');

        // YÜZEN menü: panel position:fixed, konumu ortak yardımcıdan
        // (bcc_bindFloatingPanel) — sol paneldeki görünüm listesini AŞAĞI İTMEZ.
        // Kapanma (dışarı tık + Escape + karşılıklı dışlama) grid-table-tabs.js'in
        // <details name="gs-table-tab-menu"> grup yöneticisinden gelir, burada
        // ikinci bir dinleyici YAZILMADI.
        if (createMenu && createPanel) {
            var createSummary = createMenu.querySelector(':scope > summary');
            if (createSummary) {
                window.bcc_bindFloatingPanel(createMenu, createPanel, createSummary);
            }
        }

        if (createPanel) {
            createPanel.addEventListener('click', function (e) {
                var option = e.target.closest('.gs-view-create-option');
                // data-view-type ŞART: bu panelde artık görünüm türü OLMAYAN
                // bir seçenek de var ("Boş tablo oluştur"). Yalnızca sınıfa
                // bakmak onu da yakalıyor ve view_create.php'ye boş tür
                // göndererek "Geçersiz görünüm türü" hatası veriyordu.
                if (!option || !option.hasAttribute('data-view-type')) {
                    return;
                }

                // Seçim yapılınca menü KAPANIR — istek yola çıkarken açık kalan
                // bir menü, yönlendirme gecikirse kullanıcıya "tıklamam işe
                // yaramadı" hissi verirdi.
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
                        // Hedef adresi SUNUCU belirliyor (bcc_view_route_for) —
                        // istemci '/grid.php?...' dizgisini artık kendi kurmuyor,
                        // yoksa her yeni tür için burası da güncellenmek zorunda kalırdı.
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

        // ---- "Boş tablo oluştur" modalı ---------------------------------
        // Menüdeki tetikleyici ve modal YALNIZCA owner'a basılır; ikisi de
        // yoksa bu blok sessizce no-op olur (projedeki null-check deseni).
        // Asıl yetki kapısı sunucuda: api/table_create.php.
        var createTableBtn = document.getElementById('gs-create-table-btn');
        var createTableModal = document.getElementById('gs-create-table-modal');

        if (createTableBtn && createTableModal) {
            var ctForm = document.getElementById('gs-create-table-form');
            var ctError = document.getElementById('gs-create-table-error');
            var ctNameInput = ctForm.querySelector('input[name="name"]');

            var closeCreateTable = function () {
                createTableModal.hidden = true;
                ctForm.reset();
                ctError.hidden = true;
            };

            createTableBtn.addEventListener('click', function () {
                // Menü kapanır — görünüm seçenekleriyle AYNI davranış.
                if (createMenu) {
                    createMenu.removeAttribute('open');
                }
                ctError.hidden = true;
                createTableModal.hidden = false;
                ctNameInput.focus();
            });

            document.getElementById('gs-create-table-close').addEventListener('click', closeCreateTable);
            document.getElementById('gs-create-table-cancel').addEventListener('click', closeCreateTable);

            // Arka plana tıklayınca kapan — modalın İÇİNE tıklamak kapatmamalı,
            // o yüzden hedefin backdrop'ın KENDİSİ olması aranıyor.
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
                // Sayfa terk edilmesin: gönderim AJAX.
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
                        // Yeni tabloya geç — kullanıcı oluşturduğu boş tabloyu
                        // hemen görsün. Hedefi SUNUCU veriyor.
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

        // ---- Excel indir — aktif URL state'i (sort/filter/hidden_fields) AYNEN
        // /api/view_export_xlsx.php'ye taşınır, ikinci bir state okuma/parse YOK.
        var downloadItem = document.getElementById('gs-view-download-xlsx-item');
        if (downloadItem) {
            downloadItem.addEventListener('click', function () {
                closeOptionsMenu();
                window.location.href = '/api/view_export_xlsx.php' + window.location.search;
            });
        }

        // ---- PDF olarak indir (window.print()) ----
        // Çıktının BİÇİMİ tamamen CSS'te: ortak kurallar assets/grid-export.css
        // (sayfaya media="print" ile bağlı, PNG ile PAYLAŞILIYOR), kâğıda özgü
        // olanlar grid-shell.css @media print. Burada JS'in tek işi SAYFA YÖNÜ —
        // çünkü @page bir sınıfa/medya sorgusuna göre şartlanamıyor, tek yolu
        // kuralı çalışma anında enjekte etmek.
        //
        // Sayfa yönü sütun SAYISINA göre veriliyor, ölçülen genişliğe göre
        // DEĞİL: table.grid'in `min-width:100%`i (style.css) tabloyu her zaman
        // sarmalayıcı kadar geniş gösterir, yani scrollWidth dar bir tabloda da
        // ~1400px okur ve HER tabloyu landscape yapardı (denendi, ölçü bu yüzden
        // kullanılamıyor). Sütun sayısı ekran genişliğinden bağımsız ve
        // deterministik.
        var PRINT_LANDSCAPE_MIN_COLUMNS = 6;

        function syncPrintOrientation() {
            var table = document.querySelector('table.grid');
            if (!table) {
                return;
            }
            // Veri sütunları: satır numarası sütunu ve (owner'da) "+" yeni alan
            // sütunu sayılmaz — ikisi de çıktıda gizli (grid-export.css).
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
            // Kenar boşluğu grid-shell.css @media print'teki @page ile AYNI —
            // `size` verilirken margin tekrar yazılmazsa tarayıcı varsayılana döner.
            styleEl.textContent = '@media print { @page { size: landscape; margin: 12mm 10mm; } }';
        }

        // Hem açılışta hem yazdırmadan hemen önce: "Alanları gizle" sayfayı
        // yeniden yüklediği için açılış zaten yeterli, ama beforeprint Ctrl+P
        // dahil HER yazdırma yolunda tetiklendiğinden karar güncel kalır.
        // Desteklenmediği durumda en kötü ihtimalle dikey basılır — çıktı
        // BOZULMAZ, çünkü gizleme/kırpma kuralları JS'e bağlı değil.
        syncPrintOrientation();
        window.addEventListener('beforeprint', syncPrintOrientation);

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

        // ---- D1: Sol panel satır menüsü — "Yeniden adlandır" (window.bcc_startViewRename,
        // grid-table-tabs.js'te tanımlı — ikinci bir düzenleme akışı YOK) + "Sil"
        // (view_delete.php, zaten HERHANGİ bir view_id'yi kabul ediyor, "son view
        // silinemez" kuralı sunucuda zaten var).
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
                if (!window.confirm('Bu görünümü silmek istediğinize emin misiniz?')) {
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

        // ---- D1: Sürükle-bırak sıralama — bcc_bindColumnDrag() (mevcut
        // mousedown/mousemove(rAF)/mouseup iskeleti, clientY de iletecek şekilde
        // genişletildi) ÜZERİNE inşa edildi, paralel bir sürükleme mekanizması
        // YOK. Sürüklenen satırın KENDİSİ yerinde durur (soluklaştırılır,
        // .is-drag-source) — konum önizlemesi için ayrı, dış görünüşü boş bir
        // "hologram" (.gs-view-drag-placeholder, bkz. grid-shell.css) fareyi
        // takip eder. Önceki sürüm satırı canlı taşıyordu; bu, taşınan satırın
        // kendi index'ini her onMove'da yeniden hesaplaması gerektirdiği için
        // ardışık karelerde salınım/thrashing riski taşıyordu — placeholder
        // satırdan TAMAMEN bağımsız olduğu için bu sorun yok. Bırakınca placeholder
        // satırın yeni konumuna dönüşür (replaceChild benzeri), yeni sıra MEVCUT
        // view_reorder.php'ye (bcc_reorder_sibling()) ardışık up/down çağrılarıyla
        // kaydedilir — yeni bir "toplu sırala" uç noktası YAZILMADI.
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

                // Arama filtresiyle gizlenmiş (hidden) satırlar konum hesabına
                // katılmaz — gs-view-search-input ile AYNI "hidden = gizli" kuralı.
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
                        placeholder.style.height = row.getBoundingClientRect().height + 'px';
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
                        // Fare tüm satırların altında — en sona (boş sonuç mesajından ÖNCE) bırak.
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
