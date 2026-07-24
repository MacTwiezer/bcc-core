(function () {
    'use strict';

    // Tip-önce-isim-sonra alan ekleme akışı (Airtable tarzı) — table_fields.php
    // (tam sayfa form) VE grid.php'nin "+" popup'ı TARAFINDAN PAYLAŞILIR, ikinci
    // bir kopya YOK. Her iki sayfada da aynı element ID'leri (#new-field-*)
    // kullanılır; sayfa başına yalnızca TEK bir sihirbaz örneği olduğu için
    // document.getElementById() ile sabit ID'ler yeterli.
    document.addEventListener('DOMContentLoaded', function () {
        var typeStep = document.getElementById('new-field-type-step');
        var detailsStep = document.getElementById('new-field-details-step');
        var typeInput = document.getElementById('new-field-type-input');
        var chosenLabel = document.getElementById('new-field-type-chosen-label');
        var optionsRow = document.getElementById('new-field-options-row');
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
                    optionsRow.hidden = (window.BCC_SELECT_FIELD_TYPES || []).indexOf(type) === -1;
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
    });
})();
