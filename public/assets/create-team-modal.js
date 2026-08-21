(function () {
    'use strict';

    // "Yeni Çalışma Alanı" (= ekip) modalının davranışı — PAYLAŞILAN.
    //
    // İKİ sayfa kullanıyor: workspaces.php ve admin/index.php. Markup
    // src/partials/create_team_modal.php'de, davranış burada — ikisi de
    // kopyalanmadı (share_link_popover.php / share-popover.js ile aynı desen).
    //
    // Tetikleyici: [data-create-team-btn] taşıyan HER bağlantı. Bunlar gerçek
    // <a href="/admin/create_team.php"> — JS bu dosyada araya girip modalı
    // açıyor. JS yüklenmezse bağlantı kendi sayfasına gider, yani akış JS'siz
    // de tamamlanır (ilerici zenginleştirme).

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('create-team-modal');
        var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-create-team-btn]'));

        if (!modal || triggers.length === 0) {
            return;
        }

        var form = document.getElementById('create-team-form');
        var errorEl = document.getElementById('create-team-error');
        var nameInput = form.querySelector('input[name="name"]');
        var submitBtn = form.querySelector('button[type="submit"]');
        // Modal kapanınca odağın kaybolmaması için: hangi tetikleyiciden
        // açıldıysa oraya geri verilir (birden fazla tetikleyici olabilir).
        var lastTrigger = null;

        function showError(message) {
            errorEl.textContent = message;
            errorEl.hidden = false;
        }

        function closeModal() {
            modal.hidden = true;
            errorEl.hidden = true;
            submitBtn.disabled = false;
            if (lastTrigger) {
                lastTrigger.focus();
            }
        }

        function openModal(trigger) {
            lastTrigger = trigger || null;
            modal.hidden = false;
            errorEl.hidden = true;
            submitBtn.disabled = false;
            nameInput.value = '';
            nameInput.focus();
        }

        triggers.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); // href yalnızca JS'siz yedek
                openModal(btn);
            });
        });

        Array.prototype.forEach.call(modal.querySelectorAll('[data-create-team-close]'), function (btn) {
            btn.addEventListener('click', closeModal);
        });

        // Dışarı tıklayınca kapanma — .home-modal'ın İÇİNE tıklandığında olay
        // yukarı kabarıp backdrop'a ulaştığı için hedef kontrolü ŞART
        // (e.target === modal), yoksa form alanlarına her tıklama modalı
        // kapatırdı (home.js'teki base modalında yazılı AYNI gerekçe).
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        form.addEventListener('submit', function (e) {
            // Buraya gelindiyse AJAX yolu kullanılır; formun kendi
            // action="/admin/create_team.php" POST'u yalnızca bu dinleyici hiç
            // bağlanamadıysa devreye giren yedektir.
            e.preventDefault();

            if (submitBtn.disabled) {
                return;
            }
            submitBtn.disabled = true;
            errorEl.hidden = true;

            var payload = new URLSearchParams(new FormData(form)).toString();

            fetch('/api/team_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload,
            }).then(function (res) {
                return res.json().catch(function () { return { ok: false }; });
            }).then(function (data) {
                if (data && data.ok && data.redirect_url) {
                    // Hedef SUNUCUDAN geliyor (istemci id'den URL uydurmuyor).
                    // Sayfa yeniden yükleniyor: yeni çalışma alanı listeye
                    // girsin ve seçili gelsin — kartı DOM'a elle eklemeye gerek
                    // yok, sayfa zaten terk ediliyor.
                    window.location.href = data.redirect_url;
                    return;
                }
                submitBtn.disabled = false;
                showError((data && data.error) || 'Çalışma alanı oluşturulamadı.');
            }).catch(function () {
                submitBtn.disabled = false;
                showError('Çalışma alanı oluşturulamadı (bağlantı hatası).');
            });
        });
    });
})();
