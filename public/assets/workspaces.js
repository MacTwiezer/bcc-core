(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.getElementById('wsx-collab-grid');
        var head = document.getElementById('wsx-collab-head');
        if (!grid || !head) {
            return;
        }

        var members = Array.prototype.slice.call(grid.querySelectorAll('.wsx-member'));
        if (members.length < 8) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'wsx-search';
        wrap.innerHTML = '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
            + '<circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.5"/>'
            + '<path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

        var input = document.createElement('input');
        input.type = 'search';
        input.autocomplete = 'off';
        input.placeholder = 'Katılımcı ara…';
        input.setAttribute('aria-label', 'Katılımcı ara');
        wrap.appendChild(input);
        head.appendChild(wrap);

        var empty = document.createElement('p');
        empty.className = 'wsx-collab-empty';
        empty.textContent = 'Eşleşen katılımcı yok.';
        empty.hidden = true;
        grid.parentNode.insertBefore(empty, grid.nextSibling);

        var haystacks = members.map(function (row) {
            var name = row.querySelector('.wsx-member-name');
            var mail = row.querySelector('.wsx-member-mail');
            return ((name ? name.textContent : '') + ' ' + (mail ? mail.textContent : '')).toLocaleLowerCase('tr');
        });

        input.addEventListener('input', function () {
            var q = input.value.trim().toLocaleLowerCase('tr');
            var visible = 0;

            members.forEach(function (row, i) {
                var match = q === '' || haystacks[i].indexOf(q) !== -1;
                row.classList.toggle('wsx-hidden', !match);
                if (match) {
                    visible++;
                }
            });

            empty.hidden = visible !== 0;
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                e.stopPropagation();
                input.value = '';
                input.dispatchEvent(new Event('input'));
            }
        });
    });
})();

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var input = document.querySelector('[data-wsx-team-search]');
        var list = document.querySelector('[data-wsx-team-list]');
        if (!input || !list) {
            return;
        }

        var rows = Array.prototype.slice.call(list.querySelectorAll('.wsx-card'));
        var empty = list.querySelector('[data-wsx-team-empty]');

        var keys = rows.map(function (row) {
            return row.getAttribute('data-wsx-team-name') || '';
        });

        function apply() {
            var q = input.value.trim().toLowerCase();
            var visible = 0;

            rows.forEach(function (row, i) {
                var match = q === '' || keys[i].indexOf(q) !== -1;
                row.classList.toggle('wsx-team-hidden', !match);
                if (match) {
                    visible++;
                }
            });

            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        input.addEventListener('input', apply);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                e.stopPropagation();
                input.value = '';
                apply();
            }
        });
    });
})();

(function () {
    'use strict';

    document.addEventListener('bcc:share-modal-changed', function () {
        window.location.reload();
    });
})();
/* Calisma alanini silme (2026-09-22). Kullanici karari: icerigi ne olursa
   olsun silinebilsin, yalnizca onay kutusu ciksin. Dugme sunucuda zaten
   yalnizca owner/platform yoneticisi icin basiliyor; uc nokta ayrica
   require_role(owner) uyguluyor. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.querySelector('[data-team-delete]');
        if (!btn) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        btn.addEventListener('click', function () {
            var teamId = btn.getAttribute('data-team-delete');
            var ad = btn.getAttribute('data-team-name') || 'Bu çalışma alanı';
            var baseSayisi = parseInt(btn.getAttribute('data-base-count') || '0', 10);
            var uyeSayisi = parseInt(btn.getAttribute('data-member-count') || '0', 10);

            var mesaj = '"' + ad + '" çalışma alanı silinecek.';
            if (baseSayisi > 0) {
                mesaj += ' İçindeki ' + baseSayisi + ' base ve onlara bağlı bütün tablolar, '
                    + 'kayıtlar ve ekler de silinecek.';
            }
            if (uyeSayisi > 0) {
                mesaj += ' ' + uyeSayisi + ' katılımcının bu alandaki üyeliği kalkacak '
                    + '(kullanıcı hesapları silinmez).';
            }
            mesaj += ' Bu işlem geri alınamaz.';

            var sor = typeof window.bcc_confirm === 'function'
                ? window.bcc_confirm({
                    title: 'Çalışma alanını sil',
                    message: mesaj,
                    confirmLabel: 'Evet, sil',
                })
                : Promise.resolve(window.confirm(mesaj));

            sor.then(function (onaylandi) {
                if (!onaylandi) {
                    return;
                }

                btn.disabled = true;

                fetch('/api/team_delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: CSRF, team_id: teamId }).toString(),
                }).then(function (res) {
                    return res.json().catch(function () { return { ok: false }; });
                }).then(function (data) {
                    if (data && data.ok) {
                        window.location.href = (data.redirect_url || '/workspaces.php');
                    } else {
                        btn.disabled = false;
                        window.alert((data && data.error) || 'Çalışma alanı silinemedi.');
                    }
                }).catch(function () {
                    btn.disabled = false;
                    window.alert('Çalışma alanı silinemedi (bağlantı hatası).');
                });
            });
        });
    });
})();
