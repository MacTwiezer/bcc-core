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

(function () {
    'use strict';

    // Boş tablo kartı -> alan ekleme penceresi.
    //
    // Tabloda hiç alan yokken grid tablosu (dolayısıyla <thead>'deki "+"
    // popup'ı) HİÇ basılmıyor; eskiden tek çıkış yolu table_fields.php'ye giden
    // bir bağlantıydı. Artık kartın tamamı bu penceyi açıyor.
    //
    // Gönderim mantığı BURADA YOK: pencere içindeki form da
    // [data-grid-add-field] taşıyor, yani bu dosyanın yukarıdaki mevcut submit
    // dinleyicisi onu da işliyor. Burada yalnızca aç/kapat var.
    document.addEventListener('DOMContentLoaded', function () {
        var trigger = document.getElementById('gs-empty-fields-trigger');
        var modal = document.getElementById('gs-empty-fields-modal');

        // İkisi de yalnızca "alan yok + owner" durumunda basılır; aksi hâlde
        // bu blok sessizce no-op olur (projedeki null-check deseni).
        if (!trigger || !modal) {
            return;
        }

        function close() {
            modal.hidden = true;
        }

        trigger.addEventListener('click', function () {
            modal.hidden = false;
            // İlk tip düğmesine odaklan — klavyeyle de ilerlenebilsin.
            var firstType = modal.querySelector('.field-type-option');
            if (firstType) {
                firstType.focus();
            }
        });

        document.getElementById('gs-empty-fields-close').addEventListener('click', close);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                close();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) {
                close();
            }
        });
    });
})();
