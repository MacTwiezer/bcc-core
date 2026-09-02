(function () {
    'use strict';

    window.bcc_bindColumnDrag = function (handle, options) {
        options = options || {};
        var onStart = options.onStart || function () {};
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
