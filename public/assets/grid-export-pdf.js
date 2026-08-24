(function () {
    'use strict';

    // "PDF olarak indir" — grid tablosunu PDF olarak indirir.
    //
    // ⚠️ "Yazdır"DAN FARKI: "Yazdır" tarayıcının yazdırma diyaloğunu açar
    // (kullanıcı oradan "PDF olarak kaydet" seçebilir ama bu iki adım ve hedef
    // yazıcıya göre değişir). Bu kalem DOĞRUDAN bir .pdf dosyası indirir —
    // PNG kardeşiyle aynı tek tıklama sözleşmesi.
    //
    // ⚠️ YENİ KÜTÜPHANE EKLENMEDİ. PDF, jsPDF gibi bir bağımlılık yerine
    // burada elle üretiliyor — projede AYNI karar sunucu tarafında da var
    // (src/xlsx_writer.php kendi xlsx'ini yazıyor, bir kütüphane çekmiyor).
    // Tek gereken html2canvas ZATEN yerelde ve PNG için yükleniyor; PDF onun
    // ürettiği AYNI canvas'ı kullanıyor (window.BCC_GRID_EXPORT).
    //
    // BİÇİM: tek sayfalık, içine JPEG gömülü bir PDF. JPEG seçildi çünkü
    // /DCTDecode ile ham bayt olarak gömülebiliyor; PNG gömmek örnekleri
    // yeniden sıkıştırmayı (Flate) gerektirirdi, yani onlarca satır daha kod.
    //
    // SAYFA BOYUTU: görüntünün kendisi (96 DPI -> 72 pt dönüşümüyle). Yani
    // "ekranda ne varsa o" — PNG'nin sözleşmesiyle AYNI. A4'e sığdırmak geniş
    // tabloları okunmaz hale getirirdi. SAYFALAMA KAPSAM DIŞI: uzun tablolar
    // tek uzun sayfa olur (bilinçli; çok sayfalı yerleşim ayrı bir iş).

    document.addEventListener('DOMContentLoaded', function () {
        var item = document.getElementById('gs-view-download-pdf-item');
        if (!item) {
            return;
        }

        // ---- Minimal PDF yazıcı ------------------------------------------
        // Bayt bazlı çalışır: xref tablosundaki ofsetler BAYT cinsindendir,
        // bu yüzden parçalar tek tek ölçülüp toplanır. ASCII bölümler string,
        // JPEG bölümü Uint8Array olarak tutulur.
        function buildPdf(jpegBytes, pxW, pxH) {
            // CSS px -> PDF punto (1pt = 1/72 inç, tarayıcı px = 1/96 inç).
            var ptW = Math.max(1, Math.round(pxW * 72 / 96));
            var ptH = Math.max(1, Math.round(pxH * 72 / 96));

            var parts = [];   // string | Uint8Array
            var offsets = []; // her nesnenin başlangıç bayt ofseti
            var length = 0;

            function push(part) {
                parts.push(part);
                length += (typeof part === 'string')
                    ? part.length          // hepsi ASCII: karakter = bayt
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

            // Görüntü nesnesi — ham JPEG, /DCTDecode.
            beginObject(4);
            push('<< /Type /XObject /Subtype /Image /Width ' + pxW + ' /Height ' + pxH
                + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
                + jpegBytes.byteLength + ' >>\nstream\n');
            push(jpegBytes);
            push('\nendstream\nendobj\n');

            // İçerik akışı: görüntüyü sayfanın TAMAMINA yerleştir.
            // `cm` matrisi ölçekleme yapar; PDF'te (0,0) SOL ALT köşedir, bu
            // yüzden ayrıca bir çevirme gerekmez — görüntü zaten tam sayfa.
            var content = 'q\n' + ptW + ' 0 0 ' + ptH + ' 0 0 cm\n/Im0 Do\nQ\n';
            beginObject(5);
            push('<< /Length ' + content.length + ' >>\nstream\n' + content + 'endstream\nendobj\n');

            // xref: nesne 0 her zaman serbest kayıttır (f), diğerleri kullanımda (n).
            // Ofsetler 10 haneye sıfırla doldurulur — biçim SABİT genişliktedir,
            // 20 bayt/satır; kaymalar dosyayı bozar.
            var xrefStart = length;
            var xref = 'xref\n0 6\n0000000000 65535 f \n';
            for (var i = 1; i <= 5; i++) {
                var off = String(offsets[i]);
                while (off.length < 10) { off = '0' + off; }
                xref += off + ' 00000 n \n';
            }
            push(xref);
            push('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xrefStart + '\n%%EOF\n');

            // Blob'a yazarken ASCII parçalar Uint8Array'e çevrilir: string'i
            // doğrudan Blob'a vermek UTF-8 kodlar ve çok baytlı karakter
            // olmasa bile ofset güvenini koda değil şansa bırakırdı.
            var chunks = parts.map(function (p) {
                if (typeof p !== 'string') { return p; }
                var bytes = new Uint8Array(p.length);
                for (var j = 0; j < p.length; j++) { bytes[j] = p.charCodeAt(j) & 0xff; }
                return bytes;
            });

            return new Blob(chunks, { type: 'application/pdf' });
        }

        // data:image/jpeg;base64,... -> Uint8Array
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
                    // Tablo yok ya da kullanıcı büyük-tablo onayını reddetti.
                    api.busy(item, false);
                    return;
                }
                if (!canvas.width || !canvas.height) {
                    api.busy(item, false);
                    window.alert('PDF oluşturulamadı: görünüm sığmayacak kadar büyük. Excel indirmeyi deneyin.');
                    return;
                }

                // 0.92 kalite: metin tablosunda gözle fark edilmiyor ama dosya
                // kayıpsız PNG'ye göre belirgin küçük kalıyor.
                var dataUrl = canvas.toDataURL('image/jpeg', 0.92);
                if (dataUrl.indexOf('data:image/jpeg') !== 0) {
                    // Tarayıcı JPEG üretemediyse PNG'ye düşerdi; o baytlar
                    // /DCTDecode ile GEÇERSİZ bir PDF üretirdi — sessiz bozuk
                    // dosya vermektense açıkça bildiriliyor.
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
