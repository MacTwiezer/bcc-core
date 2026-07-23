(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Tarih filtresi (<details id="home-filter">): açma/kapama tamamen native
        // (JS'siz de çalışır) — burada yalnızca projedeki ortak "dışarı tıklayınca /
        // Escape ile kapanma" deseni ekleniyor.
        var filterDetails = document.getElementById('home-filter');
        if (filterDetails) {
            document.addEventListener('click', function (e) {
                if (filterDetails.open && !filterDetails.contains(e.target)) {
                    filterDetails.removeAttribute('open');
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && filterDetails.open) {
                    filterDetails.removeAttribute('open');
                }
            });
        }

        var STORAGE_KEY = 'bcc_home_view_mode';
        var grid = document.getElementById('home-base-grid');
        var buttons = document.querySelectorAll('[data-view-mode-btn]');

        if (!grid || !buttons.length) {
            return;
        }

        // İlk mod: <head>'teki senkron script sayfa boyanmadan önce zaten
        // localStorage'ı okuyup doğrulamış ve sonucu <html class="home-view-list">
        // olarak işaretlemişti (FOUC önleme, bkz. dashboard.php <head>). Burada
        // localStorage TEKRAR okunmuyor/doğrulanmıyor — tek doğrulama kaynağı odur.
        var mode = document.documentElement.classList.contains('home-view-list') ? 'list' : 'card';

        function applyMode(newMode) {
            mode = newMode;
            grid.classList.toggle('view-mode-list', mode === 'list');
            grid.classList.toggle('view-mode-card', mode === 'card');
            document.documentElement.classList.toggle('home-view-list', mode === 'list');

            buttons.forEach(function (btn) {
                var isActive = btn.getAttribute('data-view-mode-btn') === mode;
                btn.classList.toggle('view-btn-active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var newMode = btn.getAttribute('data-view-mode-btn');
                if (newMode !== 'card' && newMode !== 'list') {
                    return;
                }

                try {
                    localStorage.setItem(STORAGE_KEY, newMode);
                } catch (e) {
                    // localStorage kapalı/dolu olabilir (gizli sekme vb.) — mod
                    // yine de bu oturum için uygulanır, sadece kalıcı olmaz.
                }

                applyMode(newMode);
            });
        });

        applyMode(mode);
    });
})();
