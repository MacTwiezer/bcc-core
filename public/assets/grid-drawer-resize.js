// Görünüm panelinin (.gs-view-drawer) genişliğini sürükleyerek ayarlama.
//
// Genişlik <html> üzerindeki --gs-drawer-w CSS değişkeninde tutulur ve
// localStorage'a yazılır (kullanıcı/tarayıcı tercihi; sunucuda saklanacak bir
// VERİ değil — sütun genişliklerinden farkı bu: onlar görünümün parçası ve
// views.config'e yazılıyor, bu ise kişisel bir arayüz ayarı).
//
// ⚠️ SATIR İÇİ width KULLANILMIYOR. Satır içi bir genişlik,
// ".gs-view-drawer.is-collapsed { width: 0 }" kuralını ezer ve panel bir kez
// sürüklendikten sonra hamburger düğmesiyle bir daha DARALMAZDI.
(function () {
    'use strict';

    var STORAGE_KEY = 'bcc_grid_drawer_width';
    var MIN = 180;
    var MAX = 560;
    var DEFAULT = 260;

    function clamp(px) {
        return Math.max(MIN, Math.min(MAX, Math.round(px)));
    }

    function apply(px) {
        document.documentElement.style.setProperty('--gs-drawer-w', clamp(px) + 'px');
    }

    // ---- Kaydedilmiş genişliği MÜMKÜN OLAN EN ERKEN anda uygula ------------
    // Bu dosya <head>'de SENKRON yükleniyor (theme-init.js ile aynı gerekçe):
    // defer edilseydi panel önce 260px çizilir, sonra kayıtlı genişliğe
    // sıçrardı — görünür bir titreme (FOUC).
    try {
        var saved = parseInt(window.localStorage.getItem(STORAGE_KEY), 10);
        if (saved) {
            apply(saved);
        }
    } catch (e) {
        // localStorage kapalı/dolu olabilir (gizli sekme vb.) — varsayılan
        // genişlikle devam edilir, sürükleme yine çalışır (yalnızca kalıcı
        // olmaz).
    }

    document.addEventListener('DOMContentLoaded', function () {
        var drawer = document.getElementById('gs-view-drawer');
        var handle = document.getElementById('gs-view-drawer-resizer');
        if (!drawer || !handle) {
            return;
        }

        var startX = 0;
        var startW = 0;
        var dragging = false;

        function onMove(e) {
            if (!dragging) { return; }
            // Sürükleme sırasında metin seçimi/otomatik kaydırma başlamasın.
            e.preventDefault();
            apply(startW + (e.clientX - startX));
        }

        function onUp() {
            if (!dragging) { return; }
            dragging = false;
            document.body.classList.remove('gs-drawer-resizing');
            handle.classList.remove('is-dragging');

            try {
                var w = parseInt(
                    getComputedStyle(document.documentElement)
                        .getPropertyValue('--gs-drawer-w'),
                    10
                );
                if (w) { window.localStorage.setItem(STORAGE_KEY, String(w)); }
            } catch (e) { /* yukarıdaki gerekçe */ }

            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        }

        handle.addEventListener('mousedown', function (e) {
            if (e.button !== 0) { return; }
            e.preventDefault();
            dragging = true;
            startX = e.clientX;
            // Başlangıç genişliği GERÇEK ölçümden alınır (değişkenden değil):
            // kullanıcı hiç sürüklememişse değişken hiç tanımlı olmayabilir,
            // o zaman CSS'teki varsayılan geçerlidir.
            startW = drawer.getBoundingClientRect().width;

            document.body.classList.add('gs-drawer-resizing');
            handle.classList.add('is-dragging');
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // Çift tıklama varsayılana döndürür — sürükleyerek kullanılamaz hale
        // getiren bir genişlikten çıkış yolu.
        handle.addEventListener('dblclick', function () {
            apply(DEFAULT);
            try { window.localStorage.setItem(STORAGE_KEY, String(DEFAULT)); } catch (e) {}
        });

        // Klavyeyle de ayarlanabilmeli: tutamaç tabindex="0" ve
        // role="separator" taşıyor, ok tuşlarıyla 16px adımlarla değişir.
        // (Fare kullanamayan kullanıcı için tek erişim yolu.)
        handle.addEventListener('keydown', function (e) {
            var step = 0;
            if (e.key === 'ArrowLeft') { step = -16; }
            else if (e.key === 'ArrowRight') { step = 16; }
            else if (e.key === 'Home') { step = null; }
            else { return; }

            e.preventDefault();
            var next = (step === null)
                ? DEFAULT
                : drawer.getBoundingClientRect().width + step;
            apply(next);
            try {
                window.localStorage.setItem(STORAGE_KEY, String(clamp(next)));
            } catch (err) { /* yukarıdaki gerekçe */ }
        });
    });
})();
