(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        function post(url, params) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params).toString(),
            }).then(function (res) {
                return res.json().catch(function () {
                    return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                }).then(function (data) {
                    return { httpOk: res.ok, data: data };
                });
            });
        }

        // ---- Ad Soyad / E-posta satırları — Airtable'daki "Edit name" deseni:
        // "Düzenle" tıklanınca görünüm satırı gizlenip inline form açılır, aynı
        // form hem Kaydet hem İptal'i yönetir. İkisi de AYNI genel davranışı
        // paylaşır (data-account-field/data-account-endpoint) — ikinci bir kopya
        // yazılmadı, yalnızca sunucu tarafı doğrulaması alan başına farklı.
        Array.prototype.forEach.call(document.querySelectorAll('.account-row[data-account-field]'), function (row) {
            var field = row.getAttribute('data-account-field');
            var display = row.querySelector('[data-account-display]');
            var valueEl = row.querySelector('[data-account-value]');
            var trigger = row.querySelector('[data-account-edit-trigger]');
            var form = row.querySelector('[data-account-edit-form]');
            var input = row.querySelector('[data-account-input]');
            var cancelBtn = row.querySelector('[data-account-edit-cancel]');
            var errorEl = row.querySelector('[data-account-error]');
            var endpoint = form.getAttribute('data-account-endpoint');

            function openForm() {
                display.hidden = true;
                form.hidden = false;
                errorEl.hidden = true;
                input.value = valueEl.textContent;
                input.focus();
                input.select();
            }

            function closeForm() {
                form.hidden = true;
                display.hidden = false;
                errorEl.hidden = true;
            }

            trigger.addEventListener('click', openForm);
            cancelBtn.addEventListener('click', closeForm);

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeForm();
                }
            });

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                errorEl.hidden = true;

                var params = { csrf_token: CSRF };
                params[field] = input.value;

                post(endpoint, params).then(function (result) {
                    submitBtn.disabled = false;
                    if (result.httpOk && result.data && result.data.ok) {
                        valueEl.textContent = result.data[field];
                        closeForm();
                    } else {
                        errorEl.textContent = (result.data && result.data.error) || 'Kaydedilemedi.';
                        errorEl.hidden = false;
                    }
                }).catch(function () {
                    submitBtn.disabled = false;
                    errorEl.textContent = 'Kaydedilemedi (bağlantı hatası).';
                    errorEl.hidden = false;
                });
            });
        });

        // ---- Şifre güncelleme — ayrı akış (3 alan, ekranda gösterilecek bir
        // "değer" yok, başarıdan sonra form sıfırlanıp kapanır).
        var pwRow = document.getElementById('account-password-trigger');
        var pwForm = document.getElementById('account-password-form');
        var pwCancel = document.getElementById('account-password-cancel');
        var pwError = document.getElementById('account-password-error');

        if (pwRow && pwForm) {
            var pwDisplay = pwRow.closest('[data-account-display]');

            function openPwForm() {
                pwDisplay.hidden = true;
                pwForm.hidden = false;
                pwError.hidden = true;
                pwForm.querySelector('input[name="current_password"]').focus();
            }

            function closePwForm() {
                pwForm.reset();
                pwForm.hidden = true;
                pwDisplay.hidden = false;
                pwError.hidden = true;
            }

            pwRow.addEventListener('click', openPwForm);
            pwCancel.addEventListener('click', closePwForm);

            pwForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var submitBtn = pwForm.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                pwError.hidden = true;

                post('/api/account_update_password.php', {
                    csrf_token: CSRF,
                    current_password: pwForm.querySelector('input[name="current_password"]').value,
                    new_password: pwForm.querySelector('input[name="new_password"]').value,
                    confirm_password: pwForm.querySelector('input[name="confirm_password"]').value,
                }).then(function (result) {
                    submitBtn.disabled = false;
                    if (result.httpOk && result.data && result.data.ok) {
                        closePwForm();
                    } else {
                        pwError.textContent = (result.data && result.data.error) || 'Şifre güncellenemedi.';
                        pwError.hidden = false;
                    }
                }).catch(function () {
                    submitBtn.disabled = false;
                    pwError.textContent = 'Şifre güncellenemedi (bağlantı hatası).';
                    pwError.hidden = false;
                });
            });
        }
    });
})();
