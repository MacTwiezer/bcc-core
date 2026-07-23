(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = meta ? meta.content : '';

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

    function flash(td, ok) {
        td.classList.remove('cell-flash-ok', 'cell-flash-error');
        void td.offsetWidth; // reflow, animasyonu yeniden başlatmak için
        td.classList.add(ok ? 'cell-flash-ok' : 'cell-flash-error');
        setTimeout(function () {
            td.classList.remove('cell-flash-ok', 'cell-flash-error');
        }, 700);
    }

    function saveCell(td, value) {
        var tr = td.closest('tr');
        var recordId = tr ? tr.getAttribute('data-record-id') : '';
        var fieldId = td.getAttribute('data-field-id');

        return post('/api/cell_update.php', {
            csrf_token: CSRF,
            record_id: recordId,
            field_id: fieldId,
            value: value,
        }).then(function (result) {
            var okResult = result.httpOk && result.data && result.data.ok;

            if (okResult) {
                td.setAttribute('data-value', result.data.raw);
                var view = td.querySelector('.cell-view');
                if (view) {
                    view.textContent = result.data.display;
                }
                flash(td, true);
            } else {
                flash(td, false);
                var message = (result.data && result.data.error) ? result.data.error : 'Kaydedilemedi.';
                window.alert(message);
            }

            return okResult;
        });
    }

    // Kayıt ekleme: (a) yuvarlak + butonu, (b) tablo tabanı + satırı ve (c)
    // Shift+Enter kısayolu ÜÇÜ DE bu TEK fonksiyonu çağırır (aşağıda wire edilir) —
    // ikinci bir "kayıt ekle" mekanizması yok.
    var addingRecord = false; // istek kilidi: hızlı tekrar tıklama/kısayol çoklu kayıt üretmesin

    function renumberRows() {
        var rows = document.querySelectorAll('table.grid tbody tr[data-record-id]');
        rows.forEach(function (tr, idx) {
            var cell = tr.querySelector('.grid-rownum');
            if (cell) {
                cell.textContent = idx + 1;
            }
        });

        var countEl = document.getElementById('grid-row-count');
        if (countEl) {
            countEl.textContent = rows.length + ' kayıt';
        }
    }

    // Toast: ikinci bir bildirim sistemi kurmak yerine projedeki mevcut .ok/.error
    // metin deseni (src/partials/flash.php) yeniden kullanılır — burada tek fark,
    // async bir fetch sonrası sayfa yenilenmediği için elemanın JS ile eklenip
    // birkaç saniye sonra kendiliğinden kaldırılmasıdır.
    function showToast(message) {
        var footer = document.querySelector('.gs-grid-footer');
        if (!footer) {
            return;
        }

        var existing = footer.querySelector('.grid-add-toast');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        var toast = document.createElement('p');
        toast.className = 'ok grid-add-toast';
        toast.textContent = message;
        footer.appendChild(toast);

        setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 4000);
    }

    function addRecord(afterRecordId, targetRow) {
        if (addingRecord) {
            return;
        }
        addingRecord = true;

        var tableId = new URLSearchParams(window.location.search).get('table_id') || '';
        var params = {
            csrf_token: CSRF,
            table_id: tableId,
            state_query_string: window.location.search.replace(/^\?/, ''),
        };

        // Sort/group aktifken after_record_id kasıtlı olarak GÖNDERİLMEZ — sunucu
        // sona ekler ((a)/(b) ile aynı davranış), çünkü görünen sıra zaten
        // position'dan değil sort/group kolonlarından geliyor.
        if (!window.BCC_SORT_OR_GROUP_ACTIVE && afterRecordId) {
            params.after_record_id = afterRecordId;
        }

        post('/api/record_add.php', params).then(function (result) {
            addingRecord = false;

            if (!(result.httpOk && result.data && result.data.ok)) {
                var message = (result.data && result.data.error) ? result.data.error : 'Kayıt eklenemedi.';
                window.alert(message);
                return; // DOM'a satır eklenmez.
            }

            var temp = document.createElement('tbody');
            temp.innerHTML = result.data.row_html;
            var newRow = temp.querySelector('tr[data-record-id]');
            if (!newRow) {
                return;
            }

            if (targetRow && targetRow.parentNode) {
                targetRow.insertAdjacentElement('afterend', newRow);
            } else {
                var addRowEl = document.querySelector('[data-grid-add-row]');
                if (addRowEl && addRowEl.parentNode) {
                    addRowEl.insertAdjacentElement('beforebegin', newRow);
                } else {
                    var tbody = document.querySelector('table.grid tbody');
                    if (tbody) {
                        tbody.appendChild(newRow);
                    }
                }
            }

            renumberRows();

            // Sütun dondurma: yeni satır da mevcut dondurma durumunu almalı —
            // ikinci bir pozisyonlama mekanizması yazmak yerine grid-freeze-columns.js'in
            // kendi apply fonksiyonu çağrılır (o script her zaman yüklenir).
            if (window.BCC_reapplyFreeze) {
                window.BCC_reapplyFreeze();
            }

            var firstCell = newRow.querySelector('td.editable');
            if (firstCell) {
                startEdit(firstCell);
            }

            if (window.BCC_SORT_OR_GROUP_ACTIVE || window.BCC_FILTER_ACTIVE) {
                showToast('Kayıt eklendi. Aktif filtre/sıralama/gruplama nedeniyle konumu sayfa yenilenince değişebilir.');
            }
        }).catch(function () {
            addingRecord = false;
            window.alert('Kayıt eklenemedi (bağlantı hatası).');
        });
    }

    function getChoices(td) {
        var raw = td.getAttribute('data-options');
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

    function addOption(select, value, label) {
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = label;
        select.appendChild(opt);
        return opt;
    }

    function buildInput(type, td, raw) {
        var input;

        if (type === 'long_text') {
            input = document.createElement('textarea');
            input.rows = 3;
            input.value = raw;
        } else if (type === 'number') {
            input = document.createElement('input');
            input.type = 'number';
            input.step = 'any';
            input.value = raw;
        } else if (type === 'date') {
            input = document.createElement('input');
            input.type = 'date';
            input.value = raw;
        } else if (type === 'single_select') {
            input = document.createElement('select');
            addOption(input, '', '— boş —');
            getChoices(td).forEach(function (c) {
                addOption(input, c, c);
            });
            input.value = raw;
        } else if (type === 'multiple_select') {
            input = document.createElement('select');
            input.multiple = true;
            var choices = getChoices(td);
            input.size = Math.min(6, Math.max(3, choices.length));
            var selected = [];
            try {
                selected = JSON.parse(raw || '[]');
            } catch (e) {
                selected = [];
            }
            choices.forEach(function (c) {
                var opt = addOption(input, c, c);
                if (selected.indexOf(c) !== -1) {
                    opt.selected = true;
                }
            });
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.value = raw;
        }

        input.className = 'cell-input';

        return input;
    }

    function startEdit(td) {
        if (td.classList.contains('editing')) {
            return;
        }

        var type = td.getAttribute('data-field-type');
        if (type === 'checkbox') {
            return; // checkbox doğrudan tıklanır, edit moduna girmez
        }

        var view = td.querySelector('.cell-view');
        var raw = td.getAttribute('data-value') || '';
        var input = buildInput(type, td, raw);
        var done = false;

        td.classList.add('editing');
        if (view) {
            view.style.display = 'none';
        }
        td.appendChild(input);
        input.focus();
        if (input.select) {
            input.select();
        }

        function endEdit() {
            td.classList.remove('editing');
            if (input.parentNode === td) {
                td.removeChild(input);
            }
            if (view) {
                view.style.display = '';
            }
        }

        function commit() {
            if (done) {
                return;
            }
            done = true;

            var value;
            if (type === 'multiple_select') {
                var selectedOptions = [];
                for (var i = 0; i < input.options.length; i++) {
                    if (input.options[i].selected) {
                        selectedOptions.push(input.options[i].value);
                    }
                }
                value = JSON.stringify(selectedOptions);
            } else {
                value = input.value;
            }

            endEdit();
            saveCell(td, value);
        }

        function cancel() {
            if (done) {
                return;
            }
            done = true;
            endEdit();
        }

        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && type !== 'long_text') {
                e.preventDefault();
                commit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancel();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        if (!grid) {
            return;
        }

        grid.addEventListener('click', function (e) {
            if (e.target.matches('input[type="checkbox"].cell-checkbox')) {
                return; // change olayı hallediyor
            }
            var td = e.target.closest('td.editable');
            if (!td) {
                return;
            }
            startEdit(td);
        });

        grid.addEventListener('change', function (e) {
            if (!e.target.matches('input[type="checkbox"].cell-checkbox')) {
                return;
            }
            var checkbox = e.target;
            var td = checkbox.closest('td');
            var checked = checkbox.checked;

            saveCell(td, checked ? '1' : '0').then(function (ok) {
                if (!ok) {
                    checkbox.checked = !checked;
                }
            });
        });

        // (a) yuvarlak + butonu: JS'siz de çalışan normal form POST'unu yakalar,
        // yerine tek addRecord() fonksiyonunu çağırır (sayfa yenilenmez).
        var addForm = document.querySelector('[data-grid-add-record]');
        if (addForm) {
            addForm.addEventListener('submit', function (e) {
                e.preventDefault();
                addRecord(null, null);
            });
        }

        // (b) tablo tabanı + satırı: aynı addRecord() fonksiyonu.
        var addRow = document.querySelector('[data-grid-add-row]');
        if (addRow) {
            addRow.addEventListener('click', function () {
                addRecord(null, null);
            });
        }

        // (c) Shift+Enter: herhangi bir hücredeyken (input/select/td, textarea HARİÇ —
        // orada satır atlamalı) aktif kaydın hemen altına ekler.
        document.addEventListener('keydown', function (e) {
            if (!e.shiftKey || e.key !== 'Enter') {
                return;
            }

            var targetTag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
            if (targetTag === 'textarea') {
                return;
            }

            var row = e.target.closest ? e.target.closest('tr[data-record-id]') : null;
            if (!row) {
                return;
            }

            e.preventDefault();
            addRecord(row.getAttribute('data-record-id'), row);
        });
    });
})();
