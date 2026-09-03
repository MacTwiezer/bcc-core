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

        function refreshCellViews() {
            var rows = Array.prototype.filter.call(tbody.querySelectorAll('tr'), function (tr) {
                return tr.hasAttribute('data-record-id');
            });
            total = rows.length;

            cellViews = [];
            rows.forEach(function (tr) {
                Array.prototype.forEach.call(tr.querySelectorAll('td.grid-cell .cell-view'), function (view) {
                    cellViews.push(view);
                });
            });
        }

        refreshCellViews();

        var matches = [];
        var activeIndex = -1;

        function updateCount(visibleRows) {
            if (!countEl) {
                return;
            }
            countEl.textContent = (visibleRows === total) ? (total + ' kayıt') : (visibleRows + ' / ' + total + ' kayıt');
        }

        function buildHighlightedFragment(text, q) {
            var frag = document.createDocumentFragment();
            var lower = text.toLocaleLowerCase('tr');
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

        function clearMarks(root) {
            var old = root.querySelectorAll('mark.grid-search-mark');
            if (!old.length) {
                return;
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
                if (text === '' || text.toLocaleLowerCase('tr').indexOf(q) === -1) {
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

        function revealPastFrozenColumns(el) {
            if (el.closest('td.grid-rownum, td.grid-frozen-cell')) {
                return;
            }

            var wrap = el.closest('.grid-wrap');
            var row = el.closest('tr');
            if (!wrap || !row) {
                return;
            }

            var frozenRight = wrap.getBoundingClientRect().left;
            Array.prototype.forEach.call(
                row.querySelectorAll('td.grid-rownum, td.grid-frozen-cell'),
                function (cell) {
                    frozenRight = Math.max(frozenRight, cell.getBoundingClientRect().right);
                }
            );

            var gap = (frozenRight - el.getBoundingClientRect().left) / (window.bcc_uiScale ? window.bcc_uiScale() : 1);
            if (gap > 0) {
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

            var q = input.value.trim().toLocaleLowerCase('tr');

            matches = [];
            activeIndex = -1;

            var matchedRowIds = {};

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
