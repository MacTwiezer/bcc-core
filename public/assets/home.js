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

        // Global arama (Ctrl+K / Cmd+K popover) ARTIK BURADA DEĞİL —
        // assets/global-search.js'e taşındı ve TÜM sayfalarda (grid.php dahil)
        // çalışıyor. Buradaki eski sürüm, kısayolu da dahil olmak üzere
        // tamamen `#home-base-grid` var mı kontrolünün içindeydi; o yüzden
        // workspaces.php / team_members.php / boş dashboard'da Ctrl+K ÖLÜYDÜ.
        // Kart silindiğinde arama listesini güncelleme kancası:
        // window.bcc_searchRemoveItem(baseId).

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
                        // Sayfada birden çok ızgara olabildiği için (bkz. görünüm
                        // değiştiricideki not) kart sayısı TÜM ızgaralarda sayılır.
                        // starred.php bugün gruplamıyor ama sayım yine de tek bir
                        // kaba bağlı kalmasın.
                        if (document.querySelectorAll('.home-base-grid .home-base-card').length <= 1) {
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

            function repositionPanel() {
                if (panel && summary) {
                    positionPanelBelow(panel, summary);
                }
            }

            function repositionSubPanel() {
                if (subPanel && subSummary) {
                    positionPanelRight(subPanel, subSummary);
                }
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
                    window.removeEventListener('scroll', repositionPanel, true);
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
                    repositionPanel();
                    // Bulunan gerçek bug: konum yalnızca AÇILIŞTA hesaplanıyordu —
                    // grid-column-menu.js/grid-table-data.js/grid-view-manage.js'de
                    // bulunan AYNI sorun. Sayfa kaydırılırsa kart kayarken panel
                    // ekranda sabit kalıp kopuyordu. Scroll'da yeniden konumlandırılır.
                    window.addEventListener('scroll', repositionPanel, true);
                }
            });

            if (subMenu) {
                subMenu.addEventListener('toggle', function () {
                    if (!subMenu.open) {
                        window.removeEventListener('scroll', repositionSubPanel, true);
                        return;
                    }
                    repositionSubPanel();
                    window.addEventListener('scroll', repositionSubPanel, true);
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

        // Trash — "⋯" menüsündeki "Sil" (yalnızca owner rolünde render edilir,
        // bkz. schema.php $canDelete). Soft-delete olduğu için (geri alınabilir,
        // bkz. api/base_delete.php + hesap menüsündeki Çöp kutusu) kart burada
        // sayfa yenilenmeden DOM'dan kaldırılır — sunucudaki tek doğruluk
        // kaynağı (deleted_at) zaten bir sonraki sayfa yüklemesinde aynı sonucu verir.
        document.querySelectorAll('[data-base-delete]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (!window.confirm('Bu base\'i silmek istediğinize emin misiniz? Çöp kutusundan geri yükleyebilirsiniz.')) {
                    return;
                }

                var card = btn.closest('.home-base-card');
                var baseId = btn.getAttribute('data-base-delete');
                btn.disabled = true;

                fetch('/api/base_delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: CSRF_TOKEN, base_id: baseId }).toString(),
                }).then(function (res) {
                    return res.json().catch(function () { return { ok: false }; });
                }).then(function (data) {
                    if (data && data.ok) {
                        if (card) {
                            card.remove();
                        }

                        // Bulunan gerçek bug: Ctrl+K arama popover'ı kartlardan
                        // KLONLANMIŞ ayrı bir kopya listesi tutuyor — kart
                        // silinince bu klon silinmiyordu, artık var olmayan bir
                        // base'e giden tıklanabilir bir sonuç sayfa yenilenene
                        // kadar aramada kalıyordu. Liste artık global-search.js'te
                        // yaşadığı için temizlik onun açtığı kancadan yapılır.
                        if (typeof window.bcc_searchRemoveItem === 'function') {
                            window.bcc_searchRemoveItem(baseId);
                        }
                    } else {
                        btn.disabled = false;
                        window.alert((data && data.error) || 'Silinemedi.');
                    }
                }).catch(function () {
                    btn.disabled = false;
                    window.alert('Silinemedi (bağlantı hatası).');
                });
            });
        });

        // "+ Yeni Base Oluştur" modalı. Tetikleyici (#home-create-base-btn) ve
        // modalın KENDİSİ sunucuda koşullu basılır (bkz. dashboard.php
        // $canCreateBase / src/auth.php bcc_can_manage_bases) — yetkisi olmayan
        // kullanıcıda ikisi de DOM'da yoktur, bu blok sessizce atlanır. Buradaki
        // hiçbir kontrol yetki kontrolü DEĞİLDİR; asıl kapı api/base_create.php.
        var createModal = document.getElementById('home-create-base-modal');
        var createBtn = document.getElementById('home-create-base-btn');

        if (createModal && createBtn) {
            var createForm = document.getElementById('home-create-base-form');
            var createError = document.getElementById('home-create-base-error');
            var createNameInput = createForm.querySelector('input[name="name"]');
            var createSubmitBtn = createForm.querySelector('button[type="submit"]');

            var showCreateError = function (message) {
                createError.textContent = message;
                createError.hidden = false;
            };

            var closeCreateModal = function () {
                createModal.hidden = true;
                createError.hidden = true;
                createBtn.focus();
            };

            var openCreateModal = function () {
                createModal.hidden = false;
                createError.hidden = true;
                createNameInput.focus();
            };

            createBtn.addEventListener('click', openCreateModal);
            document.getElementById('home-create-base-close').addEventListener('click', closeCreateModal);
            document.getElementById('home-create-base-cancel').addEventListener('click', closeCreateModal);

            // Dışarı tıklayınca kapanma — .home-modal'ın İÇİNE tıklandığında
            // olay yukarı kabarıp backdrop'a ulaştığı için hedef kontrolü şart
            // (e.target === backdrop), yoksa form alanlarına her tıklama modalı
            // kapatırdı.
            createModal.addEventListener('click', function (e) {
                if (e.target === createModal) {
                    closeCreateModal();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !createModal.hidden) {
                    closeCreateModal();
                }
            });

            createForm.addEventListener('submit', function (e) {
                // JS buraya kadar geldiyse AJAX yolunu kullanırız; formun kendi
                // action="/bases.php" POST'u yalnızca bu dinleyici hiç
                // bağlanamadıysa (JS kapalı/hatalı) devreye giren yedektir.
                e.preventDefault();

                if (createSubmitBtn.disabled) {
                    return;
                }
                createSubmitBtn.disabled = true;
                createError.hidden = true;

                var payload = new URLSearchParams(new FormData(createForm)).toString();

                fetch('/api/base_create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload,
                }).then(function (res) {
                    return res.json().catch(function () { return { ok: false }; });
                }).then(function (data) {
                    if (data && data.ok && data.id) {
                        // Yeni base doğrudan açılır (OpsFlow de oluşturur
                        // oluşturmaz base'e girer). Kartı DOM'a elle eklemeye
                        // gerek yok — sayfa zaten terk ediliyor.
                        window.location.href = '/base.php?base_id=' + encodeURIComponent(data.id);
                        return;
                    }
                    createSubmitBtn.disabled = false;
                    showCreateError((data && data.error) || 'Base oluşturulamadı.');
                }).catch(function () {
                    createSubmitBtn.disabled = false;
                    showCreateError('Base oluşturulamadı (bağlantı hatası).');
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
        // ⚠️ getElementById DEĞİL: Home artık çalışma alanına göre gruplanınca
        // sayfada BİRDEN ÇOK .home-base-grid oluyor (her grup bir ızgara, bir de
        // "Yeni Base Oluştur" kutucuğunu taşıyan kuyruk ızgarası). Tek id ile
        // yalnızca ilk grup mod değiştirirdi, kalanlar kart modunda kalırdı.
        var grids = Array.prototype.slice.call(document.querySelectorAll('.home-base-grid'));
        var buttons = document.querySelectorAll('[data-view-mode-btn]');

        if (!grids.length || !buttons.length) {
            return;
        }

        // İlk mod: <head>'teki senkron script sayfa boyanmadan önce zaten
        // localStorage'ı okuyup doğrulamış ve sonucu <html class="home-view-list">
        // olarak işaretlemişti (FOUC önleme, bkz. dashboard.php <head>). Burada
        // localStorage TEKRAR okunmuyor/doğrulanmıyor — tek doğrulama kaynağı odur.
        var mode = document.documentElement.classList.contains('home-view-list') ? 'list' : 'card';

        function applyMode(newMode) {
            mode = newMode;
            grids.forEach(function (g) {
                g.classList.toggle('view-mode-list', mode === 'list');
                g.classList.toggle('view-mode-card', mode === 'card');
            });
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
