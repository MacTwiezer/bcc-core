(function () {
    'use strict';

    // form_edit.php'ye ÖZEL, YALNIZCA GÖRSEL bir davranış: seçili alan sayacı.
    //
    // "Kopyalandı" yeşil parlaması BURADAN KALDIRILDI — form bağlantısı kartı
    // (link kutusu + Kopyala butonu) sayfadan çıkarıldı, parlatacak buton yok.
    // Panoya yazma mantığı zaten burada değildi (paylaşılan share-popover.js);
    // o dosyaya dokunulmadı, başka sayfalar kullanmaya devam ediyor.

    document.addEventListener('DOMContentLoaded', function () {
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
