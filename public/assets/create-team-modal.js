(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('create-team-modal');
        var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-create-team-btn]'));

        if (!modal || triggers.length === 0) {
            return;
        }

        var form = document.getElementById('create-team-form');
        var errorEl = document.getElementById('create-team-error');
        var nameInput = form.querySelector('input[name="name"]');
        var submitBtn = form.querySelector('button[type="submit"]');
        var lastTrigger = null;

        function showError(message) {
            errorEl.textContent = message;
            errorEl.hidden = false;
        }

        function closeModal() {
            modal.hidden = true;
            errorEl.hidden = true;
            submitBtn.disabled = false;
            if (lastTrigger) {
                lastTrigger.focus();
            }
        }

        function openModal(trigger) {
            lastTrigger = trigger || null;
            modal.hidden = false;
            errorEl.hidden = true;
            submitBtn.disabled = false;
            nameInput.value = '';
            nameInput.focus();
        }

        triggers.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal(btn);
            });
        });

        Array.prototype.forEach.call(modal.querySelectorAll('[data-create-team-close]'), function (btn) {
            btn.addEventListener('click', closeModal);
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (submitBtn.disabled) {
                return;
            }
            submitBtn.disabled = true;
            errorEl.hidden = true;

            var payload = new URLSearchParams(new FormData(form)).toString();

            fetch('/api/team_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload,
            }).then(function (res) {
                return res.json().catch(function () { return { ok: false }; });
            }).then(function (data) {
                if (data && data.ok && data.redirect_url) {
                    window.location.href = data.redirect_url;
                    return;
                }
                submitBtn.disabled = false;
                showError((data && data.error) || 'Çalışma alanı oluşturulamadı.');
            }).catch(function () {
                submitBtn.disabled = false;
                showError('Çalışma alanı oluşturulamadı (bağlantı hatası).');
            });
        });
    });
})();
