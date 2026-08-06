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

        var active;
        if (stored === 'dark' || stored === 'light') {
            active = stored;
        } else {
            // Bulunan gerçek bug: kullanıcı hiç tema seçmemişse (localStorage boş)
            // burada her zaman 'light' varsayılıyordu. theme-init.js bu durumda
            // data-theme'i HİÇ yazmıyor, theme.css'in prefers-color-scheme:dark
            // kuralı (bkz. :root:not([data-theme]) seçicisi) sayfayı işletim
            // sistemi koyu istiyorsa GERÇEKTEN koyu render ediyor — menüdeki
            // işaretli seçenek sayfanın fiilen gösterdiği temeyle çelişiyordu.
            active = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }

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
    // require ediliyor). Adım 3d: aynı modale "Kayıtlar" bölümü eklendi —
    // satır render + geri-yükle mantığı TEK bir fonksiyona (renderTrashSection)
    // çıkarıldı, base'ler VE kayıtlar AYNI fonksiyonu çağırıyor, kopya yok.
    var trashOpenBtn = menu.querySelector('[data-account-trash-open]');
    var trashOverlay = document.querySelector('.bcc-trash-overlay');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = csrfMeta ? csrfMeta.content : '';

    if (trashOpenBtn && trashOverlay) {
        var trashList = trashOverlay.querySelector('[data-trash-list]');
        var trashEmpty = trashOverlay.querySelector('[data-trash-empty]');
        var trashRecordList = trashOverlay.querySelector('[data-trash-record-list]');
        var trashRecordEmpty = trashOverlay.querySelector('[data-trash-record-empty]');
        var trashCloseBtn = trashOverlay.querySelector('[data-account-trash-close]');

        function closeTrash() {
            trashOverlay.hidden = true;
        }

        // listEl/emptyEl: hedef bölüm. idAttr: satırın taşıyacağı data-* (yalnızca
        // hata ayıklama/gelecekteki kullanım için, işlevsel olarak kullanılmıyor).
        // restoreUrl/idParam: "Geri Yükle" hangi uçnoktaya, hangi POST alanıyla gitsin.
        function renderTrashSection(items, listEl, emptyEl, idAttr, restoreUrl, idParam) {
            Array.prototype.forEach.call(listEl.querySelectorAll('.bcc-trash-item'), function (el) {
                el.remove();
            });

            emptyEl.hidden = items.length > 0;

            items.forEach(function (item) {
                var row = document.createElement('div');
                row.className = 'bcc-trash-item';
                row.setAttribute(idAttr, item.id);

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
                        var params = {};
                        params.csrf_token = CSRF;
                        params[idParam] = item.id;
                        fetch(restoreUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams(params).toString(),
                        }).then(function (res) {
                            return res.json().catch(function () { return { ok: false }; });
                        }).then(function (data) {
                            if (data && data.ok) {
                                row.remove();
                                if (!listEl.querySelector('.bcc-trash-item')) {
                                    emptyEl.hidden = false;
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

                listEl.appendChild(row);
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
                        renderTrashSection(data.items, trashList, trashEmpty, 'data-trash-base-id', '/api/base_restore.php', 'base_id');
                    }
                })
                .catch(function () {});

            if (trashRecordList && trashRecordEmpty) {
                fetch('/api/trash_records_list.php')
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            renderTrashSection(data.items, trashRecordList, trashRecordEmpty, 'data-trash-record-id', '/api/record_restore.php', 'record_id');
                        }
                    })
                    .catch(function () {});
            }
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
