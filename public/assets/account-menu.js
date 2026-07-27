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

    // Dışarı tıklayınca kapatma artık projenin ortak mekanizmasıyla (bkz.
    // dismissable-panel.js) — elle yeniden yazılmış kopya kaldırıldı. Bu menü
    // native <details> DEĞİL (is-open class'ıyla açılıp kapanıyor), bu yüzden
    // isOpen/close override edilir; dışarı-tık koşulu varsayılanla (!el.contains)
    // ÖNCEKİYLE BİREBİR AYNI.
    window.bcc_bindDismissable(menu, {
        isOpen: function () { return menu.classList.contains('is-open'); },
        close: function () { menu.classList.remove('is-open'); },
    });
})();
