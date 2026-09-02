(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var searchInput = document.querySelector('[data-group-search]');
        var fieldList = document.querySelector('[data-group-field-list]');

        if (searchInput && fieldList) {
            var options = Array.prototype.slice.call(fieldList.querySelectorAll('.group-field-option'));
            var emptyNote = fieldList.querySelector('[data-group-empty]');
            var keys = options.map(function (o) {
                return (o.getAttribute('data-group-field-name') || '').trim();
            });

            var applyGroupFilter = function () {
                var q = searchInput.value.trim().toLowerCase();
                var visible = 0;

                options.forEach(function (option, i) {
                    var match = q === '' || keys[i].indexOf(q) !== -1;
                    option.classList.toggle('is-filtered-out', !match);
                    if (match) {
                        visible++;
                    }
                });

                if (emptyNote) {
                    emptyNote.hidden = visible !== 0;
                }
            };

            searchInput.addEventListener('input', applyGroupFilter);

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && searchInput.value !== '') {
                    e.stopPropagation();
                    searchInput.value = '';
                    applyGroupFilter();
                }
            });
        }

        function isWithinGroup(path, parentPath) {
            return path === parentPath || path.indexOf(parentPath + '-') === 0;
        }

        function setGroupCollapsed(headerRow, collapsed) {
            var toggle = headerRow.querySelector('[data-group-toggle]');
            var groupPath = headerRow.getAttribute('data-group-path');

            headerRow.setAttribute('data-group-collapsed', collapsed ? 'true' : 'false');
            if (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }

            document.querySelectorAll('[data-group-path]').forEach(function (el) {
                if (el === headerRow) {
                    return;
                }

                var elPath = el.getAttribute('data-group-path');
                if (!isWithinGroup(elPath, groupPath)) {
                    return;
                }

                el.style.display = collapsed ? 'none' : '';

                if (el.hasAttribute('data-group-header')) {
                    el.setAttribute('data-group-collapsed', 'false');
                    var innerToggle = el.querySelector('[data-group-toggle]');
                    if (innerToggle) {
                        innerToggle.setAttribute('aria-expanded', 'true');
                    }
                }
            });
        }

        var headerRows = document.querySelectorAll('[data-group-header]');
        headerRows.forEach(function (headerRow) {
            var toggle = headerRow.querySelector('[data-group-toggle]');

            if (!toggle) {
                return;
            }

            toggle.addEventListener('click', function () {
                var collapsed = headerRow.getAttribute('data-group-collapsed') === 'true';
                setGroupCollapsed(headerRow, !collapsed);
            });
        });

        var collapseAllBtn = document.querySelector('[data-group-collapse-all]');
        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function () {
                headerRows.forEach(function (headerRow) {
                    setGroupCollapsed(headerRow, true);
                });
            });
        }

        var expandAllBtn = document.querySelector('[data-group-expand-all]');
        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function () {
                headerRows.forEach(function (headerRow) {
                    setGroupCollapsed(headerRow, false);
                });
            });
        }
    });
})();
