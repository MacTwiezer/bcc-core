(function () {
    // Giriş yapmadan erişilen authentication sayfaları.
    var AUTH_PAGES = [
        'login.php',
        'register.php',
        'forgot-password.php',
        'reset-password.php',
        'verify_email.php'
    ];
    // URL'deki mevcut PHP dosyasının adını alıyoruz.
    var page = window.location.pathname.split('/').pop().toLowerCase();

    // Mevcut sayfanın authentication sayfası olup olmadığını belirleniyor.
    var isAuthPage = AUTH_PAGES.indexOf(page) !== -1;

    // AUTHENTICATION SAYFALARI
    if (isAuthPage) {
        // Önceki kullanıcının dark/light tercihini temizle.
        try {
            window.localStorage.removeItem('bcc_theme');
        } catch (e) {}

        // Authentication sayfalarını hep light yap.
        document.documentElement.setAttribute('data-theme', 'light');

        return;
    }
    // GİRİŞ YAPILMIŞ / UYGULAMA SAYFALARI
    
    var stored = null;

    try {
        stored = window.localStorage.getItem('bcc_theme');
    } catch (e) {}

    // Kullanıcının daha önceden seçtiği tema varsa uygula.
    if (stored === 'dark' || stored === 'light') {
        document.documentElement.setAttribute('data-theme', stored);
    }
})();