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
