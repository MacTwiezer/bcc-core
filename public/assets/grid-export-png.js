(function () {
    'use strict';

    // "PNG olarak indir" — grid tablosunu istemci tarafında canvas'a çizip
    // indirir. Sunucuda YENİ BİR UÇ NOKTA YOK: PNG, o an DOM'da ne varsa onu
    // bastığı için filtre/sıralama/gizli sütun durumu ZATEN uygulanmış olarak
    // gelir. Excel (view_export_xlsx.php) ve PDF (window.print()) ile veri
    // kapsamı bu yüzden kendiliğinden tutarlı — üçü de aynı URL state'inden
    // türeyen aynı kayıt kümesini gösterir, ikinci bir parse/sorgu YOK.
    //
    // Kapsam: YALNIZCA Grid görünümü. Kanban/Form dışa aktarma ayrı bir tur.

    document.addEventListener('DOMContentLoaded', function () {
        var item = document.getElementById('gs-view-download-png-item');
        if (!item) {
            return;
        }

        // Eşikler SERT ENGEL DEĞİL — aşılınca yalnızca onay soruluyor.
        var ROW_WARN_THRESHOLD = 500;
        var HEIGHT_WARN_THRESHOLD = 12000;
        // Chromium'da tek bir canvas kenarı ~16384px'te sessizce boş canvas
        // döndürüyor; ölçek bu sınırın altında kalacak şekilde kısılıyor.
        var MAX_CANVAS_EDGE = 16000;

        var loadPromise = null;

        // html2canvas YEREL bir dosyadan (assets/vendor/, MIT, sürüm 1.4.1) ve
        // yalnızca İLK TIKLAMADA yükleniyor — CDN YOK, ve ~200KB'lık kütüphane
        // her grid açılışının önüne konmuyor. Yol sunucudan geliyor
        // (data-html2canvas-src, bcc_asset_url ile mtime cache-bust'lı), istemci
        // '/assets/...' dizgisini kendi kurmuyor.
        function loadHtml2Canvas() {
            if (window.html2canvas) {
                return Promise.resolve(window.html2canvas);
            }
            if (loadPromise) {
                return loadPromise;
            }
            loadPromise = new Promise(function (resolve, reject) {
                var src = item.getAttribute('data-html2canvas-src');
                if (!src) {
                    reject(new Error('kaynak yok'));
                    return;
                }
                var script = document.createElement('script');
                script.src = src;
                script.onload = function () {
                    if (window.html2canvas) {
                        resolve(window.html2canvas);
                    } else {
                        reject(new Error('yüklendi ama global yok'));
                    }
                };
                script.onerror = function () {
                    // Başarısız yükleme önbelleğe alınmasın: kullanıcı tekrar
                    // deneyebilsin.
                    loadPromise = null;
                    reject(new Error('yüklenemedi'));
                };
                document.head.appendChild(script);
            });
            return loadPromise;
        }

        function fileNameBase() {
            // view_export_xlsx.php'deki dosya adı kuralının AYNISI
            // (preg_replace('/[^a-zA-Z0-9_\-]+/', '_')) — .xlsx ile .png yan yana
            // indirildiğinde adlar tutarlı olsun.
            var name = (window.BCC_TABLE_NAME || '').replace(/[^a-zA-Z0-9_-]+/g, '_');
            return name !== '' ? name : 'grid';
        }

        function download(blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = fileNameBase() + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            // Hemen revoke etmek bazı tarayıcılarda indirmeyi yarıda kesiyor.
            setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        }

        item.addEventListener('click', function () {
            var menu = document.querySelector('.gs-view-options-menu');
            if (menu) {
                menu.removeAttribute('open');
            }

            var table = document.querySelector('table.grid');
            if (!table) {
                window.alert('Bu tabloda henüz alan yok, PNG oluşturulamıyor.');
                return;
            }

            var rowCount = table.querySelectorAll('tbody tr[data-record-id]').length;

            // ÖLÇÜ, canlı tablodan OLDUĞU GİBİ alınamaz — bulunan gerçek bug:
            // klonda sol şerit/görünüm paneli gizlendiği için .gs-main genişliyor
            // ve table.grid'in `min-width:100%`i (style.css) tabloyu ekrandakinden
            // GENİŞ yayıyordu (ölçüldü: canlı 926px -> klon 1240px). Canvas canlı
            // ölçüyle açıldığı için sağdaki 314px KIRPILIYOR, altta da gizlenen
            // "+" satırı kadar boşluk kalıyordu; metin bu yüzden "ortalanmış"
            // görünüyordu (hiza her zaman left'ti, kayan şey sütun sınırlarıydı).
            //
            // Çözüm: sütun genişlikleri EKRANDAN ölçülüp klona `table-layout:fixed`
            // ile birebir dayatılıyor. Böylece PNG ekrandaki tabloyla aynı
            // yerleşimi koruyor — PNG'nin sözleşmesi zaten "ekranda ne varsa o".
            var addFieldTh = table.querySelector('thead th.grid-add-field-th');
            var addRow = table.querySelector('tr.grid-add-row');

            var colWidths = [];
            Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (th) {
                // "+" (yeni alan) sütunu çıktıda gizli (grid-export.css) — genişliğe
                // de katılmamalı, yoksa sağda o kadar boşluk kalırdı.
                if (th === addFieldTh) {
                    return;
                }
                colWidths.push(Math.round(th.getBoundingClientRect().width));
            });

            var width = 0;
            for (var ci = 0; ci < colWidths.length; ci++) { width += colWidths[ci]; }
            // Taban "+" satırı da çıktıda gizli — yüksekliğinden düşülmezse PNG'nin
            // altında boş bir şerit kalır.
            var height = Math.ceil(table.scrollHeight - (addRow ? addRow.getBoundingClientRect().height : 0));

            if (rowCount > ROW_WARN_THRESHOLD || height > HEIGHT_WARN_THRESHOLD) {
                if (!window.confirm('Bu görünüm büyük, PNG yavaş/okunmayabilir. Excel önerilir. Devam edilsin mi?')) {
                    return;
                }
            }

            // Küçük tablolarda 2x (retina netliği), büyüklerde 1x — ve her
            // durumda canvas kenar sınırına göre kısılır.
            var scale = rowCount > 200 ? 1 : 2;
            var longestEdge = Math.max(width, height);
            if (longestEdge * scale > MAX_CANVAS_EDGE) {
                scale = Math.max(1, Math.floor(MAX_CANVAS_EDGE / longestEdge));
            }

            item.disabled = true;
            document.body.style.cursor = 'progress';

            function done() {
                item.disabled = false;
                document.body.style.cursor = '';
            }

            loadHtml2Canvas().then(function (html2canvas) {
                return html2canvas(table, {
                    backgroundColor: '#ffffff',
                    scale: scale,
                    logging: false,
                    width: width,
                    height: height,
                    // Klonun yerleşim viewport'u tablodan DAR kalırsa tablo
                    // yeniden sarılıp ekrandakinden farklı çıkardı — tablonun
                    // kendi ölçüleri taban alınıyor.
                    windowWidth: Math.max(document.documentElement.clientWidth, width + 100),
                    windowHeight: Math.max(document.documentElement.clientHeight, height + 100),
                    onclone: function (clonedDoc) {
                        // ORTAK dışa aktarma kuralları (assets/grid-export.css,
                        // sayfaya media="print" ile bağlı). Klon `screen`
                        // medyasında render edildiği için media "all"a
                        // çevriliyor — kurallar böylece PDF ile TEK KAYNAKTAN
                        // paylaşılıyor, PNG'ye özel ikinci bir gizleme/kırpma
                        // listesi YOK. Değişiklik yalnızca KOPYADA: canlı
                        // sayfada hiçbir şey oynamıyor (ekranda titreme yok).
                        var link = clonedDoc.querySelector('link[data-grid-export-css]');
                        if (link) {
                            link.media = 'all';
                        }

                        // Ekrandan ölçülen sütun genişliklerini klona sabitle
                        // (bkz. yukarıdaki "ÖLÇÜ" notu). table-layout:fixed,
                        // genişliği ilk satırın hücrelerinden aldığı için
                        // sütunlar ekrandakiyle BİREBİR aynı kalıyor.
                        var clonedTable = clonedDoc.querySelector('table.grid');
                        if (!clonedTable) {
                            return;
                        }
                        clonedTable.style.tableLayout = 'fixed';
                        clonedTable.style.width = width + 'px';
                        clonedTable.style.minWidth = width + 'px';
                        clonedTable.style.maxWidth = width + 'px';

                        var wi = 0;
                        Array.prototype.forEach.call(clonedTable.querySelectorAll('thead th'), function (th) {
                            if (th.classList.contains('grid-add-field-th')) {
                                return;
                            }
                            th.style.width = colWidths[wi] + 'px';
                            wi++;
                        });
                    },
                });
            }).then(function (canvas) {
                if (!canvas || !canvas.width || !canvas.height) {
                    done();
                    window.alert('PNG oluşturulamadı: görünüm bir görüntüye sığmayacak kadar büyük. Excel indirmeyi deneyin.');
                    return;
                }
                canvas.toBlob(function (blob) {
                    done();
                    if (!blob) {
                        window.alert('PNG oluşturulamadı: görünüm bir görüntüye sığmayacak kadar büyük. Excel indirmeyi deneyin.');
                        return;
                    }
                    download(blob);
                }, 'image/png');
            }).catch(function () {
                done();
                window.alert('PNG oluşturulamadı.');
            });
        });
    });
})();
