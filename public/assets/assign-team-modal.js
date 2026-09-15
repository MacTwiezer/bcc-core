(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('assign-team-modal');
        var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-assign-team-btn]'));

        if (!modal || triggers.length === 0) {
            return;
        }

        var form = document.getElementById('assign-team-form');
        var errorEl = document.getElementById('assign-team-error');
        var submitBtn = form.querySelector('button[type="submit"]');
        var roleSelect = form.querySelector('select[name="role"]');
        var searches = Array.prototype.slice.call(form.querySelectorAll('[data-assign-filter]'));
        var lastTrigger = null;

        function normalize(text) {
            return String(text || '').toLocaleLowerCase('tr');
        }

        function applyFilter(input) {
            var list = document.getElementById(input.getAttribute('data-assign-filter'));
            var empty = input.parentNode.querySelector('.assign-team-empty');
            var q = normalize(input.value.trim());
            var visible = 0;

            Array.prototype.forEach.call(list.options, function (opt) {
                var match = q === '' || normalize(opt.textContent).indexOf(q) !== -1;
                opt.hidden = !match;
                if (!match && opt.selected) {
                    opt.selected = false;
                }
                if (match) {
                    visible++;
                }
            });

            if (empty) {
                empty.hidden = visible > 0;
            }
            list.hidden = visible === 0;
        }

        function firstVisibleOption(list) {
            for (var i = 0; i < list.options.length; i++) {
                if (!list.options[i].hidden) {
                    return list.options[i];
                }
            }
            return null;
        }

        searches.forEach(function (input) {
            input.addEventListener('input', function () {
                applyFilter(input);
            });
            input.addEventListener('keydown', function (e) {
                var list = document.getElementById(input.getAttribute('data-assign-filter'));
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var first = firstVisibleOption(list);
                    if (first) {
                        first.selected = true;
                    }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    var target = list.selectedIndex >= 0 && !list.options[list.selectedIndex].hidden
                        ? list.options[list.selectedIndex]
                        : firstVisibleOption(list);
                    if (target) {
                        target.selected = true;
                        list.focus();
                    }
                }
            });
        });

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
            form.reset();
            searches.forEach(applyFilter);
            if (roleSelect) {
                roleSelect.value = 'viewer';
            }
            modal.hidden = false;
            errorEl.hidden = true;
            submitBtn.disabled = false;
            if (searches[0]) {
                searches[0].focus();
            }
        }

        triggers.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal(btn);
            });
        });

        Array.prototype.forEach.call(modal.querySelectorAll('[data-assign-team-close]'), function (btn) {
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

            var userList = document.getElementById('assign-team-user');
            var teamList = document.getElementById('assign-team-team');
            if (!userList.value || !teamList.value) {
                showError('Kullanıcı ve ekip seçin.');
                return;
            }

            submitBtn.disabled = true;
            errorEl.hidden = true;

            fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(new FormData(form)).toString(),
            }).then(function (res) {
                return res.json().catch(function () { return { ok: false }; });
            }).then(function (data) {
                if (data && data.ok) {
                    window.location.href = '/admin/index.php';
                    return;
                }
                submitBtn.disabled = false;
                showError((data && data.error) || 'Atama kaydedilemedi.');
            }).catch(function () {
                submitBtn.disabled = false;
                showError('Atama kaydedilemedi (bağlantı hatası).');
            });
        });

        if (new URLSearchParams(window.location.search).get('ekibe_ata') === '1') {
            openModal(triggers[0]);
        }
    });
})();
