(function () {
    'use strict';

    // Tip-önce-isim-sonra alan ekleme akışı (OpsFlow) — table_fields.php
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
        // Currency/Percent/Rating (Grup C1) — optionsRow ile AYNI desen: sihirbaz
        // yalnızca GÖRÜNÜRLÜĞÜ ayarlar, hangi anahtarın okunacağına sunucu tarafı
        // (bcc_build_field_options, field_type'a göre) karar verir. Bu yüzden
        // gizli satırların input'ları forma dahil olsa bile zararsız.
        var currencyRow = document.getElementById('new-field-currency-row');
        var percentRow = document.getElementById('new-field-percent-row');
        var ratingRow = document.getElementById('new-field-rating-row');
        // Autonumber (Grup C2): "Zorunlu alan" onay kutusu bu tipte gizlenir —
        // değer her kayıtta sunucu tarafından otomatik dolar, kullanıcının
        // doldurup doldurmaması diye bir durum YOK. Bu satır yalnızca
        // table_fields.php'de var (grid.php'nin "+" popup'ında $fieldWizardShowRequired
        // false), bu yüzden null olabilir — her erişim korumalı.
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
                    optionsRow.hidden = (window.BCC_SELECT_FIELD_TYPES || []).indexOf(type) === -1;
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
                    // Gizlerken işareti de temizle: kullanıcı önce "number" seçip
                    // kutuyu işaretler, sonra tip değiştirip autonumber'a geçerse
                    // gizli-ama-işaretli bir checkbox forma DAHİL olurdu.
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
    });
})();
