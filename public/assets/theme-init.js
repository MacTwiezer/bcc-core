// Her sayfanın <head>'inde, stylesheet'lerden ÖNCE, defer'sız/senkron
// yüklenir (view-mode FOUC-önleme deseniyle AYNI mantık, bkz.
// src/partials/home_shell_top.php) — CSS boyanmadan önce <html>'e
// data-theme yazar, açık/koyu sıçraması (FOUC) olmaz. TEK kopya: bu dosya
// dashboard/starred/workspaces/account (home_shell_top.php), grid.php,
// interface.php, login.php, register.php tarafından paylaşılır.
(function () {
    var stored = null;
    try { stored = window.localStorage.getItem('bcc_theme'); } catch (e) {}
    if (stored === 'dark' || stored === 'light') {
        document.documentElement.setAttribute('data-theme', stored);
    }
})();
