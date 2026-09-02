(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('hide-fields-form');
        if (!form) {
            return;
        }

        var toggles = Array.prototype.slice.call(form.querySelectorAll('.hide-field-toggle-input'));
        var rows = Array.prototype.slice.call(form.querySelectorAll('.hide-field-row'));
        var counter = form.querySelector('[data-hide-fields-counter]');
        var emptyNote = form.querySelector('[data-hide-fields-empty]');
        var searchInput = form.querySelector('[data-hide-fields-search]');
        var applyBtn = form.querySelector('[data-hide-fields-apply]');

        var total = counter ? (parseInt(counter.getAttribute('data-total'), 10) || toggles.length) : toggles.length;

        function hiddenCount() {
            var n = 0;
            toggles.forEach(function (t) {
                if (!t.checked) {
                    n++;
                }
            });
            return n;
        }

        function refreshCounter() {
            if (!counter) {
                return;
            }
            counter.textContent = hiddenCount() + ' / ' + total + ' alan gizli';
        }

        var submitTimer = null;
        function scheduleSubmit() {
            if (submitTimer) {
                window.clearTimeout(submitTimer);
            }
            form.classList.add('is-pending');
            submitTimer = window.setTimeout(function () {
                submitTimer = null;
                form.submit();
            }, 350);
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                refreshCounter();
                scheduleSubmit();
            });
        });

        if (applyBtn) {
            applyBtn.hidden = true;
        }

        var keys = rows.map(function (row) {
            var nameEl = row.querySelector('.hide-field-name');
            return (nameEl ? nameEl.textContent : '').trim().toLowerCase();
        });

        function applyFilter() {
            var q = searchInput.value.trim().toLowerCase();
            var visible = 0;

            rows.forEach(function (row, i) {
                var match = q === '' || keys[i].indexOf(q) !== -1;
                row.classList.toggle('is-filtered-out', !match);
                if (match) {
                    visible++;
                }
            });

            if (emptyNote) {
                emptyNote.hidden = visible !== 0;
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilter);

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && searchInput.value !== '') {
                    e.stopPropagation();
                    searchInput.value = '';
                    applyFilter();
                }
            });
        }

        refreshCounter();
    });
})();
