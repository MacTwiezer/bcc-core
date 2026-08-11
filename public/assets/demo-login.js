// login.php'deki "Hızlı Demo Girişi" butonları — YALNIZCA $BCC_DEMO_LOGIN
// açıkken sayfaya eklenir (bkz. config/app.php + public/login.php).
//
// Tek işi iki alanı doldurmak: oturum AÇMAZ, formu göndermez. Giriş yine
// kullanıcının "Giriş yap"a basmasıyla, normal POST + CSRF + attempt_login()
// yolundan geçer — kimlik doğrulamayı atlayan bir kısayol YOK.
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

                // Seçili rolü işaretle — hangi hesabın dolu olduğu, alanlara
                // bakmadan da görünsün.
                buttons.forEach(function (other) {
                    other.classList.toggle('is-selected', other === btn);
                });

                if (note) {
                    note.textContent = btn.getAttribute('data-demo-email') + ' dolduruldu — “Giriş yap”a basın.';
                }

                // Odak "Giriş yap"a değil şifre alanına verilir: kullanıcı
                // Enter'a basarsa form yine gönderilir, ama araya bir şey
                // yazmak isterse (ör. yanlış şifre denemesi) alan hazırdır.
                passwordInput.focus();
            });
        });

        // Kullanıcı alanları elle değiştirdiyse seçim işareti yanıltıcı olur.
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
