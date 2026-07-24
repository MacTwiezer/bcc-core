(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Tarih filtresi (<details id="home-filter">): açma/kapama tamamen native
        // (JS'siz de çalışır) — burada yalnızca projedeki ortak "dışarı tıklayınca /
        // Escape ile kapanma" deseni ekleniyor (bkz. assets/dismissable-panel.js).
        var filterDetails = document.getElementById('home-filter');
        if (filterDetails) {
            window.bcc_bindDismissable(filterDetails);
        }

        // Global arama (Ctrl+K / Cmd+K popover) — canlı filtre mantığı ÖNCEKİYLE
        // AYNI (substring eşleşme, hidden toggle, boş durum sayacı); yalnızca
        // sonuçlar artık sayfaya gömülü değil, position:fixed bir popover'da
        // (Airtable tarzı, arkası kararmış). #home-base-grid'deki kartlar artık
        // hiç gizlenmiyor — arama yalnızca popover'ın kendi sonuç listesini
        // süzüyor. Sonuç satırları .home-base-card'lardan (ad/tarih/ikon,
        // sunucunun zaten team_id ile KVKK-izole edip bastığı veri) klonlanır —
        // PHP tarafında ikinci bir döngü/sorgu YOK. Açma/kapama, #home-filter
        // ile aynı <details> + dışarı-tık deseni.
        var searchDetails = document.getElementById('home-search');
        var searchInput = document.getElementById('home-search-input');
        var searchResults = document.getElementById('home-search-results');
        var searchEmpty = document.getElementById('home-search-empty');
        var searchGrid = document.getElementById('home-base-grid');

        if (searchDetails && searchInput && searchResults && searchGrid) {
            var resultItems = [];

            Array.prototype.forEach.call(searchGrid.querySelectorAll('.home-base-card'), function (card) {
                var nameEl = card.querySelector('.home-base-name');
                var metaEl = card.querySelector('.home-base-meta');
                var iconEl = card.querySelector('.home-base-icon');
                var name = nameEl ? nameEl.textContent : '';

                var row = document.createElement('a');
                row.className = 'home-search-result';
                row.href = card.getAttribute('href');

                if (iconEl) {
                    row.appendChild(iconEl.cloneNode(true));
                }

                var nameNode = document.createElement('div');
                nameNode.className = 'home-search-result-name';
                nameNode.textContent = name;
                row.appendChild(nameNode);

                var typeNode = document.createElement('div');
                typeNode.className = 'home-search-result-type';
                typeNode.textContent = 'Base';
                row.appendChild(typeNode);

                var metaNode = document.createElement('div');
                metaNode.className = 'home-search-result-meta';
                metaNode.textContent = metaEl ? metaEl.textContent : '';
                row.appendChild(metaNode);

                searchResults.appendChild(row);
                resultItems.push({ el: row, name: name.toLowerCase() });
            });

            var openSearch = function () {
                searchDetails.setAttribute('open', '');
                searchInput.focus();
                searchInput.select();
            };

            // Ctrl+K/Cmd+K açma kısayolu burada kalır (dismiss deseniyle ilgisiz);
            // dışarı-tık/Escape ile kapanma assets/dismissable-panel.js'den.
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    openSearch();
                }
            });

            window.bcc_bindDismissable(searchDetails);

            searchDetails.addEventListener('toggle', function () {
                if (searchDetails.open) {
                    searchInput.focus();
                    searchInput.select();
                }
            });

            searchInput.addEventListener('input', function () {
                var q = searchInput.value.trim().toLowerCase();
                var visibleCount = 0;

                resultItems.forEach(function (item) {
                    var matches = q === '' || item.name.indexOf(q) !== -1;
                    item.el.hidden = !matches;
                    if (matches) {
                        visibleCount++;
                    }
                });

                if (searchEmpty) {
                    searchEmpty.hidden = !(q !== '' && visibleCount === 0);
                }
            });
        }

        // Yıldız (favori) toggle — .home-base-card'ın sağ üst köşesindeki
        // yıldız butonu. Sunucu: /api/star_base.php (CSRF + require_team_access,
        // user_starred_bases'te INSERT/DELETE toggle). Sol paneldeki "Starred"
        // listesi DOM'da anında güncellenir — ad/href zaten karttan okunur,
        // ikinci bir sorgu YOK. JS yalnızca listenin SONUNA ekler (alfabetik
        // sıralama yeniden yüklemede sunucudan gelir, kabul edilebilir küçük fark).
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF_TOKEN = csrfMeta ? csrfMeta.content : '';
        var starredList = document.getElementById('home-starred-list');

        // ⋯ artık gerçek bir menü açıyor (Open > Interface / Duplicate) — bkz.
        // aşağıdaki "..." menü bağlama bloğu. Eski dekoratif preventDefault()
        // (⋯ bir <button> iken eklenmişti) KALDIRILDI: artık bir <summary>,
        // preventDefault() native <details> açılmasını ENGELLERDİ. Kartın
        // (bir <a>) navigasyonunu engelleme işini aşağıdaki blok zaten
        // stopPropagation() ile (preventDefault OLMADAN) yapıyor.

        Array.prototype.forEach.call(document.querySelectorAll('.home-base-star-btn'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var card = btn.closest('.home-base-card');
                if (!card || btn.disabled) {
                    return;
                }

                var baseId = card.getAttribute('data-base-id');
                btn.disabled = true;

                fetch('/api/star_base.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ base_id: baseId, csrf_token: CSRF_TOKEN }).toString(),
                }).then(function (res) {
                    return res.json();
                }).then(function (data) {
                    btn.disabled = false;

                    if (!data || !data.ok) {
                        return;
                    }

                    btn.setAttribute('aria-pressed', data.starred ? 'true' : 'false');
                    card.classList.toggle('is-starred', data.starred);

                    if (!starredList) {
                        return;
                    }

                    var existingItem = starredList.querySelector('[data-starred-base-id="' + baseId + '"]');

                    if (data.starred && !existingItem) {
                        var nameEl = card.querySelector('.home-base-name');
                        var name = nameEl ? nameEl.textContent : '';

                        var item = document.createElement('a');
                        item.className = 'home-sidenav-item home-starred-item';
                        item.href = card.getAttribute('href');
                        item.setAttribute('data-starred-base-id', baseId);

                        var dot = document.createElement('span');
                        dot.className = 'home-starred-item-dot';
                        item.appendChild(dot);

                        var nameSpan = document.createElement('span');
                        nameSpan.className = 'home-starred-item-name';
                        nameSpan.textContent = name;
                        item.appendChild(nameSpan);

                        starredList.appendChild(item);
                    } else if (!data.starred && existingItem) {
                        existingItem.remove();
                    }

                    // starred.php'de ana grid'in KENDİSİ "yıldızlı base'ler"
                    // listesi — unstar edilen kart artık oraya ait değil, hemen
                    // kaldırılır. Son kart kaldırılıyorsa boş-durum mesajını JS'de
                    // yeniden inşa etmek yerine (sunucudaki tek doğruluk kaynağını
                    // tekrar etmemek için) sayfa yeniden yüklenir.
                    if (!data.starred && window.location.pathname.indexOf('/starred.php') !== -1) {
                        var grid = document.getElementById('home-base-grid');
                        if (grid && grid.querySelectorAll('.home-base-card').length <= 1) {
                            window.location.reload();
                            return;
                        }
                        card.remove();
                    }
                }).catch(function () {
                    btn.disabled = false;
                });
            });
        });

        // "..." menüsü (Open > Interface / Duplicate) — grid-table-tabs.js'deki
        // <details>+dışarı-tık+Escape deseninin AYNISI (birden fazla kart, her
        // birinin kendi menüsü var; toggle olayı diğerlerini kapatır). Panel
        // position:fixed olduğu için (bkz. home.css — liste modunun
        // overflow:hidden'ından kaçınmak için) açılışta konumu JS hesaplıyor.
        var moreMenus = Array.prototype.slice.call(document.querySelectorAll('.home-base-more-menu'));
        var menuEntries = []; // {menu, panel} — dışarı-tık/Escape kontrolü panel.contains() de bakmalı (aşağıya bkz.)

        function positionPanelBelow(panel, anchorEl) {
            var rect = anchorEl.getBoundingClientRect();
            panel.style.top = (rect.bottom + 4) + 'px';
            panel.style.left = 'auto';
            panel.style.right = (window.innerWidth - rect.right) + 'px';
        }

        function positionPanelRight(panel, anchorEl) {
            var rect = anchorEl.getBoundingClientRect();
            panel.style.top = rect.top + 'px';
            panel.style.left = (rect.right + 4) + 'px';
            panel.style.right = 'auto';
        }

        moreMenus.forEach(function (menu) {
            var summary = menu.querySelector(':scope > summary');
            var panel = menu.querySelector(':scope > .home-base-more-panel');
            var subMenu = panel ? panel.querySelector(':scope > .home-base-more-submenu') : null;
            var subSummary = subMenu ? subMenu.querySelector(':scope > summary') : null;
            var subPanel = subMenu ? subMenu.querySelector(':scope > .home-base-more-submenu-panel') : null;

            menuEntries.push({ menu: menu, panel: panel });
            if (subMenu) {
                menuEntries.push({ menu: subMenu, panel: subPanel });
            }

            if (summary) {
                summary.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }
            if (subSummary) {
                subSummary.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }

            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    // Kapanınca kendi alt menüsünü de sıfırla — tekrar açılışta
                    // "Open" önceden genişlemiş görünmesin — VE panel'i (aşağıda
                    // <body>'ye taşınmışsa) menünün içine geri koy.
                    if (subMenu && subMenu.open) {
                        subMenu.removeAttribute('open');
                    }
                    if (panel && panel.parentNode === document.body) {
                        menu.appendChild(panel);
                    }
                    return;
                }

                menuEntries.forEach(function (entry) {
                    if (entry.menu !== menu && !menu.contains(entry.menu) && entry.menu.open) {
                        entry.menu.removeAttribute('open');
                    }
                });

                if (panel && summary) {
                    // .home-base-card:hover { transform: translateY(-1px); }
                    // (mevcut, önceki işten) position:fixed torunları için YENİ
                    // bir containing block oluşturuyor — panel viewport'a göre
                    // DEĞİL, dönüştürülmüş karta göre "fixed" oluyordu (yanlış
                    // konum). Açılışta panel <body>'ye taşınır (alt menüsüyle
                    // BİRLİKTE — appendChild tüm alt ağacı taşır), kapanışta
                    // menünün içine geri döner.
                    document.body.appendChild(panel);
                    positionPanelBelow(panel, summary);
                }
            });

            if (subMenu) {
                subMenu.addEventListener('toggle', function () {
                    if (!subMenu.open) {
                        return;
                    }
                    if (subPanel && subSummary) {
                        positionPanelRight(subPanel, subSummary);
                    }
                });
            }
        });

        if (menuEntries.length) {
            // Panel açılışta <body>'ye taşındığı için (yukarıda) "dışarısı" kontrolü
            // hem menu hem panel'e bakmalı — bcc_bindDismissable'ın varsayılan
            // el.contains() kontrolü tek başına yetmez, isClickOutside override edilir.
            menuEntries.forEach(function (entry) {
                window.bcc_bindDismissable(entry.menu, {
                    isClickOutside: function (target) {
                        return !entry.menu.contains(target) && !(entry.panel && entry.panel.contains(target));
                    },
                });
            });

            // "Interface" (Open > Interface): gerçek <a> DEĞİL (kartın kendisi
            // zaten bir <a> — iç içe <a> HTML ayrıştırıcısı tarafından otomatik
            // kapatılıp yapı bozulurdu, bkz. grid satır genişletme/Starred
            // işlerindeki AYNI karar). Bu yüzden <button data-nav-href> +
            // window.location.href.
            document.querySelectorAll('[data-nav-href]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.location.href = btn.getAttribute('data-nav-href');
                });
            });
        }

        // Bildirim paneli (zil ikonu) — #home-filter ile AYNI <details>+dışarı-tık
        // deseni. Tab (Unread/Read) + arama TAMAMEN client-side (veri zaten DOM'da,
        // Ctrl+K aramasıyla aynı gerekçe — network isteği yok, debounce gerekmiyor).
        // "Mark all as read" TEK gerçek AJAX çağrısı — sunucudaki
        // last_seen_notifications_at'i günceller (CSRF_TOKEN yukarıda zaten
        // tanımlı, ikinci bir okuma YOK).
        var notifDetails = document.getElementById('home-notif');
        if (notifDetails) {
            var notifTabs = Array.prototype.slice.call(notifDetails.querySelectorAll('.home-notif-tab'));
            var notifItems = Array.prototype.slice.call(notifDetails.querySelectorAll('.home-notif-item'));
            var notifSearchInput = document.getElementById('home-notif-search-input');
            var notifNoMatch = document.getElementById('home-notif-no-match');
            var notifMarkAllBtn = document.getElementById('home-notif-mark-all');
            var notifBadge = notifDetails.querySelector('.home-notif-badge');
            var notifActiveTab = 'unread';

            var applyNotifFilter = function () {
                var q = notifSearchInput ? notifSearchInput.value.trim().toLowerCase() : '';
                var visibleCount = 0;

                notifItems.forEach(function (item) {
                    var matchesTab = notifActiveTab === 'read'
                        ? item.getAttribute('data-notif-unread') === '0'
                        : item.getAttribute('data-notif-unread') === '1';
                    var matchesSearch = q === '' || (item.getAttribute('data-notif-text') || '').indexOf(q) !== -1;
                    var visible = matchesTab && matchesSearch;
                    item.hidden = !visible;
                    if (visible) {
                        visibleCount++;
                    }
                });

                if (notifNoMatch) {
                    notifNoMatch.hidden = visibleCount !== 0 || notifItems.length === 0;
                }
            };

            notifTabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    notifActiveTab = tab.getAttribute('data-notif-tab');
                    notifTabs.forEach(function (t) {
                        t.classList.toggle('is-active', t === tab);
                    });
                    applyNotifFilter();
                });
            });

            if (notifSearchInput) {
                notifSearchInput.addEventListener('input', applyNotifFilter);
            }

            if (notifMarkAllBtn) {
                notifMarkAllBtn.addEventListener('click', function () {
                    if (notifMarkAllBtn.disabled) {
                        return;
                    }
                    notifMarkAllBtn.disabled = true;

                    fetch('/api/notifications_mark_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ csrf_token: CSRF_TOKEN }).toString(),
                    }).then(function (res) {
                        return res.json();
                    }).then(function (data) {
                        notifMarkAllBtn.disabled = false;
                        if (!data || !data.ok) {
                            return;
                        }
                        notifItems.forEach(function (item) {
                            item.classList.remove('is-unread');
                            item.setAttribute('data-notif-unread', '0');
                        });
                        if (notifBadge && notifBadge.parentNode) {
                            notifBadge.parentNode.removeChild(notifBadge);
                        }
                        applyNotifFilter();
                    }).catch(function () {
                        notifMarkAllBtn.disabled = false;
                    });
                });
            }

            applyNotifFilter();

            window.bcc_bindDismissable(notifDetails);
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
