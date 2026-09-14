/*
 * Slack toplu bildirimi — tarayici tarafi tetikleyici (2026-09-08).
 *
 * Sunucu, bir kaydin hucre degisikliklerini hemen duyurmuyor; kayda bir sure
 * dokunulmayinca hepsini TEK mesajda gonderiyor (src/slack.php). "Bir sure"nin
 * gectigini fark edecek bir arka plan isleyicisi (cron) YOK — bu dosya o
 * tetikleyicinin yerini tutuyor:
 *
 *   1. Sayfadan ayrilirken (sekme kapaniyor / arka plana atiliyor / baska
 *      sayfaya geciliyor) hemen bir ping atar. Normal senaryoda mesaji
 *      gonderten budur: kullanici isini bitirir, cikar, ozet gider.
 *   2. Sayfa acik kalirsa, son duzenlemeden sonra periyodik olarak dener.
 *
 * Ping bir SINYALDIR, emir degil: gonderip gondermemeye sunucu karar verir
 * (kayit hâlâ "taze" ise hicbir sey yapmaz). Bu yuzden fazladan ping zararsiz.
 * Kaybolma riskine karsi emniyet agi da sunucuda: ayni bosaltma sonraki her
 * yazma isteginde ve grid/arayuz sayfa yuklemesinde de calisir.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = meta ? meta.content : '';

    /* Tablo kimligi adres cubugundan okunuyor — grid-paste.js:462 ile ayni
       kaynak, boylece iki dosya farkli yerlerden farkli deger okuyamaz. */
    var TABLE_ID = new URLSearchParams(window.location.search).get('table_id') || '';

    if (!CSRF || !TABLE_ID) {
        return;
    }

    /* Sunucudaki bekleme suresi 180 sn; 60 sn'de bir yoklamak "sure doldugu an"
       ile "mesajin gittigi an" arasindaki farki bir dakikanin altinda tutar. */
    var POLL_MS = 60000;

    var dirty = false;
    var timer = null;

    /* Ayrilma pinginin bekleme suresi (2026-09-09). Tarayici "sekmeyi kapatti"
       ile "baska sekmeye gecti"yi ayni visibilitychange olayiyla haber veriyor,
       bu yuzden ikisi ayri ele aliniyor:
         pagehide         -> 0  : sayfa gercekten gidiyor, ozeti hemen gonder
         visibilitychange -> 30 : sekmeye bakip donmek ozeti IKIYE BOLMESIN
       Sunucu bu degeri sinirliyor (slack_flush.php), istemci beklemeyi
       uzatamaz. */
    var LEAVE_IDLE = 0;
    var HIDDEN_IDLE = 30;

    function payload(idle) {
        var data = new FormData();
        data.append('csrf_token', CSRF);
        data.append('table_id', TABLE_ID);

        /* Yoklama (flushAsync) idle gondermez — orada sunucunun varsayilan
           180 saniyesi dogru olan. */
        if (idle !== undefined) {
            data.append('idle', String(idle));
        }

        return data;
    }

    function flushBeacon(idle, sonPing) {
        if (!dirty) {
            return;
        }

        /* sendBeacon sayfa kapanirken bile teslim edilir; fetch bu anda
           iptal edilebilirdi. */
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/api/slack_flush.php', payload(idle));
        }

        /* Sekme kapanirken iki olay da tetikleniyor ve visibilitychange ONCE
           geliyor. Orada bayrak temizlenseydi, hemen ardindan gelen pagehide
           pingi (bekleme 0 — asil gonderen o) "temiz" gorup hic gitmezdi.
           Bu yuzden bayrak yalnizca son pingde temizleniyor; kullanici sekmeye
           geri donerse yoklama zamanlayicisi de calismaya devam etmis olur.
           Fazladan ping zararsiz: gonderilip gonderilmeyecegine sunucu karar
           veriyor. */
        if (sonPing) {
            dirty = false;
            stopTimer();
        }
    }

    function flushAsync() {
        if (!dirty) {
            return;
        }

        fetch('/api/slack_flush.php', {
            method: 'POST',
            body: payload(),
            credentials: 'same-origin',
        }).then(function (res) {
            return res.ok ? res.json() : null;
        }).then(function (data) {
            /* Sunucu gerceketen mesaj gonderdiyse bekleyen bir sey kalmadi. */
            if (data && data.ok && data.sent > 0) {
                dirty = false;
                stopTimer();
            }
        }).catch(function () {
            /* Baglanti hatasi: bayrak duruyor, bir sonraki turda tekrar denenir. */
        });
    }

    function stopTimer() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    function markDirty() {
        dirty = true;

        if (timer === null) {
            timer = window.setInterval(flushAsync, POLL_MS);
        }
    }

    /* Hucre kaydeden her yol (satir ici duzenleme, satir cekmecesi, yapistirma,
       kanban surukleme) sonunda /api/cell_update.php ya da
       /api/cells_bulk_update.php cagiriyor. Tek tek dinlemek yerine fetch
       sarmalanip "bu sayfada veri degisti" bilgisi tek yerden yakalaniyor. */
    var originalFetch = window.fetch;

    window.fetch = function (input, init) {
        var url = (typeof input === 'string') ? input : (input && input.url ? input.url : '');

        if (url.indexOf('/api/cell_update.php') !== -1 || url.indexOf('/api/cells_bulk_update.php') !== -1) {
            markDirty();
        }

        return originalFetch.apply(this, arguments);
    };

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flushBeacon(HIDDEN_IDLE, false);
        }
    });

    window.addEventListener('pagehide', function () {
        flushBeacon(LEAVE_IDLE, true);
    });
})();
