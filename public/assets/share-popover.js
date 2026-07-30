(function () {
    'use strict';

    // D2/D3 "Share" / "Share and Sync" popover'ları — sunucuya istek YOK,
    // yalnızca yanındaki readonly input'un değerini panoya kopyalar. İki
    // tetikleyici (grid.php'deki Share ve Share and Sync) AYNI kodu paylaşır.
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-share-copy-btn]'), function (btn) {
            btn.addEventListener('click', function () {
                var form = btn.closest('.share-popover-form');
                var input = form ? form.querySelector('[data-share-url-input]') : null;
                if (!input) {
                    return;
                }

                var restoreLabel = btn.textContent;

                function showCopied() {
                    btn.textContent = 'Kopyalandı';
                    window.setTimeout(function () {
                        btn.textContent = restoreLabel;
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
