(function () {
    'use strict';

    /* Sunucuda GD yok, resim orada kucultulemiyor. Kucultme burada: resim
       ortadan kare kirpilip 256px JPEG olarak yeniden kodlaniyor. Yan etkisi
       istenen bir sey: tuval yeniden kodlamasi EXIF'i (GPS konumu dahil) siler. */
    var SIDE = 256;
    var QUALITY = 0.9;
    var ACCEPT = ['image/png', 'image/jpeg', 'image/webp'];
    var MAX_INPUT_BYTES = 15 * 1024 * 1024;

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

        function toSquareJpeg(file) {
            return new Promise(function (resolve, reject) {
                var url = URL.createObjectURL(file);
                var img = new Image();

                img.onload = function () {
                    var w = img.naturalWidth;
                    var h = img.naturalHeight;
                    if (!w || !h) {
                        URL.revokeObjectURL(url);
                        reject(new Error('Resim okunamadı.'));
                        return;
                    }

                    var side = Math.min(w, h);
                    var canvas = document.createElement('canvas');
                    canvas.width = SIDE;
                    canvas.height = SIDE;

                    var ctx = canvas.getContext('2d');
                    /* JPEG saydamlik tasimaz: saydam PNG siyah zemine dusmesin. */
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, SIDE, SIDE);
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(img, (w - side) / 2, (h - side) / 2, side, side, 0, 0, SIDE, SIDE);

                    URL.revokeObjectURL(url);

                    canvas.toBlob(function (blob) {
                        if (blob) {
                            resolve(blob);
                        } else {
                            reject(new Error('Resim işlenemedi.'));
                        }
                    }, 'image/jpeg', QUALITY);
                };

                img.onerror = function () {
                    URL.revokeObjectURL(url);
                    reject(new Error('Bu dosya bir resim olarak açılamadı.'));
                };

                img.src = url;
            });
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

            toSquareJpeg(file).then(function (blob) {
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
                showImage(data.url);
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
