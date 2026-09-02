(function () {
    'use strict';

    function fileTypeBadge(mime) {
        var map = {
            'application/pdf': 'PDF',
            'application/msword': 'DOC',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOC',
            'application/vnd.ms-excel': 'XLS',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLS',
            'application/vnd.ms-powerpoint': 'PPT',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PPT',
        };
        return map[mime] || 'DOSYA';
    }

    function renderAttachmentFiles(container, files) {
        container.textContent = '';
        files.forEach(function (file) {
            var isImage = file.mime.indexOf('image/') === 0;
            var a = document.createElement('a');
            a.className = 'attachment-chip';
            a.href = '/api/attachment_download.php?id=' + file.id;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.title = file.name;

            if (isImage) {
                var img = document.createElement('img');
                img.className = 'attachment-thumb';
                img.src = '/api/attachment_download.php?id=' + file.id;
                img.alt = '';
                a.appendChild(img);
            } else {
                var badge = document.createElement('span');
                badge.className = 'attachment-badge';
                badge.textContent = fileTypeBadge(file.mime);
                var name = document.createElement('span');
                name.className = 'attachment-name';
                name.textContent = file.name;
                a.appendChild(badge);
                a.appendChild(name);
            }

            container.appendChild(a);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var nav = document.getElementById('if-nav');
        var collapseBtn = document.getElementById('if-nav-collapse');
        var expandBtn = document.getElementById('if-nav-expand');
        if (nav && collapseBtn && expandBtn) {
            collapseBtn.addEventListener('click', function () {
                nav.classList.add('is-collapsed');
            });
            expandBtn.addEventListener('click', function () {
                nav.classList.remove('is-collapsed');
            });
        }

        var recordList = document.getElementById('if-record-list');
        var searchInput = document.getElementById('if-search-input');
        var noResults = document.getElementById('if-no-results');
        var detailPlaceholder = document.getElementById('if-detail-placeholder');
        var detailContent = document.getElementById('if-detail-content');
        var detailTitle = document.getElementById('if-detail-title');
        var detailLastUpdate = document.getElementById('if-detail-last-update');
        var detailFields = document.getElementById('if-detail-fields');
        var prevBtn = document.getElementById('if-detail-prev');
        var nextBtn = document.getElementById('if-detail-next');

        if (!recordList) {
            return;
        }

        var rows = Array.prototype.slice.call(recordList.querySelectorAll('.if-record-row'));
        var currentDetailRow = null;

        function getVisibleRows() {
            return Array.prototype.filter.call(
                recordList.querySelectorAll('.if-record-row'),
                function (r) { return !r.hidden; }
            );
        }

        function updateDetailNavState() {
            var visible = getVisibleRows();
            var idx = visible.indexOf(currentDetailRow);
            if (prevBtn) {
                prevBtn.disabled = idx <= 0;
            }
            if (nextBtn) {
                nextBtn.disabled = idx === -1 || idx >= visible.length - 1;
            }
        }

        function navigateDetail(delta) {
            if (!currentDetailRow) {
                return;
            }
            var visible = getVisibleRows();
            var idx = visible.indexOf(currentDetailRow);
            var next = visible[idx + delta];
            if (next) {
                selectRow(next);
            }
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () { navigateDetail(-1); });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () { navigateDetail(1); });
        }

        var trackViews = (typeof BCC_IF_TRACK_VIEWS !== 'undefined') && BCC_IF_TRACK_VIEWS === true;
        var auditCsrfMeta = document.querySelector('meta[name="csrf-token"]');
        var auditCsrf = auditCsrfMeta ? auditCsrfMeta.content : '';

        var currentViewId = null;
        var viewStartTimer = null;

        var VIEW_START_DELAY_MS = 2000;

        var VIEW_PING_MS = 15000;
        var viewPingTimer = null;

        function stopNoteViewPing() {
            if (viewPingTimer !== null) {
                clearInterval(viewPingTimer);
                viewPingTimer = null;
            }
        }

        function startNoteViewPing() {
            stopNoteViewPing();
            viewPingTimer = setInterval(function () {
                if (currentViewId === null) {
                    stopNoteViewPing();
                    return;
                }
                var body = new FormData();
                body.append('view_id', currentViewId);
                body.append('csrf_token', auditCsrf);
                fetch('/api/note_view_ping.php', { method: 'POST', body: body, keepalive: true })
                    .catch(function () {});
            }, VIEW_PING_MS);
        }

        function endNoteView() {
            stopNoteViewPing();
            if (viewStartTimer !== null) {
                clearTimeout(viewStartTimer);
                viewStartTimer = null;
            }
            if (currentViewId === null) {
                return;
            }

            var body = new FormData();
            body.append('view_id', currentViewId);
            body.append('csrf_token', auditCsrf);
            currentViewId = null;

            if (navigator.sendBeacon) {
                navigator.sendBeacon('/api/note_view_end.php', body);
                return;
            }
            fetch('/api/note_view_end.php', { method: 'POST', body: body, keepalive: true })
                .catch(function () {});
        }

        function startNoteView(row) {
            if (!trackViews || !row) {
                return;
            }
            var recordId = row.getAttribute('data-record-id');
            if (!recordId) {
                return;
            }

            viewStartTimer = setTimeout(function () {
                viewStartTimer = null;

                var body = new FormData();
                body.append('record_id', recordId);
                body.append('csrf_token', auditCsrf);

                fetch('/api/note_view_start.php', { method: 'POST', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.ok && data.view_id) {
                            currentViewId = data.view_id;
                            startNoteViewPing();
                        }
                    })
                    .catch(function () {});
            }, VIEW_START_DELAY_MS);
        }

        var auditEl = document.getElementById('if-audit');
        var auditList = auditEl ? auditEl.querySelector('[data-audit-list]') : null;
        var auditEmpty = auditEl ? auditEl.querySelector('[data-audit-empty]') : null;
        var auditError = auditEl ? auditEl.querySelector('[data-audit-error]') : null;
        var auditCount = auditEl ? auditEl.querySelector('[data-audit-count]') : null;
        var auditExport = auditEl ? auditEl.querySelector('[data-audit-export]') : null;

        function syncAuditExport() {
            if (!auditExport) {
                return;
            }
            var recordId = currentDetailRow ? currentDetailRow.getAttribute('data-record-id') : null;
            if (recordId) {
                auditExport.href = '/api/note_view_export_xlsx.php?record_id=' + encodeURIComponent(recordId);
                auditExport.removeAttribute('aria-disabled');
            } else {
                auditExport.href = '#';
                auditExport.setAttribute('aria-disabled', 'true');
            }
        }

        if (auditExport) {
            auditExport.addEventListener('click', function (e) {
                if (auditExport.getAttribute('aria-disabled') === 'true') {
                    e.preventDefault();
                }
            });
        }
        var auditLoadedFor = null;

        function resetAuditPanel() {
            if (!auditEl) {
                return;
            }
            auditEl.open = false;
            auditLoadedFor = null;
            auditList.textContent = '';
            auditEmpty.hidden = true;
            auditError.hidden = true;
            auditCount.hidden = true;
            auditCount.textContent = '';
            syncAuditExport();
        }

        function renderAuditRows(views) {
            auditList.textContent = '';

            views.forEach(function (v) {
                var row = document.createElement('div');
                row.className = 'if-audit-item';

                var name = document.createElement('span');
                name.className = 'if-audit-item-name';
                name.textContent = v.user_name;
                row.appendChild(name);

                var date = document.createElement('span');
                date.className = 'if-audit-item-date';
                date.textContent = v.closed_at_display
                    ? v.opened_at_display + ' → ' + v.closed_at_display
                    : v.opened_at_display;
                row.appendChild(date);

                var dur = document.createElement('span');
                dur.className = 'if-audit-item-duration';
                if (v.duration_display === null) {
                    dur.classList.add('is-open');
                    dur.textContent = 'süre kaydedilmedi';
                } else if (v.is_open) {
                    dur.classList.add('is-open');
                    dur.textContent = 'en az ' + v.duration_display;
                } else {
                    dur.textContent = v.duration_display;
                }
                row.appendChild(dur);

                auditList.appendChild(row);
            });
        }

        if (auditEl) {
            auditEl.addEventListener('toggle', function () {
                if (!auditEl.open || !currentDetailRow) {
                    return;
                }

                syncAuditExport();

                var recordId = currentDetailRow.getAttribute('data-record-id');
                if (!recordId || auditLoadedFor === recordId) {
                    return;
                }
                auditLoadedFor = recordId;

                auditError.hidden = true;
                auditEmpty.hidden = true;

                fetch('/api/note_view_list.php?record_id=' + encodeURIComponent(recordId))
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            auditLoadedFor = null;
                            auditError.hidden = false;
                            return;
                        }
                        renderAuditRows(data.views);
                        auditEmpty.hidden = data.views.length > 0;
                        auditCount.hidden = data.views.length === 0;
                        auditCount.textContent = data.views.length;
                    })
                    .catch(function () {
                        auditLoadedFor = null;
                        auditError.hidden = false;
                    });
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                endNoteView();
            } else if (currentDetailRow) {
                startNoteView(currentDetailRow);
            }
        });
        window.addEventListener('pagehide', endNoteView);

        function selectRow(row) {
            endNoteView();

            currentDetailRow = row;
            rows.forEach(function (r) { r.classList.remove('is-selected'); });
            row.classList.add('is-selected');

            row.scrollIntoView({ block: 'nearest' });

            var fields = [];
            try {
                fields = JSON.parse(row.getAttribute('data-detail-fields') || '[]');
            } catch (e) {
                fields = [];
            }

            detailTitle.textContent = row.getAttribute('data-title') || '';
            detailLastUpdate.textContent = row.getAttribute('data-last-update') || '';

            detailFields.textContent = '';
            fields.forEach(function (f) {
                var wrap = document.createElement('div');
                wrap.className = 'if-detail-field';

                var label = document.createElement('div');
                label.className = 'if-detail-field-label';
                label.textContent = f.label;
                wrap.appendChild(label);

                var value = document.createElement('div');
                value.className = 'if-detail-field-value';
                if (f.field_type === 'attachment') {
                    value.className = 'if-detail-field-value attachment-cell-view';
                    renderAttachmentFiles(value, f.files || []);
                } else if (f.is_rich) {
                    value.innerHTML = f.value;
                } else {
                    value.textContent = f.value;
                }
                wrap.appendChild(value);

                detailFields.appendChild(wrap);
            });

            detailPlaceholder.hidden = true;
            detailContent.hidden = false;
            updateDetailNavState();

            resetAuditPanel();

            startNoteView(row);
        }

        rows.forEach(function (row) {
            row.addEventListener('click', function () {
                selectRow(row);
            });
        });


        function isTypingTarget(el) {
            if (!el) {
                return false;
            }
            if (el.isContentEditable) {
                return true;
            }
            var tag = el.tagName;
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
        }

        function modalIsOpen() {
            var dlg = document.querySelector('[aria-modal="true"]');
            return !!(dlg && dlg.getClientRects().length);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') {
                return;
            }
            if (e.ctrlKey || e.altKey || e.metaKey || e.shiftKey) {
                return;
            }
            if (isTypingTarget(e.target) || modalIsOpen()) {
                return;
            }

            var visible = getVisibleRows();
            if (!visible.length) {
                return;
            }

            e.preventDefault();

            if (!currentDetailRow || visible.indexOf(currentDetailRow) === -1) {
                selectRow(visible[0]);
                return;
            }

            navigateDetail(e.key === 'ArrowDown' ? 1 : -1);
        });

        var toolsWrap = document.getElementById('if-tools');
        var groupHeaders = [];

        function fieldById(id) {
            for (var i = 0; i < BCC_IF_FIELDS.length; i++) {
                if (BCC_IF_FIELDS[i].id === id) { return BCC_IF_FIELDS[i]; }
            }
            return null;
        }

        function makeSelect(cls, options, selected) {
            var sel = document.createElement('select');
            sel.className = cls;
            options.forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                if (String(o.value) === String(selected)) { opt.selected = true; }
                sel.appendChild(opt);
            });
            return sel;
        }

        function fieldOptions(kind) {
            var out = [];
            BCC_IF_FIELDS.forEach(function (f) {
                if (kind !== 'group' && !BCC_IF_OPERATORS[f.type]) { return; }
                out.push({ value: f.id, label: f.name });
            });
            return out;
        }

        function buildRow(panel, kind) {
            var row = document.createElement('div');
            row.className = 'if-tool-row';

            var opts = fieldOptions(kind);
            if (!opts.length) { return null; }

            var fieldSel = makeSelect('if-tool-field', opts, opts[0].value);
            row.appendChild(fieldSel);

            if (kind === 'filter') {
                var condSel = makeSelect('if-tool-cond', [], '');
                row.appendChild(condSel);
                var val = document.createElement('input');
                val.type = 'text';
                val.className = 'if-tool-value';
                val.placeholder = 'Değer';
                row.appendChild(val);

                var syncOps = function () {
                    var f = fieldById(parseInt(fieldSel.value, 10));
                    var map = (f && BCC_IF_OPERATORS[f.type]) || {};
                    condSel.textContent = '';
                    Object.keys(map).forEach(function (op) {
                        var o = document.createElement('option');
                        o.value = op;
                        o.textContent = map[op];
                        condSel.appendChild(o);
                    });
                    var noVal = condSel.value === 'empty' || condSel.value === 'not_empty';
                    val.hidden = noVal;
                };
                fieldSel.addEventListener('change', function () { syncOps(); apply(); });
                condSel.addEventListener('change', function () {
                    val.hidden = (condSel.value === 'empty' || condSel.value === 'not_empty');
                    apply();
                });
                val.addEventListener('input', debounceApply);
                syncOps();
            } else {
                var dirSel = makeSelect('if-tool-dir', [
                    { value: 'asc', label: 'A → Z' },
                    { value: 'desc', label: 'Z → A' }
                ], 'asc');
                row.appendChild(dirSel);
                fieldSel.addEventListener('change', apply);
                dirSel.addEventListener('change', apply);
            }

            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'if-tool-del';
            del.setAttribute('aria-label', 'Kaldır');
            del.textContent = '×';
            del.addEventListener('click', function () {
                row.parentNode.removeChild(row);
                apply();
            });
            row.appendChild(del);

            return row;
        }

        function positionToolPanel(details) {
            var panel = details.querySelector('.if-tool-panel');
            var btn = details.querySelector('.if-tool-btn');
            if (!panel || !btn) { return; }

            var s = window.bcc_uiScale ? window.bcc_uiScale() : 1;
            var r = btn.getBoundingClientRect();
            panel.style.top = (r.bottom / s + 4) + 'px';
            panel.style.left = (r.left / s) + 'px';

            var pr = panel.getBoundingClientRect();
            var overflowRight = (pr.right - (window.innerWidth - 8)) / s;
            if (overflowRight > 0) {
                panel.style.left = Math.max(8, r.left / s - overflowRight) + 'px';
            }
        }

        if (toolsWrap && window.BCC_IF_FIELDS) {
            Array.prototype.forEach.call(toolsWrap.querySelectorAll('.if-tool'), function (details) {
                details.addEventListener('toggle', function () {
                    if (details.open) { positionToolPanel(details); }
                });
            });
            window.addEventListener('resize', function () {
                Array.prototype.forEach.call(toolsWrap.querySelectorAll('.if-tool[open]'), positionToolPanel);
            });

            Array.prototype.forEach.call(toolsWrap.querySelectorAll('.if-tool-panel'), function (panel) {
                var kind = panel.getAttribute('data-tool-panel');
                var rows = panel.querySelector('[data-tool-rows]');
                var addBtn = panel.querySelector('[data-tool-add]');

                addBtn.addEventListener('click', function () {
                    if (rows.children.length >= (BCC_IF_MAX[kind] || 3)) { return; }
                    var row = buildRow(panel, kind);
                    if (row) { rows.appendChild(row); }
                    if (kind !== 'filter') { apply(); }
                });

                if (kind === 'filter') {
                    var logic = makeSelect('if-tool-logic', [
                        { value: 'and', label: 'Tüm koşullar (VE)' },
                        { value: 'or', label: 'Herhangi biri (VEYA)' }
                    ], 'and');
                    logic.setAttribute('data-tool-logic', '');
                    logic.addEventListener('change', apply);
                    panel.insertBefore(logic, rows);
                }
            });
        }

        function collectParams() {
            var p = new URLSearchParams();
            p.set('table_id', recordList.getAttribute('data-table-id') || '');
            if (searchInput && searchInput.value.trim() !== '') {
                p.set('q', searchInput.value.trim());
            }
            if (!toolsWrap) { return p; }

            Array.prototype.forEach.call(toolsWrap.querySelectorAll('.if-tool-panel'), function (panel) {
                var kind = panel.getAttribute('data-tool-panel');
                var slot = 0;
                Array.prototype.forEach.call(panel.querySelectorAll('.if-tool-row'), function (row) {
                    var field = row.querySelector('.if-tool-field');
                    if (!field || !field.value) { return; }
                    slot++;
                    if (kind === 'filter') {
                        var cond = row.querySelector('.if-tool-cond');
                        var val = row.querySelector('.if-tool-value');
                        if (!cond || !cond.value) { slot--; return; }
                        p.set('filter_field_' + slot, field.value);
                        p.set('filter_cond_' + slot, cond.value);
                        p.set('filter_value_' + slot, val && !val.hidden ? val.value : '');
                    } else {
                        var dir = row.querySelector('.if-tool-dir');
                        p.set(kind + '_field_' + slot, field.value);
                        p.set(kind + '_dir_' + slot, dir ? dir.value : 'asc');
                    }
                });
                if (kind === 'filter') {
                    var lg = panel.querySelector('[data-tool-logic]');
                    if (lg) { p.set('filter_logic', lg.value); }
                }
            });
            return p;
        }

        function setBadge(kind, n) {
            var el = toolsWrap && toolsWrap.querySelector('[data-tool-badge="' + kind + '"]');
            if (!el) { return; }
            el.hidden = !n;
            el.textContent = n ? String(n) : '';
        }

        function renderItems(items) {
            groupHeaders.forEach(function (h) { if (h.parentNode) { h.parentNode.removeChild(h); } });
            groupHeaders = [];

            var byId = {};
            rows.forEach(function (r) {
                byId[r.getAttribute('data-record-id')] = r;
                r.hidden = true;
            });

            var frag = document.createDocumentFragment();
            items.forEach(function (it) {
                if (it.t === 'g') {
                    var h = document.createElement('div');
                    h.className = 'if-group-header if-group-level-' + it.level;
                    var label = document.createElement('span');
                    label.className = 'if-group-label';
                    label.textContent = it.label;
                    var count = document.createElement('span');
                    count.className = 'if-group-count';
                    count.textContent = it.count;
                    h.appendChild(label);
                    h.appendChild(count);
                    groupHeaders.push(h);
                    frag.appendChild(h);
                    return;
                }
                var row = byId[String(it.id)];
                if (row) {
                    row.hidden = false;
                    frag.appendChild(row);
                }
            });

            recordList.insertBefore(frag, noResults || null);

            if (noResults) {
                noResults.hidden = items.length !== 0;
            }
            updateDetailNavState();
        }

        var applyTimer = null;
        var applyReqId = 0;

        function apply() {
            var reqId = ++applyReqId;
            fetch('/api/interface_records.php?' + collectParams().toString())
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (reqId !== applyReqId || !data || !data.ok) { return; }
                    renderItems(data.items);
                    setBadge('filter', data.counts.filters);
                    setBadge('sort', data.counts.sorts);
                    setBadge('group', data.counts.groups);
                })
                .catch(function () {});
        }

        function debounceApply() {
            if (applyTimer) { clearTimeout(applyTimer); }
            applyTimer = setTimeout(apply, 200);
        }

        if (searchInput && recordList) {
            searchInput.addEventListener('input', debounceApply);
        }

    });
})();
