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

    // Ortak YÜZEN panel konumlandırması. Bu blok daha önce grid-add-field.js ve
    // grid-column-menu.js'de BİREBİR kopyalanmıştı (ikisinin de yorumu "AYNI
    // teknik" diyordu); "+ Yeni oluştur..." menüsü üçüncü kopyayı gerektirince
    // buraya çıkarıldı.
    //
    // Neden position:fixed: bu panellerin atası .grid-wrap { overflow: auto }
    // taşıyor — absolute konumlandırma panelin o kaydırma kutusuna kırpılmasına
    // yol açardı. fixed viewport'a göre konumlanır, kırpılmaz; bedeli, konumun
    // JS'te hesaplanması ve scroll/resize'da tazelenmesi.
    //
    // menu   : <details> (open özniteliği izlenir)
    // panel  : konumlanacak kutu
    // anchor : konumun hesaplanacağı eleman (genellikle <summary>)
    // options.align : 'left' (varsayılan) | 'right' — panelin hangi kenarının
    //                 anchor'a hizalanacağı
    // options.gap   : anchor ile panel arası dikey boşluk (varsayılan 4px)
    window.bcc_bindFloatingPanel = function (menu, panel, anchor, options) {
        options = options || {};
        var align = options.align === 'right' ? 'right' : 'left';
        var gap = typeof options.gap === 'number' ? options.gap : 4;
        var margin = 8; // viewport kenarlarına bırakılan pay

        function position() {
            var rect = anchor.getBoundingClientRect();
            panel.style.top = (rect.bottom + gap) + 'px';

            if (align === 'right') {
                // Sağ kenarı anchor'ın sağına hizala. Dar ekranda panel sola
                // taşarsa sol kenardan margin kadar içeri çekilir.
                var rightOffset = window.innerWidth - rect.right;
                var pw = panel.offsetWidth || 0;
                if (rightOffset + pw > window.innerWidth - margin) {
                    rightOffset = window.innerWidth - margin - pw;
                }
                if (rightOffset < margin) {
                    rightOffset = margin;
                }
                panel.style.left = 'auto';
                panel.style.right = rightOffset + 'px';
                return;
            }

            // Sol hizalama + taşma koruması: grid-column-menu.js'de bulunmuş
            // gerçek bug (en sağdaki sütunlarda panel viewport'un sağından
            // taşıyordu) burada TÜM yüzen paneller için geçerli.
            var left = rect.left;
            var panelWidth = panel.offsetWidth || 0;
            if (left + panelWidth > window.innerWidth - margin) {
                left = window.innerWidth - margin - panelWidth;
            }
            if (left < margin) {
                left = margin;
            }
            panel.style.right = 'auto';
            panel.style.left = left + 'px';
        }

        // Bulunan gerçek bug (iki kopyada da vardı): konum yalnızca AÇILIŞTA
        // hesaplanıyordu — panel açıkken sayfa kaydırılırsa anchor kayarken panel
        // ekranda sabit kalıp butondan kopuyordu. scroll capture ile dinlenir
        // (herhangi bir ATA kaydırma kutusu da yakalansın diye).
        //
        // ⚠️ resize dinleyicisi İKİ ESKİ KOPYADA DA YOKTU — pencere yeniden
        // boyutlandırılınca panel yine kopuyordu. Ortak yardımcıya taşınırken
        // eklendi, yani bu düzeltme her iki eski çağrı yerine de bedava geldi.
        function attach() {
            window.addEventListener('scroll', position, true);
            window.addEventListener('resize', position);
        }

        function detach() {
            window.removeEventListener('scroll', position, true);
            window.removeEventListener('resize', position);
        }

        menu.addEventListener('toggle', function () {
            if (!menu.open) {
                detach();
                return;
            }
            position();
            attach();
        });
    };
})();
