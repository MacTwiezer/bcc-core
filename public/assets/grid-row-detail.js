(function () {
    'use strict';

    // Satır seçimi (checkbox) + satır genişletme paneli. cell_update.php'yi
    // KENDİSİ çağırmaz — window.BCC_GRID (grid.js) üzerinden postCellValue/
    // applyCellResultToTd/buildInput/getChoices yeniden kullanılır, bu dosya
    // yalnızca grid.js'DEN SONRA yüklenir (bkz. grid.php script sırası).
    //
    // Görünür alanlar: gerçek <td data-field-id> DOM'da var — widget ondan
    // (data-value/data-options) kurulur, kaydedince applyCellResultToTd o
    // td'yi de günceller (gridle panel her zaman senkron kalır).
    // Gizli alanlar: <td> yok — satırın data-fields JSON'undaki
    // {id, name, field_type, options, raw} kullanılır, kaydetme doğrudan
    // postCellValue(recordId, fieldId, value) ile (senkronlanacak <td> yok).

    function getRowFields(tr) {
        var raw = tr.getAttribute('data-fields');
        if (!raw) {
            return [];
        }
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function findLiveTd(tr, fieldId) {
        return tr.querySelector('td.grid-cell[data-field-id="' + fieldId + '"]');
    }

    // long_text'in ham değeri (raw) sunucuda zaten temizlenmiş (whitelist)
    // HTML — burada yalnızca DÜZ METNİ okumak için DOM'a hiç eklenmeyen bir
    // <div>'e yazılıp .textContent okunuyor (script YÜRÜTÜLMEZ, hiçbir zaman
    // sayfaya eklenmedi); panelde tam zengin metin araç çubuğu YOK, "input/
    // textarea" isteğine uygun düz bir <textarea>.
    function htmlToPlainText(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('table.grid');
        var overlay = document.getElementById('grid-detail-overlay');
        if (!grid || !overlay || !window.BCC_GRID) {
            return;
        }

        var fieldsContainer = document.getElementById('grid-detail-fields');
        var titleEl = document.getElementById('grid-detail-title');
        var closeBtn = document.getElementById('grid-detail-close');
        var prevBtn = document.getElementById('grid-detail-prev');
        var nextBtn = document.getElementById('grid-detail-next');
        var selectAll = document.getElementById('grid-rownum-selectall');

        var currentDetailRow = null;

        function getAllDataRows() {
            return Array.prototype.slice.call(grid.querySelectorAll('tbody tr[data-record-id]'));
        }

        function commitFieldValue(tr, field, liveTd, value) {
            var recordId = tr.getAttribute('data-record-id');

            window.BCC_GRID.postCellValue(recordId, field.id, value).then(function (result) {
                var ok = result.httpOk && result.data && result.data.ok;

                if (!ok) {
                    var message = (result.data && result.data.error) ? result.data.error : 'Kaydedilemedi.';
                    window.alert(message);
                    return;
                }

                if (liveTd) {
                    window.BCC_GRID.applyCellResultToTd(liveTd, result.data);
                }

                // Birincil alan (panel başlığı) düzenlendiyse başlık da güncellensin.
                var fields = getRowFields(tr);
                if (tr === currentDetailRow && fields.length && fields[0].id === field.id) {
                    updateDetailTitle(tr);
                }
            });
        }

        function buildFieldWidget(tr, field) {
            var liveTd = findLiveTd(tr, field.id);
            var wrap = document.createElement('div');
            wrap.className = 'grid-detail-field-value';

            if (field.field_type === 'checkbox') {
                var rawChecked = liveTd ? liveTd.getAttribute('data-value') : field.raw;
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = rawChecked === '1';
                cb.addEventListener('change', function () {
                    commitFieldValue(tr, field, liveTd, cb.checked ? '1' : '0');
                });
                wrap.appendChild(cb);
                return wrap;
            }

            if (field.field_type === 'long_text') {
                var rawHtml = liveTd ? liveTd.getAttribute('data-value') : field.raw;
                var ta = document.createElement('textarea');
                ta.className = 'cell-input grid-detail-textarea';
                var initialPlainText = htmlToPlainText(rawHtml || '');
                ta.value = initialPlainText;
                // Bulunan gerçek bug: panelde zengin metin (kalın/link) araç çubuğu
                // YOK, bu yüzden düzenleme alanı yalnızca DÜZ METİN gösteriyor —
                // dokunulmadan panel kapatılsa bile blur, bu düz metni sunucuya
                // geri gönderip mevcut biçimlendirmeyi sessizce siliyordu. Artık
                // yalnızca kullanıcı gerçekten metni değiştirdiyse kaydediliyor.
                ta.addEventListener('blur', function () {
                    if (ta.value === initialPlainText) {
                        return;
                    }
                    commitFieldValue(tr, field, liveTd, ta.value);
                });
                wrap.appendChild(ta);
                return wrap;
            }

            // Dosya eki: cell_update.php'ye hiç gitmez (attachment_upload/delete.php
            // üzerinden kendi AJAX'ı) — liste/yükle/sil arayüzü grid.js'nin
            // window.BCC_GRID.buildAttachmentManager() ile PAYLAŞILIR, ikinci bir
            // kopya yazılmaz. Görünür alan ise canlı <td>'nin data-attachments'ı da
            // (grid'deki hücreyle senkron kalsın diye) güncellenir.
            if (field.field_type === 'attachment') {
                var recordId = tr.getAttribute('data-record-id');
                var initialFiles = liveTd
                    ? (function () {
                        try {
                            return JSON.parse(liveTd.getAttribute('data-attachments') || '[]');
                        } catch (e) {
                            return [];
                        }
                    })()
                    : (field.files || []);

                var manager = window.BCC_GRID.buildAttachmentManager(recordId, field.id, initialFiles, function (files) {
                    if (liveTd) {
                        liveTd.setAttribute('data-attachments', JSON.stringify(files));
                        var liveView = liveTd.querySelector('.cell-view');
                        if (liveView) {
                            window.BCC_GRID.renderAttachmentChips(liveView, files);
                        }
                    }
                });
                wrap.appendChild(manager);
                return wrap;
            }

            var choices = liveTd ? window.BCC_GRID.getChoices(liveTd) : (field.options || []);
            var raw = liveTd ? liveTd.getAttribute('data-value') : field.raw;
            var input = window.BCC_GRID.buildInput(field.field_type, choices, raw || '');

            var commit = function () {
                var value;
                if (field.field_type === 'multiple_select') {
                    var selected = [];
                    for (var i = 0; i < input.options.length; i++) {
                        if (input.options[i].selected) {
                            selected.push(input.options[i].value);
                        }
                    }
                    value = JSON.stringify(selected);
                } else {
                    value = input.value;
                }
                commitFieldValue(tr, field, liveTd, value);
            };

            input.addEventListener('blur', commit);
            if (input.tagName === 'SELECT' && !input.multiple) {
                input.addEventListener('change', commit);
            }

            wrap.appendChild(input);
            return wrap;
        }

        function primaryFieldTitle(tr) {
            var fields = getRowFields(tr);
            if (!fields.length) {
                return '';
            }
            var primary = fields[0];
            var liveTd = findLiveTd(tr, primary.id);
            if (liveTd) {
                var view = liveTd.querySelector('.cell-view');
                if (view) {
                    return view.textContent;
                }
            }
            return primary.raw || '(başlıksız kayıt)';
        }

        function updateDetailTitle(tr) {
            titleEl.textContent = primaryFieldTitle(tr);
        }

        function renderDetailFields(tr) {
            fieldsContainer.textContent = '';
            getRowFields(tr).forEach(function (field) {
                var row = document.createElement('div');
                row.className = 'grid-detail-field';

                var label = document.createElement('label');
                label.className = 'grid-detail-field-label';
                label.textContent = field.name;
                row.appendChild(label);

                row.appendChild(buildFieldWidget(tr, field));
                fieldsContainer.appendChild(row);
            });
        }

        function updateNavState() {
            var rows = getAllDataRows();
            var idx = rows.indexOf(currentDetailRow);
            if (prevBtn) {
                prevBtn.disabled = idx <= 0;
            }
            if (nextBtn) {
                nextBtn.disabled = idx === -1 || idx >= rows.length - 1;
            }
        }

        function openDetail(tr) {
            currentDetailRow = tr;
            updateDetailTitle(tr);
            renderDetailFields(tr);
            updateNavState();
            overlay.hidden = false;
        }

        function closeDetail() {
            overlay.hidden = true;
            currentDetailRow = null;
            fieldsContainer.textContent = '';
        }

        function navigate(delta) {
            if (!currentDetailRow) {
                return;
            }
            var rows = getAllDataRows();
            var idx = rows.indexOf(currentDetailRow);
            var next = rows[idx + delta];
            if (next) {
                openDetail(next);
            }
        }

        function updateSelectAllState() {
            if (!selectAll) {
                return;
            }
            var rows = getAllDataRows();
            var checkedCount = rows.filter(function (tr) {
                var cb = tr.querySelector('.grid-row-select');
                return cb && cb.checked;
            }).length;
            selectAll.checked = rows.length > 0 && checkedCount === rows.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < rows.length;
        }

        grid.addEventListener('click', function (e) {
            var expandBtn = e.target.closest('.grid-row-expand');
            if (!expandBtn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var tr = expandBtn.closest('tr[data-record-id]');
            if (tr) {
                openDetail(tr);
            }
        });

        grid.addEventListener('change', function (e) {
            if (!e.target.matches('.grid-row-select')) {
                return;
            }
            var tr = e.target.closest('tr[data-record-id]');
            if (tr) {
                tr.classList.toggle('is-row-selected', e.target.checked);
            }
            updateSelectAllState();
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checked = selectAll.checked;
                getAllDataRows().forEach(function (tr) {
                    var cb = tr.querySelector('.grid-row-select');
                    if (cb) {
                        cb.checked = checked;
                    }
                    tr.classList.toggle('is-row-selected', checked);
                });
                selectAll.indeterminate = false;
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', closeDetail);
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function () { navigate(-1); });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () { navigate(1); });
        }

        // Backdrop'a (modal içeriğine değil) tıklayınca / Escape ile kapanma —
        // assets/dismissable-panel.js, isOpen/close .hidden'a göre override edilir.
        window.bcc_bindDismissable(overlay, {
            isOpen: function () { return !overlay.hidden; },
            close: closeDetail,
            isClickOutside: function (target) { return target === overlay; },
        });
    });
})();
