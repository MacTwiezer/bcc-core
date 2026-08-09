(function () {
    'use strict';

    // Sütun genişliğini başlık kenarından sürükleyerek ayarlama (Airtable
    // paritesi). Kalıcılık views.config.column_widths (api/view_config_update.php,
    // frozen_column_count ile AYNI oku-değiştir-yaz mekanizması).
    //
    // SÜRÜKLEME İSKELETİ YENİDEN KULLANILIYOR: mousedown/mousemove(rAF)/mouseup
    // deseni bcc_bindColumnDrag()'de (assets/grid-column-drag.js) — kanban kartı,
    // görünüm listesi sıralaması ve sütun DONDURMA da onu kullanıyor. Burada
    // yalnızca "sürüklerken ne hesaplanır / bırakınca ne kaydedilir" var.
    //
    // DONDURMA TUTAMACIYLA KARIŞTIRILMAMASI: .grid-freeze-handle (12px şerit,
    // cursor:grab, MAVİ çizgi, tabloda TEK tane — donmuş kenarda) dondurulan
    // sütun SAYISINI ayarlıyor. Bu dosyanın tutamacı .grid-col-resize-handle
    // (7px şerit, cursor:col-resize, GRİ çizgi, HER başlıkta) genişliği
    // ayarlıyor. Donmuş kenar sütununda ikisi yan yana gelir; CSS orada
    // boyutlandırma tutamacını 12px sola kaydırıp z-index'ini altta tutuyor
    // (bkz. style.css .grid-frozen-edge .grid-col-resize-handle) — üst üste
    // binmiyorlar. grid-freeze-columns.js'e HİÇ DOKUNULMADI.

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.querySelector('table.grid');
        if (!table || !window.BCC_CAN_EDIT || !window.bcc_bindColumnDrag) {
            return;
        }

        var viewId = window.BCC_VIEW_ID || '';
        if (!viewId) {
            return;
        }

        var MIN_WIDTH = parseInt(window.BCC_MIN_COLUMN_WIDTH, 10) || 80;
        var MAX_WIDTH = parseInt(window.BCC_MAX_COLUMN_WIDTH, 10) || 800;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        function headerCells() {
            var row = table.querySelector('thead tr');
            return row ? Array.prototype.slice.call(row.children) : [];
        }

        // Sürüklenebilir sütunlar: satır no ve "+" (yeni alan) HARİÇ.
        // "+" ikon genişliğinde sabit; satır no ise kullanıcıya ait bir alan
        // değil (genişliği yine de KAYDEDİLİYOR, çünkü fixed layout'ta her
        // sütunun bir değeri olmak zorunda — ama sürüklenmiyor).
        function isResizable(th) {
            return !!th.getAttribute('data-col-key');
        }

        // ---- Sabit yerleşime geçiş (idempotent) -----------------------------
        // Tablo kaydedilmiş genişlik olmadan açıldıysa hâlâ otomatik
        // yerleşimdedir (`width:auto; min-width:100%`). İLK sürüklemede o anki
        // TÜM sütun genişlikleri EKRANDAN ölçülüp <colgroup>'a yazılır ve
        // table-layout:fixed'e geçilir — böylece geçiş görsel olarak dikişsiz
        // olur (kullanıcı yalnızca çektiği sütunun değiştiğini görür), sonraki
        // hareketler de birebir piksel olur.
        function ensureFixedLayout() {
            if (table.classList.contains('grid-has-col-widths')) {
                return;
            }

            var heads = headerCells();
            var widths = heads.map(function (th) {
                return Math.round(th.getBoundingClientRect().width);
            });

            var colgroup = document.createElement('colgroup');
            var total = 0;
            heads.forEach(function (th, i) {
                var col = document.createElement('col');
                var key = th.getAttribute('data-col-key');
                if (key) {
                    col.setAttribute('data-col-key', key);
                }
                col.style.width = widths[i] + 'px';
                total += widths[i];
                colgroup.appendChild(col);
            });

            table.insertBefore(colgroup, table.firstChild);
            table.classList.add('grid-has-col-widths');
            table.style.width = total + 'px';
        }

        function colFor(th) {
            var key = th.getAttribute('data-col-key');
            if (!key) {
                return null;
            }
            return table.querySelector('colgroup > col[data-col-key="' + key + '"]');
        }

        function syncTableWidth() {
            var total = 0;
            Array.prototype.forEach.call(table.querySelectorAll('colgroup > col'), function (col) {
                total += parseInt(col.style.width, 10) || 0;
            });
            table.style.width = total + 'px';
        }

        // ---- Kaydetme -------------------------------------------------------
        function currentWidthMap() {
            var map = {};
            var cols = table.querySelectorAll('colgroup > col');
            var heads = headerCells();

            Array.prototype.forEach.call(cols, function (col, i) {
                var width = parseInt(col.style.width, 10);
                if (!width) {
                    return;
                }
                var th = heads[i];
                if (!th) {
                    return;
                }
                if (th.classList.contains('grid-rownum')) {
                    map.row = width;
                    return;
                }
                var key = th.getAttribute('data-col-key');
                if (key) {
                    map[key] = width;
                }
                // "+" (grid-add-field-th) BİLEREK atlanıyor: sunucu ona her
                // zaman sabit 40px veriyor, config'te yer kaplamasın.
            });

            return map;
        }

        function persist() {
            fetch('/api/view_config_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    view_id: viewId,
                    // frozen_column_count BİLEREK GÖNDERİLMİYOR — uç nokta artık
                    // yalnızca gönderilen anahtarı yazıyor, yani dondurma ayarı
                    // bu istekten etkilenmiyor.
                    column_widths: JSON.stringify(currentWidthMap()),
                }).toString(),
            }).catch(function () {
                // Sessiz başarısızlık: genişlik bu oturumda görsel olarak
                // uygulanmış kalır, yalnızca kalıcı olmaz (F5'te eskiye döner).
                // grid-freeze-columns.js'in persistFrozenCount()'u ile AYNI karar.
            });
        }

        // ---- Tutamaçlar -----------------------------------------------------
        headerCells().forEach(function (th) {
            if (!isResizable(th)) {
                return;
            }

            var handle = document.createElement('div');
            handle.className = 'grid-col-resize-handle';
            handle.setAttribute('data-tooltip-host', '');
            handle.setAttribute('tabindex', '-1');
            var tip = document.createElement('span');
            tip.className = 'gs-kbd-tooltip';
            tip.textContent = 'Sütun genişliğini ayarlamak için sürükleyin';
            handle.appendChild(tip);
            th.appendChild(handle);

            var startX = 0;
            var startWidth = 0;
            var col = null;

            window.bcc_bindColumnDrag(handle, {
                onStart: function (e) {
                    // Sürükleme BAŞLARKEN sabit yerleşime geçilir; ölçüm hâlâ
                    // otomatik yerleşimin ürettiği gerçek genişliklerden alınır.
                    ensureFixedLayout();
                    col = colFor(th);
                    startX = e.clientX;
                    startWidth = Math.round(th.getBoundingClientRect().width);
                },
                onMove: function (clientX) {
                    if (!col) {
                        return;
                    }
                    var next = startWidth + (clientX - startX);
                    if (next < MIN_WIDTH) {
                        next = MIN_WIDTH;
                    }
                    if (next > MAX_WIDTH) {
                        next = MAX_WIDTH;
                    }
                    col.style.width = next + 'px';
                    syncTableWidth();

                    // Dondurulmuş sütunların sticky `left` ofsetleri sütun
                    // genişliklerinin TOPLAMINDAN hesaplanıyor — genişlik
                    // değişince yeniden hesaplanmazsa donuk sütunlar kayardı.
                    // grid-freeze-columns.js'in kendi açtığı kanca kullanılıyor,
                    // ikinci bir hesaplama YAZILMADI.
                    if (window.BCC_reapplyFreeze) {
                        window.BCC_reapplyFreeze();
                    }
                },
                onEnd: function () {
                    if (!col) {
                        return;
                    }
                    col = null;
                    persist();
                },
            });
        });
    });
})();
