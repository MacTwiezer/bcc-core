(function () {
    'use strict';

    // D4 — sütun başlığı "▾" menüsü (Sırala/Filtrele/Grupla/Gizle).
    //
    // Konumlandırma ortak yardımcıdan gelir (bcc_bindFloatingPanel,
    // dismissable-panel.js): .grid-wrap { overflow: auto } taşıdığı için panel
    // position:fixed olmak ZORUNDA, bu da konumun JS'te hesaplanmasını
    // gerektiriyor. Bu blok grid-add-field.js'de BİREBİR kopyalanmıştı;
    // "+ Yeni oluştur..." menüsü üçüncüsünü gerektirince tek yere alındı.
    // Sol hizalama + viewport taşma koruması (en sağdaki sütunlarda panelin
    // ekran dışına taşması, bu dosyada bulunmuş gerçek bug) artık TÜM yüzen
    // panellerde geçerli; resize dinleyicisi de yardımcıyla birlikte eklendi
    // (burada EKSİKTİ).
    //
    // KAPANMA: bu dosyada dinleyici YOK — grid-table-tabs.js tüm
    // <details name="gs-table-tab-menu"> grubunu tek yerden yönetiyor
    // (karşılıklı dışlama + dışarı tık + Escape).
    // ⚠️ Bu menü uzun süre o gruptan DIŞARIDA kaldı: oradaki seçici sınıf adı
    // listesiydi ve .grid-th-menu listeye hiç eklenmemişti, bu yüzden dışarı
    // tıklayınca kapanmıyordu. Seçici artık name özniteliğine bakıyor.
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.grid-th-menu'), function (menu) {
            var summary = menu.querySelector(':scope > summary');
            var panel = menu.querySelector(':scope > .grid-th-menu-panel');

            if (!summary || !panel) {
                return;
            }

            window.bcc_bindFloatingPanel(menu, panel, summary);
        });
    });
})();
