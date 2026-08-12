(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // ---- "Grupla" paneli -------------------------------------------------
        // Panel artık TAMAMEN BAĞLANTI TABANLI: seviye ekleme, yön çevirme,
        // seviye kaldırma ve "Gruplamayı kaldır" birer <a> (sunucuda önceden
        // kurulmuş group_field_N/group_dir_N URL'leri). Bu yüzden buradan
        // KALDIRILANLAR:
        //   - #group-form select'lerini dinleyip form.submit() eden blok
        //   - "Uygula" butonunu gizleyen blok
        //   - #group-add-subgroup ("+ Alt grup ekle") gizli satır açma bloğu
        // Hepsi artık gereksiz: <select> ve <form> kalmadı, alan eklemek için
        // listedeki alana tıklamak yeterli. JS'siz de tam çalışıyor.
        //
        // Panelde JS'in yaptığı TEK iş alan listesini filtrelemek; grid'deki
        // grup başlıklarını aç/kapa işi (aşağısı) panelden bağımsız.

        // "Alan ara": panel içi listeyi istemci tarafında filtreler.
        // Eşleşme anahtarı SUNUCUDAN (data-group-field-name, küçük harfe
        // çevrilmiş) — option.textContent kullanılmıyor, çünkü satır artık
        // "N. seviye" rozetini de içeriyor ve arama sessizce onu da
        // eşleştirirdi ("seviye" yazınca tüm gruplu alanlar çıkardı).
        var searchInput = document.querySelector('[data-group-search]');
        var fieldList = document.querySelector('[data-group-field-list]');

        if (searchInput && fieldList) {
            var options = Array.prototype.slice.call(fieldList.querySelectorAll('.group-field-option'));
            var emptyNote = fieldList.querySelector('[data-group-empty]');
            var keys = options.map(function (o) {
                return (o.getAttribute('data-group-field-name') || '').trim();
            });

            var applyGroupFilter = function () {
                var q = searchInput.value.trim().toLowerCase();
                var visible = 0;

                options.forEach(function (option, i) {
                    var match = q === '' || keys[i].indexOf(q) !== -1;
                    // [hidden]/style.display DEĞİL ayrı sınıf: satırlar flex ve
                    // flex öğesine uygulanan display, [hidden]'ı ezer.
                    option.classList.toggle('is-filtered-out', !match);
                    if (match) {
                        visible++;
                    }
                });

                if (emptyNote) {
                    emptyNote.hidden = visible !== 0;
                }
            };

            searchInput.addEventListener('input', applyGroupFilter);

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && searchInput.value !== '') {
                    // stopPropagation: paneli kapatan ortak Escape dinleyicisi
                    // bu tuşu ayrıca yorumlamasın — ilk Escape aramayı temizler,
                    // ikincisi paneli kapatır.
                    e.stopPropagation();
                    searchInput.value = '';
                    applyGroupFilter();
                }
            });
        }

        // Bir yolun (path) verilen üst grubun içinde olup olmadığını kontrol eder:
        // ya üst grubun ta kendisi (yaprak seviyenin kendi satırları için) ya da
        // "üst-" önekiyle başlayan bir alt yol (iç içe alt gruplar/satırlar için).
        function isWithinGroup(path, parentPath) {
            return path === parentPath || path.indexOf(parentPath + '-') === 0;
        }

        // Grup başlığına tıkla -> o grubun altındaki TÜM iç başlıkları ve satırları
        // (kaç seviye iç içe olursa olsun) aç/kapa. Kapatılan bir dış grubun içindeki
        // alt grup başlıkları da "genişletilmiş" durumuna sıfırlanır (aç/kapa
        // hafızası seviye başına ayrı tutulmuyor — tek dış toggle basitçe kapsar).
        function setGroupCollapsed(headerRow, collapsed) {
            var toggle = headerRow.querySelector('[data-group-toggle]');
            var groupPath = headerRow.getAttribute('data-group-path');

            headerRow.setAttribute('data-group-collapsed', collapsed ? 'true' : 'false');
            if (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }

            document.querySelectorAll('[data-group-path]').forEach(function (el) {
                if (el === headerRow) {
                    return;
                }

                var elPath = el.getAttribute('data-group-path');
                if (!isWithinGroup(elPath, groupPath)) {
                    return;
                }

                el.style.display = collapsed ? 'none' : '';

                if (el.hasAttribute('data-group-header')) {
                    el.setAttribute('data-group-collapsed', 'false');
                    var innerToggle = el.querySelector('[data-group-toggle]');
                    if (innerToggle) {
                        innerToggle.setAttribute('aria-expanded', 'true');
                    }
                }
            });
        }

        var headerRows = document.querySelectorAll('[data-group-header]');
        headerRows.forEach(function (headerRow) {
            var toggle = headerRow.querySelector('[data-group-toggle]');

            if (!toggle) {
                return;
            }

            toggle.addEventListener('click', function () {
                var collapsed = headerRow.getAttribute('data-group-collapsed') === 'true';
                setGroupCollapsed(headerRow, !collapsed);
            });
        });

        var collapseAllBtn = document.querySelector('[data-group-collapse-all]');
        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function () {
                headerRows.forEach(function (headerRow) {
                    setGroupCollapsed(headerRow, true);
                });
            });
        }

        var expandAllBtn = document.querySelector('[data-group-expand-all]');
        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function () {
                headerRows.forEach(function (headerRow) {
                    setGroupCollapsed(headerRow, false);
                });
            });
        }
    });
})();
