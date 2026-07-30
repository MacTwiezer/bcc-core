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
