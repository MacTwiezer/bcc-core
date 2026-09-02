(function () {
    'use strict';

    var DEFAULT_COLOR = '#2D7FF9';

    function metaContent(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? (el.getAttribute('content') || '') : '';
    }

    function buildBadgeSvg(innerPaths, colorHex) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32">'
            + '<rect width="24" height="24" rx="5" fill="' + colorHex + '"/>'
            + '<g transform="translate(4 4) scale(0.6667)" fill="none" stroke="#ffffff"'
            + ' stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round">'
            + innerPaths
            + '</g></svg>';
    }

    function svgToDataUri(svg) {
        return 'data:image/svg+xml,' + encodeURIComponent(svg);
    }

    function updatePageFavicon(iconUrlOrSvg, colorHex) {
        var raw = (iconUrlOrSvg === null || iconUrlOrSvg === undefined) ? '' : String(iconUrlOrSvg).trim();
        if (raw === '') {
            return null;
        }

        var href;
        if (raw.charAt(0) !== '<') {
            href = raw;
        } else if (raw.slice(0, 4).toLowerCase() === '<svg') {
            href = svgToDataUri(raw);
        } else {
            href = svgToDataUri(buildBadgeSvg(raw, colorHex || DEFAULT_COLOR));
        }

        var existing = document.querySelectorAll('link[rel~="icon"]');
        Array.prototype.forEach.call(existing, function (link) {
            if (link.parentNode) {
                link.parentNode.removeChild(link);
            }
        });

        var el = document.createElement('link');
        el.setAttribute('rel', 'icon');
        el.setAttribute('type', 'image/svg+xml');
        el.setAttribute('href', href);
        document.head.appendChild(el);

        return el;
    }

    function updatePageTitle(baseName, contextName) {
        var base = (baseName === null || baseName === undefined) ? '' : String(baseName).trim();
        var ctx = (contextName === null || contextName === undefined) ? '' : String(contextName).trim();

        var brand = metaContent('bcc-brand') || 'OpsFlow';

        var title;
        if (base === '') {
            title = ctx !== '' ? ctx + ' - ' + brand : brand;
        } else if (ctx !== '') {
            title = base + ': ' + ctx + ' - ' + brand;
        } else {
            title = base + ' - ' + brand;
        }

        document.title = title;

        return title;
    }

    window.updatePageFavicon = updatePageFavicon;
    window.updatePageTitle = updatePageTitle;

    document.addEventListener('DOMContentLoaded', function () {
        var icon = metaContent('bcc-base-icon');
        if (icon !== '') {
            updatePageFavicon(icon, metaContent('bcc-base-color'));
        }

    });
})();
