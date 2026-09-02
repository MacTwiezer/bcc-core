(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-filter-form]');
        if (!form) {
            return;
        }

        var fieldTypesById = window.BCC_FIELD_TYPES_BY_ID || {};
        var opsByType = window.BCC_FILTER_OPS || {};
        var noValueOps = window.BCC_FILTER_NO_VALUE_OPS || [];
        var teamMembers = window.BCC_TEAM_MEMBERS || [];
        var userValueTypes = window.BCC_USER_VALUE_FIELD_TYPES || ['user'];

        function isUserValueType(type) {
            return userValueTypes.indexOf(type) !== -1;
        }
        var maxSlots = parseInt(window.BCC_FILTER_MAX_SLOTS, 10) || 5;

        var rowsWrap = form.querySelector('[data-filter-rows]');
        var addBtn = form.querySelector('[data-filter-add]');
        var slotNote = form.querySelector('[data-filter-slot-note]');

        function rows() {
            return Array.prototype.slice.call(rowsWrap.querySelectorAll('[data-filter-row]'));
        }

        function bindRow(row) {
            var fieldSelect = row.querySelector('.filter-field-select');
            var condSelect = row.querySelector('.filter-cond-select');
            var valueInput = row.querySelector('.filter-value-input');
            var badge = row.querySelector('[data-filter-field-badge]');

            if (!fieldSelect || !condSelect || !valueInput) {
                return;
            }

            function fieldType() {
                return fieldTypesById[fieldSelect.value];
            }

            function refreshBadge() {
                if (!badge) {
                    return;
                }
                var type = fieldType();
                badge.className = 'field-badge filter-field-badge ' + (type ? 'field-badge--' + type : 'is-empty');
            }

            function ensureValueInputKind(type) {
                var wantSelect = isUserValueType(type);
                var isSelect = valueInput.tagName === 'SELECT';

                if (wantSelect === isSelect) {
                    return;
                }

                var name = valueInput.name;
                var replacement;

                if (wantSelect) {
                    replacement = document.createElement('select');
                    var blank = document.createElement('option');
                    blank.value = '';
                    blank.textContent = '— seç —';
                    replacement.appendChild(blank);
                    teamMembers.forEach(function (m) {
                        var opt = document.createElement('option');
                        opt.value = m.id;
                        opt.textContent = m.name;
                        replacement.appendChild(opt);
                    });
                } else {
                    replacement = document.createElement('input');
                    replacement.type = 'text';
                    replacement.placeholder = 'değer';
                }

                replacement.name = name;
                replacement.className = 'filter-value-input' + (wantSelect ? ' filter-value-user-select' : '');
                replacement.setAttribute('aria-label', 'Değer');

                valueInput.parentNode.replaceChild(replacement, valueInput);
                valueInput = replacement;
            }

            function updateValueInput() {
                var type = fieldType();
                var op = condSelect.value;

                ensureValueInputKind(type);

                if (!type || noValueOps.indexOf(op) !== -1) {
                    valueInput.style.display = 'none';
                    valueInput.value = '';
                    return;
                }

                valueInput.style.display = '';

                if (type === 'number') {
                    valueInput.type = 'number';
                } else if (type === 'date') {
                    valueInput.type = 'date';
                } else if (type === 'time') {
                    valueInput.type = 'time';
                } else if (!isUserValueType(type)) {
                    valueInput.type = 'text';
                }
            }

            function rebuildConditions() {
                var type = fieldType();
                condSelect.innerHTML = '';
                refreshBadge();

                if (!type) {
                    var placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = '— önce alan seçin —';
                    condSelect.appendChild(placeholder);
                    condSelect.disabled = true;
                    valueInput.style.display = 'none';
                    valueInput.value = '';
                    return;
                }

                condSelect.disabled = false;
                var ops = opsByType[type] || {};

                Object.keys(ops).forEach(function (key) {
                    var opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = ops[key];
                    condSelect.appendChild(opt);
                });

                updateValueInput();
                valueInput.value = '';
            }

            fieldSelect.addEventListener('change', rebuildConditions);
            condSelect.addEventListener('change', updateValueInput);
            refreshBadge();
        }

        function logicValue() {
            var sel = form.querySelector('[data-filter-logic]');
            if (sel) {
                return sel.value;
            }
            var hidden = form.querySelector('[data-filter-logic-hidden]');
            return hidden ? hidden.value : 'and';
        }

        function syncMirrors() {
            var text = logicValue() === 'or' ? 'VEYA' : 'VE';
            Array.prototype.forEach.call(form.querySelectorAll('[data-filter-conj-mirror]'), function (m) {
                m.textContent = text;
            });
        }

        function bindLogicSelect() {
            var sel = form.querySelector('[data-filter-logic]');
            if (sel && !sel.hasAttribute('data-bound')) {
                sel.setAttribute('data-bound', '1');
                sel.addEventListener('change', syncMirrors);
            }
        }

        function renderConjunctions() {
            var list = rows();
            var current = logicValue();
            var hidden = form.querySelector('[data-filter-logic-hidden]');

            list.forEach(function (row, i) {
                var cell = row.querySelector('[data-filter-conj]');
                if (!cell) {
                    return;
                }

                if (i === 0) {
                    cell.innerHTML = '<span class="filter-conj-label">Koşul</span>';
                    return;
                }

                if (i === 1) {
                    if (!cell.querySelector('[data-filter-logic]')) {
                        cell.innerHTML =
                            '<select name="filter_logic" class="filter-conj-select" data-filter-logic aria-label="Kurallar arası bağlaç">' +
                            '<option value="and">VE</option><option value="or">VEYA</option></select>';
                        cell.querySelector('select').value = current;
                    }
                    if (hidden) {
                        hidden.parentNode.removeChild(hidden);
                    }
                    return;
                }

                if (!cell.querySelector('[data-filter-conj-mirror]')) {
                    cell.innerHTML = '<span class="filter-conj-mirror" data-filter-conj-mirror title="Bağlaç tüm kurallara birlikte uygulanır"></span>';
                }
            });

            if (list.length < 2 && !form.querySelector('[data-filter-logic]') && !form.querySelector('[data-filter-logic-hidden]')) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'filter_logic';
                h.value = current;
                h.setAttribute('data-filter-logic-hidden', '');
                form.appendChild(h);
            }

            bindLogicSelect();
            syncMirrors();
        }

        function renumber() {
            rows().forEach(function (row, i) {
                var slot = i + 1;
                row.setAttribute('data-slot', slot);
                var f = row.querySelector('.filter-field-select');
                var c = row.querySelector('.filter-cond-select');
                var v = row.querySelector('.filter-value-input');
                if (f) { f.name = 'filter_field_' + slot; }
                if (c) { c.name = 'filter_cond_' + slot; }
                if (v) { v.name = 'filter_value_' + slot; }
            });
        }

        function refreshAddState() {
            var atMax = rows().length >= maxSlots;
            if (addBtn) {
                addBtn.disabled = atMax;
            }
            if (slotNote) {
                slotNote.hidden = !atMax;
            }
        }

        function refreshAll() {
            renumber();
            renderConjunctions();
            refreshAddState();
        }

        function addRow() {
            if (rows().length >= maxSlots) {
                return;
            }

            var template = rows()[0];
            var clone = template.cloneNode(true);

            Array.prototype.forEach.call(clone.querySelectorAll('[data-bound]'), function (el) {
                el.removeAttribute('data-bound');
            });

            var f = clone.querySelector('.filter-field-select');
            var c = clone.querySelector('.filter-cond-select');
            var v = clone.querySelector('.filter-value-input');
            f.value = '';
            c.innerHTML = '<option value="">— önce alan seçin —</option>';
            c.disabled = true;
            if (v.tagName === 'SELECT') {
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'filter-value-input';
                input.placeholder = 'değer';
                input.setAttribute('aria-label', 'Değer');
                v.parentNode.replaceChild(input, v);
                v = input;
            }
            v.value = '';
            v.style.display = 'none';

            var badge = clone.querySelector('[data-filter-field-badge]');
            if (badge) {
                badge.className = 'field-badge filter-field-badge is-empty';
            }

            rowsWrap.appendChild(clone);
            bindRow(clone);
            bindRemove(clone);
            refreshAll();

            clone.querySelector('.filter-field-select').focus();
        }

        function removeRow(row) {
            var list = rows();

            if (list.length === 1) {
                var f = row.querySelector('.filter-field-select');
                f.value = '';
                f.dispatchEvent(new Event('change'));
                refreshAll();
                return;
            }

            row.parentNode.removeChild(row);
            refreshAll();
        }

        function bindRemove(row) {
            var btn = row.querySelector('[data-filter-remove]');
            if (btn && !btn.hasAttribute('data-bound')) {
                btn.setAttribute('data-bound', '1');
                btn.addEventListener('click', function () {
                    removeRow(row);
                });
            }
        }

        rows().forEach(function (row) {
            bindRow(row);
            bindRemove(row);
        });

        if (addBtn) {
            addBtn.addEventListener('click', addRow);
        }

        refreshAll();
    });
})();
