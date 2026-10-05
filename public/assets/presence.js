/*
 * Temsilci durumu nabzi (2026-10-05) — girisli her sayfada yuklenir.
 *
 * Dakikada bir /api/presence_ping.php'ye istek atar. Istek oturumu canli tutar
 * (bekleyen temsilci cikisa dusmez) ve o dakika icinde fare/klavye kullanilip
 * kullanilmadigini bildirir. Sunucu buna gore temsilciyi Aktif ya da Pasif
 * gosterir (src/auth.php). Temsilci icin hicbir sey degismez: pasifken sayfa
 * oldugu gibi durur, dokundugu anda tekrar Aktif olur.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = meta ? meta.content : '';

    if (!CSRF || !window.fetch) {
        return;
    }

    var PING_MS = 60000;

    /* Sayfanin acilmasi zaten bir islem (sunucu onu saydi); ilk nabiz yalnizca
       acilistan SONRAKI hareketi bildirir. */
    var dirty = false;
    var lastSentActive = true;
    var stopped = false;

    function ping() {
        if (stopped) {
            return;
        }

        var active = dirty;
        dirty = false;
        lastSentActive = active;

        var data = new FormData();
        data.append('csrf_token', CSRF);
        data.append('active', active ? '1' : '0');

        fetch('/api/presence_ping.php', { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (res) {
                /* Oturum bittiyse (baska sekmeden cikis) bosuna yoklama. */
                if (res.status === 401 || res.status === 403) {
                    stopped = true;
                }
            })
            .catch(function () { /* ag hatasi: sonraki nabiz yeniden dener */ });
    }

    function onInteraction() {
        if (dirty) {
            return;
        }
        dirty = true;

        /* Pasiften donus: bir sonraki nabzi beklemeden hemen bildir. */
        if (!lastSentActive) {
            ping();
        }
    }

    ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach(function (name) {
        window.addEventListener(name, onInteraction, { capture: true, passive: true });
    });

    window.setInterval(ping, PING_MS);
})();
