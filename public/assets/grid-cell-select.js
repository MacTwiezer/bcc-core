// Grid hücre seçimi — pano yapıştırmasının (Aşama 2) ÇAPASINI belirler ve
// nereye yapıştırılacağını GÖRSEL olarak gösterir.
//
// ⚠️ MEVCUT DÜZENLEME AKIŞI DEĞİŞMEDİ. Bu projede tek tıklama doğrudan
// düzenleyiciyi açar (grid.js startEdit) — Excel/Airtable'ın "tıkla=seç,
// çift tıkla=düzenle" modeline GEÇİLMEDİ, çünkü o, her kullanıcının günlük
// düzenleme alışkanlığını bozardı. Bunun yerine:
//   • normal tıklama  -> düzenleyici açılır (AYNEN) + hücre ÇAPA olur
//   • Shift+tıklama   -> düzenleyici AÇILMAZ, çapadan buraya dikdörtgen seçilir
//   • Escape          -> seçim temizlenir
//
// Shift+tıklamayı YAKALAMA (capture) fazında kesiyoruz: grid.js'in kendi
// dinleyicisi kabarma (bubble) fazında ve .grid tablosuna bağlı, bu yüzden
// stopPropagation() ile ona hiç ulaşmadan durduruluyor. grid.js'e DOKUNULMADI.
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        if (!grid) {
            return;
        }

        // Çapa: yapıştırmanın başlayacağı hücre. Odak: Shift+tıklamayla
        // genişletilen köşe. İkisi aynıysa seçim tek hücreliktir.
        var anchorTd = null;
        var focusTd = null;

        // ---- Koordinat yardımcıları ---------------------------------------
        // Satır sırası DOM sırasıdır. GİZLİ satırlar (istemci tarafı arama/
        // filtre) atlanır — kullanıcı ekranda görmediği bir satıra
        // yapıştırdığını sanmasın.
        function visibleRows() {
            return Array.prototype.filter.call(
                grid.querySelectorAll('tr[data-record-id]'),
                function (tr) { return tr.offsetParent !== null; }
            );
        }

        // Bir satırdaki veri hücreleri (.grid-rownum HARİÇ). Sütun indeksi
        // buradaki sıradır; gizli alanlar zaten DOM'da yoktur.
        function rowCells(tr) {
            return Array.prototype.slice.call(tr.querySelectorAll('td.grid-cell'));
        }

        function cellCoords(td) {
            var tr = td.closest('tr[data-record-id]');
            if (!tr) {
                return null;
            }
            var rows = visibleRows();
            var r = rows.indexOf(tr);
            var c = rowCells(tr).indexOf(td);
            if (r === -1 || c === -1) {
                return null;
            }
            return { row: r, col: c };
        }

        // ---- Görsel ---------------------------------------------------------
        function clearHighlight() {
            Array.prototype.forEach.call(
                grid.querySelectorAll('.is-paste-anchor, .is-paste-range'),
                function (td) {
                    td.classList.remove('is-paste-anchor', 'is-paste-range');
                }
            );
        }

        function paintSelection() {
            clearHighlight();
            if (!anchorTd) {
                return;
            }

            var a = cellCoords(anchorTd);
            var f = focusTd ? cellCoords(focusTd) : a;
            if (!a || !f) {
                return;
            }

            var r1 = Math.min(a.row, f.row), r2 = Math.max(a.row, f.row);
            var c1 = Math.min(a.col, f.col), c2 = Math.max(a.col, f.col);
            var rows = visibleRows();

            for (var r = r1; r <= r2; r++) {
                var cells = rowCells(rows[r]);
                for (var c = c1; c <= c2; c++) {
                    if (cells[c]) {
                        cells[c].classList.add('is-paste-range');
                    }
                }
            }
            // Çapa ayrıca kesik çizgiyle işaretlenir — "yapıştırma buradan
            // başlayacak" bilgisi seçim alanından AYRI bir sinyal.
            anchorTd.classList.add('is-paste-anchor');
        }

        function clearSelection() {
            anchorTd = null;
            focusTd = null;
            clearHighlight();
        }

        // ---- Olaylar --------------------------------------------------------
        // YAKALAMA fazı: grid.js'in kabarma fazındaki dinleyicisinden ÖNCE
        // çalışır, böylece Shift+tıklamada düzenleyici hiç açılmaz.
        grid.addEventListener('click', function (e) {
            var td = e.target.closest('td.grid-cell');

            if (!td) {
                // Satır numarası, checkbox, başlık vb. — seçimi bozma.
                return;
            }

            if (e.shiftKey && anchorTd) {
                // Aralığı genişlet. preventDefault: Shift+tıklama tarayıcının
                // metin seçimini başlatır, hücreler mavi boyanırdı.
                e.preventDefault();
                e.stopPropagation();
                focusTd = td;
                paintSelection();
                return;
            }

            // Normal tıklama: grid.js düzenleyiciyi açmaya devam eder
            // (stopPropagation YOK), biz sadece çapayı kaydederiz.
            anchorTd = td;
            focusTd = null;
            paintSelection();
        }, true);

        // Grid DIŞINA tıklayınca seçim kalkar — kullanıcı başka bir işe geçti.
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.grid') && !e.target.closest('.home-modal')) {
                clearSelection();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && anchorTd) {
                clearSelection();
            }
        });

        // ---- Aşama 2 (yapıştırma) için paylaşılan yüzey ----------------------
        // window.BCC_GRID ile AYNI desen: seçim mantığının ikinci bir kopyası
        // yazılmasın diye tek yerden dışa açılıyor.
        window.BCC_GRID_SELECT = {
            // Yapıştırmanın başlayacağı hücre (yoksa null).
            getAnchor: function () { return anchorTd; },
            // Seçili dikdörtgen: {row1,col1,row2,col2} ya da null.
            getRange: function () {
                if (!anchorTd) {
                    return null;
                }
                var a = cellCoords(anchorTd);
                var f = focusTd ? cellCoords(focusTd) : a;
                if (!a || !f) {
                    return null;
                }
                return {
                    row1: Math.min(a.row, f.row), row2: Math.max(a.row, f.row),
                    col1: Math.min(a.col, f.col), col2: Math.max(a.col, f.col)
                };
            },
            // Kullanıcı aralık ÇİZDİ mi (Shift+tıklama), yoksa tek hücre mi?
            hasRange: function () { return anchorTd !== null && focusTd !== null; },
            visibleRows: visibleRows,
            rowCells: rowCells,
            clear: clearSelection,
            repaint: paintSelection
        };
    });
})();
