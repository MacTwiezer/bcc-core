(function () {
    var AUTH_PAGES = [
        'login.php',
        'register.php',
        'forgot-password.php',
        'reset-password.php',
        'verify_email.php'
    ];
    var page = window.location.pathname.split('/').pop().toLowerCase();

    var isAuthPage = AUTH_PAGES.indexOf(page) !== -1;

    if (isAuthPage) {
        try {
            window.localStorage.removeItem('bcc_theme');
        } catch (e) {}

        document.documentElement.setAttribute('data-theme', 'light');

        return;
    }
    
    var stored = null;

    try {
        stored = window.localStorage.getItem('bcc_theme');
    } catch (e) {}

    if (stored === 'dark' || stored === 'light') {
        document.documentElement.setAttribute('data-theme', stored);
    }
})();
window.bcc_uiScale = function () {
    var z = parseFloat(window.getComputedStyle(document.documentElement).zoom);
    return (z && isFinite(z) && z > 0) ? z : 1;
};

window.bcc_post = function (url, params) {
    var body = new URLSearchParams();
    Object.keys(params || {}).forEach(function (k) {
        if (Array.isArray(params[k])) {
            params[k].forEach(function (v) { body.append(k + '[]', v); });
            return;
        }
        body.append(k, params[k]);
    });

    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
    }).then(function (res) {
        return res.json().catch(function () {
            return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
        }).then(function (data) {
            return { httpOk: res.ok, data: data };
        });
    });
};
