(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-sort-form]');
        if (!form) {
            return;
        }

        var fieldTypesById = window.BCC_FIELD_TYPES_BY_ID || {};
        var dirLabels = window.BCC_DIR_LABELS || {};
        var dirFallback = { asc: 'artan', desc: 'azalan' };
        var maxSlots = parseInt(window.BCC_SORT_MAX_SLOTS, 10) || 3;

        var rowsWrap = form.querySelector('[data-sort-rows]');
        var addBtn = form.querySelector('[data-sort-add]');
        var slotNote = form.querySelector('[data-sort-slot-note]');

        function rows() {
            return Array.prototype.slice.call(rowsWrap.querySelectorAll('[data-sort-row]'));
        }

        function labelsFor(type) {
            return (type && dirLabels[type]) ? dirLabels[type] : dirFallback;
        }

        function bindRow(row) {
            var fieldSelect = row.querySelector('.sort-field-select');
            var dirSelect = row.querySelector('[data-sort-dir]');
            var badge = row.querySelector('[data-sort-field-badge]');

            if (!fieldSelect || !dirSelect) {
                return;
            }

            function refresh() {
                var type = fieldTypesById[fieldSelect.value];

                if (badge) {
                    badge.className = 'field-badge sort-field-badge ' + (type ? 'field-badge--' + type : 'is-empty');
                }

                var labels = labelsFor(type);
                var opts = dirSelect.options;
                if (opts.length >= 2) {
                    opts[0].textContent = labels.asc;
                    opts[1].textContent = labels.desc;
                }
            }

            fieldSelect.addEventListener('change', refresh);
            refresh();
        }

        function renumber() {
            rows().forEach(function (row, i) {
                var slot = i + 1;
                row.setAttribute('data-slot', slot);
                var f = row.querySelector('.sort-field-select');
                var d = row.querySelector('[data-sort-dir]');
                var badge = row.querySelector('.sort-level-badge');
                if (f) { f.name = 'sort_field_' + slot; }
                if (d) { d.name = 'sort_dir_' + slot; }
                if (badge) { badge.textContent = slot; }
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
            refreshAddState();
        }

        function addRow() {
            if (rows().length >= maxSlots) {
                return;
            }

            var clone = rows()[0].cloneNode(true);

            Array.prototype.forEach.call(clone.querySelectorAll('[data-bound]'), function (el) {
                el.removeAttribute('data-bound');
            });

            var f = clone.querySelector('.sort-field-select');
            var d = clone.querySelector('[data-sort-dir]');
            f.value = '';
            d.value = 'asc';

            var badge = clone.querySelector('[data-sort-field-badge]');
            if (badge) {
                badge.className = 'field-badge sort-field-badge is-empty';
            }

            rowsWrap.appendChild(clone);
            bindRow(clone);
            bindRemove(clone);
            refreshAll();

            f.focus();
        }

        function removeRow(row) {
            if (rows().length === 1) {
                var f = row.querySelector('.sort-field-select');
                var d = row.querySelector('[data-sort-dir]');
                f.value = '';
                d.value = 'asc';
                f.dispatchEvent(new Event('change'));
                refreshAll();
                return;
            }

            row.parentNode.removeChild(row);
            refreshAll();
        }

        function bindRemove(row) {
            var btn = row.querySelector('[data-sort-remove]');
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
