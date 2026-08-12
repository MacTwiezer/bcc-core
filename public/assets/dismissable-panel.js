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
    // Yüzen bir kutuyu bir ANCHOR DİKDÖRTGENİNE göre konumlandırır ve viewport
    // içinde tutar. bcc_bindFloatingPanel'in içinden çıkarıldı çünkü hücre
    // düzenleme popover'ları (grid.js: richtext + ek dosya) <details> DEĞİL —
    // zorunlu olarak yaratılıp yok ediliyorlar, yani toggle'a bağlı sarmalayıcıyı
    // kullanamıyorlar ama AYNI konumlandırma matematiğine ihtiyaç duyuyorlar.
    // Tek uygulama: buradaki bir düzeltme her iki çağrı yerine de gider.
    //
    // panel   : konumlanacak kutu (position: fixed olmalı)
    // rect    : anchor'ın getBoundingClientRect()'i
    // options : align ('left'|'right'), gap (px)
    window.bcc_positionFloating = function (panel, rect, options) {
        options = options || {};
        var align = options.align === 'right' ? 'right' : 'left';
        var gap = typeof options.gap === 'number' ? options.gap : 4;
        var margin = 8; // viewport kenarlarına bırakılan pay

        // ---- DİKEY: aşağı sığmıyorsa YUKARI ÇEVİR ----
        // Bulunan gerçek bug: konum koşulsuz "anchor'ın ALTI" idi. Alt
        // satırlardaki (ör. 8./9. satır) hücrelerde popover ekranın altından
        // taşıp kırpılıyordu. Önce ölçüp hangi tarafta daha çok yer varsa
        // oraya koyuyoruz; hiçbir tarafa tam sığmıyorsa yüksekliği kırpıp
        // içeriği kaydırılabilir yapıyoruz (kırpmak yerine).
        // max-height her seferinde SIFIRLANIR: aksi halde bir kez daralan
        // panel, yer açıldığında dar kalırdı.
        panel.style.maxHeight = '';
        var panelHeight = panel.offsetHeight || 0;
        var spaceBelow = window.innerHeight - rect.bottom - gap - margin;
        var spaceAbove = rect.top - gap - margin;

        if (panelHeight <= spaceBelow || spaceBelow >= spaceAbove) {
            if (panelHeight > spaceBelow) {
                panel.style.maxHeight = Math.max(spaceBelow, 120) + 'px';
            }
            panel.style.top = (rect.bottom + gap) + 'px';
            panel.style.bottom = 'auto';
        } else {
            if (panelHeight > spaceAbove) {
                panel.style.maxHeight = Math.max(spaceAbove, 120) + 'px';
            }
            panel.style.top = 'auto';
            panel.style.bottom = (window.innerHeight - rect.top + gap) + 'px';
        }

        // ---- YATAY ----
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
    };

    window.bcc_bindFloatingPanel = function (menu, panel, anchor, options) {
        function position() {
            window.bcc_positionFloating(panel, anchor.getBoundingClientRect(), options);
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

(function () {
    'use strict';

    // ---------------------------------------------------------------------
    // GENEL (OTOMATİK) DIŞARI-TIK / ESCAPE KAPATMA
    // ---------------------------------------------------------------------
    // Yukarıdaki bcc_bindDismissable() TEK bir elemanı bağlar ve çağıran onu
    // açıkça çağırmak zorundadır. Sorun tam olarak buydu: bir <details> popover
    // eklenip bu çağrı UNUTULDUĞUNDA panel dışarı tıklayınca kapanmıyor,
    // Escape'e cevap vermiyordu — sessiz bir kusur, çünkü panel diğer her
    // açıdan çalışıyor görünüyor.
    //
    // Ölçülen gerçek durum (denetim): interface.php'deki ÜÇ popover'ın da
    // (nav menüsü, "Paylaş" katılımcı popover'ı, "Bağlantı" popover'ı)
    // dışarı-tık ve Escape davranışı YOKTU. Sebep: o davranış grid'e özel
    // assets/grid-table-tabs.js içinde, YALNIZCA name="gs-table-tab-menu"
    // grubu için yazılmıştı ve o dosya interface.php'de hiç yüklenmiyor.
    //
    // Çözüm, o mantığı ORTAK dosyaya taşımak ve KAYIT GEREKTİRMEZ hâle
    // getirmek: sayfadaki HER <details> otomatik kapsanır. Böylece ileride
    // eklenecek bir popover "listeye yazılmayı" unutamaz.
    //
    // KAPSAM: bu projedeki her <details> bir menü/popover'dır (denetlendi:
    // araç çubuğu panelleri, sütun başlığı menüsü, paylaşım popover'ları,
    // nav menüleri, bildirimler, arama). İleride içerik açar-kapar bir
    // <details> gerekirse ona data-no-auto-dismiss eklemek yeterli.
    //
    // NEDEN pointerdown DEĞİL click: pointerdown, kullanıcı popover içindeki
    // bir metni seçmek için dışarıda basıp içeride bırakırken (ya da tersi)
    // erken kapatırdı. click yalnızca basma+bırakma AYNI hedefte olduğunda
    // oluşur, yani seçim/sürükleme jestlerini bozmaz.

    function isAutoDismissable(details) {
        return !details.hasAttribute('data-no-auto-dismiss');
    }

    function openDetails() {
        // Her olayda YENİDEN sorgulanıyor (önbelleğe alınmıyor): sonradan
        // DOM'a eklenen popover'lar da kendiliğinden kapsansın.
        return Array.prototype.slice.call(document.querySelectorAll('details[open]'));
    }

    // ---- Dışarı tıklama ----
    document.addEventListener('click', function (e) {
        openDetails().forEach(function (details) {
            if (!isAutoDismissable(details)) {
                return;
            }
            // İÇERİDEKİ tıklama kapatmaz (bağlantı kopyalama, input'a yazma,
            // rol seçme...) — gereksinim 3. contains() summary'yi de kapsar,
            // yani tetikleyiciye tıklamak da normal aç/kapa olarak çalışır.
            if (details.contains(e.target)) {
                return;
            }
            details.removeAttribute('open');
        });
    });

    // ---- Escape ----
    // capture:false ve stopPropagation'a SAYGILI: panel içindeki arama
    // kutuları (grid "Alan ara", çalışma alanı araması...) ilk Escape'te
    // yalnızca metni temizleyip olayı durduruyor; panel ikinci Escape'te
    // kapanıyor. Bu, o kutuların bilinçli davranışı.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        openDetails().forEach(function (details) {
            if (isAutoDismissable(details)) {
                details.removeAttribute('open');
            }
        });
    });

    // ---- Aynı gruptan yalnızca biri açık kalsın ----
    // <details name="X"> modern tarayıcılarda bunu KENDİSİ yapıyor; eski
    // Firefox/Safari desteklemiyor. Aşağısı o tarayıcılar için yedek.
    // 'toggle' olayı KABARMADIĞI için eleman başına bağlanmak zorunda.
    document.addEventListener('DOMContentLoaded', function () {
        var named = document.querySelectorAll('details[name]');

        named.forEach(function (details) {
            details.addEventListener('toggle', function () {
                if (!details.open) {
                    return;
                }
                var group = details.getAttribute('name');
                document.querySelectorAll('details[name="' + group + '"]').forEach(function (other) {
                    if (other !== details && other.open) {
                        other.removeAttribute('open');
                    }
                });
            });
        });
    });
})();
