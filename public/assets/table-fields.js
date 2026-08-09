(function () {
    'use strict';

    // table_fields.php'ye ÖZEL: alan tipi seçicisine arama kutusu ekler.
    //
    // Neden burada, partial'da DEĞİL: arama kutusunun içine gireceği DOM
    // (#new-field-type-step + .field-type-grid) src/partials/
    // field_type_wizard_fields.php'den geliyor ve o partial grid.php'nin "+"
    // POPUP'IYLA PAYLAŞILIYOR. Popup dar (~260px) ve zaten kısa bir liste
    // gösteriyor; oraya bir arama kutusu koymak yer kazandırmaz, daraltır.
    // Bu dosya yalnızca table_fields.php'de yükleniyor, yani paylaşılan
    // partial'a ve grid.php'ye HİÇ dokunulmuyor.
    //
    // Kutu ÇALIŞMA ANINDA #new-field-type-step'in İÇİNE ekleniyor (grid'in
    // hemen üstüne). Bilerek dışına değil: field-type-wizard.js tip seçilince
    // `typeStep.hidden = true` yapıyor — kutu dışarıda kalsaydı ikinci adımda
    // ekranda asılı kalırdı.

    document.addEventListener('DOMContentLoaded', function () {
        var typeStep = document.getElementById('new-field-type-step');
        var grid = typeStep ? typeStep.querySelector('.field-type-grid') : null;
        if (!typeStep || !grid) {
            return;
        }

        var options = Array.prototype.slice.call(grid.querySelectorAll('.field-type-option'));
        // Kısa listede arama kutusu gürültüdür — yalnızca gerçekten uzunsa eklenir.
        if (options.length < 8) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'tf-type-search';
        wrap.innerHTML = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
            + '<circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.5"/>'
            + '<path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

        var input = document.createElement('input');
        input.type = 'search';
        input.autocomplete = 'off';
        input.placeholder = 'Alan tipi ara…';
        input.setAttribute('aria-label', 'Alan tipi ara');
        wrap.appendChild(input);

        var empty = document.createElement('p');
        empty.className = 'tf-type-empty';
        empty.textContent = 'Eşleşen alan tipi yok.';
        empty.hidden = true;

        typeStep.insertBefore(wrap, grid);
        typeStep.appendChild(empty);

        // Etiketler bir kez okunup küçük harfe çevriliyor; her tuş vuruşunda
        // DOM'dan tekrar okunmuyor.
        var haystacks = options.map(function (btn) {
            var label = btn.getAttribute('data-field-type-label') || btn.textContent;
            return label.toLocaleLowerCase('tr');
        });

        input.addEventListener('input', function () {
            var q = input.value.trim().toLocaleLowerCase('tr');
            var visible = 0;

            options.forEach(function (btn, i) {
                var match = q === '' || haystacks[i].indexOf(q) !== -1;
                // [hidden] DEĞİL ayrı bir sınıf: .field-type-grid display:grid,
                // ve grid öğelerine uygulanan display kuralı [hidden]'ın
                // display:none'ını ezer (projede daha önce yaşanan tuzak).
                btn.classList.toggle('tf-hidden', !match);
                if (match) {
                    visible++;
                }
            });

            empty.hidden = visible !== 0;
        });

        // Escape aramayı temizler — listeye dönmenin en hızlı yolu.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                e.stopPropagation();
                input.value = '';
                input.dispatchEvent(new Event('input'));
            }
        });
    });
})();
