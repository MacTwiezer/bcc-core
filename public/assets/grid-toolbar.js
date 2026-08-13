(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var input = document.getElementById('grid-search');
        var countEl = document.getElementById('grid-row-count');
        var tbody = document.querySelector('table.grid tbody');

        if (!input || !tbody) {
            return;
        }

        var navEl = document.getElementById('grid-search-nav');
        var matchCountEl = document.getElementById('grid-search-count');
        var prevBtn = document.getElementById('grid-search-prev');
        var nextBtn = document.getElementById('grid-search-next');

        var total = 0;
        var cellViews = [];

        // Bulunan gerçek bug: satır/hücre listesi eskiden yalnızca SAYFA
        // YÜKLENİRKEN bir kez taranıyordu. grid.js'nin addRecord() fonksiyonu
        // yeni satırı sayfa yenilenmeden doğrudan DOM'a ekliyor (bkz. grid.js
        // insertAdjacentElement) — bu yüzden yeni eklenen bir kayıt, sayfa
        // yenilenene kadar arama ile HİÇ bulunamıyor ve toplam kayıt sayısı
        // yanlış kalıyordu. Artık her aramada DOM'dan TAZE okunuyor.
        function refreshCellViews() {
            var rows = Array.prototype.filter.call(tbody.querySelectorAll('tr'), function (tr) {
                return tr.hasAttribute('data-record-id');
            });
            total = rows.length;

            // Aranabilir hücreler: yalnızca veri hücrelerinin metni (.cell-view) —
            // satır no ve işlemler (Sil butonu) sütunu hariç. Checkbox hücrelerinde
            // .cell-view yok, doğal olarak elenir. Satırlar artık GİZLENMEZ (yalnızca
            // vurgulanır) — bu yüzden bir grubun tüm satırları elense bile grup
            // başlığı zaten hep görünür kalır (bilinen kusur bu tasarımda oluşmaz).
            cellViews = [];
            rows.forEach(function (tr) {
                Array.prototype.forEach.call(tr.querySelectorAll('td.grid-cell .cell-view'), function (view) {
                    cellViews.push(view);
                });
            });
        }

        refreshCellViews();

        var matches = []; // <mark> elemanları, DOM sırasına göre
        var activeIndex = -1;

        function updateCount(visibleRows) {
            if (!countEl) {
                return;
            }
            countEl.textContent = (visibleRows === total) ? (total + ' kayıt') : (visibleRows + ' / ' + total + ' kayıt');
        }

        // $text içindeki TÜM q eşleşmelerini <mark> ile sarmalanmış bir
        // DocumentFragment'e çevirir. Yalnızca createTextNode/createElement
        // kullanılır — ham innerHTML string birleştirmesi YOK, bu yüzden kullanıcı
        // verisi (zaten sunucuda htmlspecialchars ile kaçırılmış metnin DOM'daki
        // düz hâli) ayrıca kaçırılmaya gerek kalmadan güvenle enjekte edilir.
        function buildHighlightedFragment(text, q) {
            var frag = document.createDocumentFragment();
            var lower = text.toLowerCase();
            var marks = [];
            var start = 0;
            var idx = lower.indexOf(q, start);

            if (idx === -1) {
                frag.appendChild(document.createTextNode(text));
                return { fragment: frag, marks: marks };
            }

            while (idx !== -1) {
                if (idx > start) {
                    frag.appendChild(document.createTextNode(text.slice(start, idx)));
                }
                var mark = document.createElement('mark');
                mark.className = 'grid-search-mark';
                mark.appendChild(document.createTextNode(text.slice(idx, idx + q.length)));
                frag.appendChild(mark);
                marks.push(mark);
                start = idx + q.length;
                idx = lower.indexOf(q, start);
            }
            if (start < text.length) {
                frag.appendChild(document.createTextNode(text.slice(start)));
            }

            return { fragment: frag, marks: marks };
        }

        // Bir hücredeki önceki vurguları GERİ ALIR: her <mark>'ı kendi metin
        // çocuklarıyla değiştirir, sonra normalize() ile bölünmüş metin
        // düğümlerini yeniden birleştirir.
        //
        // Bulunan gerçek bug: eskiden temizleme `view.textContent = text` ile
        // yapılıyordu. Bu, hücrenin TÜM ELEMAN ÇOCUKLARINI siler — seçim
        // rozetleri (.choice-chip), yıldızlar (.rating-view), ek dosya
        // linkleri (.attachment-cell-view) düz metne dönüşüyordu. Canlı
        // testte doğrulandı: arama kutusuna tek bir harf yazmak tablodaki 5
        // rozeti kalıcı olarak yok ediyordu (arama temizlenince de geri
        // gelmiyorlardı, yalnızca sayfa yenilemesi düzeltiyordu).
        // Yalnızca <mark>'ları söküp geri kalan DOM'a hiç dokunmamak bu sınıf
        // hatayı tamamen ortadan kaldırıyor.
        //
        // normalize() ŞART: "sil" + "silesi" gibi ayrı metin düğümlerine
        // bölünmüş kalan bir hücrede, sonraki arama sınırı aşan bir kelimeyi
        // ("silsilesi") bulamazdı.
        function clearMarks(root) {
            var old = root.querySelectorAll('mark.grid-search-mark');
            if (!old.length) {
                return; // hiç vurgu yoksa metin düğümleri de bölünmemiştir
            }
            Array.prototype.forEach.call(old, function (mark) {
                var parent = mark.parentNode;
                while (mark.firstChild) {
                    parent.insertBefore(mark.firstChild, mark);
                }
                parent.removeChild(mark);
            });
            root.normalize();
        }

        // Hücrenin İÇİNDEKİ metin düğümlerini tek tek gezip eşleşmeleri
        // <mark>'lar. Eleman yapısına (chip'ler, <strong>/<a> gibi zengin
        // metin etiketleri) DOKUNMAZ — bu yüzden zengin metin hücreleri de
        // artık diğer sütunlarla AYNI yoldan aranabiliyor; eski koddaki
        // "isRichText ise yalnızca say, vurgulama" özel durumu KALDIRILDI.
        // O özel durum yüzünden yalnızca Notlar'da geçen bir kelime aramada
        // "0 / 0" gösteriyor ve ▲▼ düğmeleri ölü kalıyordu (satır sayacı ise
        // "1 / 5 kayıt" diyordu — iki sayaç birbiriyle çelişiyordu).
        //
        // Metin düğümleri ÖNCE toplanır, sonra değiştirilir: yürüyüş sırasında
        // DOM'u değiştirmek TreeWalker'ı geçersiz kılar.
        function highlightTextNodes(root, q) {
            var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
            var textNodes = [];
            var node;
            while ((node = walker.nextNode())) {
                textNodes.push(node);
            }

            var marks = [];
            textNodes.forEach(function (textNode) {
                var text = textNode.nodeValue;
                if (text === '' || text.toLowerCase().indexOf(q) === -1) {
                    return;
                }
                var result = buildHighlightedFragment(text, q);
                result.marks.forEach(function (mark) { marks.push(mark); });
                textNode.parentNode.replaceChild(result.fragment, textNode);
            });

            return marks;
        }

        function setNavVisible(show) {
            if (navEl) {
                navEl.hidden = !show;
            }
        }

        // scrollIntoView() DONMUŞ (sticky) sütunları bilmez: hedefi kaydırma
        // alanının SOL KENARINA getirmekle yetinir, ama o kenarın üstünü
        // donmuş sütunlar (satır no + .grid-frozen-cell, bkz.
        // grid-freeze-columns.js) örtüyor olabilir — eşleşme "görünür" sayılıp
        // aslında panelin ALTINDA kalır.
        //
        // Canlı testte doğrulandı: sağa kaydırılmış bir tabloda "Bursa"
        // aratınca aktif eşleşme 333px'e getiriliyordu, donmuş sütunlar ise
        // 537px'e kadar uzanıyordu — kullanıcı "1 / 1" görüyor ama vurguyu
        // GÖREMİYORDU. Burada aradaki fark kadar geri kaydırılıyor.
        function revealPastFrozenColumns(el) {
            // Eşleşmenin KENDİSİ donmuş bir sütundaysa zaten hep görünür;
            // düzeltme uygulanırsa gereksiz yere sola kayardı.
            if (el.closest('td.grid-rownum, td.grid-frozen-cell')) {
                return;
            }

            var wrap = el.closest('.grid-wrap');
            var row = el.closest('tr');
            if (!wrap || !row) {
                return;
            }

            // Donmuş grubun sağ kenarı, AYNI satırın donmuş hücrelerinden
            // ölçülür (genişlikler görünüme göre değişebiliyor).
            var frozenRight = wrap.getBoundingClientRect().left;
            Array.prototype.forEach.call(
                row.querySelectorAll('td.grid-rownum, td.grid-frozen-cell'),
                function (cell) {
                    frozenRight = Math.max(frozenRight, cell.getBoundingClientRect().right);
                }
            );

            var gap = frozenRight - el.getBoundingClientRect().left;
            if (gap > 0) {
                // scrollLeft AZALTMAK içeriği SAĞA kaydırır; +8px nefes payı.
                // Tarayıcı değeri 0'ın altına düşürmez, ayrıca kırpma gerekmez.
                wrap.scrollLeft -= gap + 8;
            }
        }

        function clearActive() {
            if (activeIndex >= 0 && matches[activeIndex]) {
                matches[activeIndex].classList.remove('is-active');
            }
        }

        function setActive(index) {
            if (matches.length === 0) {
                activeIndex = -1;
                if (matchCountEl) {
                    matchCountEl.textContent = '0 / 0';
                }
                return;
            }

            clearActive();
            activeIndex = ((index % matches.length) + matches.length) % matches.length;

            var mark = matches[activeIndex];
            mark.classList.add('is-active');
            mark.scrollIntoView({ block: 'center', inline: 'nearest' });
            revealPastFrozenColumns(mark);

            if (matchCountEl) {
                matchCountEl.textContent = (activeIndex + 1) + ' / ' + matches.length;
            }
        }

        function runSearch() {
            refreshCellViews();

            var q = input.value.trim().toLowerCase();

            matches = [];
            activeIndex = -1;

            var matchedRowIds = {};

            // Tek yol, TÜM sütun tipleri için: önce eski vurgular sökülür,
            // sonra (sorgu varsa) metin düğümleri gezilerek yeniden vurgulanır.
            // Tip'e göre dallanma YOK — düz metin, seçim rozeti, sayı, tarih ve
            // zengin metin aynı mekanizmadan geçer, bu yüzden hepsi eşleşme
            // sayacına ve ▲▼ gezinmesine dahildir.
            cellViews.forEach(function (view) {
                clearMarks(view);

                if (q === '') {
                    return;
                }

                var found = highlightTextNodes(view, q);
                if (found.length) {
                    var tr = view.closest('tr[data-record-id]');
                    if (tr) {
                        matchedRowIds[tr.getAttribute('data-record-id')] = true;
                    }
                    found.forEach(function (mark) {
                        matches.push(mark);
                    });
                }
            });

            if (q === '') {
                updateCount(total);
                setNavVisible(false);
                if (prevBtn) { prevBtn.disabled = true; }
                if (nextBtn) { nextBtn.disabled = true; }
                // Sayacı da sıfırla: panel gizlense de içinde eski "1 / 1"
                // metni kalıyordu, sonraki aramada bir an için görünüyordu.
                if (matchCountEl) { matchCountEl.textContent = ''; }
                return;
            }

            updateCount(Object.keys(matchedRowIds).length);
            setNavVisible(true);

            var hasMatches = matches.length > 0;
            if (prevBtn) { prevBtn.disabled = !hasMatches; }
            if (nextBtn) { nextBtn.disabled = !hasMatches; }

            if (hasMatches) {
                setActive(0);
            } else if (matchCountEl) {
                matchCountEl.textContent = '0 / 0';
            }
        }

        updateCount(total);

        input.addEventListener('input', runSearch);

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (matches.length) {
                    setActive(activeIndex - 1);
                }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (matches.length) {
                    setActive(activeIndex + 1);
                }
            });
        }
    });
})();
