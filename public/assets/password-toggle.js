(function () {
    'use strict';

    // Genel şifre göster/gizle — bir sayfada birden fazla ".input-with-toggle"
    // olabilir (ör. account.php'nin mevcut/yeni/tekrar şifre alanları), her biri
    // kendi tetikleyicisine bağlanır; ikinci bir kopya YOK (create_user.php'de
    // daha önce sayfaya özel, tek input'a bağlı bir kopyası vardı).
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
