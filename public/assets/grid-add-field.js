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
            // Konumlandırma bloğu ortak yardımcıya taşındı (bcc_bindFloatingPanel,
            // dismissable-panel.js) — grid-column-menu.js ile BİREBİR aynı kod
            // iki yerde duruyordu, "+ Yeni oluştur..." menüsü üçüncüsünü
            // gerektirince tek yere alındı. Davranış aynı (sağa hizalı), üstüne
            // resize dinleyicisi de bedava geldi (burada EKSİKTİ: pencere yeniden
            // boyutlandırılınca panel butondan kopuyordu).
            window.bcc_bindFloatingPanel(menu, panel, summary, { align: 'right' });
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
