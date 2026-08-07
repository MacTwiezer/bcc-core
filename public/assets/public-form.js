(function () {
    'use strict';

    // Herkese açık form gönderimi (public/form.php) — Grup View-Form.
    //
    // ⚠️ Bu dosya OTURUMSUZ bir sayfada çalışıyor. CSRF token'ı YOK ve olmamalı
    // (bkz. src/form_security.php: klasik CSRF burada korunacak bir şey değil,
    // çünkü gönderen zaten yetkisiz ve form zaten herkese açık). Korumalar
    // sunucuda: honeypot + zaman-bazlı HMAC nonce.
    //
    // İstemci tarafı doğrulama BİLEREK YOK (form novalidate) — tek doğrulama
    // yeri sunucu (form_submit.php + normalize_cell_value). İstemcide ikinci bir
    // kural yazmak, ikisinin zamanla ayrışması demek olurdu.

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('public-form');
        var messageBox = document.getElementById('form-message');
        var submitBtn = document.getElementById('form-submit-btn');

        if (!form || !messageBox || !submitBtn) {
            return;
        }

        function showMessage(text, isError) {
            messageBox.textContent = text;
            messageBox.className = 'public-form-message ' + (isError ? 'is-error' : 'is-success');
            messageBox.hidden = false;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var data = new FormData();

            // multiple_select: her seçili seçenek AYNI ada birden çok kez
            // eklenir; sunucu bunları JSON dizisine çevirir (grid.js'in
            // JSON.stringify yaklaşımından farklı — burada FormData doğal
            // çoklu değeri zaten taşıyor, elle JSON kurmaya gerek yok).
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name) {
                    return;
                }
                if (el.tagName === 'SELECT' && el.multiple) {
                    Array.prototype.forEach.call(el.options, function (opt) {
                        if (opt.selected) {
                            data.append(el.name + '[]', opt.value);
                        }
                    });
                    return;
                }
                if (el.type === 'checkbox') {
                    data.append(el.name, el.checked ? '1' : '0');
                    return;
                }
                data.append(el.name, el.value);
            });

            submitBtn.disabled = true;
            submitBtn.textContent = 'Gönderiliyor…';

            fetch('/api/form_submit.php', { method: 'POST', body: data })
                .then(function (res) {
                    return res.json().then(function (json) {
                        return { ok: res.ok, data: json };
                    }).catch(function () {
                        return { ok: false, data: null };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.data && result.data.ok) {
                        // Başarıda formu GİZLE — aynı gönderimin yanlışlıkla
                        // tekrarlanmasını engeller (nonce zaten tek sayfa
                        // render'ına bağlı, ama kullanıcı deneyimi de net olsun).
                        form.hidden = true;
                        showMessage(result.data.message || 'Kaydınız alındı.', false);
                        return;
                    }

                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Gönder';
                    showMessage(
                        (result.data && result.data.error) ? result.data.error : 'Gönderilemedi, lütfen tekrar deneyin.',
                        true
                    );
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Gönder';
                    showMessage('Bağlantı hatası, lütfen tekrar deneyin.', true);
                });
        });
    });
}());
