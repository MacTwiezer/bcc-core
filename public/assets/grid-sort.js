(function () {
    'use strict';

    // Sıralama paneli (grid.php .sort-form).
    //
    // SUNUCU MANTIĞI DEĞİŞMEDİ: form hâlâ sort_field_N / sort_dir_N gönderiyor
    // ve parse_grid_sort_rules() boş slotları atlıyor. Buradaki iş yalnızca
    // PANELİN kendisi: satır ekleme/silme, alan tipi rozeti ve yön etiketlerinin
    // seçili alana göre tazelenmesi.
    //
    // grid-filter.js ile AYNI desen (satır ekle/sil + slot yeniden numaralama).
    // Ortak bir soyutlama YAZILMADI: iki panelin satır İÇERİĞİ farklı (filtrede
    // alan+operatör+değer, burada alan+yön) ve paylaşılan tek şey ~20 satırlık
    // numaralama; erken bir soyutlama iki paneli de okunmaz hâle getirirdi.

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-sort-form]');
        if (!form) {
            return;
        }

        var fieldTypesById = window.BCC_FIELD_TYPES_BY_ID || {};
        var dirLabels = window.BCC_DIR_LABELS || {};
        var dirFallback = { asc: 'artan', desc: 'azalan' };
        var maxSlots = parseInt(window.BCC_SORT_MAX_SLOTS, 10) || 3;

        var rowsWrap = form.querySelector('[data-sort-rows]');
        var addBtn = form.querySelector('[data-sort-add]');
        var slotNote = form.querySelector('[data-sort-slot-note]');

        function rows() {
            return Array.prototype.slice.call(rowsWrap.querySelectorAll('[data-sort-row]'));
        }

        function labelsFor(type) {
            return (type && dirLabels[type]) ? dirLabels[type] : dirFallback;
        }

        function bindRow(row) {
            var fieldSelect = row.querySelector('.sort-field-select');
            var dirSelect = row.querySelector('[data-sort-dir]');
            var badge = row.querySelector('[data-sort-field-badge]');

            if (!fieldSelect || !dirSelect) {
                return;
            }

            function refresh() {
                var type = fieldTypesById[fieldSelect.value];

                // Alan tipi rozeti — sunucunun bastığıyla AYNI sınıf kalıbı.
                if (badge) {
                    badge.className = 'field-badge sort-field-badge ' + (type ? 'field-badge--' + type : 'is-empty');
                }

                // Yön etiketleri alan tipine göre değişiyor ("A → Z" / "1 → 9" /
                // "Erken → Geç"). SEÇİLİ yön KORUNUYOR: yalnızca etiket metni
                // değişiyor, kullanıcının asc/desc tercihi sıfırlanmıyor.
                var labels = labelsFor(type);
                var opts = dirSelect.options;
                if (opts.length >= 2) {
                    opts[0].textContent = labels.asc;
                    opts[1].textContent = labels.desc;
                }
            }

            fieldSelect.addEventListener('change', refresh);
            refresh();
        }

        // Slot numaraları DOM sırasına göre yeniden yazılır: bir satır silinince
        // aradaki slot boş kalsaydı sunucu onu atlardı (sorun değil), ama
        // "+ Sıralama ekle" bir sonraki numarayı seçerken o boşluğa düşüp iki
        // satırın aynı slotu paylaşmasına yol açabilirdi.
        function renumber() {
            rows().forEach(function (row, i) {
                var slot = i + 1;
                row.setAttribute('data-slot', slot);
                var f = row.querySelector('.sort-field-select');
                var d = row.querySelector('[data-sort-dir]');
                var badge = row.querySelector('.sort-level-badge');
                if (f) { f.name = 'sort_field_' + slot; }
                if (d) { d.name = 'sort_dir_' + slot; }
                if (badge) { badge.textContent = slot; }
            });
        }

        function refreshAddState() {
            var atMax = rows().length >= maxSlots;
            if (addBtn) {
                addBtn.disabled = atMax;
            }
            if (slotNote) {
                slotNote.hidden = !atMax;
            }
        }

        function refreshAll() {
            renumber();
            refreshAddState();
        }

        function addRow() {
            if (rows().length >= maxSlots) {
                return;
            }

            // Şablon: İLK satırın kopyası — alan listesini (ve 'attachment'
            // hariç tutma kuralını) JS'te yeniden üretmemek için. Tek kaynak PHP.
            var clone = rows()[0].cloneNode(true);

            // cloneNode DİNLEYİCİLERİ kopyalamaz ama ÖZNİTELİKLERİ kopyalar —
            // "zaten bağlı" işaretleri temizlenmezse eklenen satırın sil butonu
            // sessizce ölü kalırdı (grid-filter.js'te yakalanan aynı tuzak).
            Array.prototype.forEach.call(clone.querySelectorAll('[data-bound]'), function (el) {
                el.removeAttribute('data-bound');
            });

            var f = clone.querySelector('.sort-field-select');
            var d = clone.querySelector('[data-sort-dir]');
            f.value = '';
            d.value = 'asc';

            var badge = clone.querySelector('[data-sort-field-badge]');
            if (badge) {
                badge.className = 'field-badge sort-field-badge is-empty';
            }

            rowsWrap.appendChild(clone);
            bindRow(clone);
            bindRemove(clone);
            refreshAll();

            f.focus();
        }

        function removeRow(row) {
            if (rows().length === 1) {
                // SON satır silinmez, SIFIRLANIR: panel her zaman en az bir
                // satır göstersin (boş panel "sıralama eklenemiyor" gibi okunur).
                var f = row.querySelector('.sort-field-select');
                var d = row.querySelector('[data-sort-dir]');
                f.value = '';
                d.value = 'asc';
                f.dispatchEvent(new Event('change'));
                refreshAll();
                return;
            }

            row.parentNode.removeChild(row);
            refreshAll();
        }

        function bindRemove(row) {
            var btn = row.querySelector('[data-sort-remove]');
            if (btn && !btn.hasAttribute('data-bound')) {
                btn.setAttribute('data-bound', '1');
                btn.addEventListener('click', function () {
                    removeRow(row);
                });
            }
        }

        rows().forEach(function (row) {
            bindRow(row);
            bindRemove(row);
        });

        if (addBtn) {
            addBtn.addEventListener('click', addRow);
        }

        refreshAll();
    });
})();
