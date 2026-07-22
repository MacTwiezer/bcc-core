// Ortak hesap menüsü davranışı (src/partials/account_menu.php ile birlikte kullanılır).
// Sayfa başına tek menü varsayılır (dashboard.php'de "home", grid.php'de "gs" öneki) —
// seçiciler data-account-toggle / data-account-menu olduğu için sınıf öneki fark etmez.
(function () {
    var toggle = document.querySelector('[data-account-toggle]');
    var menu = document.querySelector('[data-account-menu]');

    if (!toggle || !menu) {
        return;
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.classList.toggle('is-open');
    });

    document.addEventListener('click', function (e) {
        if (!menu.contains(e.target)) {
            menu.classList.remove('is-open');
        }
    });
})();
