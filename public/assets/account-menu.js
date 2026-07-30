// Ortak hesap menüsü davranışı (src/partials/account_menu.php ile birlikte kullanılır).
// Sayfa başına tek menü varsayılır (dashboard.php'de "home", grid.php'de "gs",
// interface.php'de "if" öneki) — seçiciler data-account-toggle / data-account-menu
// olduğu için sınıf öneki fark etmez.
(function () {
    var toggle = document.querySelector('[data-account-toggle]');
    var menu = document.querySelector('[data-account-menu]');

    if (!toggle || !menu) {
        return;
    }

    // "Görünüm" alt-paneli — sayfa yenilenmeden ("main" <-> "appearance")
    // geçiş, tema toggle'ı ile AYNI localStorage anahtarı/mekanizması (bkz.
    // theme-init.js — sayfa boyanmadan önce <html data-theme> yazan senkron
    // script, ikinci bir kopya YOK, burada yalnızca kullanıcı tıklayınca
    // anlık uygulama + kalıcı kayıt yapılır).
    var THEME_STORAGE_KEY = 'bcc_theme';
    var pages = menu.querySelectorAll('[data-account-page]');
    var appearanceOpenBtn = menu.querySelector('[data-account-appearance-open]');
    var appearanceBackBtn = menu.querySelector('[data-account-appearance-back]');
    var themeButtons = menu.querySelectorAll('[data-theme-option]');

    function showAccountPage(name) {
        pages.forEach(function (p) {
            p.hidden = p.getAttribute('data-account-page') !== name;
        });
    }

    function syncThemeChecks() {
        var stored = null;
        try { stored = window.localStorage.getItem(THEME_STORAGE_KEY); } catch (e) {}
        var active = stored === 'dark' ? 'dark' : 'light';

        themeButtons.forEach(function (btn) {
            var check = btn.querySelector('[data-theme-check]');
            if (check) {
                check.classList.toggle('is-active', btn.getAttribute('data-theme-option') === active);
            }
        });
    }

    if (appearanceOpenBtn) {
        appearanceOpenBtn.addEventListener('click', function () {
            syncThemeChecks();
            showAccountPage('appearance');
        });
    }
    if (appearanceBackBtn) {
        appearanceBackBtn.addEventListener('click', function () {
            showAccountPage('main');
        });
    }

    themeButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var value = btn.getAttribute('data-theme-option');

            try {
                window.localStorage.setItem(THEME_STORAGE_KEY, value);
            } catch (e) {}

            document.documentElement.setAttribute('data-theme', value);
            syncThemeChecks();
        });
    });

    // Trash — Airtable workspace trash referansı. Overlay .X-account'ın
    // DIŞINDA (sayfa-geneli), bu yüzden document üzerinden aranıyor; tek
    // sayfada en fazla bir tane olur (account_menu.php sayfa başına tek kez
    // require ediliyor).
    var trashOpenBtn = menu.querySelector('[data-account-trash-open]');
    var trashOverlay = document.querySelector('.bcc-trash-overlay');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = csrfMeta ? csrfMeta.content : '';

    if (trashOpenBtn && trashOverlay) {
        var trashList = trashOverlay.querySelector('[data-trash-list]');
        var trashEmpty = trashOverlay.querySelector('[data-trash-empty]');
        var trashCloseBtn = trashOverlay.querySelector('[data-account-trash-close]');

        function closeTrash() {
            trashOverlay.hidden = true;
        }

        function renderTrashItems(items) {
            Array.prototype.forEach.call(trashList.querySelectorAll('.bcc-trash-item'), function (el) {
                el.remove();
            });

            trashEmpty.hidden = items.length > 0;

            items.forEach(function (item) {
                var row = document.createElement('div');
                row.className = 'bcc-trash-item';
                row.setAttribute('data-trash-base-id', item.id);

                var avatar = document.createElement('div');
                avatar.className = 'bcc-trash-item-avatar';
                avatar.textContent = item.actor_initial;
                row.appendChild(avatar);

                var body = document.createElement('div');
                body.className = 'bcc-trash-item-body';

                var message = document.createElement('div');
                message.className = 'bcc-trash-item-message';
                message.textContent = item.message;
                body.appendChild(message);

                var time = document.createElement('div');
                time.className = 'bcc-trash-item-time';
                time.textContent = item.relative_date;
                body.appendChild(time);

                row.appendChild(body);

                if (item.can_restore) {
                    var restoreBtn = document.createElement('button');
                    restoreBtn.type = 'button';
                    restoreBtn.className = 'bcc-trash-restore-btn';
                    restoreBtn.textContent = 'Geri Yükle';
                    restoreBtn.addEventListener('click', function () {
                        restoreBtn.disabled = true;
                        fetch('/api/base_restore.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ csrf_token: CSRF, base_id: item.id }).toString(),
                        }).then(function (res) {
                            return res.json().catch(function () { return { ok: false }; });
                        }).then(function (data) {
                            if (data && data.ok) {
                                row.remove();
                                if (!trashList.querySelector('.bcc-trash-item')) {
                                    trashEmpty.hidden = false;
                                }
                            } else {
                                restoreBtn.disabled = false;
                                window.alert((data && data.error) || 'Geri yüklenemedi.');
                            }
                        }).catch(function () {
                            restoreBtn.disabled = false;
                            window.alert('Geri yüklenemedi (bağlantı hatası).');
                        });
                    });
                    row.appendChild(restoreBtn);
                }

                trashList.appendChild(row);
            });
        }

        trashOpenBtn.addEventListener('click', function () {
            menu.classList.remove('is-open');
            showAccountPage('main');
            trashOverlay.hidden = false;

            fetch('/api/trash_list.php')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        renderTrashItems(data.items);
                    }
                })
                .catch(function () {});
        });

        if (trashCloseBtn) {
            trashCloseBtn.addEventListener('click', closeTrash);
        }

        window.bcc_bindDismissable(trashOverlay, {
            isOpen: function () { return !trashOverlay.hidden; },
            close: closeTrash,
            isClickOutside: function (target) { return target === trashOverlay; },
        });
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.classList.toggle('is-open');
    });

    // Dışarı tıklayınca kapatma artık projenin ortak mekanizmasıyla (bkz.
    // dismissable-panel.js) — elle yeniden yazılmış kopya kaldırıldı. Bu menü
    // native <details> DEĞİL (is-open class'ıyla açılıp kapanıyor), bu yüzden
    // isOpen/close override edilir; dışarı-tık koşulu varsayılanla (!el.contains)
    // ÖNCEKİYLE BİREBİR AYNI. Kapanınca "main" sayfaya sıfırlanır — tekrar
    // açılışta kullanıcı kaldığı yerde (Görünüm alt-panelinde) bulmasın diye.
    window.bcc_bindDismissable(menu, {
        isOpen: function () { return menu.classList.contains('is-open'); },
        close: function () {
            menu.classList.remove('is-open');
            showAccountPage('main');
        },
    });
})();
