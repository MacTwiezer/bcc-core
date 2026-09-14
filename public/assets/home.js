(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var filterDetails = document.getElementById('home-filter');
        if (filterDetails) {
            window.bcc_bindDismissable(filterDetails);
        }


        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF_TOKEN = csrfMeta ? csrfMeta.content : '';
        var starredList = document.getElementById('home-starred-list');


        /* 2026-09-14 — Yildiz ve data-nav-href dinleyicileri dugmelere TEK TEK
           baglaniyordu. Cop kutusundan geri yuklenen kart sonradan enjekte
           edildigi icin (account-menu.js insertRestoredCard) ona hic
           baglanmiyordu; kart bir <a> oldugu icin tiklama karta dusup kullaniciyi
           base'e goturuyordu (olculdu: yildiz istegi yok, adres #git-<id>).
           Dinleyiciler belge seviyesinde; <a> gezinmesi preventDefault ile
           iptal ediliyor. */
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.home-base-star-btn') : null;
            if (!btn) {
                return;
            }
            e.preventDefault();
            handleStarClick(btn);
        });

        function handleStarClick(btn) {
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

                    var teamId = card.getAttribute('data-team-id');
                    var group = teamId
                        ? starredList.querySelector('.home-starred-group[data-starred-team-id="' + teamId + '"]')
                        : null;

                    if (!group && teamId) {
                        var wsEl = card.querySelector('.home-base-workspace');

                        group = document.createElement('div');
                        group.className = 'home-starred-group';
                        group.setAttribute('data-starred-team-id', teamId);

                        var teamLabel = document.createElement('div');
                        teamLabel.className = 'home-starred-team';
                        teamLabel.textContent = wsEl ? wsEl.textContent.trim() : '';
                        teamLabel.title = teamLabel.textContent;
                        group.appendChild(teamLabel);

                        starredList.appendChild(group);
                    }

                    (group || starredList).appendChild(item);
                } else if (!data.starred && existingItem) {
                    var oldGroup = existingItem.closest('.home-starred-group');
                    existingItem.remove();
                    if (oldGroup && !oldGroup.querySelector('.home-starred-item')) {
                        oldGroup.remove();
                    }
                }

                if (!data.starred && window.location.pathname.indexOf('/starred.php') !== -1) {
                    if (document.querySelectorAll('.home-base-grid .home-base-card').length <= 1) {
                        window.location.reload();
                        return;
                    }
                    card.remove();
                }
            }).catch(function () {
                btn.disabled = false;
            });
        }

        var menuEntries = [];

        function positionPanelBelow(panel, anchorEl) {
            var rect = anchorEl.getBoundingClientRect();
            var s = window.bcc_uiScale ? window.bcc_uiScale() : 1;
            panel.style.top = (rect.bottom / s + 4) + 'px';
            panel.style.left = 'auto';
            panel.style.right = ((window.innerWidth - rect.right) / s) + 'px';
        }

        function positionPanelRight(panel, anchorEl) {
            var rect = anchorEl.getBoundingClientRect();
            var s = window.bcc_uiScale ? window.bcc_uiScale() : 1;
            panel.style.top = (rect.top / s) + 'px';
            panel.style.left = (rect.right / s + 4) + 'px';
            panel.style.right = 'auto';
        }

        /* "..." menusu: <details>'in "toggle" olayi kabarciklanmadigi icin belge
           seviyesine tasinamiyor; her menu bu fonksiyonla tek tek baglaniyor.
           Sonradan eklenen kart (cop kutusundan geri yukleme) "bcc:base-card-inserted"
           olayiyla ayni fonksiyondan geciyor. Iki kez baglanmasin diye isaretleniyor. */
        function wireMoreMenu(menu) {
            if (menu.hasAttribute('data-menu-wired')) {
                return;
            }
            menu.setAttribute('data-menu-wired', '1');

            var ilkYeniGiris = menuEntries.length;

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
                    document.body.appendChild(panel);
                    repositionPanel();
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

            menuEntries.slice(ilkYeniGiris).forEach(function (entry) {
                window.bcc_bindDismissable(entry.menu, {
                    isClickOutside: function (target) {
                        return !entry.menu.contains(target) && !(entry.panel && entry.panel.contains(target));
                    },
                });
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('.home-base-more-menu'), wireMoreMenu);

        document.addEventListener('bcc:base-card-inserted', function (e) {
            var card = e.detail && e.detail.card;
            if (!card || !card.querySelectorAll) {
                return;
            }
            Array.prototype.forEach.call(card.querySelectorAll('.home-base-more-menu'), wireMoreMenu);
            grubuEsitle(card.parentElement);
        });

        /* "Tabloya git" ve "Ac > Duyuru". Belge seviyesinde: acik menunun paneli
           document.body'ye tasiniyor ve geri yuklenen kart sonradan ekleniyor —
           ikisinde de dugmeye dogrudan baglanan dinleyici calismazdi. */
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-nav-href]') : null;
            if (!btn) {
                return;
            }
            e.preventDefault();
            window.location.href = btn.getAttribute('data-nav-href');
        });

        /* Kart silindikten sonra sayfada ondan geriye kalanlari da temizler:
           grup basligindaki "N base" sayaci sunucudan basiliyor, kendiliginden
           guncellenmiyordu; grubun son base'i silinince de bos bir baslik geride
           kaliyordu. Ikisi de "silinmedi, yenilemem lazim" izlenimi veriyordu. */
        /* Bir izgaranin grup basligini ve "N base" sayacini icerige gore esitler.
           Silmede de geri yuklemede de ayni fonksiyon: ikisi ayri yazilinca biri
           unutuluyordu (geri yukleme sayaci hic guncellemiyordu, olculdu).

           Bosalan grup KALDIRILMIYOR, GIZLENIYOR (hidden): cop kutusundan geri
           yuklenen kart ayni izgaraya donebilsin. Kaldirilsaydi geri yukleme
           ekibin izgarasini bulamayip karti baska ekibin altina koyuyordu. */
        function grubuEsitle(grid) {
            if (!grid || !grid.classList.contains('home-base-grid')) {
                return;
            }

            var kalan = grid.querySelectorAll('.home-base-card:not(.home-base-create)').length;
            var head = grid.previousElementSibling;
            var baslikVar = head && head.classList.contains('home-section-head');

            /* Gruplanmamis duzende "Yeni Base Olustur" karosu AYNI izgaranin
               icinde (bcc_render_home_base_grid_block): izgara yalnizca icinde hic
               kart kalmadiysa gizlenir. */
            grid.hidden = !grid.querySelector('.home-base-card');

            if (!baslikVar) {
                return;
            }

            head.hidden = kalan === 0;

            var meta = head.querySelector('.home-section-meta');
            if (meta) {
                meta.textContent = kalan + ' base';
            }
        }

        function baseKartiniTemizle(card) {
            var grid = card.parentElement;
            card.remove();
            grubuEsitle(grid);
        }

        /* Dinleyici dogrudan dugmede degil belgede: cop kutusundan geri yuklenen
           kart DOM'a sonradan enjekte ediliyor (account-menu.js:108) ve o kartin
           dugmesine dogrudan baglanan bir dinleyici hic takilmiyordu. */
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-base-delete]') : null;
            if (!btn || btn.disabled) {
                return;
            }

            /* Kart bir <a>; varsayilan eylemi (base'e gitmek) iptal ediliyor.
               Olay belgeye kadar zaten cikti, stopPropagation gereksiz. */
            e.preventDefault();

            window.bcc_confirm({
                title: 'Base\'i sil',
                message: 'Bu base\'i silmek istediğinize emin misiniz? Çöp kutusundan geri yükleyebilirsiniz.',
            }).then(function (onaylandi) {
                if (!onaylandi) {
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
                            baseKartiniTemizle(card);
                        }

                        /* Sol paneldeki "Yildizlilar" satiri da sunucudan
                           basiliyor; silinen base orada kaliyordu. Grup
                           basligindan baska bir sey kalmadiysa grup da gider. */
                        var yildizli = document.querySelector('[data-starred-base-id="' + baseId + '"]');
                        if (yildizli) {
                            var grup = yildizli.closest('.home-starred-group');
                            yildizli.remove();
                            if (grup && !grup.querySelector('[data-starred-base-id]')) {
                                grup.remove();
                            }
                        }

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

        var createModal = document.getElementById('home-create-base-modal');
        var createTriggers = Array.prototype.slice.call(
            document.querySelectorAll('[data-create-base-open]')
        );

        if (createModal && createTriggers.length) {
            var createForm = document.getElementById('home-create-base-form');
            var createError = document.getElementById('home-create-base-error');
            var createNameInput = createForm.querySelector('input[name="name"]');
            var createSubmitBtn = createForm.querySelector('button[type="submit"]');

            var showCreateError = function (message) {
                createError.textContent = message;
                createError.hidden = false;
            };

            var lastCreateTrigger = null;

            var closeCreateModal = function () {
                createModal.hidden = true;
                createError.hidden = true;
                if (lastCreateTrigger) {
                    lastCreateTrigger.focus();
                }
            };

            var openCreateModal = function () {
                createModal.hidden = false;
                createError.hidden = true;
                createNameInput.focus();
            };

            createTriggers.forEach(function (trigger) {
                trigger.addEventListener('click', function (e) {
                    e.preventDefault();
                    lastCreateTrigger = trigger;
                    openCreateModal();
                });
            });
            document.getElementById('home-create-base-close').addEventListener('click', closeCreateModal);
            document.getElementById('home-create-base-cancel').addEventListener('click', closeCreateModal);

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
                        closeCreateModal();
                        window.location.reload();
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

                markNotifRuns();
            };

            var markNotifRuns = function () {
                var visible = notifItems.filter(function (item) {
                    return !item.hidden;
                });

                visible.forEach(function (item, i) {
                    var unread = item.classList.contains('is-unread');
                    var prev = i > 0 ? visible[i - 1] : null;
                    var next = i < visible.length - 1 ? visible[i + 1] : null;

                    item.classList.toggle(
                        'is-run-start',
                        unread && (prev === null || !prev.classList.contains('is-unread'))
                    );
                    item.classList.toggle(
                        'is-run-end',
                        unread && (next === null || !next.classList.contains('is-unread'))
                    );
                });
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
                        Array.prototype.forEach.call(
                            notifDetails.querySelectorAll('[data-notif-read]'),
                            function (b) {
                                if (b.parentNode) {
                                    b.parentNode.removeChild(b);
                                }
                            }
                        );
                        if (notifBadge && notifBadge.parentNode) {
                            notifBadge.parentNode.removeChild(notifBadge);
                        }
                        applyNotifFilter();
                    }).catch(function () {
                        notifMarkAllBtn.disabled = false;
                    });
                });
            }

            var notifBadgeEl = notifBadge;
            Array.prototype.forEach.call(notifDetails.querySelectorAll('[data-notif-read]'), function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (btn.disabled) {
                        return;
                    }
                    btn.disabled = true;

                    var item = btn.closest('.home-notif-item');

                    fetch('/api/notification_mark_one_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            csrf_token: CSRF_TOKEN,
                            notification_id: btn.getAttribute('data-notif-read'),
                        }).toString(),
                    }).then(function (res) {
                        return res.json().catch(function () { return { ok: false }; });
                    }).then(function (data) {
                        if (!data || !data.ok) {
                            btn.disabled = false;
                            window.alert((data && data.error) || 'Okundu işaretlenemedi.');
                            return;
                        }

                        if (item) {
                            item.classList.remove('is-unread');
                            item.setAttribute('data-notif-unread', '0');
                        }
                        if (btn.parentNode) {
                            btn.parentNode.removeChild(btn);
                        }

                        if (notifBadgeEl && notifBadgeEl.parentNode) {
                            var kalan = notifItems.filter(function (it) {
                                return it.getAttribute('data-notif-unread') === '1';
                            }).length;
                            if (kalan === 0) {
                                notifBadgeEl.parentNode.removeChild(notifBadgeEl);
                                notifBadgeEl = null;
                            } else {
                                notifBadgeEl.textContent = kalan > 9 ? '9+' : String(kalan);
                            }
                        }

                        applyNotifFilter();
                    }).catch(function () {
                        btn.disabled = false;
                        window.alert('Okundu işaretlenemedi (bağlantı hatası).');
                    });
                });
            });

            applyNotifFilter();

            window.bcc_bindDismissable(notifDetails);
        }

        var membersSearch = document.querySelector('[data-members-search]');
        if (membersSearch) {
            var membersList = document.querySelector('[data-members-list]');
            var membersEmpty = membersList ? membersList.querySelector('[data-members-empty]') : null;
            var memberItems = membersList
                ? Array.prototype.slice.call(membersList.querySelectorAll('[data-members-name]'))
                : [];

            membersSearch.addEventListener('input', function () {
                var q = membersSearch.value.trim().toLowerCase();
                var gorunen = 0;

                memberItems.forEach(function (item) {
                    var eslesti = q === '' || (item.getAttribute('data-members-name') || '').indexOf(q) !== -1;
                    item.hidden = !eslesti;
                    if (eslesti) {
                        gorunen++;
                    }
                });

                if (membersEmpty) {
                    membersEmpty.hidden = gorunen !== 0;
                }
            });
        }

        var STORAGE_KEY = 'bcc_home_view_mode';
        var grids = Array.prototype.slice.call(document.querySelectorAll('.home-base-grid'));
        var buttons = document.querySelectorAll('[data-view-mode-btn]');

        if (!grids.length || !buttons.length) {
            return;
        }

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
                }

                applyMode(newMode);
            });
        });

        applyMode(mode);
    });
})();
