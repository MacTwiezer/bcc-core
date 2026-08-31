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
        // Boş tablo penceresi ARDIŞIK ekleme yapar: sayfayı yenilemek yerine
        // tip seçme adımına döner (kullanıcı isteği). "+" popup'ında böyle bir
        // ihtiyaç yok — orada tablo zaten ekranda, yenilemek en dürüst sonuç.
        var keepOpen = form.hasAttribute('data-grid-add-field-keep-open');

        // İstek UÇARKEN pencereyi kapatmak (X/Esc/dışarı tık) — ölçülen gerçek
        // durum: yanıt gelmeden kapatılırsa "en az bir sütun eklendi mi?"
        // sayacı hâlâ 0'dır, kapanış sayfayı yenilemez ve kullanıcı arkada
        // "bu tabloda alan yok" kartını görür; oysa sütun SUNUCUDA oluşmuştur.
        // Bu yüzden gönderim başlarken pencereye haber veriliyor.
        function setPending(value) {
            if (keepOpen && typeof window.bcc_gridFieldPending === 'function') {
                window.bcc_gridFieldPending(value);
            }
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (submitBtn) {
                submitBtn.disabled = true;
            }
            setPending(true);

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
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                    if (keepOpen && typeof window.bcc_gridFieldAdded === 'function') {
                        window.bcc_gridFieldAdded(data);
                        return;
                    }
                    window.location.reload();
                    return;
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                setPending(false);
                window.alert((data && data.error) || 'Alan oluşturulamadı.');
            }).catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                setPending(false);
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

        var addedBox = document.getElementById('gs-empty-fields-added');
        var addedList = document.getElementById('gs-empty-fields-added-list');
        var doneRow = document.getElementById('gs-empty-fields-done-row');
        var doneBtn = document.getElementById('gs-empty-fields-done');
        var typeStep = document.getElementById('new-field-type-step');
        var detailsStep = document.getElementById('new-field-details-step');
        var nameInput = document.getElementById('new-field-name-input');
        var addedCount = 0;
        var createPending = false;

        // Gönderim bloğu (yukarıdaki IIFE) istek başlarken/biterken çağırır.
        window.bcc_gridFieldPending = function (value) {
            createPending = !!value;
        };

        function close() {
            // En az bir sütun eklendiyse kapanış = "tabloyu görüntüle":
            // yenilemezsek arkada hâlâ "bu tabloda alan yok" kartı durur ve
            // kullanıcı eklediklerini kaybetmiş sanır.
            // createPending: yanıt daha gelmemiş olabilir ama sütun sunucuda
            // OLUŞMUŞ olabilir — o durumda da yenilemek tek dürüst davranış.
            if (addedCount > 0 || createPending) {
                window.location.reload();
                return;
            }
            modal.hidden = true;
        }

        // grid-add-field.js'in gönderim dinleyicisi (yukarıdaki blok) başarılı
        // yanıtta bunu çağırır — iki blok arasındaki TEK bağ bu, gönderim
        // mantığı ikinci kez yazılmadı.
        window.bcc_gridFieldAdded = function (data) {
            addedCount++;
            createPending = false;

            if (addedList) {
                var chip = document.createElement('span');
                chip.className = 'gs-empty-fields-added-chip';
                chip.textContent = (data && data.name) ? data.name : 'Sütun';
                addedList.appendChild(chip);
            }
            if (addedBox) {
                addedBox.hidden = false;
            }
            if (doneRow) {
                doneRow.hidden = false;
            }

            // Sihirbazı 1. adıma (tip seçimi) döndür — "Tip değiştir"in yaptığı
            // şeyin aynısı, o düğmenin kendi dinleyicisi field-type-wizard.js'de
            // olduğu için burada yalnızca görünürlük ayarlanıyor.
            if (detailsStep && typeStep) {
                detailsStep.hidden = true;
                typeStep.hidden = false;
            }
            if (nameInput) {
                nameInput.value = '';
            }

            var firstType = modal.querySelector('.field-type-option');
            if (firstType) {
                firstType.focus();
            }
        };

        trigger.addEventListener('click', function () {
            modal.hidden = false;
            // İlk tip düğmesine odaklan — klavyeyle de ilerlenebilsin.
            var firstType = modal.querySelector('.field-type-option');
            if (firstType) {
                firstType.focus();
            }
        });

        if (doneBtn) {
            doneBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }

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
