(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.input-toggle-btn'), function (toggle) {
            var wrapper = toggle.closest('.input-with-toggle');
            var input = wrapper ? wrapper.querySelector('input') : null;
            if (!input) {
                return;
            }
            toggle.addEventListener('click', function () {
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                toggle.setAttribute('aria-label', showing ? 'Şifreyi göster' : 'Şifreyi gizle');
            });
        });
    });
})();
