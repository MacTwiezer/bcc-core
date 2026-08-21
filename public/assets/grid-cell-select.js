// Grid hücre seçimi — kopyalama (grid-copy.js) ve yapıştırmanın
// (grid-paste.js) ORTAK zemini: hangi hücreler seçili, çapa nerede.
//
// ⚠️ MEVCUT DÜZENLEME AKIŞI DEĞİŞMEDİ. Bu projede tek tıklama doğrudan
// düzenleyiciyi açar (grid.js startEdit) — Excel/Airtable'ın "tıkla=seç,
// çift tıkla=düzenle" modeline GEÇİLMEDİ, çünkü o, her kullanıcının günlük
// düzenleme alışkanlığını bozardı. Üstüne SEÇİM yetenekleri eklendi:
//   • normal tıklama       -> düzenleyici açılır (AYNEN) + hücre ÇAPA olur
//   • Shift+tıklama        -> düzenleyici AÇILMAZ, çapadan buraya dikdörtgen
//   • fareyle SÜRÜKLEME    -> dikdörtgen seçim (düzenleyici açılmaz)
//   • ok tuşları           -> hücreden hücreye geçiş
//   • Shift + ok           -> seçimi genişlet
//   • Ctrl/Cmd + A         -> tüm grid
//   • Delete / Backspace   -> seçili hücreleri temizle (grid-copy.js dinler)
//   • Escape               -> seçim temizlenir
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

        // Çapa: seçimin sabit köşesi (yapıştırma buradan başlar).
        // Odak: Shift/sürükleme/ok ile genişletilen karşı köşe.
        // İkisi aynıysa seçim tek hücreliktir.
        var anchorTd = null;
        var focusTd = null;

        // ---- Koordinat yardımcıları ---------------------------------------
        // Satır sırası DOM sırasıdır. GİZLİ satırlar (istemci tarafı arama/
        // filtre) atlanır — kullanıcı ekranda görmediği bir satıra
        // yapıştırdığını/kopyaladığını sanmasın.
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

        function cellAt(row, col) {
            var rows = visibleRows();
            if (row < 0 || row >= rows.length) {
                return null;
            }
            var cells = rowCells(rows[row]);
            if (col < 0 || col >= cells.length) {
                return null;
            }
            return cells[col];
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

        function currentRange() {
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
        }

        function paintSelection() {
            clearHighlight();
            var rg = currentRange();
            if (!rg) {
                return;
            }

            var rows = visibleRows();
            for (var r = rg.row1; r <= rg.row2; r++) {
                if (!rows[r]) { continue; }
                var cells = rowCells(rows[r]);
                for (var c = rg.col1; c <= rg.col2; c++) {
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

        // Klavyeyle gezerken hedef hücre görüş alanının dışına çıkabilir.
        // scrollIntoView({block:'nearest'}) sayfayı zıplatmadan yalnızca
        // gerekiyorsa kaydırır.
        function reveal(td) {
            if (td && td.scrollIntoView) {
                td.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        // ---- Düzenleyici açıkken klavye grid'e AİT DEĞİL --------------------
        // Bir hücre düzenleniyorsa ok tuşları metin imlecini oynatmalı, seçimi
        // değil. Aynı şekilde arama kutusu/modal gibi grid dışı bir alana
        // yazılıyorsa hiç karışılmaz.
        function keyboardBelongsToGrid() {
            var el = document.activeElement;
            if (!el) {
                return true;
            }
            if (el.closest && el.closest('td.editing')) {
                return false;
            }
            var tag = el.tagName ? el.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable) {
                return false;
            }
            // Açık bir modal varsa (yapıştırma onayı vb.) klavye oraya ait.
            var openModal = document.querySelector('.home-modal-backdrop:not([hidden])');
            return !openModal;
        }

        // ---- Olaylar: fare ---------------------------------------------------
        // YAKALAMA fazı: grid.js'in kabarma fazındaki dinleyicisinden ÖNCE
        // çalışır, böylece Shift+tıklamada düzenleyici hiç açılmaz.
        grid.addEventListener('click', function (e) {
            var td = e.target.closest('td.grid-cell');

            if (!td) {
                // Satır numarası, checkbox, başlık vb. — seçimi bozma.
                return;
            }

            // Sürükleme bitişinden gelen "click" düzenleyiciyi AÇMAMALI:
            // kullanıcı alan seçti, hücreye girmek istemedi.
            if (suppressNextClick) {
                suppressNextClick = false;
                e.preventDefault();
                e.stopPropagation();
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

        // ---- Fareyle sürükleyerek alan seçme --------------------------------
        // ⚠️ TIKLA-DÜZENLE İLE ÇAKIŞMAMALI. Bu yüzden mousedown'da HİÇBİR ŞEY
        // yapılmaz; yalnızca başlangıç kaydedilir. Ancak imleç EŞİĞİ (3px)
        // aşarsa "sürükleme" sayılır, seçim boyanır ve o hareketin sonundaki
        // click BASTIRILIR — yoksa kullanıcı alan seçtiğinde bir de düzenleyici
        // açılırdı. Eşik olmadan her minik titreme sürükleme sanılırdı.
        var DRAG_THRESHOLD = 3;
        var dragStartTd = null;
        var dragStartX = 0;
        var dragStartY = 0;
        var dragging = false;
        var suppressNextClick = false;

        grid.addEventListener('mousedown', function (e) {
            // Yalnızca sol tuş. Shift+tıklama kendi yolunda (click) işlenir.
            if (e.button !== 0 || e.shiftKey) {
                return;
            }
            var td = e.target.closest('td.grid-cell');
            if (!td) {
                return;
            }
            // Hücre içindeki gerçek bir kontrole (checkbox, yıldız, dosya eki
            // butonu) basılıyorsa sürükleme başlatma — o kontrol çalışsın.
            if (e.target.closest('input, button, a, select, textarea')) {
                return;
            }
            dragStartTd = td;
            dragStartX = e.clientX;
            dragStartY = e.clientY;
            dragging = false;
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragStartTd) {
                return;
            }
            if (!dragging) {
                if (Math.abs(e.clientX - dragStartX) < DRAG_THRESHOLD &&
                    Math.abs(e.clientY - dragStartY) < DRAG_THRESHOLD) {
                    return;
                }
                dragging = true;
                anchorTd = dragStartTd;
                focusTd = dragStartTd;
                // Sürüklerken tarayıcının metin seçimi devreye girmesin.
                document.body.style.userSelect = 'none';
            }

            var over = e.target && e.target.closest ? e.target.closest('td.grid-cell') : null;
            if (over && over !== focusTd) {
                focusTd = over;
                paintSelection();
            }
        });

        document.addEventListener('mouseup', function () {
            if (dragging) {
                suppressNextClick = true;
                document.body.style.userSelect = '';
                paintSelection();
            }
            dragStartTd = null;
            dragging = false;
        });

        // Grid DIŞINA tıklayınca seçim kalkar — kullanıcı başka bir işe geçti.
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.grid') && !e.target.closest('.home-modal')) {
                clearSelection();
            }
        });

        // ---- Olaylar: klavye -------------------------------------------------
        var ARROWS = {
            ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1],
            // Eski tarayıcı adları — tek haritada tutuluyor, ikinci bir dal yok.
            Up: [-1, 0], Down: [1, 0], Left: [0, -1], Right: [0, 1]
        };

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && anchorTd) {
                clearSelection();
                return;
            }

            if (!keyboardBelongsToGrid()) {
                return;
            }

            // Ctrl/Cmd + A: tüm grid. ⚠️ Yalnızca ZATEN bir seçim varken —
            // kullanıcı grid'le hiç etkileşmediyse Ctrl+A tarayıcının "tüm
            // sayfayı seç"i olarak kalmalı, sayfayı ele geçirmeyiz.
            if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
                if (!anchorTd) {
                    return;
                }
                var rows = visibleRows();
                if (!rows.length) {
                    return;
                }
                var lastCells = rowCells(rows[rows.length - 1]);
                if (!lastCells.length) {
                    return;
                }
                e.preventDefault();
                anchorTd = rowCells(rows[0])[0];
                focusTd = lastCells[lastCells.length - 1];
                paintSelection();
                return;
            }

            var delta = ARROWS[e.key];
            if (!delta || !anchorTd) {
                return;
            }

            // Genişletirken hareket eden köşe ODAK, sabit kalan ÇAPA'dır —
            // Excel'deki davranış. Genişletme yoksa ikisi birlikte taşınır.
            var moving = (e.shiftKey && focusTd) ? focusTd : (e.shiftKey ? anchorTd : (focusTd || anchorTd));
            var pos = cellCoords(moving);
            if (!pos) {
                return;
            }

            var next = cellAt(pos.row + delta[0], pos.col + delta[1]);
            if (!next) {
                // Kenara dayandı — olayı yine de tüketiyoruz ki sayfa kaymasın.
                e.preventDefault();
                return;
            }

            e.preventDefault();
            if (e.shiftKey) {
                focusTd = next;
            } else {
                anchorTd = next;
                focusTd = null;
            }
            paintSelection();
            reveal(next);
        });

        // ---- Kopyalama/yapıştırma için paylaşılan yüzey ----------------------
        // window.BCC_GRID ile AYNI desen: seçim mantığının ikinci bir kopyası
        // yazılmasın diye tek yerden dışa açılıyor.
        window.BCC_GRID_SELECT = {
            // Yapıştırmanın başlayacağı hücre (yoksa null).
            getAnchor: function () { return anchorTd; },
            // Seçili dikdörtgen: {row1,col1,row2,col2} ya da null.
            getRange: currentRange,
            // Kullanıcı aralık ÇİZDİ mi (Shift/sürükleme), yoksa tek hücre mi?
            hasRange: function () { return anchorTd !== null && focusTd !== null && focusTd !== anchorTd; },
            // Seçili dikdörtgendeki <td>'ler, satır satır. Kopyalama ve
            // "seçimi temizle" bunun üzerinden çalışır — koordinat matematiği
            // ikinci kez yazılmasın diye.
            getMatrix: function () {
                var rg = currentRange();
                if (!rg) {
                    return [];
                }
                var rows = visibleRows();
                var out = [];
                for (var r = rg.row1; r <= rg.row2; r++) {
                    if (!rows[r]) { continue; }
                    var cells = rowCells(rows[r]);
                    var line = [];
                    for (var c = rg.col1; c <= rg.col2; c++) {
                        if (cells[c]) { line.push(cells[c]); }
                    }
                    if (line.length) { out.push(line); }
                }
                return out;
            },
            visibleRows: visibleRows,
            rowCells: rowCells,
            cellAt: cellAt,
            clear: clearSelection,
            repaint: paintSelection,
            keyboardBelongsToGrid: keyboardBelongsToGrid
        };
    });
})();
