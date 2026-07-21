(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var fieldTypesById = window.BCC_FIELD_TYPES_BY_ID || {};
        var opsByType = window.BCC_FILTER_OPS || {};
        var noValueOps = window.BCC_FILTER_NO_VALUE_OPS || [];

        var rows = document.querySelectorAll('.filter-row');

        rows.forEach(function (row) {
            var fieldSelect = row.querySelector('.filter-field-select');
            var condSelect = row.querySelector('.filter-cond-select');
            var valueInput = row.querySelector('.filter-value-input');

            if (!fieldSelect || !condSelect || !valueInput) {
                return;
            }

            function fieldType() {
                return fieldTypesById[fieldSelect.value];
            }

            function rebuildConditions() {
                var type = fieldType();
                condSelect.innerHTML = '';

                if (!type) {
                    var placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = '— önce alan seçin —';
                    condSelect.appendChild(placeholder);
                    condSelect.disabled = true;
                    valueInput.style.display = 'none';
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
            }

            function updateValueInput() {
                var type = fieldType();
                var op = condSelect.value;

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
                } else {
                    valueInput.type = 'text';
                }
            }

            fieldSelect.addEventListener('change', rebuildConditions);
            condSelect.addEventListener('change', updateValueInput);
        });
    });
})();
