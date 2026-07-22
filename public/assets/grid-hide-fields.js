(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('hide-fields-form');

        if (!form) {
            return;
        }

        // Toggle'lar name="visible_fields[]" ile normal bir GET form elemanı; JS
        // olmadan da "Uygula" butonuyla submit edilip doğru şekilde çalışır. JS
        // varken yalnızca UX'i hızlandırıyoruz: her değişiklikte anında submit
        // ediyoruz ve artık gereksiz olan "Uygula" butonunu gizliyoruz.
        var toggles = form.querySelectorAll('.hide-field-toggle-input');
        toggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                form.submit();
            });
        });

        var applyBtn = form.querySelector('[data-hide-fields-apply]');
        if (applyBtn) {
            applyBtn.style.display = 'none';
        }

        // "Find a field": yalnızca istemci tarafında panel içi listeyi filtreler,
        // grid'in kendisini veya sunucu tarafı gizleme durumunu etkilemez.
        var searchInput = form.querySelector('[data-hide-fields-search]');
        var rows = form.querySelectorAll('.hide-field-row');

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim().toLowerCase();

                rows.forEach(function (row) {
                    var match = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                });
            });
        }
    });
})();
