(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var emailInput = document.getElementById('login-email');
        var passwordInput = document.getElementById('login-password');
        var note = document.getElementById('login-demo-note');
        var buttons = document.querySelectorAll('[data-demo-email]');

        if (!emailInput || !passwordInput || !buttons.length) {
            return;
        }

        var defaultNote = note ? note.textContent : '';

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                emailInput.value = btn.getAttribute('data-demo-email');
                passwordInput.value = btn.getAttribute('data-demo-password');

                buttons.forEach(function (other) {
                    other.classList.toggle('is-selected', other === btn);
                });

                if (note) {
                    note.textContent = btn.getAttribute('data-demo-email') + ' dolduruldu — “Giriş yap”a basın.';
                }

                passwordInput.focus();
            });
        });

        [emailInput, passwordInput].forEach(function (input) {
            input.addEventListener('input', function () {
                buttons.forEach(function (btn) {
                    btn.classList.remove('is-selected');
                });
                if (note) {
                    note.textContent = defaultNote;
                }
            });
        });
    });
})();
