(function () {
    'use strict';

    // Ortak "dışarı tıklayınca / Escape ile kapan" yardımcısı — grid-table-tabs.js'teki
    // .gs-table-tab-menu grubunun (Bölüm D'de merkezileştirilen, çoklu-eleman +
    // karşılıklı-dışlama deseni) TEK-ELEMAN sürümü genelleştirilerek çıkarıldı.
    // home.js/grid-view-manage.js/grid-row-detail.js'teki 6 AYRI kopya artık bunu
    // ÇAĞIRIYOR — kapanma koşulları (davranış) AYNI kaldı, yalnızca kod tek yerde.
    // (grid-table-tabs.js'in kendisi DOKUNULMADI: o zaten karşılıklı-dışlamalı bir
    // GRUP yönetiyor, bu yardımcı tek elemanlık dismiss'i soyutluyor — grup mantığı
    // gerektiren yerler, ör. home.js'teki .home-base-more-menu, bu yardımcıyı HER
    // eleman için ayrı ayrı çağırıp kendi karşılıklı-dışlama mantığını üstte tutar.)
    //
    // el: varsayılan olarak native <details> (open özniteliği) varsayar. Başka bir
    // açık/kapalı göstergesi olan elemanlar (ör. .hidden ile açılıp kapanan modal
    // overlay'ler) isOpen/close override ederek de kullanabilir.
    //
    // options.isOpen()               - (opsiyonel) varsayılan: el.hasAttribute('open')
    // options.close()                - (opsiyonel) varsayılan: el.removeAttribute('open')
    // options.isClickOutside(target) - (opsiyonel) varsayılan: !el.contains(target);
    //     tam ekran backdrop'lu overlay'ler (yalnızca backdrop'a tıklayınca kapanan,
    //     modal içeriğine tıklayınca kapanmayan) target === el şeklinde override eder —
    //     bkz. grid-view-manage.js / grid-row-detail.js.
    // options.onClose()              - (opsiyonel) kapanınca ek temizlik
    window.bcc_bindDismissable = function (el, options) {
        options = options || {};
        var isOpen = options.isOpen || function () { return el.hasAttribute('open'); };
        var close = options.close || function () { el.removeAttribute('open'); };
        var isClickOutside = options.isClickOutside || function (target) { return !el.contains(target); };
        var onClose = options.onClose || function () {};

        document.addEventListener('click', function (e) {
            if (isOpen() && isClickOutside(e.target)) {
                close();
                onClose();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) {
                close();
                onClose();
            }
        });
    };
})();
