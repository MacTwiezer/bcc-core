(function () {
    'use strict';

    // Grid'in sağ üstündeki "+" popup'ı: tip-önce-isim-sonra akışının adım
    // geçişleri field-type-wizard.js'de (table_fields.php ile PAYLAŞILIR);
    // burada yalnızca bu sayfaya özgü kısım var — formu fetch ile
    // /api/field_create.php'ye gönderip yeni sütunun görünmesi için sayfayı
    // yeniler (view state URL'de zaten korunuyor, aynı görünüme döner).
    document.addEventListener('DOMContentLoaded', function () {
        // .grid-wrap { overflow: auto } taşıyor — panel position:fixed olduğu
        // için burada AÇILIŞTA konumu hesaplanır (.gs-view-row-menu-panel'deki
        // AYNI teknik, bkz. grid-view-manage.js).
        var menu = document.querySelector('.grid-add-field-menu');
        var summary = menu ? menu.querySelector(':scope > summary') : null;
        var panel = menu ? menu.querySelector(':scope > .grid-add-field-panel') : null;

        if (menu && summary && panel) {
            function positionPanel() {
                var rect = summary.getBoundingClientRect();
                panel.style.top = (rect.bottom + 4) + 'px';
                panel.style.left = 'auto';
                panel.style.right = (window.innerWidth - rect.right) + 'px';
            }

            // Bulunan gerçek bug: konum yalnızca AÇILIŞTA hesaplanıyordu — panel
            // açıkken sayfa kaydırılırsa (uzun bir tabloda çok olağan) "+" butonu
            // kayarken panel ekranda sabit kalıp butondan tamamen kopuyordu
            // (grid.js'deki richtext-popover'da bulunan AYNI sorun). Scroll'da
            // yeniden konumlandırılır, menü kapanınca listener kaldırılır.
            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    window.removeEventListener('scroll', positionPanel, true);
                    return;
                }
                positionPanel();
                window.addEventListener('scroll', positionPanel, true);
            });
        }

        var form = document.querySelector('[data-grid-add-field]');
        if (!form) {
            return;
        }

        var submitBtn = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (submitBtn) {
                submitBtn.disabled = true;
            }

            var body = new URLSearchParams(new FormData(form));

            fetch('/api/field_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            }).then(function (res) {
                return res.json().catch(function () {
                    return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                });
            }).then(function (data) {
                if (data && data.ok) {
                    window.location.reload();
                    return;
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                window.alert((data && data.error) || 'Alan oluşturulamadı.');
            }).catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                window.alert('Alan oluşturulamadı (bağlantı hatası).');
            });
        });
    });
})();
