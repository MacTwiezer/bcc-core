(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var typeStep = document.getElementById('new-field-type-step');
        var detailsStep = document.getElementById('new-field-details-step');
        var typeInput = document.getElementById('new-field-type-input');
        var chosenLabel = document.getElementById('new-field-type-chosen-label');
        var optionsRow = document.getElementById('new-field-options-row');
        var currencyRow = document.getElementById('new-field-currency-row');
        var percentRow = document.getElementById('new-field-percent-row');
        var ratingRow = document.getElementById('new-field-rating-row');
        var requiredRow = document.getElementById('new-field-required-row');
        var nameInput = document.getElementById('new-field-name-input');
        var changeBtn = document.getElementById('new-field-type-change');

        if (!typeStep || !detailsStep || !typeInput || !chosenLabel || !nameInput || !changeBtn) {
            return;
        }

        Array.prototype.forEach.call(document.querySelectorAll('.field-type-option'), function (btn) {
            btn.addEventListener('click', function () {
                var type = btn.getAttribute('data-field-type');

                typeInput.value = type;
                chosenLabel.textContent = btn.getAttribute('data-field-type-label');
                if (optionsRow) {
                    var isSelectType = (window.BCC_SELECT_FIELD_TYPES || []).indexOf(type) !== -1;
                    optionsRow.hidden = !isSelectType;

                    var optionsInput = optionsRow.querySelector('textarea');
                    if (optionsInput) {
                        optionsInput.required = isSelectType;
                        optionsInput.setCustomValidity('');
                    }
                }
                if (currencyRow) {
                    currencyRow.hidden = (type !== 'currency');
                }
                if (percentRow) {
                    percentRow.hidden = (type !== 'percent');
                }
                if (ratingRow) {
                    ratingRow.hidden = (type !== 'rating');
                }
                if (requiredRow) {
                    requiredRow.hidden = (type === 'autonumber');
                    if (requiredRow.hidden) {
                        var reqInput = requiredRow.querySelector('input[name="is_required"]');
                        if (reqInput) {
                            reqInput.checked = false;
                        }
                    }
                }

                typeStep.hidden = true;
                detailsStep.hidden = false;
                nameInput.focus();
            });
        });

        changeBtn.addEventListener('click', function () {
            detailsStep.hidden = true;
            typeStep.hidden = false;
        });

        var optionsField = optionsRow ? optionsRow.querySelector('textarea') : null;
        if (optionsField) {
            optionsField.addEventListener('invalid', function () {
                if (optionsField.value.trim() === '') {
                    optionsField.setCustomValidity('Bu tip için en az bir seçenek girilmeli (her satıra bir tane).');
                }
            });

            optionsField.addEventListener('input', function () {
                optionsField.setCustomValidity('');
            });

            optionsField.addEventListener('blur', function () {
                if (optionsField.value.trim() === '') {
                    optionsField.value = '';
                }
            });
        }
    });
})();
