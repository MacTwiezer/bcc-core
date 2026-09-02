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

    try {
        var saved = parseInt(window.localStorage.getItem(STORAGE_KEY), 10);
        if (saved) {
            apply(saved);
        }
    } catch (e) {
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
            e.preventDefault();
            apply(startW + (e.clientX - startX) / (window.bcc_uiScale ? window.bcc_uiScale() : 1));
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
            } catch (e) {}

            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        }

        handle.addEventListener('mousedown', function (e) {
            if (e.button !== 0) { return; }
            e.preventDefault();
            dragging = true;
            startX = e.clientX;
            startW = drawer.offsetWidth;

            document.body.classList.add('gs-drawer-resizing');
            handle.classList.add('is-dragging');
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        handle.addEventListener('dblclick', function () {
            apply(DEFAULT);
            try { window.localStorage.setItem(STORAGE_KEY, String(DEFAULT)); } catch (e) {}
        });

        handle.addEventListener('keydown', function (e) {
            var step = 0;
            if (e.key === 'ArrowLeft') { step = -16; }
            else if (e.key === 'ArrowRight') { step = 16; }
            else if (e.key === 'Home') { step = null; }
            else { return; }

            e.preventDefault();
            var next = (step === null)
                ? DEFAULT
                : drawer.offsetWidth + step;
            apply(next);
            try {
                window.localStorage.setItem(STORAGE_KEY, String(clamp(next)));
            } catch (err) {}
        });
    });
})();
