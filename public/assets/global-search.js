// Genel arama davranışı — TÜM sayfalarda AYNI dosya çalışır.
//
// Neyi çözüyor (bulunan gerçek kusurlar, canlı doğrulandı):
//   1. Ctrl+K ÖLÜ kısayoldu. Eski kod home.js'te
//      `if (searchDetails && searchInput && searchResults && searchGrid)`
//      guard'ının İÇİNDEydi; #home-base-grid yalnızca dashboard/starred'da var.
//      Sonuç: workspaces.php ve team_members.php'de tetikleyici görünüyordu ama
//      Ctrl+K hiçbir şey yapmıyordu (ölçüldü: popover open=false) ve sonuç
//      listesi HER ZAMAN boştu (0 sonuç). Base'i olmayan bir dashboard'da da
//      aynı ölü durum oluşuyordu.
//   2. grid.php bu bileşeni HİÇ almıyordu (kendi kabuğu var) — orada Ctrl+K yok.
//   3. Arama yalnızca base kartlarını biliyordu; çalışma alanı, katılımcı ve
//      kayıt aramak için ayrı ayrı kutular vardı.
//
// Tasarım: kısayol/açma-kapama/klavye gezinme SAYFADAN BAĞIMSIZ ve koşulsuz
// bağlanır. "Ne aranıyor" sorusunu ise aşağıdaki COLLECTOR'lar cevaplar —
// sayfanın DOM'una bakıp o bağlamın öğelerini üretirler. Yeni bir sayfa
// eklemek = yeni bir collector; açma/kapama mantığına dokunulmaz.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var details = document.getElementById('home-search');
        var input = document.getElementById('home-search-input');
        var resultsBox = document.getElementById('home-search-results');
        var emptyBox = document.getElementById('home-search-empty');
        var scopeBox = document.getElementById('home-search-scope');

        if (!details || !input || !resultsBox) {
            return;
        }

        // -------------------------------------------------------------------
        // Bağlam toplayıcıları (collector)
        // -------------------------------------------------------------------
        // Her collector: { scope: 'etiket', items: [{label, meta, type, href,
        // icon, onSelect}] }. İlk EŞLEŞEN collector kazanır — bir sayfada
        // birden çok liste varsa (ör. workspaces: hem alanlar hem katılımcılar)
        // collector ikisini de tek listede birleştirir, çünkü kullanıcı için
        // tek bir arama kutusu var.
        //
        // text(): aranacak metin. label + meta + type birleşimi — böylece
        // team_members'ta "editor" yazmak rolü de yakalar.

        function textOf(el, selector) {
            var found = el.querySelector(selector);
            return found ? found.textContent.trim().replace(/\s+/g, ' ') : '';
        }

        // --- dashboard.php / starred.php: base kartları ---
        function collectBases() {
            var grid = document.getElementById('home-base-grid');
            if (!grid) {
                return null;
            }

            var items = [];
            Array.prototype.forEach.call(grid.querySelectorAll('.home-base-card'), function (card) {
                // "+ Yeni Base Oluştur" kutucuğu bir <button>, veri DEĞİL —
                // aranabilir öğe listesine girmemeli.
                if (card.classList.contains('home-base-create')) {
                    return;
                }

                var icon = card.querySelector('.home-base-icon');
                items.push({
                    label: textOf(card, '.home-base-name'),
                    meta: textOf(card, '.home-base-meta'),
                    type: 'Base',
                    href: card.getAttribute('href'),
                    icon: icon ? icon.cloneNode(true) : null,
                    id: card.getAttribute('data-base-id') || '',
                });
            });

            return { scope: items.length + ' base', items: items };
        }

        // --- workspaces.php: çalışma alanları + katılımcılar ---
        function collectWorkspaces() {
            var side = document.querySelector('.wsx-side');
            var collab = document.getElementById('wsx-collab-grid');
            if (!side && !collab) {
                return null;
            }

            var items = [];

            if (side) {
                Array.prototype.forEach.call(side.querySelectorAll('.wsx-card'), function (card) {
                    // "Yeni çalışma alanı" kartı bir aksiyon, veri değil.
                    if (card.classList.contains('wsx-card-new')) {
                        return;
                    }
                    items.push({
                        label: textOf(card, '.wsx-card-name'),
                        meta: textOf(card, '.wsx-card-meta'),
                        type: 'Çalışma alanı',
                        href: card.getAttribute('href'),
                    });
                });
            }

            if (collab) {
                Array.prototype.forEach.call(collab.querySelectorAll('.wsx-member'), function (member) {
                    var role = textOf(member, '.sp-role');
                    items.push({
                        label: textOf(member, '.wsx-member-name'),
                        meta: textOf(member, '.wsx-member-mail') + (role ? ' · ' + role : ''),
                        type: 'Katılımcı',
                        // Katılımcının kendi sayfası yok; seçilince ızgaradaki
                        // kartına kaydırılıp vurgulanır.
                        onSelect: function () { flashTo(member); },
                    });
                });
            }

            return { scope: items.length + ' kayıt (alan + katılımcı)', items: items };
        }

        // --- team_members.php: üye satırları ---
        function collectMembers() {
            var body = document.querySelector('[data-tm-rows]');
            if (!body) {
                return null;
            }

            var items = [];
            Array.prototype.forEach.call(body.querySelectorAll('.tm-row'), function (row) {
                var roleSelect = row.querySelector('.tm-role-select');
                // Rol ya bir <select>'te (owner görünümü) ya da düz metinde
                // (salt-okunur görünüm) — ikisini de oku, aranabilir olsun.
                var role = roleSelect
                    ? (roleSelect.options[roleSelect.selectedIndex] || {}).text || ''
                    : textOf(row, '.tm-role-readonly');

                items.push({
                    label: textOf(row, '.ws-collab-name'),
                    meta: textOf(row, '.ws-collab-email') + (role ? ' · ' + role.trim() : ''),
                    type: 'Üye',
                    onSelect: function () { flashTo(row); },
                });
            });

            return { scope: items.length + ' üye', items: items };
        }

        // --- grid.php: kayıt satırları ve hücre içerikleri ---
        function collectRecords() {
            // Tablo elemanının sınıfı "grid" (bkz. grid.php'deki <table class="grid ...">),
            // satırlar data-record-id taşır (src/schema.php bcc_render_grid_data_row).
            var rows = document.querySelectorAll('table.grid tr[data-record-id]');
            if (!rows.length) {
                return null;
            }

            var items = [];
            Array.prototype.forEach.call(rows, function (row) {
                // .grid-rownum ATLANIR: içinde satır numarası, seçim kutusu ve
                // "genişlet" butonu var — veri değil. Aksi hâlde "3" yazınca
                // 3. satır eşleşirdi.
                var cells = Array.prototype.slice.call(row.querySelectorAll('td:not(.grid-rownum)'));
                var texts = cells.map(function (td) {
                    return td.textContent.trim().replace(/\s+/g, ' ');
                }).filter(function (t) { return t !== ''; });

                if (!texts.length) {
                    return;
                }

                // İlk dolu hücre birincil alandır (grid'in ilk veri sütunu) —
                // başlık olarak o kullanılır, kalanı "eşleşen hücre" metni.
                var label = texts[0];
                var rest = texts.slice(1).join(' · ');

                items.push({
                    label: label,
                    meta: rest,
                    type: 'Kayıt',
                    onSelect: function () { flashTo(row); },
                });
            });

            return { scope: items.length + ' kayıt', items: items };
        }

        // Sayfada kalınarak yapılan "git" davranışı: hedefi görünür alana
        // kaydır ve kısa süre vurgula. Yeni bir kalıcı stil yerine tek bir
        // sınıf (.bcc-search-flash) — CSS home.css'te.
        function flashTo(el) {
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            el.classList.add('bcc-search-flash');
            window.setTimeout(function () {
                el.classList.remove('bcc-search-flash');
            }, 1600);
        }

        var collectors = [collectBases, collectWorkspaces, collectMembers, collectRecords];

        var context = null;
        for (var i = 0; i < collectors.length; i++) {
            context = collectors[i]();
            if (context && context.items.length) {
                break;
            }
            context = null;
        }

        if (!context) {
            context = { scope: '', items: [] };
        }

        // -------------------------------------------------------------------
        // Sonuç satırlarını bir kez kur (arama sırasında DOM yeniden ÜRETİLMEZ,
        // yalnızca hidden toggle edilir — eski davranışla aynı, ucuz)
        // -------------------------------------------------------------------
        var entries = [];

        context.items.forEach(function (item) {
            // Gidilecek bir adres varsa <a>, sayfa içi atlama ise <button>:
            // ikisi de klavyeyle erişilebilir olsun diye gerçek etkileşimli
            // elemanlar kullanılır, tıklanabilir <div> DEĞİL.
            var row = document.createElement(item.href ? 'a' : 'button');
            row.className = 'home-search-result';
            row.setAttribute('role', 'option');

            if (item.href) {
                row.href = item.href;
            } else {
                row.type = 'button';
            }
            if (item.id) {
                row.setAttribute('data-base-id', item.id);
            }

            if (item.icon) {
                row.appendChild(item.icon);
            }

            var nameNode = document.createElement('div');
            nameNode.className = 'home-search-result-name';
            nameNode.textContent = item.label;
            row.appendChild(nameNode);

            var typeNode = document.createElement('div');
            typeNode.className = 'home-search-result-type';
            typeNode.textContent = item.type;
            row.appendChild(typeNode);

            var metaNode = document.createElement('div');
            metaNode.className = 'home-search-result-meta';
            metaNode.textContent = item.meta;
            row.appendChild(metaNode);

            if (item.onSelect) {
                row.addEventListener('click', function (e) {
                    e.preventDefault();
                    closeSearch(false); // odak hedefe gidecek, tetikleyiciye DEĞİL
                    item.onSelect();
                });
            }

            resultsBox.appendChild(row);
            entries.push({
                el: row,
                // Ad + meta + tür birlikte aranır: "editor" yazınca rol,
                // "@bcc.local" yazınca e-posta, "Istanbul" yazınca hücre eşleşir.
                text: (item.label + ' ' + item.meta + ' ' + item.type).toLowerCase(),
            });
        });

        if (scopeBox && context.scope) {
            scopeBox.textContent = context.scope + ' içinde aranıyor';
            scopeBox.hidden = false;
        }

        // -------------------------------------------------------------------
        // Açma / kapama — SAYFADAN BAĞIMSIZ, koşulsuz bağlanır
        // -------------------------------------------------------------------
        var lastFocused = null;

        function openSearch() {
            // Odağı geri verebilmek için nereden geldiğimizi sakla (Escape
            // sonrası odak kaybolup <body>'ye düşmesin — erişilebilirlik).
            lastFocused = document.activeElement;
            details.setAttribute('open', '');
            input.focus();
            input.select();
        }

        function closeSearch(restoreFocus) {
            if (!details.hasAttribute('open')) {
                return;
            }
            details.removeAttribute('open');
            clearActive();

            if (restoreFocus !== false && lastFocused && document.contains(lastFocused)) {
                lastFocused.focus();
            }
            lastFocused = null;
        }

        // Ctrl+K / Cmd+K — HER sayfada, aramada içerik olup olmadığından
        // BAĞIMSIZ. (Eski kusur tam buradaydı: kısayol, sonuç kaynağı bulunma
        // koşuluna bağlıydı.)
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                if (details.hasAttribute('open')) {
                    closeSearch(true);
                } else {
                    openSearch();
                }
            }
        });

        // Escape + dışarı tıklama: projenin ORTAK yardımcısı (assets/
        // dismissable-panel.js) — burada ikinci bir kopya yazılmaz.
        //
        // isClickOutside ZORUNLU olarak override ediliyor. Bulunan gerçek bug
        // (eski kodda da vardı, ölçüldü: popover açıkken sayfaya tıklamak onu
        // KAPATMIYORDU): yardımcının varsayılanı `!el.contains(target)` ve
        // .home-search-overlay (tam ekran backdrop, position:fixed inset:0)
        // <details>'in İÇİNDE yaşıyor — yani popover açıkken ekranın herhangi
        // bir yerine yapılan tıklama teknik olarak "elemanın içi" sayılıyor ve
        // koşul HİÇBİR ZAMAN sağlanmıyordu. Doğru ölçüt "backdrop'a mı yoksa
        // panelin kendisine mi tıklandı".
        var popover = details.querySelector('.home-search-popover');
        var trigger = details.querySelector('.home-search-trigger');

        window.bcc_bindDismissable(details, {
            close: function () { closeSearch(true); },
            isClickOutside: function (target) {
                // Panelin içi: kapatma (girdiye/sonuca tıklanıyor).
                if (popover && popover.contains(target)) {
                    return false;
                }
                // Tetikleyici: native <details> zaten toggle ediyor; burada da
                // kapatırsak açılır-açılmaz kapanırdı.
                if (trigger && trigger.contains(target)) {
                    return false;
                }
                return true;
            },
        });

        // Tetikleyiciye (summary) tıklandığında native <details> açılır;
        // odak/seçim bu kancadan gelir.
        details.addEventListener('toggle', function () {
            if (details.open) {
                input.focus();
                input.select();
            }
        });

        // -------------------------------------------------------------------
        // Süzme + klavyeyle gezinme
        // -------------------------------------------------------------------
        var activeIndex = -1;

        function visibleEntries() {
            return entries.filter(function (entry) { return !entry.el.hidden; });
        }

        function clearActive() {
            entries.forEach(function (entry) { entry.el.classList.remove('is-active'); });
            activeIndex = -1;
        }

        function setActive(list, index) {
            list.forEach(function (entry) { entry.el.classList.remove('is-active'); });
            if (index < 0 || index >= list.length) {
                activeIndex = -1;
                return;
            }
            activeIndex = index;
            list[index].el.classList.add('is-active');
            list[index].el.scrollIntoView({ block: 'nearest' });
        }

        function applyFilter() {
            var q = input.value.trim().toLowerCase();
            var visible = 0;

            entries.forEach(function (entry) {
                var matches = q === '' || entry.text.indexOf(q) !== -1;
                entry.el.hidden = !matches;
                if (matches) {
                    visible++;
                }
            });

            if (emptyBox) {
                emptyBox.hidden = !(q !== '' && visible === 0);
            }

            clearActive();
        }

        input.addEventListener('input', applyFilter);

        input.addEventListener('keydown', function (e) {
            var list = visibleEntries();

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(list, activeIndex + 1 >= list.length ? 0 : activeIndex + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(list, activeIndex - 1 < 0 ? list.length - 1 : activeIndex - 1);
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && list[activeIndex]) {
                    e.preventDefault();
                    list[activeIndex].el.click();
                }
            }
        });

        // Bir sonuç DOM'dan silindiğinde (ör. dashboard'da base silinince)
        // arama listesi de güncellensin diye home.js'in kullandığı kanca.
        // Eski kodda bu, home.js'in kendi resultItems dizisiydi; bileşen ayrı
        // dosyaya taşınınca dışarıya açık tek bir fonksiyona dönüştü.
        window.bcc_searchRemoveItem = function (baseId) {
            for (var i = entries.length - 1; i >= 0; i--) {
                if (entries[i].el.getAttribute('data-base-id') === String(baseId)) {
                    entries[i].el.remove();
                    entries.splice(i, 1);
                }
            }
        };
    });
})();
