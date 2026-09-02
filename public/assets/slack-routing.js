(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var fieldSelect = document.getElementById('routing-rule-field');
        var valueSelect = document.getElementById('routing-rule-value');

        if (!fieldSelect || !valueSelect) {
            return;
        }

        function rebuildValueOptions() {
            var selectedOption = fieldSelect.options[fieldSelect.selectedIndex];
            var choices = [];

            if (selectedOption) {
                try {
                    choices = JSON.parse(selectedOption.getAttribute('data-choices') || '[]');
                } catch (e) {
                    choices = [];
                }
            }

            valueSelect.textContent = '';
            choices.forEach(function (choice) {
                var opt = document.createElement('option');
                opt.value = choice;
                opt.textContent = choice;
                valueSelect.appendChild(opt);
            });
        }

        fieldSelect.addEventListener('change', rebuildValueOptions);
        rebuildValueOptions();
    });
})();
