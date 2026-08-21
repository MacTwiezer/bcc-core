(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.querySelector('table.grid');
        if (!table) {
            return;
        }

        // Satır no kolonu (.grid-rownum) zaten her zaman sticky/left:0 (style.css) —
        // buraya hiç dokunulmuyor. frozenCount, satır no dahil TOPLAM dondurulmuş
        // kolon sayısıdır; index 0 (rownum) bu yüzden aşağıdaki döngüde hep atlanır,
        // yalnızca index >= 1 için .grid-frozen-cell eklenir/kaldırılır.
        var frozenCount = Math.max(1, parseInt(window.BCC_FROZEN_COLUMN_COUNT, 10) || 1);
        var maxFrozen = Math.max(1, parseInt(window.BCC_MAX_FROZEN_COLUMNS, 10) || 1);
        var viewId = window.BCC_VIEW_ID || '';
        var canEdit = !!window.BCC_CAN_EDIT;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        function headerCells() {
            var row = table.querySelector('thead tr');
            return row ? Array.prototype.slice.call(row.children) : [];
        }

        // Grup başlığı satırları (colspan'lı, tüm genişliği kaplar) KASITLI OLARAK
        // hariç tutulur — dondurma onlarla çakışmaz, tam genişlikte kalırlar.
        function bodyRows() {
            return Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-record-id], tbody tr.grid-add-row'));
        }

        var handle = null;
        if (canEdit) {
            handle = document.createElement('div');
            handle.className = 'grid-freeze-handle';
            handle.setAttribute('data-tooltip-host', '');
            handle.setAttribute('tabindex', '-1');
            var tip = document.createElement('span');
            tip.className = 'gs-kbd-tooltip';
            tip.textContent = 'Dondurulan sütun sayısını ayarlamak için sürükleyin';
            handle.appendChild(tip);
        }

        function applyFreeze() {
            var heads = headerCells();
            var offsets = [];
            var acc = 0;

            heads.forEach(function (cell, idx) {
                offsets[idx] = acc;
                acc += cell.offsetWidth;
            });

            function styleCell(cell, idx) {
                if (idx === 0) {
                    return; // .grid-rownum — statik CSS zaten hallediyor, dokunma
                }

                if (idx < frozenCount) {
                    cell.style.left = offsets[idx] + 'px';
                    cell.classList.add('grid-frozen-cell');
                    cell.classList.toggle('grid-frozen-edge', idx === frozenCount - 1);
                } else {
                    cell.style.left = '';
                    cell.classList.remove('grid-frozen-cell', 'grid-frozen-edge');
                }
            }

            heads.forEach(styleCell);
            bodyRows().forEach(function (tr) {
                // ⚠️ styleCell hücreyi DOM INDEX'İNE göre sütuna eşler; bu eşleme
                // yalnızca satırdaki her hücre TEK sütun kaplarken doğrudur.
                // colspan'lı bir hücreden SONRA index↔sütun bağı kopar.
                //
                // Bulunan gerçek bug: bodyRows() "tr.grid-add-row"u da kapsıyor
                // ve o satır [satır no][colspan'lı ipucu][boşluk] şeklinde. İpucu
                // hücresi index 1 olduğu için "1. veri sütunu" sanılıp
                // .grid-frozen-cell + left:44px alıyordu; .grid-frozen-cell
                // position:sticky verdiğinden bant sağa kayıp tablonun kendi
                // genişliğini aşıyordu (ölçüm: left=88 olması gerekirken 44,
                // 88+676=764 > tablo 760 → yatay kaydırma çubuğu).
                // Grup başlığı satırları bodyRows()'ta zaten hariç; bu koruma
                // colspan kullanan HER satır için genel çözüm.
                var cells = tr.children;
                for (var i = 0; i < cells.length; i++) {
                    if (cells[i].colSpan > 1) {
                        // Eski bir çalıştırmadan kalmış olabilecek durumu temizle
                        // ve satırın geri kalanına DOKUNMA — index artık güvenilmez.
                        cells[i].style.left = '';
                        cells[i].classList.remove('grid-frozen-cell', 'grid-frozen-edge');
                        break;
                    }
                    styleCell(cells[i], i);
                }
            });

            // Satır no sütununun sağ gölgesi (style.css) donuk grubun SONUNU
            // işaretler. Bir VERİ sütunu da donduğunda grup satır no'da bitmiyor;
            // gölge grubun ORTASINDA kalıp sahte bir ayraç gibi görünüyor. O
            // durumda gölgeyi CSS kapatır, sınırı .grid-frozen-edge çizgisi taşır.
            table.classList.toggle('grid-has-frozen-data', frozenCount > 1 && heads.length > 1);

            if (handle) {
                var edgeIdx = Math.min(frozenCount - 1, heads.length - 1);
                var edgeCell = heads[edgeIdx];
                if (edgeCell && edgeCell !== handle.parentNode) {
                    edgeCell.style.position = edgeCell.style.position || 'sticky';
                    edgeCell.appendChild(handle);
                }
            }

            // Sütun genişliği şeritleri (grid-column-resize.js) tablonun DIŞINDA,
            // mutlak konumlu bir katmanda duruyor: donmuş sınır değiştiğinde
            // yalnızca SINIFLAR değişiyor (boyut değil), yani o dosyanın
            // ResizeObserver'ı bunu göremez — hangi şeridin pinlendiğini ve
            // hangisinin donmuş grubun altında kaldığını yeniden hesaplaması için
            // kendi açtığı kanca çağrılıyor (BCC_reapplyFreeze'in simetriği).
            if (window.BCC_relayoutColumnResize) {
                window.BCC_relayoutColumnResize();
            }
        }

        // grid.js (kayıt ekleme) yeni bir satır DOM'a eklediğinde bu satırın da
        // dondurma stilini alması için çağırır — ikinci bir mekanizma yazılmaz.
        window.BCC_reapplyFreeze = applyFreeze;

        applyFreeze();
        window.addEventListener('resize', applyFreeze);

        if (!handle || !viewId) {
            return;
        }

        function computeFrozenCountForX(clientX) {
            var rect = table.getBoundingClientRect();
            var x = clientX - rect.left;
            var heads = headerCells();
            var acc = 0;
            var count = 1;

            for (var i = 0; i < heads.length; i++) {
                acc += heads[i].offsetWidth;
                if (x >= acc) {
                    count = i + 1;
                }
            }

            if (count < 1) {
                count = 1;
            }
            if (count > maxFrozen) {
                count = maxFrozen;
            }

            return count;
        }

        function persistFrozenCount(count) {
            var stateQueryString = window.location.search.replace(/^\?/, '');

            fetch('/api/view_config_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    view_id: viewId,
                    frozen_column_count: count,
                    state_query_string: stateQueryString,
                }).toString(),
            }).catch(function () {
                // Sessiz başarısızlık: dondurma bu oturumda görsel olarak uygulanmış
                // kalır, yalnızca kalıcı olmaz (F5'te eski değere dönebilir).
            });
        }

        // mousedown/mousemove(rAF throttle)/mouseup/mouseleave iskeleti
        // assets/grid-column-drag.js'de — burada yalnızca "sürüklerken ne hesaplanır /
        // bırakınca ne kaydedilir" var.
        window.bcc_bindColumnDrag(handle, {
            onMove: function (clientX) {
                var newCount = computeFrozenCountForX(clientX);
                if (newCount !== frozenCount) {
                    frozenCount = newCount;
                    applyFreeze();
                }
            },
            onEnd: function () {
                persistFrozenCount(frozenCount);
            },
        });
    });
})();
