(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var openModal = null;
        var lastTrigger = null;

        function removeError(modal) {
            var error = modal.querySelector('.home-modal-error');
            if (error) {
                error.parentNode.removeChild(error);
            }
        }

        function show(modal, trigger) {
            if (openModal && openModal !== modal) {
                hide(openModal);
            }
            openModal = modal;
            lastTrigger = trigger || null;
            modal.hidden = false;
            var nameInput = modal.querySelector('input[name="name"]');
            if (nameInput) {
                nameInput.focus();
                nameInput.select();
            }
        }

        function hide(modal) {
            modal.hidden = true;
            removeError(modal);
            if (openModal === modal) {
                openModal = null;
            }
            if (window.history && window.history.replaceState && /[?&]edit=/.test(window.location.search)) {
                var params = new URLSearchParams(window.location.search);
                params.delete('edit');
                var query = params.toString();
                window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
            }
            if (lastTrigger && document.body.contains(lastTrigger)) {
                lastTrigger.focus();
            }
        }

        Array.prototype.forEach.call(document.querySelectorAll('#bt-create-modal, #bt-edit-modal'), function (modal) {
            Array.prototype.forEach.call(modal.querySelectorAll('[data-bt-modal-close]'), function (btn) {
                btn.addEventListener('click', function () {
                    hide(modal);
                });
            });

            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    hide(modal);
                }
            });

            var form = modal.querySelector('form');
            if (form) {
                form.addEventListener('submit', function () {
                    var submit = form.querySelector('button[type="submit"]');
                    if (submit) {
                        submit.disabled = true;
                    }
                });
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && openModal) {
                hide(openModal);
            }
        });

        var createModal = document.getElementById('bt-create-modal');
        if (createModal) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-bt-create-open]'), function (trigger) {
                trigger.addEventListener('click', function (e) {
                    e.preventDefault();
                    show(createModal, trigger);
                });
            });
        }

        var editModal = document.getElementById('bt-edit-modal');
        if (editModal) {
            var editForm = editModal.querySelector('form');
            Array.prototype.forEach.call(document.querySelectorAll('[data-bt-edit-open]'), function (trigger) {
                trigger.addEventListener('click', function (e) {
                    e.preventDefault();
                    removeError(editModal);
                    editForm.querySelector('input[name="table_id"]').value = trigger.getAttribute('data-table-id');
                    editForm.querySelector('input[name="name"]').value = trigger.getAttribute('data-table-name');
                    editForm.querySelector('input[name="description"]').value = trigger.getAttribute('data-table-description');
                    show(editModal, trigger);
                });
            });
        }

        var startOpen = document.querySelector('#bt-create-modal:not([hidden]), #bt-edit-modal:not([hidden])');
        if (startOpen) {
            var startTrigger = null;
            if (startOpen === editModal) {
                var tableId = editModal.querySelector('input[name="table_id"]').value;
                startTrigger = document.querySelector('[data-bt-edit-open][data-table-id="' + tableId + '"]');
            } else {
                startTrigger = document.querySelector('[data-bt-create-open]');
            }
            show(startOpen, startTrigger);
        }
    });
})();
