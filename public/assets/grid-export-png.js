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

            var devamSozu = (rowCount > ROW_WARN_THRESHOLD || height > HEIGHT_WARN_THRESHOLD)
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

                var scale = rowCount > 200 ? 1 : 2;
                var longestEdge = Math.max(width, height);
                if (longestEdge * scale > MAX_CANVAS_EDGE) {
                    scale = Math.max(1, Math.floor(MAX_CANVAS_EDGE / longestEdge));
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
