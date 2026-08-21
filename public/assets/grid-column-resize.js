(function () {
    'use strict';

    // Sütun genişliğini SÜTUN AYIRAÇ ÇİZGİSİNDEN sürükleyerek ayarlama (OpsFlow
    // davranışı). Kalıcılık views.config.column_widths (api/view_config_update.php,
    // frozen_column_count ile AYNI oku-değiştir-yaz mekanizması) + localStorage
    // yedeği (aşağıda).
    //
    // TUTAMAÇ ARTIK BAŞLIĞIN İÇİNDE DEĞİL — SÜTUNUN TAM BOYUNDA:
    // Önceki sürümde her <th>'ye mutlak konumlu 7px'lik bir şerit ekleniyordu,
    // yani boyutlandırma yalnızca BAŞLIK SATIRI yüksekliğinde (~32px) yakalanıyor,
    // gövde hücrelerinin arasındaki aynı çizgi ölü bölgeydi. Şerit artık tablonun
    // DIŞINDA, .grid-wrap'in içinde duran tek bir katmanda (.grid-col-resize-layer)
    // yaşıyor ve tablonun TAM YÜKSEKLİĞİ kadar uzuyor — başlık da, altındaki bütün
    // veri hücreleri de aynı ayıracı paylaşıyor ve her yerde cursor:col-resize
    // gösteriyor.
    //
    // NEDEN <td>'lere tek tek tutamaç EKLENMEDİ: satır × sütun kadar DOM düğümü
    // demekti (500 satır × 12 sütun = 6000 şerit) ve sabit genişlik modunda
    // `td { overflow: hidden }` (style.css) hücreden taşan şeridi zaten
    // kırpardı. Sütun başına TEK şerit hem ucuz hem de kırpılmıyor.
    //
    // KATMANIN KONUMLANDIRILMASI: .grid-wrap position:relative (style.css) ve
    // katman onun mutlak konumlu çocuğu — yani şeritler tablonun kendi
    // koordinatlarında duruyor ve .grid-wrap kaydırıldığında İÇERİKLE BİRLİKTE
    // kayıyorlar. Yatay kaydırmada dondurulmuş sütunların ALTINA giren şeritler
    // gizleniyor (bkz. layout()), donmuş sütunların KENDİ şeritleri ise sabit
    // hücreyle birlikte pinlenmiş konumda kalıyor.
    //
    // SÜRÜKLEME İSKELETİ YENİDEN KULLANILIYOR: mousedown/mousemove(rAF)/mouseup
    // deseni bcc_bindColumnDrag()'de (assets/grid-column-drag.js) — kanban kartı,
    // görünüm listesi sıralaması ve sütun DONDURMA da onu kullanıyor. Burada
    // yalnızca "sürüklerken ne hesaplanır / bırakınca ne kaydedilir" var. İskelet
    // yalnızca clientX'i okuyor; dikey hareket HİÇBİR ŞEYİ değiştirmiyor, yani
    // etkileşim tanımı gereği tek eksenli (yatay).
    //
    // DONDURMA TUTAMACIYLA KARIŞTIRILMAMASI: .grid-freeze-handle (12px şerit,
    // cursor:grab, MAVİ çizgi, tabloda TEK tane — donmuş kenarda) dondurulan
    // sütun SAYISINI ayarlıyor. Bu dosyanın şeridi .grid-col-resize-handle
    // (9px, cursor:col-resize, GRİ çizgi, HER sütunda) genişliği ayarlıyor.
    // Donmuş kenar sütununda ikisi aynı çizgide buluşuyor; boyutlandırma şeridi
    // orada 12px sola kaydırılıyor (FREEZE_CLEARANCE) — üst üste binmiyorlar.
    // grid-freeze-columns.js'e yalnızca "yerleşimi tazele" kancası eklendi.
    //
    // TUTAMAÇTA ARTIK .gs-kbd-tooltip YOK: ipucu balonu `bottom: 100%` ile
    // host'un ÜSTÜNE açılıyor (grid-shell.css) — tablo boyunda bir şeritte bu,
    // balonu .grid-wrap'in üst kenarının dışına, kırpılan bölgeye atardı; üstelik
    // gövde hücrelerinin üzerinde gezinirken sürekli açılırdı. Tam boy
    // col-resize imleci + hover'da beliren ayıraç çizgisi zaten daha güçlü bir
    // ipucu. Dondurma tutamacının balonuna DOKUNULMADI.

    document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.querySelector('.grid-wrap');
        var table = wrap ? wrap.querySelector('table.grid') : null;
        if (!table || !window.bcc_bindColumnDrag) {
            return;
        }

        var viewId = window.BCC_VIEW_ID || '';
        var canEdit = !!window.BCC_CAN_EDIT;
        var MIN_WIDTH = parseInt(window.BCC_MIN_COLUMN_WIDTH, 10) || 80;
        var MAX_WIDTH = parseInt(window.BCC_MAX_COLUMN_WIDTH, 10) || 800;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        var STRIP_WIDTH = 9;      // .grid-col-resize-handle genişliği (style.css ile AYNI)
        var FREEZE_CLEARANCE = 12; // donmuş kenarda dondurma tutamacına yer aç

        // Görünüm başına ayrı anahtar: aynı tablonun iki görünümü farklı
        // genişliklere sahip olabilir (sunucu tarafı da view_id bazlı).
        var STORAGE_KEY = viewId ? 'bcc.grid.column_widths.v' + viewId : '';

        function headerCells() {
            var row = table.querySelector('thead tr');
            return row ? Array.prototype.slice.call(row.children) : [];
        }

        // Sürüklenebilir sütunlar: satır no ve "+" (yeni alan) HARİÇ.
        // "+" ikon genişliğinde sabit; satır no ise kullanıcıya ait bir alan
        // değil — genişliği yine de KAYDEDİLİYOR (fixed layout'ta her sütunun
        // bir değeri olmak zorunda) ama sürüklenmiyor: sunucu 'row'u da
        // MIN_WIDTH'e (80px) kırpıyor, oysa satır no sütunu ~50px bir cetvel.
        function isResizable(th) {
            return !!th.getAttribute('data-col-key');
        }

        function clampWidth(width) {
            if (width < MIN_WIDTH) {
                return MIN_WIDTH;
            }
            if (width > MAX_WIDTH) {
                return MAX_WIDTH;
            }
            return width;
        }

        // ---- Sabit yerleşime geçiş (idempotent) -----------------------------
        // Tablo kaydedilmiş genişlik olmadan açıldıysa hâlâ otomatik
        // yerleşimdedir (`width:auto; min-width:100%`). İLK sürüklemede (ya da
        // localStorage'dan geri yüklemede) o anki TÜM sütun genişlikleri
        // EKRANDAN ölçülüp <colgroup>'a yazılır ve table-layout:fixed'e geçilir —
        // böylece geçiş görsel olarak dikişsiz olur (kullanıcı yalnızca çektiği
        // sütunun değiştiğini görür), sonraki hareketler de birebir piksel olur.
        //
        // $overrides verilirse (localStorage'dan okunan harita) ölçülen değerin
        // yerine o kullanılır; haritada olmayan sütunlar ölçümde kalır — silinmiş
        // ya da yeni eklenmiş bir alan yerleşimi kaydırmasın.
        function ensureFixedLayout(overrides) {
            if (table.classList.contains('grid-has-col-widths')) {
                return;
            }

            var heads = headerCells();
            var colgroup = document.createElement('colgroup');
            var total = 0;

            heads.forEach(function (th) {
                var col = document.createElement('col');
                var key = th.getAttribute('data-col-key');
                var storeKey = key || (th.classList.contains('grid-rownum') ? 'row' : '');
                var width = Math.round(th.getBoundingClientRect().width);

                if (overrides && storeKey && typeof overrides[storeKey] === 'number' && isFinite(overrides[storeKey])) {
                    // 'row' kırpılmıyor: sürüklenmeyen cetvel sütunu, kendi
                    // ölçülen genişliğinde kalmalı (MIN_WIDTH ona 80px dayatırdı).
                    width = key ? clampWidth(Math.round(overrides[storeKey])) : Math.round(overrides[storeKey]);
                }

                if (key) {
                    col.setAttribute('data-col-key', key);
                }
                col.style.width = width + 'px';
                total += width;
                colgroup.appendChild(col);
            });

            table.insertBefore(colgroup, table.firstChild);
            table.classList.add('grid-has-col-widths');
            table.style.width = total + 'px';
        }

        function colFor(key) {
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

        // localStorage: sunucu kaydının YEDEĞİ, alternatifi değil.
        //   - Yazma HER sürükleme sonunda (fetch başarısız olsa bile genişlik
        //     F5'ten sonra korunsun).
        //   - Okuma YALNIZCA sunucu hiç genişlik render etmediyse — sunucudaki
        //     değer her zaman kazanır, aksi hâlde başka bir cihazdan yapılan
        //     değişiklik bu tarayıcıdaki bayat kopyayla ezilirdi.
        //   - Yalnızca-okuma yetkisiyle açan kullanıcı için TEK kalıcılık yolu
        //     budur (view_config_update.php 'editor' rolü istiyor); onun için
        //     genişlik kişisel/yerel bir tercih olarak yaşıyor.
        function readStored() {
            if (!STORAGE_KEY) {
                return null;
            }
            try {
                var raw = window.localStorage.getItem(STORAGE_KEY);
                if (!raw) {
                    return null;
                }
                var parsed = JSON.parse(raw);
                return (parsed && typeof parsed === 'object') ? parsed : null;
            } catch (e) {
                // Bozuk JSON / kapalı depolama (gizli sekme kotası, "site
                // verilerini engelle") — özellik sessizce sunucu kaydına düşer.
                return null;
            }
        }

        function writeStored(map) {
            if (!STORAGE_KEY) {
                return;
            }
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
            } catch (e) {
                // Kota dolu / depolama kapalı — sessiz geç.
            }
        }

        function persist() {
            var map = currentWidthMap();
            writeStored(map);

            if (!canEdit || !viewId) {
                return; // Yalnızca-okuma: kalıcılık localStorage'da kaldı.
            }

            fetch('/api/view_config_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    view_id: viewId,
                    // frozen_column_count BİLEREK GÖNDERİLMİYOR — uç nokta artık
                    // yalnızca gönderilen anahtarı yazıyor, yani dondurma ayarı
                    // bu istekten etkilenmiyor.
                    column_widths: JSON.stringify(map),
                }).toString(),
            }).catch(function () {
                // Sessiz başarısızlık: genişlik bu oturumda görsel olarak
                // uygulanmış kalır ve localStorage sayesinde F5'ten sonra da
                // durur — yalnızca başka cihaza taşınmaz.
                // grid-freeze-columns.js'in persistFrozenCount()'u ile AYNI karar.
            });
        }

        // ---- Açılışta geri yükleme -----------------------------------------
        if (table.classList.contains('grid-has-col-widths')) {
            // Sunucu genişlikleri render etti: yerel kopyayı ONUNLA tazele ki
            // bayat bir localStorage kaydı ileride geri gelmesin.
            writeStored(currentWidthMap());
        } else {
            var stored = readStored();
            if (stored) {
                ensureFixedLayout(stored);
                if (window.BCC_reapplyFreeze) {
                    window.BCC_reapplyFreeze(); // sticky left ofsetleri yeni genişliklere göre
                }
            }
        }

        // ---- Tam boy şeritler ------------------------------------------------
        var layer = document.createElement('div');
        layer.className = 'grid-col-resize-layer';
        layer.setAttribute('aria-hidden', 'true');
        wrap.appendChild(layer);

        var strips = [];

        headerCells().forEach(function (th) {
            if (!isResizable(th)) {
                strips.push(null);
                return;
            }

            var strip = document.createElement('div');
            strip.className = 'grid-col-resize-handle';
            strip.setAttribute('data-col-key', th.getAttribute('data-col-key'));
            layer.appendChild(strip);
            strips.push(strip);
        });

        // Donmuş grubun (satır no + .grid-frozen-cell başlıkları) toplam
        // genişliği: yatay kaydırmada bu şeridin ALTINA giren şeritler
        // gizlenmeli, yoksa pinlenmiş sütunların üzerinde havada duran bir
        // ayıraç çizgisi ve yanlış yerde bir col-resize imleci kalırdı.
        function frozenGroupWidth(heads) {
            var w = 0;
            for (var i = 0; i < heads.length; i++) {
                if (i === 0 || heads[i].classList.contains('grid-frozen-cell')) {
                    w += heads[i].offsetWidth;
                } else {
                    break;
                }
            }
            return w;
        }

        // Şeritleri sütun sınırlarına oturt. Sınır x'i başlık offsetWidth'lerinin
        // TOPLAMINDAN hesaplanıyor (getBoundingClientRect DEĞİL): donmuş başlıklar
        // position:sticky olduğu için rect'leri yatay kaydırmada pinlenmiş konumu
        // gösterir, offsetWidth ise kaydırmadan bağımsızdır. grid-freeze-columns.js
        // de ofsetleri AYNI şekilde topluyor.
        function layout() {
            var heads = headerCells();
            var top = table.offsetTop;
            var height = table.offsetHeight;
            var scrollLeft = wrap.scrollLeft;
            var frozenW = frozenGroupWidth(heads);
            var acc = table.offsetLeft;

            heads.forEach(function (th, i) {
                acc += th.offsetWidth;

                var strip = strips[i];
                if (!strip) {
                    return;
                }

                var frozen = th.classList.contains('grid-frozen-cell');
                // Donmuş sütun kaydırmayla birlikte hareket etmez: sınırı
                // görüntü alanının soluna sabitlenmiş grubun içindedir.
                var x = frozen ? (scrollLeft + acc) : acc;

                // Donmuş kenarda ŞERİDİN KENDİSİ (tıklama alanı) sola kaçırılıyor
                // ki dondurma tutamacıyla çakışmasın. Ama şeridin GÖRÜNEN çizgisi
                // (::after) de birlikte kayınca kullanıcıya "genişlik çubuğu
                // sütun sınırının üstünde değil" gibi görünüyordu. Sınıf, CSS'in
                // ::after'ı aynı miktarda SAĞA alıp çizgiyi gerçek sınıra
                // oturtması için — tıklama alanı yerinde kalır.
                var isFrozenEdge = th.classList.contains('grid-frozen-edge');
                strip.classList.toggle('is-frozen-edge', isFrozenEdge);
                if (isFrozenEdge) {
                    x -= FREEZE_CLEARANCE;
                }

                if (!frozen && x <= scrollLeft + frozenW) {
                    strip.style.display = 'none';
                    return;
                }

                strip.style.display = '';
                strip.style.left = (x - Math.floor(STRIP_WIDTH / 2)) + 'px';
                strip.style.top = top + 'px';
                strip.style.height = height + 'px';
            });
        }

        // grid-freeze-columns.js dondurma sınırını değiştirdiğinde (sınıf değişir,
        // boyut değişmez — ResizeObserver görmez) bu kancayı çağırıyor.
        window.BCC_relayoutColumnResize = layout;

        layout();
        window.addEventListener('resize', layout);

        // Yatay kaydırma donmuş grubun altına giren şeritleri etkiliyor.
        // rAF kısıtlaması bcc_bindColumnDrag'daki desenin AYNISI.
        var scrollPending = false;
        wrap.addEventListener('scroll', function () {
            if (scrollPending) {
                return;
            }
            scrollPending = true;
            requestAnimationFrame(function () {
                scrollPending = false;
                layout();
            });
        });

        // Tablo yüksekliği/genişliği sayfa ömrü boyunca değişiyor: kayıt ekleme
        // (grid.js), grup açma/kapama, satır detay panelinden silme, hücre
        // düzenlerken büyüyen satır... Hepsini tek tek kancalamak yerine tabloyu
        // gözlemliyoruz. layout() yalnızca katmandaki şeritlere dokunduğu için
        // gözlemciyi yeniden tetikleyemez (sonsuz döngü yok).
        if (window.ResizeObserver) {
            new window.ResizeObserver(layout).observe(table);
        }

        // ---- Sürükleme --------------------------------------------------------
        strips.forEach(function (strip) {
            if (!strip) {
                return;
            }

            var key = strip.getAttribute('data-col-key');
            var startX = 0;
            var startWidth = 0;
            var col = null;

            window.bcc_bindColumnDrag(strip, {
                onStart: function (e) {
                    // Sürükleme BAŞLARKEN sabit yerleşime geçilir; ölçüm hâlâ
                    // otomatik yerleşimin ürettiği gerçek genişliklerden alınır.
                    ensureFixedLayout();
                    col = colFor(key);
                    startX = e.clientX;
                    var th = table.querySelector('thead th[data-col-key="' + key + '"]');
                    startWidth = th ? Math.round(th.getBoundingClientRect().width) : 0;
                    // İmleç sürükleme boyunca col-resize kalsın: fare şeridin
                    // dışına (hücrelerin, hatta tablonun dışına) çıktığında bile
                    // etkileşim sürüyor.
                    document.body.classList.add('is-col-resizing');
                },
                onMove: function (clientX) {
                    if (!col) {
                        return;
                    }
                    // YALNIZCA clientX kullanılıyor — dikey hareket genişliği de
                    // yüksekliği de değiştirmiyor (satır yüksekliği ayrı bir
                    // ayar, grid-toolbar.js).
                    var next = clampWidth(startWidth + (clientX - startX));
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
                    layout();
                },
                onEnd: function () {
                    document.body.classList.remove('is-col-resizing');
                    if (!col) {
                        return;
                    }
                    col = null;
                    layout();
                    persist();
                },
            });
        });
    });
})();
