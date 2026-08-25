// Sayfa İÇİNDE onay penceresi — native window.confirm() YERİNE.
//
// NEDEN: window.confirm tarayıcının kendi kutusunu açıyor ("localhost web
// sitesinin mesajı…"), sayfanın görsel diliyle hiç ilgisi yok ve kullanıcı
// bunu bir sistem hatası gibi okuyor (bildirildi). grid.php'de yapıştırma ve
// tablo silme onayları ZATEN sayfa içi pencereyle çözülmüştü; bu dosya o
// deseni TEK bir yerde toplayıp tüm çağrı yerlerine açıyor — her onay için
// ayrı markup yazmak yerine.
//
// Görünüm .home-modal-* sınıflarından gelir (home.css); grid.php, interface.php
// ve home kabuğunu kullanan tüm sayfalar o dosyayı zaten yüklüyor, yani İKİNCİ
// bir modal stili yazılmadı.
//
// İKİ KULLANIM ŞEKLİ:
//   1) JS'ten:  window.bcc_confirm('Silinsin mi?').then(function (ok) { ... })
//      veya     window.bcc_confirm({ title: '...', message: '...', danger: true })
//   2) Düz formdan:  <form data-confirm="Bu kaydı silmek istiyor musunuz?">
//      (inline onsubmit="return confirm(...)" yerine — aşağıdaki tek dinleyici
//       tüm sayfadaki bu formları yakalar.)
(function () {
    'use strict';

    var backdrop = null;
    var titleEl = null;
    var messageEl = null;
    var confirmBtn = null;
    var cancelBtn = null;
    var closeBtn = null;
    var resolveFn = null;
    var lastFocused = null;

    function settle(result) {
        if (!resolveFn) {
            return;
        }
        var fn = resolveFn;
        resolveFn = null;
        backdrop.hidden = true;
        // Odak, pencereyi AÇAN öğeye döner — klavye kullanıcısı listenin
        // başına fırlamasın.
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
        fn(result);
    }

    function build() {
        if (backdrop) {
            return;
        }

        backdrop = document.createElement('div');
        backdrop.className = 'home-modal-backdrop';
        backdrop.hidden = true;
        backdrop.innerHTML =
            '<div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="bcc-confirm-title">'
            + '<div class="home-modal-head">'
            + '<h2 id="bcc-confirm-title"></h2>'
            + '<button type="button" class="home-modal-close" aria-label="Kapat">'
            + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>'
            + '</button>'
            + '</div>'
            + '<div class="home-modal-form">'
            + '<p class="home-modal-label" data-confirm-message></p>'
            + '<div class="home-modal-actions">'
            + '<button type="button" class="home-modal-btn" data-confirm-cancel></button>'
            + '<button type="button" class="home-modal-btn home-modal-btn-primary" data-confirm-ok></button>'
            + '</div>'
            + '</div>'
            + '</div>';

        document.body.appendChild(backdrop);

        titleEl = backdrop.querySelector('#bcc-confirm-title');
        messageEl = backdrop.querySelector('[data-confirm-message]');
        confirmBtn = backdrop.querySelector('[data-confirm-ok]');
        cancelBtn = backdrop.querySelector('[data-confirm-cancel]');
        closeBtn = backdrop.querySelector('.home-modal-close');

        confirmBtn.addEventListener('click', function () { settle(true); });
        cancelBtn.addEventListener('click', function () { settle(false); });
        closeBtn.addEventListener('click', function () { settle(false); });

        // Zemine tıklamak = vazgeç. Pencerenin İÇİNE tıklamak kapatmaz —
        // yanlışlıkla kapanma, onay penceresinde özellikle can sıkıcı olurdu.
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) {
                settle(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (!resolveFn) {
                return;
            }
            if (e.key === 'Escape') {
                settle(false);
            }
        });
    }

    window.bcc_confirm = function (options) {
        if (typeof options === 'string') {
            options = { message: options };
        }
        options = options || {};

        build();

        // Bir onay penceresi zaten açıksa öncekini VAZGEÇ olarak kapat: iki
        // ayrı akışın aynı DOM'u paylaşıp birbirinin cevabını çalması, sessiz
        // bir "yanlış kaydı sildim" hatasına dönüşebilirdi.
        if (resolveFn) {
            settle(false);
        }

        lastFocused = document.activeElement;

        titleEl.textContent = options.title || 'Emin misiniz?';
        messageEl.textContent = options.message || '';
        confirmBtn.textContent = options.confirmLabel || 'Evet, sil';
        cancelBtn.textContent = options.cancelLabel || 'Vazgeç';
        // Yıkıcı işlemlerde onay butonu KIRMIZI: mavi birincil buton "devam et"
        // hissi verir, silme onayında bu yanıltıcı.
        confirmBtn.classList.toggle('home-modal-btn-danger', options.danger !== false);
        confirmBtn.classList.toggle('home-modal-btn-primary', options.danger === false);

        backdrop.hidden = false;
        // Odak VAZGEÇ'te başlar: Enter'a refleksle basan kullanıcı yanlışlıkla
        // silmesin (yıkıcı işlemin varsayılanı "hayır" olmalı).
        cancelBtn.focus();

        return new Promise(function (resolve) {
            resolveFn = resolve;
        });
    };

    // Düz formlar için: <form data-confirm="mesaj">
    // Inline onsubmit="return confirm(...)" kullanan formların karşılığı.
    // submit olayı iptal edilir, onay gelirse form.submit() ile GERÇEKTEN
    // gönderilir — form.submit() submit olayını YENİDEN TETİKLEMEZ, bu yüzden
    // sonsuz döngü oluşmaz (bayrak tutmaya gerek yok).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute) {
            return;
        }

        var message = form.getAttribute('data-confirm');
        if (!message) {
            return;
        }

        e.preventDefault();

        window.bcc_confirm({
            message: message,
            title: form.getAttribute('data-confirm-title') || 'Emin misiniz?',
            confirmLabel: form.getAttribute('data-confirm-label') || 'Evet, sil',
        }).then(function (ok) {
            if (ok) {
                form.submit();
            }
        });
    });
})();
