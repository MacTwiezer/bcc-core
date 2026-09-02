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
            });
        });

        var moreMenus = Array.prototype.slice.call(document.querySelectorAll('.home-base-more-menu'));
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
        });

        if (menuEntries.length) {
            menuEntries.forEach(function (entry) {
                window.bcc_bindDismissable(entry.menu, {
                    isClickOutside: function (target) {
                        return !entry.menu.contains(target) && !(entry.panel && entry.panel.contains(target));
                    },
                });
            });
        }

        document.querySelectorAll('[data-nav-href]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                window.location.href = btn.getAttribute('data-nav-href');
            });
        });

        document.querySelectorAll('[data-base-delete]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

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
                                card.remove();
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
