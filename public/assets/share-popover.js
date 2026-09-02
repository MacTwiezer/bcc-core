(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-share-url-input]'), function (input) {
            function selectAll() {
                input.select();
            }
            input.addEventListener('focus', selectAll);
            input.addEventListener('click', selectAll);
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-share-copy-btn]'), function (btn) {
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
