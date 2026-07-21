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
    });
})();
