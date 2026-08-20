(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        function closeTabMenu(button) {
            var menu = button.closest('.gs-table-tab-menu');
            if (menu) {
                menu.removeAttribute('open');
            }
        }

        // .gs-table-tabs-scroll overflow-x:auto taşıyor (bu da spec gereği
        // overflow-y'yi "auto" yapar) — position:absolute panel sekme şeridinin
        // altında kırpılırdı (bkz. grid-shell.css .gs-table-tab-import-menu-panel
        // yorumu, aynı ders .gs-view-row-menu-panel'de de uygulanmıştı).
        // position:fixed + burada hesaplanan konum bunu atlıyor.
        Array.prototype.forEach.call(document.querySelectorAll('.gs-table-tab-import-menu'), function (menu) {
            var summary = menu.querySelector(':scope > summary');
            var panel = menu.querySelector(':scope > .gs-table-tab-import-menu-panel');

            if (!summary || !panel) {
                return;
            }

            function positionPanel() {
                var rect = summary.getBoundingClientRect();
                panel.style.top = (rect.bottom + 4) + 'px';
                panel.style.left = rect.left + 'px';
            }

            // Bulunan gerçek bug: konum yalnızca AÇILIŞTA hesaplanıyordu —
            // grid-column-menu.js/grid-add-field.js'de bulunan AYNI sorun.
            // .gs-table-tabs-scroll yatayda kaydırılabilir; menü açıkken
            // sekme şeridi kaydırılırsa özet (caret) kayarken panel ekranda
            // sabit kalıp tamamen kopuyordu. Scroll'da yeniden konumlandırılır,
            // menü kapanınca listener kaldırılır.
            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    window.removeEventListener('scroll', positionPanel, true);
                    return;
                }
                positionPanel();
                window.addEventListener('scroll', positionPanel, true);
            });
        });

        // ---- Verileri temizle (Clear data) ----
        Array.prototype.forEach.call(document.querySelectorAll('[data-table-clear]'), function (btn) {
            btn.addEventListener('click', function () {
                closeTabMenu(btn);
                var tableId = btn.getAttribute('data-table-clear');
                if (!window.confirm('Bu tablodaki TÜM kayıtlar kalıcı olarak silinecek (alanlar/kolonlar kalır). Emin misiniz?')) {
                    return;
                }
                btn.disabled = true;
                fetch('/api/table_clear_data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: CSRF, table_id: tableId }).toString(),
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                    });
                }).then(function (data) {
                    if (data && data.ok) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        window.alert((data && data.error) || 'Veriler temizlenemedi.');
                    }
                }).catch(function () {
                    btn.disabled = false;
                    window.alert('Veriler temizlenemedi (bağlantı hatası).');
                });
            });
        });

        // ---- Ad veya açıklama değiştir -------------------------------------
        // Menü öğesi ve pencere YALNIZCA owner'a basılır (grid.php $isOwner);
        // ikisi de yoksa bu blok sessizce no-op olur. Asıl yetki kapısı
        // sunucuda: api/table_rename.php require_role('owner').
        var renameModal = document.getElementById('gs-table-rename-modal');

        if (renameModal) {
            var renameForm = document.getElementById('gs-table-rename-form');
            var renameError = document.getElementById('gs-table-rename-error');
            var renameNameInput = renameForm.querySelector('input[name="name"]');
            var renameDescInput = renameForm.querySelector('input[name="description"]');
            var renameTargetId = null;

            var closeRename = function () {
                renameModal.hidden = true;
                renameError.hidden = true;
                renameTargetId = null;
            };

            Array.prototype.forEach.call(document.querySelectorAll('[data-table-rename]'), function (btn) {
                btn.addEventListener('click', function () {
                    closeTabMenu(btn);
                    renameTargetId = btn.getAttribute('data-table-rename');
                    // Alanlar MEVCUT değerlerle DOLU açılır — boş açılsaydı
                    // kullanıcı adı düzeltirken açıklamayı farkında olmadan
                    // silerdi (bu yüzden bcc_list_base_tables description'ı da
                    // seçiyor, bkz. src/schema.php).
                    renameNameInput.value = btn.getAttribute('data-table-name') || '';
                    renameDescInput.value = btn.getAttribute('data-table-desc') || '';
                    renameError.hidden = true;
                    renameModal.hidden = false;
                    renameNameInput.focus();
                    renameNameInput.select();
                });
            });

            document.getElementById('gs-table-rename-close').addEventListener('click', closeRename);
            document.getElementById('gs-table-rename-cancel').addEventListener('click', closeRename);
            renameModal.addEventListener('click', function (e) {
                if (e.target === renameModal) { closeRename(); }
            });

            renameForm.addEventListener('submit', function (e) {
                e.preventDefault(); // sayfa terk edilmesin, gönderim AJAX
                if (!renameTargetId) { return; }

                var submit = renameForm.querySelector('button[type="submit"]');
                submit.disabled = true;
                renameError.hidden = true;

                fetch('/api/table_rename.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        csrf_token: CSRF,
                        table_id: renameTargetId,
                        name: renameNameInput.value,
                        description: renameDescInput.value
                    }).toString(),
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                    });
                }).then(function (data) {
                    if (data && data.ok) {
                        // Yenileme: sekme etiketi, sayfa başlığı ve "tüm tablolar"
                        // listesi aynı adı taşıyor — üçünü elle güncellemek yerine
                        // sunucunun ürettiği doğru hâli almak daha güvenli
                        // (xlsx içe aktarmada da AYNI karar).
                        window.location.reload();
                        return;
                    }
                    submit.disabled = false;
                    renameError.textContent = (data && data.error) || 'Tablo güncellenemedi.';
                    renameError.hidden = false;
                }).catch(function () {
                    submit.disabled = false;
                    renameError.textContent = 'Tablo güncellenemedi (bağlantı hatası).';
                    renameError.hidden = false;
                });
            });
        }

        // ---- Tabloyu sil ----------------------------------------------------
        // "Verileri temizle"den AYRI bir iş: o veriyi siler ve tabloyu bırakır
        // (editor yetkisi), bu tablonun KENDİSİNİ siler (owner yetkisi).
        var deleteModal = document.getElementById('gs-table-delete-modal');

        if (deleteModal) {
            var deleteSummary = document.getElementById('gs-table-delete-summary');
            var deleteError = document.getElementById('gs-table-delete-error');
            var deleteConfirm = document.getElementById('gs-table-delete-confirm');
            var deleteTargetId = null;

            var closeDelete = function () {
                deleteModal.hidden = true;
                deleteError.hidden = true;
                deleteTargetId = null;
                deleteConfirm.disabled = false;
            };

            Array.prototype.forEach.call(document.querySelectorAll('[data-table-delete]'), function (btn) {
                btn.addEventListener('click', function () {
                    closeTabMenu(btn);
                    deleteTargetId = btn.getAttribute('data-table-delete');
                    var name = btn.getAttribute('data-table-name') || '';

                    // Tablo adı KULLANICI VERİSİDİR. innerHTML ile birleştirmek
                    // yerine DOM düğümleri kuruluyor: ad textContent ile
                    // yazıldığı için "<img onerror=...>" adlı bir tablo script
                    // çalıştıramaz. (Bu dosyada bir escapeHtml yardımcısı yok
                    // ve yalnızca bunun için bir tane eklemek gereksizdi.)
                    deleteSummary.textContent = '';

                    var strong = document.createElement('strong');
                    strong.textContent = name;
                    deleteSummary.appendChild(strong);
                    deleteSummary.appendChild(
                        document.createTextNode(' tablosu kalıcı olarak silinecek.')
                    );
                    deleteSummary.appendChild(document.createElement('br'));
                    deleteSummary.appendChild(
                        document.createTextNode('Tüm alanları, kayıtları, görünümleri ve dosya ekleri de silinir.')
                    );
                    deleteSummary.appendChild(document.createElement('br'));

                    var em = document.createElement('em');
                    em.textContent = 'Bu işlem geri alınamaz.';
                    deleteSummary.appendChild(em);

                    deleteError.hidden = true;
                    deleteModal.hidden = false;
                });
            });

            document.getElementById('gs-table-delete-close').addEventListener('click', closeDelete);
            document.getElementById('gs-table-delete-cancel').addEventListener('click', closeDelete);
            deleteModal.addEventListener('click', function (e) {
                if (e.target === deleteModal) { closeDelete(); }
            });

            deleteConfirm.addEventListener('click', function () {
                if (!deleteTargetId) { return; }

                deleteConfirm.disabled = true;
                deleteError.hidden = true;

                fetch('/api/table_delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: CSRF, table_id: deleteTargetId }).toString(),
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                    });
                }).then(function (data) {
                    if (data && data.ok) {
                        // Nereye gidileceğini SUNUCU söyler: açık olan tablo
                        // silinmiş olabilir. Base'de başka tablo varsa oraya,
                        // yoksa tablo listesine.
                        window.location.href = data.redirect_url;
                        return;
                    }
                    deleteConfirm.disabled = false;
                    deleteError.textContent = (data && data.error) || 'Tablo silinemedi.';
                    deleteError.hidden = false;
                }).catch(function () {
                    deleteConfirm.disabled = false;
                    deleteError.textContent = 'Tablo silinemedi (bağlantı hatası).';
                    deleteError.hidden = false;
                });
            });
        }

        // ---- Veri içe aktar (Import Excel) ----
        var overlay = document.getElementById('gs-table-import-overlay');
        var fileInput = document.getElementById('gs-table-import-file');
        var resultBox = document.getElementById('gs-table-import-result');
        var submitBtn = document.getElementById('gs-table-import-submit');
        var cancelBtn = document.getElementById('gs-table-import-cancel');
        var closeBtn = document.getElementById('gs-table-import-close');
        var dropzone = document.getElementById('gs-table-import-dropzone');
        var fileCard = document.getElementById('gs-table-import-file-card');
        var fileNameEl = document.getElementById('gs-table-import-file-name');
        var fileSizeEl = document.getElementById('gs-table-import-file-size');
        var fileChangeBtn = document.getElementById('gs-table-import-file-change');
        var importTableId = null;

        function formatBytes(bytes) {
            if (bytes < 1024) {
                return bytes + ' B';
            }
            if (bytes < 1024 * 1024) {
                return (bytes / 1024).toFixed(1) + ' KB';
            }
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        // Dropzone <-> seçilen dosya kartı: ikisi asla aynı anda görünmez.
        function renderSelectedFile() {
            var file = (fileInput && fileInput.files && fileInput.files[0]) || null;
            var hasFile = !!file;

            if (dropzone) {
                dropzone.hidden = hasFile;
                dropzone.classList.remove('is-dragover');
            }
            if (fileCard) {
                fileCard.hidden = !hasFile;
            }
            if (hasFile) {
                // textContent: dosya adı kullanıcı verisidir, HTML olarak
                // yorumlanmamalı.
                if (fileNameEl) { fileNameEl.textContent = file.name; }
                if (fileSizeEl) { fileSizeEl.textContent = formatBytes(file.size); }
            }
        }

        function resetImportModal() {
            if (fileInput) {
                fileInput.value = '';
            }
            if (resultBox) {
                resultBox.hidden = true;
                resultBox.textContent = '';
                resultBox.classList.remove('gs-import-result-error');
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'İçe Aktar';
            }
            renderSelectedFile();
        }

        function closeImportModal() {
            if (overlay) {
                overlay.hidden = true;
            }
            resetImportModal();
        }

        if (overlay && fileInput && submitBtn) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-table-import]'), function (btn) {
                btn.addEventListener('click', function () {
                    closeTabMenu(btn);
                    importTableId = btn.getAttribute('data-table-import');
                    resetImportModal();
                    overlay.hidden = false;
                });
            });

            if (cancelBtn) {
                cancelBtn.addEventListener('click', closeImportModal);
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', closeImportModal);
            }

            fileInput.addEventListener('change', renderSelectedFile);

            // "Dosyayı Değiştir": seçimi temizleyip dropzone'u geri getirir ve
            // dosya seçiciyi yeniden açar. fileInput.value = '' ŞART — aynı
            // dosya tekrar seçilirse 'change' aksi hâlde hiç tetiklenmezdi.
            if (fileChangeBtn) {
                fileChangeBtn.addEventListener('click', function () {
                    fileInput.value = '';
                    renderSelectedFile();
                    fileInput.click();
                });
            }

            if (dropzone) {
                // dragover'da preventDefault ŞART: yoksa tarayıcı dosyayı
                // sayfada AÇAR (varsayılan davranış) ve modal kaybolur.
                ['dragenter', 'dragover'].forEach(function (evt) {
                    dropzone.addEventListener(evt, function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropzone.classList.add('is-dragover');
                    });
                });

                ['dragleave', 'dragend'].forEach(function (evt) {
                    dropzone.addEventListener(evt, function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropzone.classList.remove('is-dragover');
                    });
                });

                dropzone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('is-dragover');

                    var dropped = e.dataTransfer && e.dataTransfer.files;
                    if (!dropped || !dropped.length) {
                        return;
                    }

                    // Uzantı istemcide de kontrol ediliyor: sunucu zaten
                    // reddediyor (api/table_import_xlsx.php, 422) ama kullanıcı
                    // yanlış dosyayı bırakır bırakmaz görsün, yükleme turunu
                    // beklemesin.
                    var file = dropped[0];
                    if (!/\.xlsx$/i.test(file.name)) {
                        resultBox.hidden = false;
                        resultBox.classList.add('gs-import-result-error');
                        resultBox.textContent = 'Yalnızca .xlsx dosyaları desteklenir.';
                        return;
                    }

                    // DataTransfer'ı doğrudan input'a bağlamak, dosyanın
                    // gönderimde FormData'ya TEK yoldan girmesini sağlıyor
                    // (ayrı bir "bırakılan dosya" değişkeni tutulmuyor).
                    fileInput.files = e.dataTransfer.files;
                    resultBox.hidden = true;
                    resultBox.textContent = '';
                    resultBox.classList.remove('gs-import-result-error');
                    renderSelectedFile();
                });

                // Klavye: label'a odaklanıp Enter/Space ile dosya seçici açılır.
                dropzone.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        fileInput.click();
                    }
                });
            }

            // Modalın DIŞINA bırakılan bir dosyayı tarayıcı sayfada açar ve
            // kullanıcı düzenlediği tabloyu kaybeder. Overlay açıkken bu
            // varsayılan iptal edilir.
            ['dragover', 'drop'].forEach(function (evt) {
                overlay.addEventListener(evt, function (e) {
                    e.preventDefault();
                });
            });

            window.bcc_bindDismissable(overlay, {
                isOpen: function () { return !overlay.hidden; },
                close: closeImportModal,
                isClickOutside: function (target) { return target === overlay; },
            });

            submitBtn.addEventListener('click', function () {
                if (!fileInput.files || !fileInput.files[0]) {
                    window.alert('Lütfen bir Excel (.xlsx) dosyası seçin.');
                    return;
                }
                if (!importTableId) {
                    return;
                }

                var formData = new FormData();
                formData.append('csrf_token', CSRF);
                formData.append('table_id', importTableId);
                formData.append('xlsx_file', fileInput.files[0]);

                submitBtn.disabled = true;
                submitBtn.textContent = 'Aktarılıyor...';

                fetch('/api/table_import_xlsx.php', {
                    method: 'POST',
                    body: formData,
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                    });
                }).then(function (data) {
                    if (data && data.ok) {
                        var msg = data.imported + ' kayıt aktarıldı.';
                        if (data.unmatched_columns && data.unmatched_columns.length) {
                            msg += ' Eşleşmeyen sütunlar (atlandı): ' + data.unmatched_columns.join(', ') + '.';
                        }
                        if (data.skipped_cells) {
                            msg += ' ' + data.skipped_cells + ' hücre geçersiz değer nedeniyle boş bırakıldı.';
                        }
                        if (data.skipped_rows) {
                            msg += ' ' + data.skipped_rows + ' satır zorunlu bir alanı boş bıraktığı için atlandı.';
                        }
                        resultBox.hidden = false;
                        resultBox.textContent = msg;
                        submitBtn.textContent = 'Tamamlandı';
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 1800);
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'İçe Aktar';
                        resultBox.hidden = false;
                        resultBox.classList.add('gs-import-result-error');
                        resultBox.textContent = (data && data.error) || 'İçe aktarma başarısız.';
                    }
                }).catch(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'İçe Aktar';
                    resultBox.hidden = false;
                    resultBox.classList.add('gs-import-result-error');
                    resultBox.textContent = 'İçe aktarma başarısız (bağlantı hatası).';
                });
            });
        }
    });
})();
