(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var item = document.getElementById('gs-view-download-png-item');
        if (!item) {
            return;
        }

        var ROW_WARN_THRESHOLD = 500;
        var HEIGHT_WARN_THRESHOLD = 12000;
        var MAX_CANVAS_EDGE = 16000;

        /* 2026-09-16: donma duzeltmesi. Esikler satir sayisina bakiyordu
           (scale = rowCount > 200 ? 1 : 2), ama isi belirleyen satir degil
           PIKSEL ALANI: 150 satir x 12 alan = ~2200x5000 CSS pikseli, scale 2
           ile 44 milyon pikselik canvas (~176 MB) demek. 12 alanli 150 satirlik
           gercek tabloda sekme "Sayfa Yanit Vermiyor"a dustu. Artik hem olcek
           hem uyari alandan hesaplaniyor. */
        var MAX_CANVAS_AREA = 25000000;
        var AREA_WARN_THRESHOLD = 8000000;

        /* MAX_CANVAS_AREA yalnizca OLCEK secer (2 mi 1 mi). Tarayicinin gercek
           siniri cok daha yukarida; oraya kadar yavas ama CALISAN bir disa
           aktarmayi reddetmek yanlis olurdu. Sert sinir ayri tutuluyor. */
        var HARD_MAX_AREA = 80000000;

        var loadPromise = null;

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
                    loadPromise = null;
                    reject(new Error('yüklenemedi'));
                };
                document.head.appendChild(script);
            });
            return loadPromise;
        }

        function fileNameBase() {
            var name = (window.BCC_TABLE_NAME || '').replace(/[^a-zA-Z0-9_-]+/g, '_');
            return name !== '' ? name : 'grid';
        }

        function download(blob, ext) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = fileNameBase() + (ext || '.png');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
        }

        /* 2026-09-16, donmanin ASIL sebebi. Uzun metin hucreleri ekranda tek
           satira kirpilir (ellipsis) ama html2canvas hucrenin metnini TAMAMEN
           olcer: her kelime icin Range.getBoundingClientRect(). 150 satirlik
           gercek tabloda iki uzun metin sutunu ~300 bin karakter ediyor ve
           olcum bitmiyor — sekme "Sayfa Yanit Vermiyor"a dusuyor. Olculdu:
           ayni tablo, ayni olcek — kisaltmasiz >100 sn (tarayici oldu),
           kisaltmayla 3,7 sn. Cizilen goruntu birebir ayni, cunku kesilen kisim
           zaten kirpilip gorunmeyen kisim.

           Butce: sutun genisligi / 4 (en dar yazi tipinde bile 4 px'ten ince
           karakter yok) x gorunur satir sayisi, ustune 40 karakter pay. */
        function kisaltGizliMetin(clonedDoc, clonedTable, colWidths, rowHeight) {
            if (!clonedDoc.createTreeWalker) {
                return;
            }

            var satirSayisi = Math.max(1, Math.round(rowHeight / 16));

            Array.prototype.forEach.call(clonedTable.querySelectorAll('tbody tr > td'), function (cell) {
                var metin = cell.textContent || '';
                if (metin.length < 400) {
                    return;
                }

                var sutunGenisligi = colWidths[cell.cellIndex] || 180;
                var butce = Math.ceil(sutunGenisligi / 4) * satirSayisi + 40;
                if (metin.length <= butce) {
                    return;
                }

                /* 4 = NodeFilter.SHOW_TEXT. Sabiti dogrudan yazmak klon
                   penceresinin NodeFilter'ina bagimli olmamak icin. */
                var yurutec = clonedDoc.createTreeWalker(cell, 4, null, false);
                var dugumler = [];
                while (yurutec.nextNode()) {
                    dugumler.push(yurutec.currentNode);
                }

                var kalan = butce;
                for (var di = 0; di < dugumler.length; di++) {
                    var dugum = dugumler[di];
                    if (kalan <= 0) {
                        dugum.nodeValue = '';
                    } else if (dugum.nodeValue.length > kalan) {
                        dugum.nodeValue = dugum.nodeValue.slice(0, kalan) + '…';
                        kalan = 0;
                    } else {
                        kalan -= dugum.nodeValue.length;
                    }
                }
            });
        }

        function captureCanvas(label) {
            var table = document.querySelector('table.grid');
            if (!table) {
                window.alert('Bu tabloda henüz alan yok, ' + label + ' oluşturulamıyor.');
                return Promise.resolve(null);
            }

            var rowCount = table.querySelectorAll('tbody tr[data-record-id]').length;

            var addFieldTh = table.querySelector('thead th.grid-add-field-th');
            var addRow = table.querySelector('tr.grid-add-row');

            var colWidths = [];
            Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (th) {
                if (th === addFieldTh) {
                    return;
                }
                colWidths.push(th.offsetWidth);
            });

            var width = 0;
            for (var ci = 0; ci < colWidths.length; ci++) { width += colWidths[ci]; }
            var height = Math.ceil(table.scrollHeight - (addRow ? addRow.offsetHeight : 0));

            var firstRow = table.querySelector('tbody tr[data-record-id]');
            var rowHeight = (firstRow && firstRow.offsetHeight) ? firstRow.offsetHeight : 32;

            /* Olcek 1'de bile tarayicinin canvas sinirini asiyorsa cizime hic
               baslama: html2canvas once 1800 hucreyi kopya pencerede yeniden
               yerlestirir, dakikalarca ana is parcacigini kilitler ve sonunda
               bos canvas doner. Kullaniciya daha en basta soylemek dogru. */
            if (Math.max(width, height) > MAX_CANVAS_EDGE || width * height > HARD_MAX_AREA) {
                window.alert('Bu görünüm bir görüntüye sığmayacak kadar büyük, '
                    + label + ' oluşturulamıyor. Excel indirmeyi deneyin.');
                return Promise.resolve(null);
            }

            var devamSozu = (rowCount > ROW_WARN_THRESHOLD
                || height > HEIGHT_WARN_THRESHOLD
                || width * height > AREA_WARN_THRESHOLD)
                ? window.bcc_confirm({
                    title: label + ' oluştur',
                    message: 'Bu görünüm büyük, ' + label + ' yavaş/okunmayabilir. Excel önerilir. Devam edilsin mi?',
                    confirmLabel: 'Devam et',
                    danger: false,
                })
                : Promise.resolve(true);

            return devamSozu.then(function (devam) {
                if (!devam) {
                    return null;
                }

                /* Keskinlik icin scale 2 istenir; alan ya da kenar tavanini
                   asiyorsa 1'e dusulur. Eski kod burada
                   Math.floor(MAX_CANVAS_EDGE / longestEdge) hesapliyordu —
                   kenar 16000'i gectiginde bu 0 veriyor, Math.max(1, 0) ile
                   yine 1'de kaliyordu: yani tavan aslinda hic korumuyordu. */
                var scale = 2;
                var longestEdge = Math.max(width, height);
                if (longestEdge * scale > MAX_CANVAS_EDGE
                    || width * height * scale * scale > MAX_CANVAS_AREA) {
                    scale = 1;
                }

                return loadHtml2Canvas().then(function (html2canvas) {
                    return html2canvas(table, {
                        backgroundColor: '#ffffff',
                        scale: scale,
                        logging: false,
                        width: width,
                        height: height,
                        windowWidth: Math.max(document.documentElement.clientWidth, width + 100),
                        windowHeight: Math.max(document.documentElement.clientHeight, height + 100),
                        onclone: function (clonedDoc) {
                            clonedDoc.documentElement.style.setProperty('--bcc-zoom', '1');

                            var link = clonedDoc.querySelector('link[data-grid-export-css]');
                            if (link) {
                                link.media = 'all';
                            }

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

                            kisaltGizliMetin(clonedDoc, clonedTable, colWidths, rowHeight);
                        },
                    });
                });
            });
        }

        function busy(el, on) {
            if (el) { el.disabled = !!on; }
            document.body.style.cursor = on ? 'progress' : '';
        }

        item.addEventListener('click', function () {
            var menu = document.querySelector('.gs-view-options-menu');
            if (menu) {
                menu.removeAttribute('open');
            }

            busy(item, true);
            captureCanvas('PNG').then(function (canvas) {
                if (canvas === null) {
                    busy(item, false);
                    return;
                }
                if (!canvas.width || !canvas.height) {
                    busy(item, false);
                    window.alert('PNG oluşturulamadı: görünüm bir görüntüye sığmayacak kadar büyük. Excel indirmeyi deneyin.');
                    return;
                }
                canvas.toBlob(function (blob) {
                    busy(item, false);
                    if (!blob) {
                        window.alert('PNG oluşturulamadı: görünüm bir görüntüye sığmayacak kadar büyük. Excel indirmeyi deneyin.');
                        return;
                    }
                    download(blob, '.png');
                }, 'image/png');
            }).catch(function () {
                busy(item, false);
                window.alert('PNG oluşturulamadı.');
            });
        });

        window.BCC_GRID_EXPORT = {
            captureCanvas: captureCanvas,
            download: download,
            busy: busy,
        };
    });
})();
