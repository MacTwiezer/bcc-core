(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var typeStep = document.getElementById('new-field-type-step');
        var grid = typeStep ? typeStep.querySelector('.field-type-grid') : null;
        if (!typeStep || !grid) {
            return;
        }

        var options = Array.prototype.slice.call(grid.querySelectorAll('.field-type-option'));
        if (options.length < 8) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'tf-type-search';
        wrap.innerHTML = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
            + '<circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.5"/>'
            + '<path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

        var input = document.createElement('input');
        input.type = 'search';
        input.autocomplete = 'off';
        input.placeholder = 'Alan tipi ara…';
        input.setAttribute('aria-label', 'Alan tipi ara');
        wrap.appendChild(input);

        var empty = document.createElement('p');
        empty.className = 'tf-type-empty';
        empty.textContent = 'Eşleşen alan tipi yok.';
        empty.hidden = true;

        typeStep.insertBefore(wrap, grid);
        typeStep.appendChild(empty);

        var haystacks = options.map(function (btn) {
            var label = btn.getAttribute('data-field-type-label') || btn.textContent;
            return label.toLocaleLowerCase('tr');
        });

        input.addEventListener('input', function () {
            var q = input.value.trim().toLocaleLowerCase('tr');
            var visible = 0;

            options.forEach(function (btn, i) {
                var match = q === '' || haystacks[i].indexOf(q) !== -1;
                btn.classList.toggle('tf-hidden', !match);
                if (match) {
                    visible++;
                }
            });

            empty.hidden = visible !== 0;
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                e.stopPropagation();
                input.value = '';
                input.dispatchEvent(new Event('input'));
            }
        });
    });
})();

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var detailsStep = document.getElementById('new-field-details-step');
        var changeBtn = document.getElementById('new-field-type-change');
        var chosenLabel = document.getElementById('new-field-type-chosen-label');

        if (!detailsStep || !changeBtn || !chosenLabel) {
            return;
        }

        var backdrop = document.createElement('div');
        backdrop.className = 'tf-modal-backdrop';
        backdrop.hidden = true;
        document.body.appendChild(backdrop);

        var hint = detailsStep.querySelector('.hint');
        if (hint) {
            var head = document.createElement('div');
            head.className = 'tf-modal-head';

            var title = document.createElement('h3');
            title.className = 'tf-modal-title';
            title.appendChild(chosenLabel);

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'tf-modal-close';
            closeBtn.setAttribute('aria-label', 'Kapat');
            closeBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" '
                + 'stroke="currentColor" stroke-width="2" stroke-linecap="round">'
                + '<path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

            head.appendChild(title);
            head.appendChild(changeBtn);
            head.appendChild(closeBtn);
            hint.parentNode.replaceChild(head, hint);

            closeBtn.addEventListener('click', function () {
                changeBtn.click();
            });
        }

        function sync() {
            var open = !detailsStep.hidden;
            backdrop.hidden = !open;
            document.body.classList.toggle('tf-modal-open', open);
        }

        new MutationObserver(sync).observe(detailsStep, {
            attributes: true,
            attributeFilter: ['hidden']
        });
        sync();

        backdrop.addEventListener('click', function () {
            changeBtn.click();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !detailsStep.hidden) {
                changeBtn.click();
            }
        });
    });
})();

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('tf-edit-modal');
        if (!modal) {
            return;
        }

        var closeUrl = modal.getAttribute('data-close-url');
        var form = document.getElementById('tf-edit-form');
        var list = modal.querySelector('[data-tf-choice-list]');
        var template = modal.querySelector('[data-tf-choice-template]');
        var addBtn = modal.querySelector('[data-tf-choice-add]');
        var empty = modal.querySelector('[data-tf-choice-empty]');
        var choicesBox = modal.querySelector('[data-tf-choices]');
        var typeSelect = modal.querySelector('[data-tf-edit-type]');
        var selectTypes = window.BCC_SELECT_FIELD_TYPES || ['single_select', 'multiple_select'];
        var nextRow = list ? list.querySelectorAll('[data-tf-choice-row]').length : 0;

        document.body.classList.add('tf-modal-open');

        function close() {
            window.location.href = closeUrl;
        }

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                close();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                close();
            }
        });

        function rowCount() {
            return list.querySelectorAll('[data-tf-choice-row]').length;
        }

        function syncEmpty() {
            if (empty) {
                empty.hidden = rowCount() > 0;
            }
        }

        function addRow(focus) {
            var holder = document.createElement('div');
            holder.innerHTML = template.innerHTML.replace(/__ROW__/g, String(nextRow++)).trim();
            var row = holder.firstElementChild;
            var radios = row.querySelectorAll('input[type="radio"]');
            if (radios.length) {
                radios[rowCount() % radios.length].checked = true;
            }
            list.appendChild(row);
            syncEmpty();
            if (focus) {
                row.querySelector('.tf-choice-input').focus();
            }
            return row;
        }

        if (list && template && addBtn) {
            addBtn.addEventListener('click', function () {
                addRow(true);
            });

            list.addEventListener('click', function (e) {
                var removeBtn = e.target.closest('[data-tf-choice-remove]');
                if (!removeBtn) {
                    return;
                }
                var row = removeBtn.closest('[data-tf-choice-row]');
                var next = row.nextElementSibling || row.previousElementSibling;
                row.parentNode.removeChild(row);
                syncEmpty();
                if (next && next.querySelector('.tf-choice-input')) {
                    next.querySelector('.tf-choice-input').focus();
                } else {
                    addBtn.focus();
                }
            });

            list.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || !e.target.classList.contains('tf-choice-input')) {
                    return;
                }
                e.preventDefault();
                var row = e.target.closest('[data-tf-choice-row]');
                if (row && row.nextElementSibling) {
                    row.nextElementSibling.querySelector('.tf-choice-input').focus();
                } else {
                    addRow(true);
                }
            });
        }

        if (typeSelect && choicesBox) {
            typeSelect.addEventListener('change', function () {
                var type = typeSelect.value;
                var isSelect = selectTypes.indexOf(type) !== -1;
                choicesBox.hidden = !isSelect;
                if (isSelect && list && rowCount() === 0) {
                    addRow(false);
                }
                Array.prototype.forEach.call(modal.querySelectorAll('[data-tf-extra]'), function (box) {
                    var active = box.getAttribute('data-tf-extra') === type;
                    box.hidden = !active;
                    Array.prototype.forEach.call(box.querySelectorAll('input'), function (input) {
                        input.disabled = !active;
                    });
                });
                var requiredRow = modal.querySelector('[data-tf-required-row]');
                if (requiredRow) {
                    requiredRow.hidden = type === 'autonumber';
                }
            });
        }

        var nameInput = form ? form.querySelector('input[name="name"]') : null;
        if (nameInput) {
            nameInput.focus();
            nameInput.select();
        }
    });
})();
