(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var details = document.getElementById('home-search');
        var input = document.getElementById('home-search-input');
        var resultsBox = document.getElementById('home-search-results');
        var emptyBox = document.getElementById('home-search-empty');
        var scopeBox = document.getElementById('home-search-scope');

        if (!details || !input || !resultsBox) {
            return;
        }


        function textOf(el, selector) {
            var found = el.querySelector(selector);
            return found ? found.textContent.trim().replace(/\s+/g, ' ') : '';
        }

        function collectBases() {
            var grids = document.querySelectorAll('.home-base-grid');
            if (!grids.length) {
                return null;
            }

            var cards = [];
            Array.prototype.forEach.call(grids, function (grid) {
                Array.prototype.push.apply(
                    cards,
                    Array.prototype.slice.call(grid.querySelectorAll('.home-base-card'))
                );
            });

            var items = [];
            cards.forEach(function (card) {
                if (card.classList.contains('home-base-create')) {
                    return;
                }

                var icon = card.querySelector('.home-base-icon');
                items.push({
                    label: textOf(card, '.home-base-name'),
                    meta: textOf(card, '.home-base-meta'),
                    type: 'Base',
                    href: card.getAttribute('href'),
                    icon: icon ? icon.cloneNode(true) : null,
                    id: card.getAttribute('data-base-id') || '',
                });
            });

            return { scope: items.length + ' base', items: items };
        }

        function collectWorkspaces() {
            var side = document.querySelector('.wsx-side');
            var collab = document.getElementById('wsx-collab-grid');
            if (!side && !collab) {
                return null;
            }

            var items = [];

            if (side) {
                Array.prototype.forEach.call(side.querySelectorAll('.wsx-card'), function (card) {
                    if (card.classList.contains('wsx-card-new')) {
                        return;
                    }
                    items.push({
                        label: textOf(card, '.wsx-card-name'),
                        meta: textOf(card, '.wsx-card-meta'),
                        type: 'Çalışma alanı',
                        href: card.getAttribute('href'),
                    });
                });
            }

            if (collab) {
                Array.prototype.forEach.call(collab.querySelectorAll('.wsx-member'), function (member) {
                    var role = textOf(member, '.sp-role');
                    items.push({
                        label: textOf(member, '.wsx-member-name'),
                        meta: textOf(member, '.wsx-member-mail') + (role ? ' · ' + role : ''),
                        type: 'Katılımcı',
                        onSelect: function () { flashTo(member); },
                    });
                });
            }

            return { scope: items.length + ' kayıt (alan + katılımcı)', items: items };
        }

        function collectMembers() {
            var body = document.querySelector('[data-tm-rows]');
            if (!body) {
                return null;
            }

            var items = [];
            Array.prototype.forEach.call(body.querySelectorAll('.tm-row'), function (row) {
                var roleSelect = row.querySelector('.tm-role-select');
                var role = roleSelect
                    ? (roleSelect.options[roleSelect.selectedIndex] || {}).text || ''
                    : textOf(row, '.tm-role-readonly');

                items.push({
                    label: textOf(row, '.ws-collab-name'),
                    meta: textOf(row, '.ws-collab-email') + (role ? ' · ' + role.trim() : ''),
                    type: 'Üye',
                    onSelect: function () { flashTo(row); },
                });
            });

            return { scope: items.length + ' üye', items: items };
        }

        function collectRecords() {
            var rows = document.querySelectorAll('table.grid tr[data-record-id]');
            if (!rows.length) {
                return null;
            }

            var items = [];
            Array.prototype.forEach.call(rows, function (row) {
                var cells = Array.prototype.slice.call(row.querySelectorAll('td:not(.grid-rownum)'));
                var texts = cells.map(function (td) {
                    return td.textContent.trim().replace(/\s+/g, ' ');
                }).filter(function (t) { return t !== ''; });

                if (!texts.length) {
                    return;
                }

                var label = texts[0];
                var rest = texts.slice(1).join(' · ');

                items.push({
                    label: label,
                    meta: rest,
                    type: 'Kayıt',
                    onSelect: function () { flashTo(row); },
                });
            });

            return { scope: items.length + ' kayıt', items: items };
        }

        function flashTo(el) {
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            el.classList.add('bcc-search-flash');
            window.setTimeout(function () {
                el.classList.remove('bcc-search-flash');
            }, 1600);
        }

        var collectors = [collectBases, collectWorkspaces, collectMembers, collectRecords];

        var context = null;
        for (var i = 0; i < collectors.length; i++) {
            context = collectors[i]();
            if (context && context.items.length) {
                break;
            }
            context = null;
        }

        if (!context) {
            context = { scope: '', items: [] };
        }

        var entries = [];

        context.items.forEach(function (item) {
            var row = document.createElement(item.href ? 'a' : 'button');
            row.className = 'home-search-result';
            row.setAttribute('role', 'option');

            if (item.href) {
                row.href = item.href;
            } else {
                row.type = 'button';
            }
            if (item.id) {
                row.setAttribute('data-base-id', item.id);
            }

            if (item.icon) {
                row.appendChild(item.icon);
            }

            var nameNode = document.createElement('div');
            nameNode.className = 'home-search-result-name';
            nameNode.textContent = item.label;
            row.appendChild(nameNode);

            var typeNode = document.createElement('div');
            typeNode.className = 'home-search-result-type';
            typeNode.textContent = item.type;
            row.appendChild(typeNode);

            var metaNode = document.createElement('div');
            metaNode.className = 'home-search-result-meta';
            metaNode.textContent = item.meta;
            row.appendChild(metaNode);

            if (item.onSelect) {
                row.addEventListener('click', function (e) {
                    e.preventDefault();
                    closeSearch(false);
                    item.onSelect();
                });
            }

            resultsBox.appendChild(row);
            entries.push({
                el: row,
                text: (item.label + ' ' + item.meta + ' ' + item.type).toLocaleLowerCase('tr'),
            });
        });

        if (scopeBox && context.scope) {
            scopeBox.textContent = context.scope + ' içinde aranıyor';
            scopeBox.hidden = false;
        }

        var lastFocused = null;

        function openSearch() {
            lastFocused = document.activeElement;
            details.setAttribute('open', '');
            input.focus();
            input.select();
        }

        function closeSearch(restoreFocus) {
            if (!details.hasAttribute('open')) {
                return;
            }
            details.removeAttribute('open');
            clearActive();

            if (restoreFocus !== false && lastFocused && document.contains(lastFocused)) {
                lastFocused.focus();
            }
            lastFocused = null;
        }

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                if (details.hasAttribute('open')) {
                    closeSearch(true);
                } else {
                    openSearch();
                }
            }
        });

        var popover = details.querySelector('.home-search-popover');
        var trigger = details.querySelector('.home-search-trigger');

        window.bcc_bindDismissable(details, {
            close: function () { closeSearch(true); },
            isClickOutside: function (target) {
                if (popover && popover.contains(target)) {
                    return false;
                }
                if (trigger && trigger.contains(target)) {
                    return false;
                }
                return true;
            },
        });

        details.addEventListener('toggle', function () {
            if (details.open) {
                input.focus();
                input.select();
            }
        });

        var activeIndex = -1;

        function visibleEntries() {
            return entries.filter(function (entry) { return !entry.el.hidden; });
        }

        function clearActive() {
            entries.forEach(function (entry) { entry.el.classList.remove('is-active'); });
            activeIndex = -1;
        }

        function setActive(list, index) {
            list.forEach(function (entry) { entry.el.classList.remove('is-active'); });
            if (index < 0 || index >= list.length) {
                activeIndex = -1;
                return;
            }
            activeIndex = index;
            list[index].el.classList.add('is-active');
            list[index].el.scrollIntoView({ block: 'nearest' });
        }

        function applyFilter() {
            var q = input.value.trim().toLocaleLowerCase('tr');
            var visible = 0;

            entries.forEach(function (entry) {
                var matches = q === '' || entry.text.indexOf(q) !== -1;
                entry.el.hidden = !matches;
                if (matches) {
                    visible++;
                }
            });

            if (emptyBox) {
                emptyBox.hidden = !(q !== '' && visible === 0);
            }

            clearActive();
        }

        input.addEventListener('input', applyFilter);

        input.addEventListener('keydown', function (e) {
            var list = visibleEntries();

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(list, activeIndex + 1 >= list.length ? 0 : activeIndex + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(list, activeIndex - 1 < 0 ? list.length - 1 : activeIndex - 1);
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && list[activeIndex]) {
                    e.preventDefault();
                    list[activeIndex].el.click();
                }
            }
        });

        window.bcc_searchRemoveItem = function (baseId) {
            for (var i = entries.length - 1; i >= 0; i--) {
                if (entries[i].el.getAttribute('data-base-id') === String(baseId)) {
                    entries[i].el.remove();
                    entries.splice(i, 1);
                }
            }
        };
    });
})();
