(function () {
    'use strict';

    // workspaces.php'ye ÖZEL: katılımcı ızgarasına arama kutusu ekler.
    //
    // table-fields.js'teki AYNI desen (kutuyu çalışma anında oluştur, eşleşmeyeni
    // ayrı bir sınıfla gizle) — orada alan tipleri, burada katılımcılar. İki
    // liste farklı DOM'da ve farklı sayfalarda yaşadığı için ortak bir "liste
    // filtresi" soyutlaması YAZILMADI; iki küçük dosya, tek bir erken
    // soyutlamadan daha okunur.

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.getElementById('wsx-collab-grid');
        var head = document.getElementById('wsx-collab-head');
        if (!grid || !head) {
            return;
        }

        var members = Array.prototype.slice.call(grid.querySelectorAll('.wsx-member'));
        // Kısa listede arama kutusu gürültüdür.
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

        // Ad + e-posta bir kez okunup küçük harfe çevriliyor (her tuş vuruşunda
        // DOM'dan tekrar okunmuyor). Türkçe için toLocaleLowerCase('tr').
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
                // [hidden] DEĞİL ayrı bir sınıf: .wsx-collab-grid display:grid,
                // ve grid öğelerine uygulanan display [hidden]'ın display:none'ını
                // ezer (projede birkaç kez yaşanan tuzak).
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
