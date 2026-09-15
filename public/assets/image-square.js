(function () {
    'use strict';

    /* Sunucuda GD yok, resim orada kucultulemiyor. Kucultme burada: resim
       ortadan kare kirpilip 256px JPEG olarak yeniden kodlaniyor. Yan etkisi
       istenen bir sey: tuval yeniden kodlamasi EXIF'i (GPS konumu dahil) siler.
       Profil fotografi ve calisma alani resmi ayni fonksiyonu kullaniyor. */
    var SIDE = 256;
    var QUALITY = 0.9;

    window.BCC_IMAGE_ACCEPT = ['image/png', 'image/jpeg', 'image/webp'];
    window.BCC_IMAGE_MAX_INPUT_BYTES = 15 * 1024 * 1024;

    window.BCC_toSquareJpeg = function (file) {
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
    };
})();
