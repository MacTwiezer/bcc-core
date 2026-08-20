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

(function () {
    'use strict';

    // table_fields.php'ye ÖZEL: alan ekleme sihirbazının 2. ADIMINI (isim +
    // seçenekler) sayfa içi bir PENCEREYE çevirir.
    //
    // Neden burada, paylaşılan yerlerde DEĞİL:
    //   • src/partials/field_type_wizard_fields.php grid.php'nin "+" POPUP'ıyla
    //     paylaşılıyor — orada 2. adım ZATEN yüzen bir panelin içinde, ikinci
    //     bir pencereye sarmak anlamsız olurdu.
    //   • assets/field-type-wizard.js de paylaşılıyor; adım geçişini o yapıyor
    //     (detailsStep.hidden = false). Buradaki kod o davranışı DEĞİŞTİRMEZ,
    //     yalnızca IZLER (MutationObserver) ve perdeyi ona göre açıp kapar.
    //
    // Yani gönderim, doğrulama ve "Tip değiştir" mantığı olduğu gibi kalıyor;
    // bu dosya sadece görünümü ve kapatma yollarını ekliyor.
    document.addEventListener('DOMContentLoaded', function () {
        var detailsStep = document.getElementById('new-field-details-step');
        var changeBtn = document.getElementById('new-field-type-change');
        var chosenLabel = document.getElementById('new-field-type-chosen-label');

        if (!detailsStep || !changeBtn || !chosenLabel) {
            return;
        }

        // Perde — <body>'nin sonuna, formun DIŞINA. Formun içinde olsaydı
        // tıklama hedefi form alanlarıyla karışırdı.
        var backdrop = document.createElement('div');
        backdrop.className = 'tf-modal-backdrop';
        backdrop.hidden = true;
        document.body.appendChild(backdrop);

        // --- Başlık satırı ---------------------------------------------------
        // Paylaşılan partial burada "Seçilen tip: X · Tip değiştir" yazan bir
        // <p class="hint"> basıyor. Pencerede bu bir BAŞLIK olmalı: seçilen tip
        // pencerenin adı, "Tip değiştir" ise sağdaki ikincil eylem.
        // Düğüm TAŞINIYOR (kopyalanmıyor): id'leri ve paylaşılan JS'in ona bağlı
        // dinleyicileri korunur.
        var hint = detailsStep.querySelector('.hint');
        if (hint) {
            var head = document.createElement('div');
            head.className = 'tf-modal-head';

            var title = document.createElement('h3');
            title.className = 'tf-modal-title';
            title.appendChild(chosenLabel);

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'tf-modal-close';
            closeBtn.setAttribute('aria-label', 'Kapat');
            closeBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" '
                + 'stroke="currentColor" stroke-width="2" stroke-linecap="round">'
                + '<path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

            head.appendChild(title);
            head.appendChild(changeBtn);
            head.appendChild(closeBtn);
            hint.parentNode.replaceChild(head, hint);

            // Kapatma = "Tip değiştir": ikinci bir kapatma yolu YAZILMIYOR,
            // paylaşılan JS'in kendi geri-dönüş mantığı tetikleniyor.
            closeBtn.addEventListener('click', function () {
                changeBtn.click();
            });
        }

        // --- Perde/gövde senkronu --------------------------------------------
        // Pencereyi AÇAN kod paylaşılan dosyada (field-type-wizard.js) ve ona
        // dokunulmuyor — bu yüzden 'hidden' özniteliği İZLENİYOR. Böylece hangi
        // yoldan açılırsa açılsın perde doğru durumda kalır.
        function sync() {
            var open = !detailsStep.hidden;
            backdrop.hidden = !open;
            // Arka planın kaymasını durdur: pencere açıkken sayfa kaydırılırsa
            // kullanıcı içeriği kaybeder.
            document.body.classList.toggle('tf-modal-open', open);
        }

        new MutationObserver(sync).observe(detailsStep, {
            attributes: true,
            attributeFilter: ['hidden']
        });
        sync();

        backdrop.addEventListener('click', function () {
            changeBtn.click();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !detailsStep.hidden) {
                changeBtn.click();
            }
        });
    });
})();
