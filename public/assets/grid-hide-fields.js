(function () {
    'use strict';

    // "Alanları gizle" paneli (grid.php .hide-fields-form).
    //
    // İLERLEMELİ ZENGİNLEŞTİRME: toggle'lar name="visible_fields[]" ile normal
    // bir GET form elemanı. JS hiç çalışmasa da "Uygula" butonuyla submit edilip
    // doğru çalışır — JS yalnızca (a) "Uygula"ya basma zorunluluğunu kaldırır,
    // (b) sayacı anında tazeler, (c) listeyi yerinde filtreler.
    //
    // SUNUCU TEK KAYNAK: hangi sütunun gizli olduğu URL'de (hidden_fields) ve
    // grid'in sütun yerleşimi (colgroup genişlikleri, dondurulmuş sütunların
    // sticky ofsetleri, boyutlandırma şeritleri) sunucudan gelen o duruma göre
    // kuruluyor. Bu yüzden toggle DOM'da sütunu kendi başına saklamıyor —
    // saklasaydı sütun düzeni için İKİNCİ bir hesaplama kaynağı doğar ve ilk
    // uyuşmazlıkta donmuş sütunlar/şeritler kayardı. Bunun yerine değişiklik
    // sunucuya gönderiliyor; panelin kendi göstergeleri (sayaç, buton durumu)
    // ANINDA güncelleniyor ki kullanıcı yanıt beklerken ölü bir arayüz görmesin.

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('hide-fields-form');
        if (!form) {
            return;
        }

        var toggles = Array.prototype.slice.call(form.querySelectorAll('.hide-field-toggle-input'));
        var rows = Array.prototype.slice.call(form.querySelectorAll('.hide-field-row'));
        var counter = form.querySelector('[data-hide-fields-counter]');
        var emptyNote = form.querySelector('[data-hide-fields-empty]');
        var searchInput = form.querySelector('[data-hide-fields-search]');
        var applyBtn = form.querySelector('[data-hide-fields-apply]');

        // ---- Sayaç ----------------------------------------------------------
        // Sunucu doğru değerle basıyor; burada yalnızca toggle değiştikçe
        // yeniden yazılıyor. Toplam sunucudan (data-total) okunuyor: birincil
        // alan listede olmadığı için rows.length ile aynı, ama sayının kaynağı
        // tek kalsın diye öznitelikten alınıyor.
        var total = counter ? (parseInt(counter.getAttribute('data-total'), 10) || toggles.length) : toggles.length;

        function hiddenCount() {
            var n = 0;
            toggles.forEach(function (t) {
                if (!t.checked) {
                    n++;
                }
            });
            return n;
        }

        function refreshCounter() {
            if (!counter) {
                return;
            }
            counter.textContent = hiddenCount() + ' / ' + total + ' alan gizli';
        }

        // ---- Toggle ---------------------------------------------------------
        // Gönderim KISA BİR GECİKMEYLE: kullanıcı üst üste birkaç alanı
        // kapatırken her tıklama ayrı bir sayfa yüklemesi başlatmasın. Son
        // tıklamadan 350ms sonra tek istek gider. Bu sırada sayaç ve satır
        // durumu zaten güncellendiği için panel "canlı" hissettiriyor.
        var submitTimer = null;
        function scheduleSubmit() {
            if (submitTimer) {
                window.clearTimeout(submitTimer);
            }
            form.classList.add('is-pending');
            submitTimer = window.setTimeout(function () {
                submitTimer = null;
                form.submit();
            }, 350);
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                refreshCounter();
                scheduleSubmit();
            });
        });

        // JS varken "Uygula" gereksiz (değişiklik kendiliğinden gidiyor).
        if (applyBtn) {
            applyBtn.hidden = true;
        }

        // ---- Arama ----------------------------------------------------------
        // Eşleşme anahtarı satırın ALAN ADINDAN okunuyor (.hide-field-name),
        // row.textContent'ten DEĞİL: textContent toggle'ın ve ikonun etrafındaki
        // boşlukları da taşıyor ve ileride satıra bir rozet eklenirse arama
        // sessizce onun metnini de eşleştirmeye başlardı.
        var keys = rows.map(function (row) {
            var nameEl = row.querySelector('.hide-field-name');
            return (nameEl ? nameEl.textContent : '').trim().toLowerCase();
        });

        function applyFilter() {
            var q = searchInput.value.trim().toLowerCase();
            var visible = 0;

            rows.forEach(function (row, i) {
                var match = q === '' || keys[i].indexOf(q) !== -1;
                // [hidden]/style.display DEĞİL ayrı sınıf: satır flex ve flex
                // öğesine uygulanan display, [hidden]'ın display:none'ını ezer
                // (bu projede birkaç kez yaşanan tuzak).
                row.classList.toggle('is-filtered-out', !match);
                if (match) {
                    visible++;
                }
            });

            if (emptyNote) {
                emptyNote.hidden = visible !== 0;
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilter);

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && searchInput.value !== '') {
                    // stopPropagation: <details> panelini kapatan ortak Escape
                    // dinleyicisi bu tuşu ayrıca yorumlamasın — ilk Escape
                    // aramayı temizler, ikincisi paneli kapatır.
                    e.stopPropagation();
                    searchInput.value = '';
                    applyFilter();
                }
            });
        }

        refreshCounter();
    });
})();
