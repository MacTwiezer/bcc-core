(function () {
    'use strict';

    /* Calisma alani resmi: owner resim kutusuna tiklayip yukler, "Kaldır" ile
       kaldirir. Kare 256px JPEG'e cevirme image-square.js'te (once yuklenir). */
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-team-image-root]');
        if (!root || typeof window.BCC_toSquareJpeg !== 'function') {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';
        var teamId = root.getAttribute('data-team-id');

        var pickBtn = root.querySelector('[data-team-image-pick]');
        var input = root.querySelector('[data-team-image-input]');
        var removeBtn = root.querySelector('[data-team-image-remove]');
        var statusEl = root.querySelector('[data-team-image-status]');

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
            if (removeBtn) {
                removeBtn.disabled = busy;
            }
            root.classList.toggle('is-busy', busy);
        }

        /* Sayfadaki bu ekibe ait butun resim kutulari (baslik, soldaki liste,
           kenar cubugu): yenilemeden birlikte guncelleniyor. */
        function applyToFaces(url) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-team-face="' + teamId + '"]'), function (face) {
                var img = face.querySelector('.bcc-team-face-img');
                if (!img) {
                    return;
                }
                if (url) {
                    img.src = url;
                    img.hidden = false;
                    face.classList.add('has-image');
                } else {
                    img.removeAttribute('src');
                    img.hidden = true;
                    face.classList.remove('has-image');
                }
            });
            if (removeBtn) {
                removeBtn.hidden = !url;
            }
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

            if (window.BCC_IMAGE_ACCEPT.indexOf(file.type) === -1) {
                setStatus('Yalnızca PNG, JPEG veya WebP resim seçebilirsiniz.', true);
                return;
            }
            if (file.size > window.BCC_IMAGE_MAX_INPUT_BYTES) {
                setStatus('Seçtiğiniz resim çok büyük (en fazla 15MB).', true);
                return;
            }

            setBusy(true);
            setStatus('Yükleniyor…', false);

            window.BCC_toSquareJpeg(file).then(function (blob) {
                var data = new FormData();
                data.append('csrf_token', CSRF);
                data.append('team_id', teamId);
                data.append('file', blob, 'team.jpg');

                return fetch('/api/team_image_upload.php', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                }).then(readJson);
            }).then(function (data) {
                if (!data || !data.ok || !data.url) {
                    throw new Error((data && data.error) || 'Resim yüklenemedi.');
                }
                applyToFaces(data.url);
                setStatus('Çalışma alanı resmi güncellendi.', false);
            }).catch(function (err) {
                setStatus((err && err.message) || 'Resim yüklenemedi.', true);
            }).then(function () {
                setBusy(false);
            });
        });

        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                var ask = typeof window.bcc_confirm === 'function'
                    ? window.bcc_confirm({
                        title: 'Çalışma alanı resmini kaldır',
                        message: 'Resim kaldırılacak ve yerine varsayılan simge gösterilecek.',
                        confirmLabel: 'Kaldır'
                    })
                    : Promise.resolve(true);

                ask.then(function (ok) {
                    if (!ok) {
                        return;
                    }

                    setBusy(true);
                    setStatus('', false);

                    var body = new URLSearchParams({ csrf_token: CSRF, team_id: teamId }).toString();

                    fetch('/api/team_image_delete.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body,
                        credentials: 'same-origin'
                    }).then(readJson).then(function (data) {
                        if (!data || !data.ok) {
                            throw new Error((data && data.error) || 'Resim kaldırılamadı.');
                        }
                        applyToFaces(null);
                        setStatus('Çalışma alanı resmi kaldırıldı.', false);
                    }).catch(function (err) {
                        setStatus((err && err.message) || 'Resim kaldırılamadı.', true);
                    }).then(function () {
                        setBusy(false);
                    });
                });
            });
        }
    });
})();
