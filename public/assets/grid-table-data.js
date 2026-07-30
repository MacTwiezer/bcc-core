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

            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    return;
                }
                var rect = summary.getBoundingClientRect();
                panel.style.top = (rect.bottom + 4) + 'px';
                panel.style.left = rect.left + 'px';
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

        // ---- Veri içe aktar (Import CSV) ----
        var overlay = document.getElementById('gs-table-import-overlay');
        var fileInput = document.getElementById('gs-table-import-file');
        var resultBox = document.getElementById('gs-table-import-result');
        var submitBtn = document.getElementById('gs-table-import-submit');
        var cancelBtn = document.getElementById('gs-table-import-cancel');
        var importTableId = null;

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

            window.bcc_bindDismissable(overlay, {
                isOpen: function () { return !overlay.hidden; },
                close: closeImportModal,
                isClickOutside: function (target) { return target === overlay; },
            });

            submitBtn.addEventListener('click', function () {
                if (!fileInput.files || !fileInput.files[0]) {
                    window.alert('Lütfen bir CSV dosyası seçin.');
                    return;
                }
                if (!importTableId) {
                    return;
                }

                var formData = new FormData();
                formData.append('csrf_token', CSRF);
                formData.append('table_id', importTableId);
                formData.append('csv_file', fileInput.files[0]);

                submitBtn.disabled = true;
                submitBtn.textContent = 'Aktarılıyor...';

                fetch('/api/table_import_csv.php', {
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
