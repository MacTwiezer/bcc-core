(function () {
    'use strict';

    /* Kare 256px JPEG'e cevirme ortak: image-square.js (bu dosyadan once yuklenir). */
    var ACCEPT = window.BCC_IMAGE_ACCEPT;
    var MAX_INPUT_BYTES = window.BCC_IMAGE_MAX_INPUT_BYTES;

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-avatar-root]');
        if (!root) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        var pickBtn = root.querySelector('[data-avatar-pick]');
        var input = root.querySelector('[data-avatar-input]');
        var removeBtn = root.querySelector('[data-avatar-remove]');
        var statusEl = document.querySelector('[data-avatar-status]');

        function setStatus(message, isError) {
            if (!statusEl) {
                return;
            }
            statusEl.textContent = message || '';
            statusEl.classList.toggle('is-error', !!isError);
            statusEl.hidden = !message;
        }

        function setBusy(busy) {
            pickBtn.disabled = busy;
            removeBtn.disabled = busy;
            root.classList.toggle('is-busy', busy);
        }

        /* Sayfadaki butun "benim avatarim" kutulari: hesap sayfasindaki buyuk
           yuz ve ust cubuktaki hesap dugmesi. Yenilemeden ikisi birlikte
           guncelleniyor. */
        function faces() {
            var list = [];
            var view = root.querySelector('[data-avatar-view]');
            if (view) {
                list.push({ el: view, initial: root.getAttribute('data-initial') || '' });
            }
            Array.prototype.forEach.call(document.querySelectorAll('[data-avatar-self]'), function (el) {
                list.push({ el: el, initial: el.getAttribute('data-initial') || '' });
            });
            return list;
        }

        function showImage(url) {
            faces().forEach(function (f) {
                var img = document.createElement('img');
                img.className = 'bcc-avatar-img';
                img.alt = '';
                img.decoding = 'sync';
                img.src = url;
                f.el.textContent = '';
                f.el.appendChild(img);
            });
            removeBtn.hidden = false;
        }

        function showInitial() {
            faces().forEach(function (f) {
                f.el.textContent = f.initial;
            });
            removeBtn.hidden = true;
        }

        function readJson(res) {
            return res.json().catch(function () {
                return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
            });
        }

        pickBtn.addEventListener('click', function () {
            input.value = '';
            input.click();
        });

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) {
                return;
            }

            if (ACCEPT.indexOf(file.type) === -1) {
                setStatus('Yalnızca PNG, JPEG veya WebP resim seçebilirsiniz.', true);
                return;
            }
            if (file.size > MAX_INPUT_BYTES) {
                setStatus('Seçtiğiniz resim çok büyük (en fazla 15MB).', true);
                return;
            }

            setBusy(true);
            setStatus('Yükleniyor…', false);

            window.BCC_toSquareJpeg(file).then(function (blob) {
                var data = new FormData();
                data.append('csrf_token', CSRF);
                data.append('file', blob, 'avatar.jpg');

                return fetch('/api/avatar_upload.php', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                }).then(readJson);
            }).then(function (data) {
                if (!data || !data.ok || !data.url) {
                    throw new Error((data && data.error) || 'Fotoğraf yüklenemedi.');
                }
                /* Gomulu kopya varsa o: yeni bir istek beklenmeden ayni karede gorunur. */
                showImage(data.inline || data.url);
                setStatus('Profil fotoğrafı güncellendi.', false);
            }).catch(function (err) {
                setStatus((err && err.message) || 'Fotoğraf yüklenemedi.', true);
            }).then(function () {
                setBusy(false);
            });
        });

        removeBtn.addEventListener('click', function () {
            var ask = typeof window.bcc_confirm === 'function'
                ? window.bcc_confirm({
                    title: 'Profil fotoğrafını kaldır',
                    message: 'Fotoğrafınız kaldırılacak ve yerine adınızın baş harfi gösterilecek.',
                    confirmLabel: 'Kaldır'
                })
                : Promise.resolve(true);

            ask.then(function (ok) {
                if (!ok) {
                    return;
                }

                setBusy(true);
                setStatus('', false);

                var body = new URLSearchParams({ csrf_token: CSRF }).toString();

                fetch('/api/avatar_delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    credentials: 'same-origin'
                }).then(readJson).then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) || 'Fotoğraf kaldırılamadı.');
                    }
                    showInitial();
                    setStatus('Profil fotoğrafı kaldırıldı.', false);
                }).catch(function (err) {
                    setStatus((err && err.message) || 'Fotoğraf kaldırılamadı.', true);
                }).then(function () {
                    setBusy(false);
                });
            });
        });
    });
})();
