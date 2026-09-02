(function () {
    'use strict';

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

    window.bcc_raiseFloatingHost = function (panel, on) {
        if (!panel || !panel.closest) {
            return;
        }
        var host = panel.closest('th, td');
        if (host) {
            host.classList.toggle('grid-floating-host', !!on);
        }
    };

    window.bcc_positionFloating = function (panel, rect, options) {
        options = options || {};
        var align = options.align === 'right' ? 'right' : 'left';
        var gap = typeof options.gap === 'number' ? options.gap : 4;
        var margin = 8;

        var uiScale = window.bcc_uiScale ? window.bcc_uiScale() : 1;
        if (uiScale !== 1) {
            rect = {
                top: rect.top / uiScale,
                bottom: rect.bottom / uiScale,
                left: rect.left / uiScale,
                right: rect.right / uiScale,
            };
        }
        var viewportW = window.innerWidth / uiScale;
        var viewportH = window.innerHeight / uiScale;

        panel.style.maxHeight = '';
        var panelHeight = panel.offsetHeight || 0;
        var spaceBelow = viewportH - rect.bottom - gap - margin;
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
            panel.style.bottom = (viewportH - rect.top + gap) + 'px';
        }

        if (align === 'right') {
            var rightOffset = viewportW - rect.right;
            var pw = panel.offsetWidth || 0;
            if (rightOffset + pw > viewportW - margin) {
                rightOffset = viewportW - margin - pw;
            }
            if (rightOffset < margin) {
                rightOffset = margin;
            }
            panel.style.left = 'auto';
            panel.style.right = rightOffset + 'px';
            return;
        }

        var left = rect.left;
        var panelWidth = panel.offsetWidth || 0;
        if (left + panelWidth > viewportW - margin) {
            left = viewportW - margin - panelWidth;
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
                window.bcc_raiseFloatingHost(panel, false);
                detach();
                return;
            }
            window.bcc_raiseFloatingHost(panel, true);
            position();
            attach();
        });
    };
})();

(function () {
    'use strict';


    function isAutoDismissable(details) {
        return !details.hasAttribute('data-no-auto-dismiss');
    }

    function openDetails() {
        return Array.prototype.slice.call(document.querySelectorAll('details[open]'));
    }

    document.addEventListener('click', function (e) {
        openDetails().forEach(function (details) {
            if (!isAutoDismissable(details)) {
                return;
            }
            if (details.contains(e.target)) {
                return;
            }
            details.removeAttribute('open');
        });
    });

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
