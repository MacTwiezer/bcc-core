(function () {
    'use strict';

    // "Paylaş" modalı (src/partials/share_modal.php) — Airtable'ın
    // Collaborators diyaloğunun karşılığı. Önceden "N kişinin erişimi var"
    // bağlantısı team_members.php'ye YÖNLENDİRİYORDU; artık sayfadan
    // çıkılmadan aynı iş burada yapılıyor.
    //
    // TEK RENDERER: renderLists() hem ilk açılışta (sayfaya gömülü
    // BCC_SHARE_MODAL) hem her mutasyondan sonra (uçnoktanın döndürdüğü AYNI
    // yapı) çalışır. PHP tarafında ikinci bir liste şablonu YOK — olsaydı ilk
    // render ile güncel render ilk değişiklikte ayrışırdı.
    //
    // YETKİ: satır başına "rolü değiştirilebilir / çıkarılabilir" kararları
    // SUNUCUDAN bayrak olarak gelir (can_change_role / can_remove, bkz.
    // src/share_modal_payload.php). Burada BCC_ROLE_RANK yeniden
    // yorumlanmıyor. Bayraklar yalnızca görseldir; asıl kapı
    // api/team_member_assign.php ve api/team_member_remove.php'de
    // (bcc_can_manage_members + hiyerarşi) — "gizleme != yetkilendirme".
    //
    // KAPANMA: backdrop tıklaması ve Escape ortak yardımcıdan gelir
    // (window.bcc_bindDismissable, assets/dismissable-panel.js) — grid'deki
    // "Görünüm açıklaması"/"Veri içe aktar" modallarıyla AYNI davranış, ikinci
    // bir dinleyici çifti yazılmadı.

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
        var suggestions = overlay.querySelector('[data-share-suggestions]');
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

        // ---- Durum satırı ---------------------------------------------------
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

        // ---- Liste render (TEK yer) -----------------------------------------
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

            // Hedefin MEVCUT rolü benim atayabileceklerim arasında değilse
            // (ör. benden yüksek rütbe) select onu hiç göstermezdi ve ilk
            // seçenek seçili görünürdü — yanlış bilgi. Sunucu bu durumda
            // can_change_role=false gönderiyor, yani buraya hiç gelinmiyor;
            // yine de savunmacı olarak rolü ekliyoruz.
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
                if (!window.confirm(member.name + ' ekipten çıkarılsın mı?')) {
                    return;
                }
                remove(member.id);
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
                // Salt-okunur: rol METİN olarak. Devre dışı bir <select>
                // "buradan değiştirebilirdim" izlenimi veren ölü bir arayüz
                // olurdu (team_members.php'deki .tm-role-readonly ile AYNI karar).
                var readonlyRole = document.createElement('span');
                readonlyRole.className = 'gs-share-role-readonly';
                readonlyRole.textContent = member.role_label;
                actions.appendChild(readonlyRole);
            }

            if (member.can_remove) {
                actions.appendChild(removeButton(member));
            } else {
                // Butonun yerini tutan görsel boşluk: rol kutuları satırlar
                // arasında hizalı kalsın (bkz. .gs-share-remove-spacer).
                // Devre dışı bir buton KOYULMADI — tıklanamayan bir çöp
                // kutusu simgesi "yetkim var ama şu an olmaz" derdi.
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

            // "Paylaş" popover'ındaki özet etiketi de tazelensin — modalda
            // biri çıkarıldığında arkadaki sayı bayatlamasın.
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
            // Airtable varsayılanı gibi en dar yetki değil, listenin en
            // alt rütbesi seçili gelsin: yanlışlıkla owner atamak yerine
            // bilinçli bir yükseltme gerektirsin.
            if (state.assignable_roles.length) {
                inviteRole.value = state.assignable_roles[0].value;
            }
        }

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.textContent = value;
            return div.innerHTML;
        }

        function renderSuggestions() {
            if (!suggestions) {
                return;
            }
            // Zaten üye olanlar öneri listesinden düşsün (popover'ın
            // $shareCandidateUsers filtresiyle AYNI kural, burada istemci
            // tarafında güncel listeye göre yeniden uygulanıyor).
            var memberIds = {};
            state.collaborators.concat(state.pending).forEach(function (m) {
                memberIds[m.id] = true;
            });

            suggestions.textContent = '';
            candidates.forEach(function (c) {
                if (memberIds[c.id]) {
                    return;
                }
                var opt = document.createElement('option');
                opt.value = c.email;
                opt.label = c.full_name;
                suggestions.appendChild(opt);
            });
        }

        function renderAll() {
            renderInviteBox();
            renderLists();
            renderSuggestions();
        }

        // ---- Sunucu çağrıları -------------------------------------------------
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
                    // Reddedilen istekte liste DEĞİŞMEZ: sunucu hiçbir şey
                    // yazmadı, ekrandaki durum hâlâ doğru.
                    setStatus((result.data && result.data.error) || 'İşlem tamamlanamadı.', true);
                    return;
                }

                state = result.data;
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

        // ---- Sekmeler ---------------------------------------------------------
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

        // ---- Açma / kapama ----------------------------------------------------
        function open() {
            setStatus(null);
            renderAll();
            overlay.hidden = false;
            if (state.can_manage && inviteEmail) {
                inviteEmail.focus();
            }
        }

        function close() {
            overlay.hidden = true;
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', close);
        }

        // Backdrop tıklaması + Escape — ortak yardımcı (isClickOutside
        // override'ı: yalnızca backdrop'un KENDİSİ, modal içeriği değil).
        window.bcc_bindDismissable(overlay, {
            isOpen: function () { return !overlay.hidden; },
            close: close,
            isClickOutside: function (target) { return target === overlay; },
        });

        // Tetikleyiciler: "Paylaş" popover'ındaki "N kişinin erişimi var"
        // satırı ve varsa başka [data-share-modal-open] öğeleri. Bunlar <a>
        // DEĞİL <button> — yönlendirme kaldırıldığı için href'i olmayan bir
        // bağlantı bırakmak yanlış olurdu.
        Array.prototype.forEach.call(document.querySelectorAll('[data-share-modal-open]'), function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                // Popover bir <details>; modal açılırken kapansın.
                var details = trigger.closest('details');
                if (details) {
                    details.removeAttribute('open');
                }
                open();
            });
        });
    });
})();
