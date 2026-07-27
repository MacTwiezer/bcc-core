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
    // options.onMove(clientX)         - (zorunlu) fare hareket ederken rAF ile
    //     throttle edilerek çağrılır
    // options.onEnd()                 - (opsiyonel) sürükleme bırakılınca (mouseup veya
    //     pencere dışına çıkma) bir kez çağrılır — genelde sunucuya kalıcı kaydetmek için
    window.bcc_bindColumnDrag = function (handle, options) {
        options = options || {};
        var onStart = options.onStart || function () {};
        var onMove = options.onMove;
        var onEnd = options.onEnd || function () {};

        var dragging = false;
        var rafPending = false;
        var pendingClientX = null;

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
            if (rafPending) {
                return;
            }
            rafPending = true;
            requestAnimationFrame(function () {
                rafPending = false;
                if (!dragging) {
                    return;
                }
                onMove(pendingClientX);
            });
        });

        document.addEventListener('mouseup', endDrag);
        document.addEventListener('mouseleave', endDrag);
    };
})();
