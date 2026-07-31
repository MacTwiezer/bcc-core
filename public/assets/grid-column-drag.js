(function () {
    'use strict';

    // Ortak sürükleme iskeleti — mousedown/mousemove(rAF throttle)/mouseup/mouseleave
    // deseni grid-freeze-columns.js (sütun dondurma) TARAFINDAN KULLANILIR, ikinci bir
    // kopya YOK. Yalnızca "sürüklerken ne hesaplanacak" (onMove) ve "bırakınca ne
    // kaydedilecek" (onEnd) farklı — o kısım çağıran dosyada kalır, iskelet burada.
    //
    // handle: mousedown ile sürüklemeyi başlatan element (bir tutamaç <div>'i).
    // options.onStart(mousedownEvent) - (opsiyonel) sürükleme başlarken bir kez çağrılır
    //     (başlangıç genişliği/pozisyonu gibi durumu yakalamak için)
    // options.onMove(clientX, clientY) - (zorunlu) fare hareket ederken rAF ile
    //     throttle edilerek çağrılır. clientY, D1 (views panelinde dikey
    //     sürükle-bırak sıralama) için eklendi — yatay kullanan mevcut
    //     çağıranlar (sütun dondurma) onu yok sayar, geriye dönük uyumlu.
    // options.onEnd()                 - (opsiyonel) sürükleme bırakılınca (mouseup veya
    //     pencere dışına çıkma) bir kez çağrılır — genelde sunucuya kalıcı kaydetmek için
    window.bcc_bindColumnDrag = function (handle, options) {
        options = options || {};
        var onStart = options.onStart || function () {};
        // Bulunan kırılgan kod: onStart/onEnd'in aksine onMove'un varsayılanı
        // yoktu — "zorunlu" olduğu belgelense de, ileride bunu unutan bir
        // çağıran, açık bir hata yerine requestAnimationFrame içinde belirsiz
        // bir TypeError alırdı. Kardeşleriyle AYNI güvenli varsayılan deseni.
        var onMove = options.onMove || function () {};
        var onEnd = options.onEnd || function () {};

        var dragging = false;
        var rafPending = false;
        var pendingClientX = null;
        var pendingClientY = null;

        function endDrag() {
            if (!dragging) {
                return;
            }
            dragging = false;
            document.body.style.userSelect = '';
            handle.classList.remove('is-dragging');
            onEnd();
        }

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            dragging = true;
            document.body.style.userSelect = 'none';
            handle.classList.add('is-dragging');
            onStart(e);
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) {
                return;
            }

            // Fare tuşu bırakılmış (ör. pencere dışında bırakılmış) — dinleyicileri
            // temizlemek için sürüklemeyi hemen bitir.
            if (e.buttons === 0) {
                endDrag();
                return;
            }

            pendingClientX = e.clientX;
            pendingClientY = e.clientY;
            if (rafPending) {
                return;
            }
            rafPending = true;
            requestAnimationFrame(function () {
                rafPending = false;
                if (!dragging) {
                    return;
                }
                onMove(pendingClientX, pendingClientY);
            });
        });

        document.addEventListener('mouseup', endDrag);
        document.addEventListener('mouseleave', endDrag);
    };
})();
