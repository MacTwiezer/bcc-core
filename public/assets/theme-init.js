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
// ---------------------------------------------------------------------------
// UI ÖLÇEĞİ — theme.css'teki `:root { zoom: var(--bcc-zoom) }` kuralının JS
// tarafındaki karşılığı. <head>'de, her sayfada tanımlıdır (bu dosya tüm
// sayfalarca yükleniyor), yani DOMContentLoaded kodları ona güvenebilir.
//
// ⚠️ NEDEN GEREKLİ: zoom altında tarayıcı İKİ AYRI piksel uzayı kullanır:
//   • getBoundingClientRect(), window.innerWidth/innerHeight ve fare
//     olaylarının clientX/clientY  -> GÖRSEL piksel (zoom ile ÇARPILMIŞ)
//   • offsetWidth/offsetHeight/offsetTop, scrollLeft ve element.style'a
//     YAZDIĞIMIZ px değerleri      -> YERLEŞİM pikseli (çarpılmamış)
// İkisi karıştırılınca hata TAM OLARAK zoom oranı kadar olur. Kullanıcının
// bildirdiği hata buydu: rect'ten okunup style.top/right'a yazılan kart "⋯"
// menüsü, 1920px'lik ekranda (zoom 1.25) %25 aşağı-sola kayıyordu; dizüstünde
// zoom 1 olduğu için aynı kod orada doğru çalışıyordu.
//
// KURAL: rect / innerWidth / clientX'ten gelen bir sayıyı style'a yazmadan ya
// da offset* / scroll* ile karşılaştırmadan ÖNCE bu orana BÖL.
//
// zoom desteklemeyen tarayıcıda (ör. Firefox 126 öncesi) computed değer sayıya
// çevrilemez -> 1 döner; orada CSS kuralı da uygulanmadığı için hesap yine tutar.
window.bcc_uiScale = function () {
    var z = parseFloat(window.getComputedStyle(document.documentElement).zoom);
    return (z && isFinite(z) && z > 0) ? z : 1;
};

// ---------------------------------------------------------------------------
// ORTAK POST YARDIMCISI — window.bcc_post(url, params) -> Promise<{httpOk,data}>
//
// NEDEN BURADA (denetimde bulundu): aynı fetch sarmalayıcısı BEŞ ayrı dosyada
// tanımlıydı; üçü (account-page.js, grid-view-manage.js, grid.js) BİREBİR
// aynıydı. Tek yere alındı.
//
// Neden theme-init.js: bu dosya HER sayfada ve <head>'de SENKRON yükleniyor,
// yani tüketicilerden (hepsi defer) önce çalışacağı GARANTİ. Yeni bir paylaşılan
// dosya açmak, onu doğru sırayla beş sayfaya eklemeyi gerektirirdi — sıra
// hatası da sessiz bir "fonksiyon tanımsız" olurdu.
//
// DİZİ DESTEĞİ: kanban.js'in kendi kopyası dizileri "k[]" olarak gönderiyordu;
// bu yetenek buraya taşındı — dizi kullanmayan çağıranlar için davranış aynı.
//
// share-modal.js'in post()'u BİLEREK DIŞARIDA: o bir HTTP sarmalayıcısı değil,
// durum makinesi — busy bayrağı tutuyor, params'a CSRF/team_id ekliyor ve
// overlay'e sınıf basıyor. Ortaklaştırmak onu bozardı.
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
        // JSON gelmezse (500 HTML sayfası, proxy hatası) çağıran taraf yine
        // okunabilir bir mesaj görsün — sessiz "undefined" yerine.
        return res.json().catch(function () {
            return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
        }).then(function (data) {
            return { httpOk: res.ok, data: data };
        });
    });
};
