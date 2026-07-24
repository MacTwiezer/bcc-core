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

        var rows = Array.prototype.filter.call(tbody.querySelectorAll('tr'), function (tr) {
            return tr.hasAttribute('data-record-id');
        });
        var total = rows.length;

        // Aranabilir hücreler: yalnızca veri hücrelerinin metni (.cell-view) —
        // satır no ve işlemler (Sil butonu) sütunu hariç. Checkbox hücrelerinde
        // .cell-view yok, doğal olarak elenir. Satırlar artık GİZLENMEZ (yalnızca
        // vurgulanır) — bu yüzden bir grubun tüm satırları elense bile grup
        // başlığı zaten hep görünür kalır (bilinen kusur bu tasarımda oluşmaz).
        var cellViews = [];
        rows.forEach(function (tr) {
            Array.prototype.forEach.call(tr.querySelectorAll('td.grid-cell .cell-view'), function (view) {
                cellViews.push(view);
            });
        });

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

        function setNavVisible(show) {
            if (navEl) {
                navEl.hidden = !show;
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

            if (matchCountEl) {
                matchCountEl.textContent = (activeIndex + 1) + ' / ' + matches.length;
            }
        }

        function runSearch() {
            var q = input.value.trim().toLowerCase();

            matches = [];
            activeIndex = -1;

            var matchedRowIds = {};

            cellViews.forEach(function (view) {
                var text = view.textContent;
                // Zengin metin (long_text) hücreleri vurgulanmaz/yeniden
                // YAZILMAZ — .textContent = '' + yeniden ekleme, kalın/italik/
                // link biçimlendirmesini (gerçek <strong>/<a> etiketlerini)
                // kaybederdi. Yalnızca eşleşme SAYIMI için metni okunur.
                var isRichText = view.classList.contains('rich-text-view');

                if (q === '') {
                    if (!isRichText) {
                        view.textContent = text; // önceki <mark>'ları temizler
                    }
                    return;
                }

                if (isRichText) {
                    if (text.toLowerCase().indexOf(q) !== -1) {
                        var richTr = view.closest('tr[data-record-id]');
                        if (richTr) {
                            matchedRowIds[richTr.getAttribute('data-record-id')] = true;
                        }
                    }
                    return;
                }

                var result = buildHighlightedFragment(text, q);
                view.textContent = '';
                view.appendChild(result.fragment);

                if (result.marks.length) {
                    var tr = view.closest('tr[data-record-id]');
                    if (tr) {
                        matchedRowIds[tr.getAttribute('data-record-id')] = true;
                    }
                    result.marks.forEach(function (mark) {
                        matches.push(mark);
                    });
                }
            });

            if (q === '') {
                updateCount(total);
                setNavVisible(false);
                if (prevBtn) { prevBtn.disabled = true; }
                if (nextBtn) { nextBtn.disabled = true; }
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
