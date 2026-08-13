// Sekme kimliği: tarayıcı sekmesindeki favicon ve başlık, o an açık olan
// base/tablo bağlamını yansıtır.
//
// Veriyi sunucu <meta> etiketleriyle veriyor (bkz. src/schema.php içindeki
// bcc_page_identity_meta) — istemci hiçbir şey SORGULAMIYOR, yalnızca hazır
// gelen glif + rengi bir data: URI'ye çeviriyor. Base ikonu veritabanında
// tutulmadığı için (glif base ADINDAN, renk base ID'sinden türetilir) sekme
// ikonu Home kartındaki ve grid üst barındaki çiple her zaman aynı çıkar.
(function () {
    'use strict';

    var DEFAULT_COLOR = '#2D7FF9'; // BCC_BASE_ICON_THEMES[0].solid

    function metaContent(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? (el.getAttribute('content') || '') : '';
    }

    // Dolu renkli rozet + BEYAZ glif — grid.php'nin .gs-base-icon çipiyle AYNI
    // görsel dil. Kartlardaki pastel zemin burada bilerek kullanılmadı: 16px'lik
    // bir sekmede pastel zemin üzerindeki koyu mürekkep neredeyse okunmuyor,
    // dolu zemin ise küçük boyutta bile base'in rengini tanıtıyor.
    function buildBadgeSvg(innerPaths, colorHex) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32">'
            + '<rect width="24" height="24" rx="5" fill="' + colorHex + '"/>'
            // Glif 24'lük ızgarada çizili; 4px kenar boşluğu bırakmak için
            // 16/24 = 0.6667 ölçekleniyor. stroke-width 1.8 -> 2.7, çünkü
            // ölçek küçülünce çizgi de incelir; bu değer optik ağırlığı korur.
            + '<g transform="translate(4 4) scale(0.6667)" fill="none" stroke="#ffffff"'
            + ' stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round">'
            + innerPaths
            + '</g></svg>';
    }

    // base64 DEĞİL yüzde-kodlama: SVG metni okunabilir kalıyor ve btoa()'nın
    // Latin-1 sınırı yüzünden Türkçe karakterli bir içerikte patlamıyor.
    function svgToDataUri(svg) {
        return 'data:image/svg+xml,' + encodeURIComponent(svg);
    }

    /**
     * Sekme ikonunu değiştirir.
     *
     * @param {string} iconUrlOrSvg Üç biçimden biri:
     *   - URL / data: URI  ("/assets/favicon.svg")     -> olduğu gibi kullanılır
     *   - tam SVG belgesi  ("<svg ...>...</svg>")      -> data: URI'ye çevrilir
     *   - yalnızca çizim yolları ("<path .../>")       -> renkli rozetin içine
     *                                                     yerleştirilir
     * @param {string} [colorHex] Rozet zemini; yalnızca üçüncü biçimde kullanılır.
     */
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

        // Var olan ikon <link>'leri KALDIRILIP yenisi ekleniyor: yalnızca
        // href'i değiştirmek bazı tarayıcılarda yeniden okumayı tetiklemiyor,
        // eski ikon sekmede asılı kalıyor. rel~="icon" hem "icon" hem
        // "shortcut icon" gibi çoklu değerleri yakalar.
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

    /**
     * Sekme başlığını "[Base]: [Tablo/Görünüm] — BCC-Core" biçiminde kurar.
     * İkisi de boşsa yalnızca "BCC-Core" kalır.
     *
     * DİKKAT: bu biçim src/schema.php'deki bcc_page_title() ile birebir AYNI
     * olmalı — sayfa açılışındaki başlığı O basıyor, bu fonksiyon yalnızca
     * sayfa yenilenmeden yapılan değişiklikler için. Biri değişirse diğeri de
     * değişmeli.
     */
    function updatePageTitle(baseName, contextName) {
        var base = (baseName === null || baseName === undefined) ? '' : String(baseName).trim();
        var ctx = (contextName === null || contextName === undefined) ? '' : String(contextName).trim();

        var title;
        if (base === '') {
            title = ctx !== '' ? ctx + ' — BCC-Core' : 'BCC-Core';
        } else if (ctx !== '') {
            title = base + ': ' + ctx + ' — BCC-Core';
        } else {
            title = base + ' — BCC-Core';
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

        // Başlık BİLEREK yeniden yazılmıyor: sunucu zaten doğrusunu bastı
        // (bkz. bcc_page_title). Burada tekrar kurmak, aynı metni bir kez daha
        // üretmekten başka bir şey yapmaz; updatePageTitle() sayfa
        // yenilenmeden yapılacak değişiklikler için dışa açık duruyor.
    });
})();
