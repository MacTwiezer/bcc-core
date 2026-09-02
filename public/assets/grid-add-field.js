(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var menu = document.querySelector('.grid-add-field-menu');
        var summary = menu ? menu.querySelector(':scope > summary') : null;
        var panel = menu ? menu.querySelector(':scope > .grid-add-field-panel') : null;

        if (menu && summary && panel) {
            window.bcc_bindFloatingPanel(menu, panel, summary, { align: 'right' });
        }

        var form = document.querySelector('[data-grid-add-field]');
        if (!form) {
            return;
        }

        var submitBtn = form.querySelector('button[type="submit"]');
        var keepOpen = form.hasAttribute('data-grid-add-field-keep-open');

        function setPending(value) {
            if (keepOpen && typeof window.bcc_gridFieldPending === 'function') {
                window.bcc_gridFieldPending(value);
            }
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (submitBtn) {
                submitBtn.disabled = true;
            }
            setPending(true);

            var body = new URLSearchParams(new FormData(form));

            fetch('/api/field_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            }).then(function (res) {
                return res.json().catch(function () {
                    return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                });
            }).then(function (data) {
                if (data && data.ok) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                    if (keepOpen && typeof window.bcc_gridFieldAdded === 'function') {
                        window.bcc_gridFieldAdded(data);
                        return;
                    }
                    window.location.reload();
                    return;
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                setPending(false);
                window.alert((data && data.error) || 'Alan oluşturulamadı.');
            }).catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                setPending(false);
                window.alert('Alan oluşturulamadı (bağlantı hatası).');
            });
        });
    });
})();

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var trigger = document.getElementById('gs-empty-fields-trigger');
        var modal = document.getElementById('gs-empty-fields-modal');

        if (!trigger || !modal) {
            return;
        }

        var addedBox = document.getElementById('gs-empty-fields-added');
        var addedList = document.getElementById('gs-empty-fields-added-list');
        var doneRow = document.getElementById('gs-empty-fields-done-row');
        var doneBtn = document.getElementById('gs-empty-fields-done');
        var typeStep = document.getElementById('new-field-type-step');
        var detailsStep = document.getElementById('new-field-details-step');
        var nameInput = document.getElementById('new-field-name-input');
        var addedCount = 0;
        var createPending = false;

        window.bcc_gridFieldPending = function (value) {
            createPending = !!value;
        };

        function close() {
            if (addedCount > 0 || createPending) {
                window.location.reload();
                return;
            }
            modal.hidden = true;
        }

        window.bcc_gridFieldAdded = function (data) {
            addedCount++;
            createPending = false;

            if (addedList) {
                var chip = document.createElement('span');
                chip.className = 'gs-empty-fields-added-chip';
                chip.textContent = (data && data.name) ? data.name : 'Sütun';
                addedList.appendChild(chip);
            }
            if (addedBox) {
                addedBox.hidden = false;
            }
            if (doneRow) {
                doneRow.hidden = false;
            }

            if (detailsStep && typeStep) {
                detailsStep.hidden = true;
                typeStep.hidden = false;
            }
            if (nameInput) {
                nameInput.value = '';
            }

            var firstType = modal.querySelector('.field-type-option');
            if (firstType) {
                firstType.focus();
            }
        };

        trigger.addEventListener('click', function () {
            modal.hidden = false;
            var firstType = modal.querySelector('.field-type-option');
            if (firstType) {
                firstType.focus();
            }
        });

        if (doneBtn) {
            doneBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }

        document.getElementById('gs-empty-fields-close').addEventListener('click', close);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                close();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) {
                close();
            }
        });
    });
})();
