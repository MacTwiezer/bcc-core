(function () {
    'use strict';

    // form_edit.php'ye ÖZEL, YALNIZCA GÖRSEL iki küçük davranış.
    //
    // ⚠️ KOPYALAMA MANTIĞI BURADA YOK. Panoya yazma işini paylaşılan
    // assets/share-popover.js yapıyor (grid.php ve interface.php de onu
    // kullanıyor) ve o dosyaya DOKUNULMADI. Aynı butona ikinci bir dinleyici
    // bağlanıyor: ikisi de tetiklenir, bu dosya yalnızca "kopyalandı" yeşil
    // parlamasını ekler. Kopyalama kodunu buraya taşımak/çoğaltmak, iki ayrı
    // panoya-yazma yolu demek olurdu.

    document.addEventListener('DOMContentLoaded', function () {
        // ---- 1) Kopyalandı geri bildirimi -------------------------------
        var copyBtn = document.querySelector('.fe-copy-btn[data-share-copy-btn]');
        if (copyBtn) {
            var copiedTimer = null;
            copyBtn.addEventListener('click', function () {
                copyBtn.classList.add('is-copied');
                if (copiedTimer) {
                    window.clearTimeout(copiedTimer);
                }
                // 1500ms: share-popover.js'in etiketi geri alma süresiyle AYNI —
                // yeşil parlama ile "Kopyalandı" yazısı birlikte sönsün.
                copiedTimer = window.setTimeout(function () {
                    copyBtn.classList.remove('is-copied');
                    copiedTimer = null;
                }, 1500);
            });
        }

        // ---- 2) Seçili alan sayacı ---------------------------------------
        var counter = document.getElementById('fe-field-count');
        var boxes = Array.prototype.slice.call(
            document.querySelectorAll('.fe-field-card input[name="form_fields[]"]')
        );

        if (!counter || boxes.length === 0) {
            return;
        }

        function render() {
            var selected = boxes.filter(function (b) { return b.checked; }).length;
            counter.textContent = selected === 0
                ? 'Hiç alan seçilmedi — form boş görünecek.'
                : boxes.length + ' alandan ' + selected + ' tanesi formda görünecek.';
        }

        boxes.forEach(function (b) {
            b.addEventListener('change', render);
        });

        render();
    });
})();
