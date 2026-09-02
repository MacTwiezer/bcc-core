(function () {
    'use strict';

    var backdrop = null;
    var titleEl = null;
    var messageEl = null;
    var confirmBtn = null;
    var cancelBtn = null;
    var closeBtn = null;
    var resolveFn = null;
    var lastFocused = null;

    function settle(result) {
        if (!resolveFn) {
            return;
        }
        var fn = resolveFn;
        resolveFn = null;
        backdrop.hidden = true;
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
        fn(result);
    }

    function build() {
        if (backdrop) {
            return;
        }

        backdrop = document.createElement('div');
        backdrop.className = 'home-modal-backdrop';
        backdrop.hidden = true;
        backdrop.innerHTML =
            '<div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="bcc-confirm-title">'
            + '<div class="home-modal-head">'
            + '<h2 id="bcc-confirm-title"></h2>'
            + '<button type="button" class="home-modal-close" aria-label="Kapat">'
            + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>'
            + '</button>'
            + '</div>'
            + '<div class="home-modal-form">'
            + '<p class="home-modal-label" data-confirm-message></p>'
            + '<div class="home-modal-actions">'
            + '<button type="button" class="home-modal-btn" data-confirm-cancel></button>'
            + '<button type="button" class="home-modal-btn home-modal-btn-primary" data-confirm-ok></button>'
            + '</div>'
            + '</div>'
            + '</div>';

        document.body.appendChild(backdrop);

        titleEl = backdrop.querySelector('#bcc-confirm-title');
        messageEl = backdrop.querySelector('[data-confirm-message]');
        confirmBtn = backdrop.querySelector('[data-confirm-ok]');
        cancelBtn = backdrop.querySelector('[data-confirm-cancel]');
        closeBtn = backdrop.querySelector('.home-modal-close');

        confirmBtn.addEventListener('click', function () { settle(true); });
        cancelBtn.addEventListener('click', function () { settle(false); });
        closeBtn.addEventListener('click', function () { settle(false); });

        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) {
                settle(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (!resolveFn) {
                return;
            }
            if (e.key === 'Escape') {
                settle(false);
            }
        });
    }

    window.bcc_confirm = function (options) {
        if (typeof options === 'string') {
            options = { message: options };
        }
        options = options || {};

        build();

        if (resolveFn) {
            settle(false);
        }

        lastFocused = document.activeElement;

        titleEl.textContent = options.title || 'Emin misiniz?';
        messageEl.textContent = options.message || '';
        confirmBtn.textContent = options.confirmLabel || 'Evet, sil';
        cancelBtn.textContent = options.cancelLabel || 'Vazgeç';
        confirmBtn.classList.toggle('home-modal-btn-danger', options.danger !== false);
        confirmBtn.classList.toggle('home-modal-btn-primary', options.danger === false);

        backdrop.hidden = false;
        cancelBtn.focus();

        return new Promise(function (resolve) {
            resolveFn = resolve;
        });
    };

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute) {
            return;
        }

        var message = form.getAttribute('data-confirm');
        if (!message) {
            return;
        }

        e.preventDefault();

        window.bcc_confirm({
            message: message,
            title: form.getAttribute('data-confirm-title') || 'Emin misiniz?',
            confirmLabel: form.getAttribute('data-confirm-label') || 'Evet, sil',
        }).then(function (ok) {
            if (ok) {
                form.submit();
            }
        });
    });
})();
