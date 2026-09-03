(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var overlay = document.getElementById('gs-share-overlay');
        if (!overlay || !window.BCC_SHARE_MODAL) {
            return;
        }

        var state = window.BCC_SHARE_MODAL;
        var candidates = window.BCC_SHARE_CANDIDATES || [];
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        var closeBtn = document.getElementById('gs-share-close');
        var subtitleEl = overlay.querySelector('[data-share-subtitle]');
        var inviteBox = overlay.querySelector('[data-share-invite]');
        var inviteEmail = overlay.querySelector('[data-share-invite-email]');
        var inviteRole = overlay.querySelector('[data-share-invite-role]');
        var inviteBtn = overlay.querySelector('[data-share-invite-btn]');
        var suggestBox = overlay.querySelector('[data-share-suggest]');
        var readonlyNote = overlay.querySelector('[data-share-readonly-note]');
        var statusEl = overlay.querySelector('[data-share-status]');
        var panels = {
            collaborators: overlay.querySelector('[data-share-panel="collaborators"]'),
            pending: overlay.querySelector('[data-share-panel="pending"]'),
        };
        var counts = {
            collaborators: overlay.querySelector('[data-share-count-collaborators]'),
            pending: overlay.querySelector('[data-share-count-pending]'),
        };

        var statusTimer = null;
        function setStatus(message, isError) {
            if (statusTimer) {
                window.clearTimeout(statusTimer);
                statusTimer = null;
            }
            if (!message) {
                statusEl.hidden = true;
                statusEl.textContent = '';
                return;
            }
            statusEl.hidden = false;
            statusEl.textContent = message;
            statusEl.classList.toggle('is-error', !!isError);
            if (!isError) {
                statusTimer = window.setTimeout(function () {
                    statusEl.hidden = true;
                }, 4000);
            }
        }

        function roleSelect(member) {
            var select = document.createElement('select');
            select.className = 'gs-share-role-select';
            select.setAttribute('data-share-role-for', member.id);
            select.setAttribute('aria-label', member.name + ' rolü');

            state.assignable_roles.forEach(function (r) {
                var opt = document.createElement('option');
                opt.value = r.value;
                opt.textContent = r.label;
                if (r.value === member.role) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });

            if (!select.value) {
                var fallback = document.createElement('option');
                fallback.value = member.role;
                fallback.textContent = member.role_label;
                fallback.selected = true;
                select.appendChild(fallback);
            }

            select.addEventListener('change', function () {
                assign({ user_id: member.id, role: select.value });
            });

            return select;
        }

        function removeButton(member) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gs-share-remove-btn';
            btn.setAttribute('data-share-remove-for', member.id);
            btn.setAttribute('aria-label', member.name + ' kullanıcısını çıkar');
            btn.title = 'Ekipten çıkar';
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M8 6V4.5a1 1 0 011-1h2a1 1 0 011 1V6m-7 0l.6 9.2a1.5 1.5 0 001.5 1.4h4.8a1.5 1.5 0 001.5-1.4L15 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            btn.addEventListener('click', function () {
                window.bcc_confirm({
                    title: 'Ekipten çıkar',
                    message: member.name + ' ekipten çıkarılsın mı?',
                    confirmLabel: 'Evet, çıkar',
                }).then(function (onaylandi) {
                    if (onaylandi) {
                        remove(member.id);
                    }
                });
            });
            return btn;
        }

        function memberRow(member) {
            var row = document.createElement('div');
            row.className = 'gs-share-row';
            row.setAttribute('data-share-row', member.id);

            var avatar = document.createElement('div');
            avatar.className = 'ws-collab-avatar gs-share-avatar';
            avatar.textContent = member.initial;
            row.appendChild(avatar);

            var info = document.createElement('div');
            info.className = 'gs-share-row-info';
            var name = document.createElement('div');
            name.className = 'gs-share-row-name';
            name.textContent = member.name + (member.is_self ? ' (siz)' : '');
            var mail = document.createElement('div');
            mail.className = 'gs-share-row-email';
            mail.textContent = member.email;
            info.appendChild(name);
            info.appendChild(mail);
            row.appendChild(info);

            var actions = document.createElement('div');
            actions.className = 'gs-share-row-actions';

            if (member.can_change_role && state.assignable_roles.length) {
                actions.appendChild(roleSelect(member));
            } else {
                var readonlyRole = document.createElement('span');
                readonlyRole.className = 'gs-share-role-readonly';
                readonlyRole.textContent = member.role_label;
                actions.appendChild(readonlyRole);
            }

            if (member.can_remove) {
                actions.appendChild(removeButton(member));
            } else {
                var spacer = document.createElement('span');
                spacer.className = 'gs-share-remove-spacer';
                spacer.setAttribute('aria-hidden', 'true');
                actions.appendChild(spacer);
            }

            row.appendChild(actions);
            return row;
        }

        function renderList(panel, members, emptyText) {
            panel.textContent = '';

            if (!members.length) {
                var empty = document.createElement('p');
                empty.className = 'gs-share-empty';
                empty.textContent = emptyText;
                panel.appendChild(empty);
                return;
            }

            members.forEach(function (m) {
                panel.appendChild(memberRow(m));
            });
        }

        function renderLists() {
            renderList(panels.collaborators, state.collaborators, 'Bu çalışma alanında henüz katılımcı yok.');
            renderList(
                panels.pending,
                state.pending,
                'Bekleyen davet yok. Hesabını henüz etkinleştirmemiş üyeler burada görünür.'
            );

            counts.collaborators.textContent = state.collaborators.length;
            counts.pending.textContent = state.pending.length;

            subtitleEl.textContent = state.collaborators.length + ' kişinin erişimi var'
                + (state.pending.length ? ' · ' + state.pending.length + ' bekleyen' : '');

            var popoverLabel = document.querySelector('[data-share-people-label]');
            if (popoverLabel) {
                popoverLabel.textContent = state.collaborators.length + ' kişinin erişimi var';
            }
        }

        function renderInviteBox() {
            if (!state.can_manage) {
                inviteBox.hidden = true;
                readonlyNote.hidden = false;
                readonlyNote.innerHTML = 'Bu çalışma alanındaki rolünüz <strong>'
                    + escapeHtml(state.my_role_label)
                    + '</strong>. Katılımcı listesini görüntüleyebilirsiniz; üye ekleme, rol değiştirme ve '
                    + 'çıkarma işlemleri yalnızca <strong>Owner</strong> rolüne açıktır.';
                return;
            }

            inviteBox.hidden = false;
            readonlyNote.hidden = true;

            inviteRole.textContent = '';
            state.assignable_roles.forEach(function (r) {
                var opt = document.createElement('option');
                opt.value = r.value;
                opt.textContent = r.label;
                inviteRole.appendChild(opt);
            });
            if (state.assignable_roles.length) {
                inviteRole.value = state.assignable_roles[0].value;
            }
        }

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.textContent = value;
            return div.innerHTML;
        }

        var SUGGEST_LIMIT = 8;
        var suggestItems = [];
        var suggestIndex = -1;

        function hideSuggest() {
            if (!suggestBox) {
                return;
            }
            suggestBox.hidden = true;
            suggestBox.textContent = '';
            suggestItems = [];
            suggestIndex = -1;
            if (inviteEmail) {
                inviteEmail.setAttribute('aria-expanded', 'false');
            }
        }

        function availableCandidates() {
            var memberIds = {};
            state.collaborators.concat(state.pending).forEach(function (m) {
                memberIds[m.id] = true;
            });

            return candidates.filter(function (c) {
                return !memberIds[c.id];
            });
        }

        function markActive() {
            Array.prototype.forEach.call(suggestBox.children, function (el, i) {
                el.classList.toggle('is-active', i === suggestIndex);
                el.setAttribute('aria-selected', i === suggestIndex ? 'true' : 'false');
            });
        }

        function chooseSuggestion(c) {
            inviteEmail.value = c.email;
            hideSuggest();
            inviteEmail.focus();
        }

        function renderSuggestions() {
            if (!suggestBox || !inviteEmail) {
                return;
            }

            var q = inviteEmail.value.trim().toLocaleLowerCase('tr');
            var matches = availableCandidates().filter(function (c) {
                if (q === '') {
                    return true;
                }
                return String(c.email).toLocaleLowerCase('tr').indexOf(q) !== -1
                    || String(c.full_name).toLocaleLowerCase('tr').indexOf(q) !== -1;
            }).slice(0, SUGGEST_LIMIT);

            suggestBox.textContent = '';
            suggestItems = matches;
            suggestIndex = -1;

            if (!matches.length) {
                hideSuggest();
                return;
            }

            matches.forEach(function (c) {
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'gs-share-suggest-item';
                row.setAttribute('role', 'option');
                row.setAttribute('aria-selected', 'false');

                var name = document.createElement('span');
                name.className = 'gs-share-suggest-name';
                name.textContent = c.full_name;
                row.appendChild(name);

                var mail = document.createElement('span');
                mail.className = 'gs-share-suggest-mail';
                mail.textContent = c.email;
                row.appendChild(mail);

                row.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    chooseSuggestion(c);
                });

                suggestBox.appendChild(row);
            });

            suggestBox.hidden = false;
            inviteEmail.setAttribute('aria-expanded', 'true');
        }

        if (inviteEmail && suggestBox) {
            inviteEmail.addEventListener('input', renderSuggestions);

            inviteEmail.addEventListener('click', renderSuggestions);

            inviteEmail.addEventListener('keydown', function (e) {
                if (suggestBox.hidden && e.key === 'ArrowDown') {
                    e.preventDefault();
                    renderSuggestions();
                    return;
                }
                if (suggestBox.hidden || !suggestItems.length) {
                    return;
                }
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    var delta = e.key === 'ArrowDown' ? 1 : -1;
                    suggestIndex = (suggestIndex + delta + suggestItems.length) % suggestItems.length;
                    markActive();
                    return;
                }
                if (e.key === 'Enter' && suggestIndex >= 0) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    chooseSuggestion(suggestItems[suggestIndex]);
                    return;
                }
                if (e.key === 'Escape') {
                    hideSuggest();
                }
            });

            inviteEmail.addEventListener('blur', function () {
                setTimeout(hideSuggest, 120);
            });
        }

        function renderAll() {
            renderInviteBox();
            renderLists();
            hideSuggest();
        }

        var busy = false;

        function post(url, params) {
            if (busy) {
                return;
            }
            busy = true;
            overlay.classList.add('is-busy');

            params.csrf_token = CSRF;
            params.team_id = state.team_id;

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params).toString(),
            }).then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            }).then(function (result) {
                busy = false;
                overlay.classList.remove('is-busy');

                if (!result.ok || !result.data || !result.data.ok) {
                    setStatus((result.data && result.data.error) || 'İşlem tamamlanamadı.', true);
                    return;
                }

                state = result.data;
                mutated = true;
                renderAll();
                setStatus(result.data.message || 'Kaydedildi.', false);
            }).catch(function () {
                busy = false;
                overlay.classList.remove('is-busy');
                setStatus('İşlem tamamlanamadı (bağlantı hatası).', true);
            });
        }

        function assign(params) {
            post('/api/team_member_assign.php', params);
        }

        function remove(userId) {
            post('/api/team_member_remove.php', { user_id: userId });
        }

        if (inviteBtn) {
            inviteBtn.addEventListener('click', function () {
                var email = (inviteEmail.value || '').trim();
                if (!email) {
                    setStatus('Bir e-posta adresi girin.', true);
                    inviteEmail.focus();
                    return;
                }
                assign({ email: email, role: inviteRole.value });
                inviteEmail.value = '';
            });
        }
        if (inviteEmail) {
            inviteEmail.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    inviteBtn.click();
                }
            });
        }

        Array.prototype.forEach.call(overlay.querySelectorAll('[data-share-tab]'), function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-share-tab');
                Array.prototype.forEach.call(overlay.querySelectorAll('[data-share-tab]'), function (t) {
                    var active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                Object.keys(panels).forEach(function (key) {
                    panels[key].hidden = key !== name;
                });
            });
        });

        var mutated = false;

        function open() {
            setStatus(null);
            mutated = false;
            renderAll();
            overlay.hidden = false;
            if (state.can_manage && inviteEmail) {
                inviteEmail.focus();
            }
        }

        function close() {
            overlay.hidden = true;

            if (mutated) {
                document.dispatchEvent(new CustomEvent('bcc:share-modal-changed'));
                mutated = false;
            }
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', close);
        }

        window.bcc_bindDismissable(overlay, {
            isOpen: function () { return !overlay.hidden; },
            close: close,
            isClickOutside: function (target) { return target === overlay; },
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-share-modal-open]'), function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                var details = trigger.closest('details');
                if (details) {
                    details.removeAttribute('open');
                }
                open();
            });
        });
    });
})();
