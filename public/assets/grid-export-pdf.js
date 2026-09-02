(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var item = document.getElementById('gs-view-download-pdf-item');
        if (!item) {
            return;
        }

        function buildPdf(jpegBytes, pxW, pxH) {
            var ptW = Math.max(1, Math.round(pxW * 72 / 96));
            var ptH = Math.max(1, Math.round(pxH * 72 / 96));

            var parts = [];
            var offsets = [];
            var length = 0;

            function push(part) {
                parts.push(part);
                length += (typeof part === 'string')
                    ? part.length
                    : part.byteLength;
            }

            function beginObject(n) {
                offsets[n] = length;
                push(n + ' 0 obj\n');
            }

            push('%PDF-1.4\n');

            beginObject(1);
            push('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n');

            beginObject(2);
            push('<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n');

            beginObject(3);
            push('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + ptW + ' ' + ptH + ']'
                + ' /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>\nendobj\n');

            beginObject(4);
            push('<< /Type /XObject /Subtype /Image /Width ' + pxW + ' /Height ' + pxH
                + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
                + jpegBytes.byteLength + ' >>\nstream\n');
            push(jpegBytes);
            push('\nendstream\nendobj\n');

            var content = 'q\n' + ptW + ' 0 0 ' + ptH + ' 0 0 cm\n/Im0 Do\nQ\n';
            beginObject(5);
            push('<< /Length ' + content.length + ' >>\nstream\n' + content + 'endstream\nendobj\n');

            var xrefStart = length;
            var xref = 'xref\n0 6\n0000000000 65535 f \n';
            for (var i = 1; i <= 5; i++) {
                var off = String(offsets[i]);
                while (off.length < 10) { off = '0' + off; }
                xref += off + ' 00000 n \n';
            }
            push(xref);
            push('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xrefStart + '\n%%EOF\n');

            var chunks = parts.map(function (p) {
                if (typeof p !== 'string') { return p; }
                var bytes = new Uint8Array(p.length);
                for (var j = 0; j < p.length; j++) { bytes[j] = p.charCodeAt(j) & 0xff; }
                return bytes;
            });

            return new Blob(chunks, { type: 'application/pdf' });
        }

        function dataUrlToBytes(dataUrl) {
            var comma = dataUrl.indexOf(',');
            var b64 = dataUrl.slice(comma + 1);
            var bin = atob(b64);
            var out = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
            return out;
        }

        item.addEventListener('click', function () {
            var menu = document.querySelector('.gs-view-options-menu');
            if (menu) {
                menu.removeAttribute('open');
            }

            var api = window.BCC_GRID_EXPORT;
            if (!api) {
                window.alert('PDF oluşturulamadı.');
                return;
            }

            api.busy(item, true);
            api.captureCanvas('PDF').then(function (canvas) {
                if (canvas === null) {
                    api.busy(item, false);
                    return;
                }
                if (!canvas.width || !canvas.height) {
                    api.busy(item, false);
                    window.alert('PDF oluşturulamadı: görünüm sığmayacak kadar büyük. Excel indirmeyi deneyin.');
                    return;
                }

                var dataUrl = canvas.toDataURL('image/jpeg', 0.92);
                if (dataUrl.indexOf('data:image/jpeg') !== 0) {
                    api.busy(item, false);
                    window.alert('PDF oluşturulamadı (JPEG kodlanamadı).');
                    return;
                }

                api.download(buildPdf(dataUrlToBytes(dataUrl), canvas.width, canvas.height), '.pdf');
                api.busy(item, false);
            }).catch(function () {
                api.busy(item, false);
                window.alert('PDF oluşturulamadı.');
            });
        });
    });
})();
