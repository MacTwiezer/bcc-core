(function () {
    'use strict';

    // D2/D3 "Share" / "Share and Sync" popover'ları — sunucuya istek YOK,
    // yalnızca yanındaki readonly input'un değerini panoya kopyalar. İki
    // tetikleyici (grid.php'deki Share ve Share and Sync) AYNI kodu paylaşır.
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-share-copy-btn]'), function (btn) {
            // Bulunan gerçek bug: gerçek orijinal etiket (restoreLabel) her
            // tıklamada YENİDEN btn.textContent'ten okunuyordu. Kullanıcı 1.5
            // saniye içinde tekrar tıklarsa, o an ekranda "Kopyalandı" yazdığı
            // için BUNU orijinal etiket sanıp yakalıyordu — buton kalıcı olarak
            // "Kopyalandı" yazısında takılı kalıyordu. Gerçek orijinal etiket
            // artık yalnızca BİR KEZ (bağlanırken) okunuyor.
            var originalLabel = btn.textContent;
            var restoreTimer = null;

            btn.addEventListener('click', function () {
                var form = btn.closest('.share-popover-form');
                var input = form ? form.querySelector('[data-share-url-input]') : null;
                if (!input) {
                    return;
                }

                function showCopied() {
                    btn.textContent = 'Kopyalandı';
                    if (restoreTimer) {
                        window.clearTimeout(restoreTimer);
                    }
                    restoreTimer = window.setTimeout(function () {
                        btn.textContent = originalLabel;
                        restoreTimer = null;
                    }, 1500);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(showCopied).catch(function () {
                        input.select();
                        document.execCommand('copy');
                        showCopied();
                    });
                } else {
                    input.select();
                    document.execCommand('copy');
                    showCopied();
                }
            });
        });
    });
})();
