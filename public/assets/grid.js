(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = meta ? meta.content : '';

    var post = window.bcc_post;

    function postFile(url, formData) {
        return fetch(url, {
            method: 'POST',
            body: formData,
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
            }).then(function (data) {
                return { httpOk: res.ok, data: data };
            });
        });
    }

    function renderChips(view, chips) {
        view.textContent = '';
        chips.forEach(function (chip) {
            var span = document.createElement('span');
            span.className = 'choice-chip';
            span.style.background = chip.color;
            span.textContent = chip.text;
            view.appendChild(span);
        });
    }

    var SVG_NS = 'http://www.w3.org/2000/svg';

    function svgChild(tag, attrs) {
        var el = document.createElementNS(SVG_NS, tag);
        Object.keys(attrs).forEach(function (k) {
            el.setAttribute(k, attrs[k]);
        });
        return el;
    }

    function buildExternalLinkIcon() {
        var svg = svgChild('svg', {
            width: '13', height: '13', viewBox: '0 0 24 24', fill: 'none',
            stroke: 'currentColor', 'stroke-width': '2',
            'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true',
        });
        svg.appendChild(svgChild('path', { d: 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6' }));
        svg.appendChild(svgChild('polyline', { points: '15 3 21 3 21 9' }));
        svg.appendChild(svgChild('line', { x1: '10', y1: '14', x2: '21', y2: '3' }));
        return svg;
    }

    function renderLinkifiedCell(view, displayText, link) {
        view.textContent = '';
        view.classList.toggle('cell-view-linkified', !!link);

        if (!link) {
            view.textContent = displayText;
            return;
        }

        var text = document.createElement('span');
        text.className = 'cell-link-text';
        text.textContent = link.text;
        view.appendChild(text);

        var a = document.createElement('a');
        a.className = 'cell-link-icon';
        a.href = link.href;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.title = 'Yeni sekmede aç';
        a.setAttribute('aria-label', 'Yeni sekmede aç');
        a.appendChild(buildExternalLinkIcon());
        view.appendChild(a);
    }

    function renderAttachmentChips(view, files) {
        view.textContent = '';
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

            view.appendChild(a);
        });
    }

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

    function uploadAttachment(recordId, fieldId, file) {
        var formData = new FormData();
        formData.append('csrf_token', CSRF);
        formData.append('record_id', recordId);
        formData.append('field_id', fieldId);
        formData.append('file', file);

        return postFile('/api/attachment_upload.php', formData);
    }

    function deleteAttachment(attachmentId) {
        return post('/api/attachment_delete.php', {
            csrf_token: CSRF,
            attachment_id: attachmentId,
        });
    }

    function flash(td, ok) {
        td.classList.remove('cell-flash-ok', 'cell-flash-error');
        void td.offsetWidth;
        td.classList.add(ok ? 'cell-flash-ok' : 'cell-flash-error');
        setTimeout(function () {
            td.classList.remove('cell-flash-ok', 'cell-flash-error');
        }, 700);
    }

    function postCellValue(recordId, fieldId, value) {
        return post('/api/cell_update.php', {
            csrf_token: CSRF,
            record_id: recordId,
            field_id: fieldId,
            value: value,
        });
    }

    function updateRatingStars(view, value) {
        Array.prototype.forEach.call(view.querySelectorAll('.rating-star'), function (star) {
            var idx = parseInt(star.getAttribute('data-rating-star'), 10);
            star.classList.toggle('rating-star-filled', idx <= value);
        });
    }

    function renderUserCell(view, display) {
        view.textContent = '';
        view.classList.toggle('cell-user-view', display !== '');

        if (display === '') {
            return;
        }

        var avatar = document.createElement('span');
        avatar.className = 'ws-collab-avatar cell-user-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.textContent = display.charAt(0).toLocaleUpperCase('tr');

        var name = document.createElement('span');
        name.className = 'cell-user-name';
        name.textContent = display;

        view.appendChild(avatar);
        view.appendChild(name);
    }

    function applyCellResultToTd(td, data) {
        td.setAttribute('data-value', data.raw);
        var view = td.querySelector('.cell-view');
        if (view) {
            if (data.display_chips) {
                renderChips(view, data.display_chips);
            } else if (Object.prototype.hasOwnProperty.call(data, 'display_link')) {
                renderLinkifiedCell(view, data.display, data.display_link);
            } else if (td.getAttribute('data-field-type') === 'long_text') {
                view.innerHTML = data.display;
            } else if (td.getAttribute('data-field-type') === 'rating') {
                updateRatingStars(view, parseInt(data.raw, 10) || 0);
            } else if (td.getAttribute('data-field-type') === 'user') {
                renderUserCell(view, data.display);
            } else {
                view.textContent = data.display;
            }
        }
    }

    function saveCell(td, value) {
        var tr = td.closest('tr');
        var recordId = tr ? tr.getAttribute('data-record-id') : '';
        var fieldId = td.getAttribute('data-field-id');

        return postCellValue(recordId, fieldId, value).then(function (result) {
            var okResult = result.httpOk && result.data && result.data.ok;

            if (okResult) {
                applyCellResultToTd(td, result.data);
                flash(td, true);
            } else {
                flash(td, false);
                var message = (result.data && result.data.error) ? result.data.error : 'Kaydedilemedi.';
                window.alert(message);
            }

            return okResult;
        });
    }

    var addingRecord = false;

    function renumberRows() {
        var rows = document.querySelectorAll('table.grid tbody tr[data-record-id]');
        rows.forEach(function (tr, idx) {
            var numberEl = tr.querySelector('.grid-rownum-number');
            if (numberEl) {
                numberEl.textContent = idx + 1;
            }
        });

        var countEl = document.getElementById('grid-row-count');
        if (countEl) {
            countEl.textContent = rows.length + ' kayıt';
        }
    }

    function showToast(message) {
        var footer = document.querySelector('.gs-grid-footer');
        if (!footer) {
            return;
        }

        var existing = footer.querySelector('.grid-add-toast');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        var toast = document.createElement('p');
        toast.className = 'ok grid-add-toast';
        toast.textContent = message;
        footer.appendChild(toast);

        setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 4000);
    }

    function addRecord(afterRecordId, targetRow, count) {
        if (addingRecord) {
            return;
        }
        addingRecord = true;
        count = parseInt(count, 10);
        if (!count || count < 1) {
            count = 1;
        }

        var tableId = new URLSearchParams(window.location.search).get('table_id') || '';
        var params = {
            csrf_token: CSRF,
            table_id: tableId,
            state_query_string: window.location.search.replace(/^\?/, ''),
            count: count,
        };

        if (!window.BCC_SORT_OR_GROUP_ACTIVE && afterRecordId) {
            params.after_record_id = afterRecordId;
        }

        post('/api/record_add.php', params).then(function (result) {
            addingRecord = false;
            var bulkBtn2 = document.querySelector('[data-grid-add-bulk-btn]');
            if (bulkBtn2) {
                bulkBtn2.disabled = false;
            }

            if (!(result.httpOk && result.data && result.data.ok)) {
                var message = (result.data && result.data.error) ? result.data.error : 'Kayıt eklenemedi.';
                window.alert(message);
                return;
            }

            var rowsHtml = (result.data.rows_html && result.data.rows_html.length)
                ? result.data.rows_html
                : (result.data.row_html ? [result.data.row_html] : []);
            var temp = document.createElement('tbody');
            temp.innerHTML = rowsHtml.join('');
            var newRows = Array.prototype.slice.call(temp.querySelectorAll('tr[data-record-id]'));
            if (!newRows.length) {
                return;
            }
            var newRow = newRows[0];

            var emptyCell = document.querySelector('table.grid tbody td.grid-empty');
            if (emptyCell && emptyCell.parentNode && emptyCell.parentNode.parentNode) {
                emptyCell.parentNode.parentNode.removeChild(emptyCell.parentNode);
            }

            var frag = document.createDocumentFragment();
            for (var r = 0; r < newRows.length; r++) {
                frag.appendChild(newRows[r]);
            }

            if (targetRow && targetRow.parentNode) {
                targetRow.parentNode.insertBefore(frag, targetRow.nextSibling);
            } else {
                var addRowEl = document.querySelector('[data-grid-add-row]');
                if (addRowEl && addRowEl.parentNode) {
                    addRowEl.parentNode.insertBefore(frag, addRowEl);
                } else {
                    var tbody = document.querySelector('table.grid tbody');
                    if (tbody) {
                        tbody.appendChild(frag);
                    }
                }
            }

            renumberRows();

            if (window.BCC_reapplyFreeze) {
                window.BCC_reapplyFreeze();
            }

            if (newRows.length === 1) {
                var firstCell = newRow.querySelector('td.editable');
                if (firstCell) {
                    startEdit(firstCell);
                }
            } else {
                showToast(newRows.length + ' satır eklendi.');
                newRow.scrollIntoView({ block: 'nearest' });
            }

            if (window.BCC_SORT_OR_GROUP_ACTIVE || window.BCC_FILTER_ACTIVE) {
                showToast('Kayıt eklendi. Aktif filtre/sıralama/gruplama nedeniyle konumu sayfa yenilenince değişebilir.');
            }
        }).catch(function () {
            addingRecord = false;
            var bulkBtn2 = document.querySelector('[data-grid-add-bulk-btn]');
            if (bulkBtn2) {
                bulkBtn2.disabled = false;
            }
            window.alert('Kayıt eklenemedi (bağlantı hatası).');
        });
    }

    function getChoices(td) {
        var raw = td.getAttribute('data-options');
        if (!raw) {
            return [];
        }
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function addOption(select, value, label) {
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = label;
        select.appendChild(opt);
        return opt;
    }

    function buildInput(type, choices, raw) {
        var input;
        choices = choices || [];

        if (type === 'number' || type === 'currency' || type === 'percent') {
            input = document.createElement('input');
            input.type = 'number';
            input.step = 'any';
            input.value = raw;
        } else if (type === 'url' || type === 'email' || type === 'phone') {
            input = document.createElement('input');
            input.type = (type === 'phone') ? 'tel' : type;
            input.value = raw;
        } else if (type === 'date') {
            input = document.createElement('input');
            input.type = 'date';
            input.value = raw;
        } else if (type === 'time') {
            input = document.createElement('input');
            input.type = 'time';
            input.value = raw;
        } else if (type === 'single_select') {
            input = document.createElement('select');
            addOption(input, '', '— boş —');
            choices.forEach(function (c) {
                addOption(input, c, c);
            });
            input.value = raw;
        } else if (type === 'user') {
            input = document.createElement('select');
            addOption(input, '', '— boş —');
            choices.forEach(function (c) {
                addOption(input, c.id, c.name);
            });
            input.value = raw;
        } else if (type === 'multiple_select') {
            input = document.createElement('select');
            input.multiple = true;
            input.size = Math.min(6, Math.max(3, choices.length));
            var selected = [];
            try {
                selected = JSON.parse(raw || '[]');
            } catch (e) {
                selected = [];
            }
            choices.forEach(function (c) {
                var opt = addOption(input, c, c);
                if (selected.indexOf(c) !== -1) {
                    opt.selected = true;
                }
            });
        } else {
            input = document.createElement('input');
            input.type = 'text';
            input.value = raw;
        }

        input.className = 'cell-input';

        return input;
    }

    function startEdit(td) {
        if (td.classList.contains('editing')) {
            return;
        }

        var type = td.getAttribute('data-field-type');
        if (type === 'checkbox') {
            return;
        }

        var view = td.querySelector('.cell-view');
        var raw = td.getAttribute('data-value') || '';
        var input = buildInput(type, getChoices(td), raw);
        var done = false;

        td.classList.add('editing');
        if (view) {
            view.style.display = 'none';
        }
        td.appendChild(input);
        input.focus();
        if (input.select) {
            input.select();
        }

        function endEdit() {
            td.classList.remove('editing');
            if (input.parentNode === td) {
                td.removeChild(input);
            }
            if (view) {
                view.style.display = '';
            }
        }

        function commit() {
            if (done) {
                return;
            }
            done = true;

            var value;
            if (type === 'multiple_select') {
                var selectedOptions = [];
                for (var i = 0; i < input.options.length; i++) {
                    if (input.options[i].selected) {
                        selectedOptions.push(input.options[i].value);
                    }
                }
                value = JSON.stringify(selectedOptions);
            } else {
                value = input.value;
            }

            endEdit();
            saveCell(td, value);
        }

        function cancel() {
            if (done) {
                return;
            }
            done = true;
            endEdit();
        }

        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                commit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancel();
            }
        });
    }

    function startRichTextEdit(td) {
        if (td.classList.contains('editing')) {
            return;
        }

        var view = td.querySelector('.cell-view');
        var raw = td.getAttribute('data-value') || '';

        td.classList.add('editing', 'richtext-editing');

        var popover = document.createElement('div');
        popover.className = 'richtext-popover';

        var toolbar = document.createElement('div');
        toolbar.className = 'richtext-toolbar';

        var editable = document.createElement('div');
        editable.className = 'richtext-editable';
        editable.contentEditable = 'true';
        editable.innerHTML = raw;

        function makeToolbarButton(label, title, onClick, isIcon) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'richtext-toolbar-btn';
            if (isIcon) {
                btn.innerHTML = label;
            } else {
                btn.textContent = label;
            }
            btn.title = title;
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });
            btn.addEventListener('click', onClick);
            return btn;
        }

        var boldBtn = makeToolbarButton('B', 'Kalın', function () {
            document.execCommand('bold', false, null);
            editable.focus();
        });
        boldBtn.style.fontWeight = 'bold';

        var italicBtn = makeToolbarButton('i', 'İtalik', function () {
            document.execCommand('italic', false, null);
            editable.focus();
        });
        italicBtn.style.fontStyle = 'italic';

        var linkIconSvg = '<svg width="13" height="13" viewBox="0 0 20 20" fill="none">'
            + '<path d="M8.5 11.5a3 3 0 004.24 0l2.5-2.5a3 3 0 10-4.24-4.24l-1 1" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
            + '<path d="M11.5 8.5a3 3 0 00-4.24 0l-2.5 2.5a3 3 0 104.24 4.24l1-1" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
            + '</svg>';

        var linkBtn = makeToolbarButton(linkIconSvg, 'Link ekle', function () {
            if (linkBar.hidden) {
                openLinkBar();
            } else {
                cancelLinkBar();
            }
        }, true);

        toolbar.appendChild(boldBtn);
        toolbar.appendChild(italicBtn);
        toolbar.appendChild(linkBtn);

        var linkBar = document.createElement('div');
        linkBar.className = 'richtext-link-bar';
        linkBar.hidden = true;

        var linkRow = document.createElement('div');
        linkRow.className = 'richtext-link-row';

        var linkInput = document.createElement('input');
        linkInput.type = 'url';
        linkInput.className = 'richtext-link-input';
        linkInput.placeholder = 'https://';
        linkInput.setAttribute('aria-label', 'Link URL');

        var linkAddBtn = document.createElement('button');
        linkAddBtn.type = 'button';
        linkAddBtn.className = 'gs-btn-primary richtext-link-add';
        linkAddBtn.textContent = 'Ekle';

        var linkCancelBtn = document.createElement('button');
        linkCancelBtn.type = 'button';
        linkCancelBtn.className = 'richtext-link-cancel';
        linkCancelBtn.textContent = '×';
        linkCancelBtn.title = 'İptal';
        linkCancelBtn.setAttribute('aria-label', 'İptal');

        var linkError = document.createElement('p');
        linkError.className = 'richtext-link-error';
        linkError.hidden = true;

        linkRow.appendChild(linkInput);
        linkRow.appendChild(linkAddBtn);
        linkRow.appendChild(linkCancelBtn);
        linkBar.appendChild(linkRow);
        linkBar.appendChild(linkError);

        var savedRange = null;
        var editingAnchor = null;

        function editableSelectionRange() {
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) {
                return null;
            }
            var range = sel.getRangeAt(0);

            return editable.contains(range.commonAncestorContainer) ? range : null;
        }

        function selectRange(range) {
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }

        function anchorAtRange(range) {
            var node = range ? range.commonAncestorContainer : null;
            if (node && node.nodeType === 3) {
                node = node.parentNode;
            }
            var anchor = (node && node.closest) ? node.closest('a') : null;

            return (anchor && editable.contains(anchor)) ? anchor : null;
        }

        function openLinkBar() {
            savedRange = editableSelectionRange();
            editingAnchor = anchorAtRange(savedRange);
            linkError.hidden = true;
            linkInput.value = editingAnchor ? editingAnchor.getAttribute('href') : '';
            linkAddBtn.textContent = editingAnchor ? 'Kaydet' : 'Ekle';
            linkBar.hidden = false;
            positionPopover();
            linkInput.focus();
            linkInput.select();
        }

        function closeLinkBar() {
            linkBar.hidden = true;
            linkError.hidden = true;
            linkInput.value = '';
            editingAnchor = null;
            savedRange = null;
            positionPopover();
            editable.focus();
        }

        function cancelLinkBar() {
            var range = savedRange;
            closeLinkBar();
            if (range) {
                selectRange(range);
            }
        }

        function applyLink() {
            var url = linkInput.value.trim();

            if (!/^https?:\/\//i.test(url)) {
                linkError.textContent = 'Link https:// veya http:// ile başlamalı.';
                linkError.hidden = false;
                linkInput.focus();
                return;
            }

            if (editingAnchor) {
                editingAnchor.setAttribute('href', url);
                closeLinkBar();
                return;
            }

            editable.focus();
            if (savedRange) {
                selectRange(savedRange);
            }

            var sel = window.getSelection();
            if (!editableSelectionRange()) {
                var endRange = document.createRange();
                endRange.selectNodeContents(editable);
                endRange.collapse(false);
                selectRange(endRange);
            }

            if (sel.isCollapsed) {
                var anchor = document.createElement('a');
                anchor.href = url;
                anchor.textContent = url;
                sel.getRangeAt(0).insertNode(anchor);

                var afterRange = document.createRange();
                afterRange.setStartAfter(anchor);
                afterRange.collapse(true);
                selectRange(afterRange);
            } else {
                document.execCommand('createLink', false, url);
            }

            closeLinkBar();
        }

        [linkAddBtn, linkCancelBtn].forEach(function (btn) {
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });
        });
        linkAddBtn.addEventListener('click', applyLink);
        linkCancelBtn.addEventListener('click', cancelLinkBar);

        linkInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyLink();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                cancelLinkBar();
            }
        });

        var actions = document.createElement('div');
        actions.className = 'richtext-actions';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn-sm';
        cancelBtn.textContent = 'İptal';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'gs-btn-primary';
        saveBtn.textContent = 'Kaydet';
        actions.appendChild(cancelBtn);
        actions.appendChild(saveBtn);

        popover.appendChild(toolbar);
        popover.appendChild(linkBar);
        popover.appendChild(editable);
        popover.appendChild(actions);

        if (view) {
            view.style.display = 'none';
        }
        td.appendChild(popover);

        function positionPopover() {
            window.bcc_positionFloating(popover, td.getBoundingClientRect());
        }
        window.bcc_raiseFloatingHost(popover, true);
        positionPopover();

        window.addEventListener('scroll', positionPopover, true);
        window.addEventListener('resize', positionPopover);

        editable.focus();

        var done = false;

        function endEdit() {
            td.classList.remove('editing', 'richtext-editing');
            window.bcc_raiseFloatingHost(popover, false);
            if (popover.parentNode === td) {
                td.removeChild(popover);
            }
            if (view) {
                view.style.display = '';
            }
            window.removeEventListener('scroll', positionPopover, true);
            window.removeEventListener('resize', positionPopover);
            document.removeEventListener('mousedown', outsideClickHandler, true);
        }

        function cancel() {
            if (done) {
                return;
            }
            done = true;
            endEdit();
        }

        function commit() {
            if (done) {
                return;
            }
            done = true;
            var value = editable.innerHTML;
            endEdit();
            saveCell(td, value);
        }

        cancelBtn.addEventListener('click', cancel);
        saveBtn.addEventListener('click', commit);

        editable.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                cancel();
            }
        });

        function outsideClickHandler(e) {
            if (!popover.contains(e.target)) {
                cancel();
            }
        }
        setTimeout(function () {
            document.addEventListener('mousedown', outsideClickHandler, true);
        }, 0);
    }

    function buildAttachmentManager(recordId, fieldId, initialFiles, onChange) {
        var files = (initialFiles || []).slice();
        var container = document.createElement('div');
        container.className = 'attachment-manager';

        var list = document.createElement('div');
        list.className = 'attachment-popover-list';

        function renderList() {
            list.textContent = '';
            files.forEach(function (file) {
                var row = document.createElement('div');
                row.className = 'attachment-popover-row';

                var isImage = file.mime.indexOf('image/') === 0;
                var link = document.createElement('a');
                link.className = 'attachment-chip';
                link.href = '/api/attachment_download.php?id=' + file.id;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.title = file.name;

                if (isImage) {
                    var img = document.createElement('img');
                    img.className = 'attachment-thumb';
                    img.src = '/api/attachment_download.php?id=' + file.id;
                    img.alt = '';
                    link.appendChild(img);
                } else {
                    var badge = document.createElement('span');
                    badge.className = 'attachment-badge';
                    badge.textContent = fileTypeBadge(file.mime);
                    var name = document.createElement('span');
                    name.className = 'attachment-name';
                    name.textContent = file.name;
                    link.appendChild(badge);
                    link.appendChild(name);
                }
                row.appendChild(link);

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'attachment-remove-btn';
                removeBtn.textContent = '×';
                removeBtn.title = 'Sil';
                removeBtn.addEventListener('click', function () {
                    removeBtn.disabled = true;
                    deleteAttachment(file.id).then(function (result) {
                        if (result.httpOk && result.data && result.data.ok) {
                            files = files.filter(function (f) { return f.id !== file.id; });
                            renderList();
                            onChange(files);
                        } else {
                            removeBtn.disabled = false;
                            window.alert((result.data && result.data.error) || 'Silinemedi.');
                        }
                    });
                });
                row.appendChild(removeBtn);

                list.appendChild(row);
            });
        }

        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = '.png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx';
        fileInput.className = 'attachment-file-input';
        fileInput.hidden = true;

        function handleFileSelected(file) {
            if (!file) {
                return;
            }
            fileInput.disabled = true;
            uploadAttachment(recordId, fieldId, file).then(function (result) {
                fileInput.disabled = false;
                fileInput.value = '';
                if (result.httpOk && result.data && result.data.ok) {
                    files.push(result.data.file);
                    renderList();
                    onChange(files);
                } else {
                    window.alert((result.data && result.data.error) || 'Yüklenemedi.');
                }
            });
        }

        fileInput.addEventListener('change', function () {
            handleFileSelected(fileInput.files[0]);
        });

        var dropzone = document.createElement('label');
        dropzone.className = 'attachment-dropzone';
        var dropzoneText = document.createElement('span');
        dropzoneText.className = 'attachment-dropzone-text';
        dropzoneText.textContent = 'Dosya seçmek için tıklayın veya buraya sürükleyin';
        dropzone.appendChild(dropzoneText);
        dropzone.appendChild(fileInput);

        dropzone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropzone.classList.add('is-dragover');
        });
        dropzone.addEventListener('dragleave', function () {
            dropzone.classList.remove('is-dragover');
        });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropzone.classList.remove('is-dragover');
            var dropped = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files[0] : null;
            handleFileSelected(dropped);
        });

        container.appendChild(list);
        container.appendChild(dropzone);
        renderList();

        return container;
    }

    function startAttachmentEdit(td) {
        if (td.classList.contains('editing')) {
            return;
        }

        var tr = td.closest('tr');
        var recordId = tr ? tr.getAttribute('data-record-id') : '';
        var fieldId = td.getAttribute('data-field-id');
        var view = td.querySelector('.cell-view');

        var initialFiles = [];
        try {
            initialFiles = JSON.parse(td.getAttribute('data-attachments') || '[]');
        } catch (e) {
            initialFiles = [];
        }

        td.classList.add('editing', 'attachment-editing');

        var manager = buildAttachmentManager(recordId, fieldId, initialFiles, function (files) {
            td.setAttribute('data-attachments', JSON.stringify(files));
            if (view) {
                renderAttachmentChips(view, files);
            }
        });

        var popover = document.createElement('div');
        popover.className = 'attachment-popover';
        popover.appendChild(manager);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-sm attachment-popover-close';
        closeBtn.textContent = 'Kapat';
        popover.appendChild(closeBtn);

        if (view) {
            view.style.display = 'none';
        }
        td.appendChild(popover);

        function positionPopover() {
            window.bcc_positionFloating(popover, td.getBoundingClientRect());
        }
        window.bcc_raiseFloatingHost(popover, true);
        positionPopover();
        window.addEventListener('scroll', positionPopover, true);
        window.addEventListener('resize', positionPopover);

        var done = false;

        function endEdit() {
            td.classList.remove('editing', 'attachment-editing');
            window.bcc_raiseFloatingHost(popover, false);
            if (popover.parentNode === td) {
                td.removeChild(popover);
            }
            if (view) {
                view.style.display = '';
            }
            window.removeEventListener('scroll', positionPopover, true);
            window.removeEventListener('resize', positionPopover);
            document.removeEventListener('mousedown', outsideClickHandler, true);
        }

        function close() {
            if (done) {
                return;
            }
            done = true;
            endEdit();
        }

        closeBtn.addEventListener('click', close);
        popover.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
            }
        });

        function outsideClickHandler(e) {
            if (!popover.contains(e.target)) {
                close();
            }
        }
        setTimeout(function () {
            document.addEventListener('mousedown', outsideClickHandler, true);
        }, 0);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.grid');
        if (!grid) {
            return;
        }

        grid.addEventListener('click', function (e) {
            if (e.target.matches('input[type="checkbox"].cell-checkbox')) {
                return;
            }
            var star = e.target.closest('.rating-star');
            if (star) {
                var ratingView = star.closest('.rating-view-editable');
                if (!ratingView) {
                    return;
                }
                var ratingTd = ratingView.closest('td');
                var clickedValue = parseInt(star.getAttribute('data-rating-star'), 10);
                var currentValue = parseInt(ratingTd.getAttribute('data-value'), 10) || 0;
                var nextValue = (clickedValue === currentValue) ? 0 : clickedValue;
                saveCell(ratingTd, String(nextValue));
                return;
            }
            if (e.target.closest('.cell-link-icon')) {
                e.stopPropagation();
                return;
            }
            var richLink = e.target.closest('.rich-text-view a');
            if (richLink) {
                richLink.target = '_blank';
                richLink.rel = 'noopener noreferrer';
                e.stopPropagation();
                return;
            }
            var td = e.target.closest('td.editable');
            if (!td) {
                return;
            }
            var fieldType = td.getAttribute('data-field-type');
            if (fieldType === 'long_text') {
                startRichTextEdit(td);
            } else if (fieldType === 'attachment') {
                startAttachmentEdit(td);
            } else if (fieldType === 'rating') {
                return;
            } else {
                startEdit(td);
            }
        });

        grid.addEventListener('mouseover', function (e) {
            var star = e.target.closest('.rating-star');
            if (!star) {
                return;
            }
            var ratingView = star.closest('.rating-view-editable');
            if (!ratingView) {
                return;
            }
            var hoverValue = parseInt(star.getAttribute('data-rating-star'), 10);
            updateRatingStars(ratingView, hoverValue);
        });
        grid.addEventListener('mouseout', function (e) {
            var star = e.target.closest('.rating-star');
            if (!star) {
                return;
            }
            var ratingView = star.closest('.rating-view-editable');
            if (!ratingView) {
                return;
            }
            var ratingTd = ratingView.closest('td');
            var actualValue = parseInt(ratingTd.getAttribute('data-value'), 10) || 0;
            updateRatingStars(ratingView, actualValue);
        });

        grid.addEventListener('change', function (e) {
            if (!e.target.matches('input[type="checkbox"].cell-checkbox')) {
                return;
            }
            var checkbox = e.target;
            var td = checkbox.closest('td');
            var checked = checkbox.checked;

            saveCell(td, checked ? '1' : '0').then(function (ok) {
                if (!ok) {
                    checkbox.checked = !checked;
                }
            });
        });

        var addRow = document.querySelector('[data-grid-add-row]');
        if (addRow) {
            addRow.addEventListener('click', function (e) {
                if (e.target && e.target.closest && e.target.closest('[data-grid-add-bulk]')) {
                    return;
                }
                addRecord(null, null, 1);
            });
        }

        var bulkWrap = document.querySelector('[data-grid-add-bulk]');
        if (bulkWrap) {
            var bulkInput = bulkWrap.querySelector('[data-grid-add-bulk-count]');
            var bulkBtn = bulkWrap.querySelector('[data-grid-add-bulk-btn]');

            var runBulk = function () {
                var n = parseInt(bulkInput ? bulkInput.value : '', 10);
                if (!n || n < 1) {
                    n = 1;
                }
                if (n > 500) {
                    n = 500;
                    if (bulkInput) {
                        bulkInput.value = '500';
                    }
                }

                if (bulkBtn) {
                    bulkBtn.disabled = true;
                }
                addRecord(null, null, n);
            };

            if (bulkBtn) {
                bulkBtn.addEventListener('click', runBulk);
            }
            if (bulkInput) {
                bulkInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.stopPropagation();
                        runBulk();
                    }
                });
            }
        }

        document.addEventListener('keydown', function (e) {
            if (!e.shiftKey || e.key !== 'Enter') {
                return;
            }

            var targetTag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
            if (targetTag === 'textarea') {
                return;
            }
            if (e.target && e.target.classList && e.target.classList.contains('richtext-editable')) {
                return;
            }

            var row = e.target.closest ? e.target.closest('tr[data-record-id]') : null;
            if (!row) {
                return;
            }

            e.preventDefault();
            addRecord(row.getAttribute('data-record-id'), row);
        });

        var saveViewBtn = document.getElementById('gs-view-save-state-btn');
        if (saveViewBtn) {
            saveViewBtn.addEventListener('click', function () {
                var viewId = window.BCC_VIEW_ID || '';
                post('/api/view_save_state.php', {
                    csrf_token: CSRF,
                    view_id: viewId,
                    state_query_string: window.location.search.replace(/^\?/, ''),
                }).then(function (result) {
                    if (result.httpOk && result.data && result.data.ok) {
                        var copy = window.BCC_GRID_COPY
                            ? window.BCC_GRID_COPY.copyWholeTable()
                            : null;

                        if (copy && copy.ok) {
                            showToast('Görünüm kaydedildi · tablo panoya kopyalandı ('
                                + copy.rows + ' satır). Ctrl+V ile yapıştırabilirsiniz.');
                        } else if (copy && copy.empty) {
                            showToast('Görünüm kaydedildi. (Kopyalanacak satır yok.)');
                        } else {
                            showToast('Görünüm kaydedildi. (Tablo panoya kopyalanamadı.)');
                        }
                    } else {
                        var message = (result.data && result.data.error) ? result.data.error : 'Görünüm kaydedilemedi.';
                        window.alert(message);
                    }
                }).catch(function () {
                    window.alert('Görünüm kaydedilemedi (bağlantı hatası).');
                });
            });
        }
    });

    window.BCC_GRID = {
        postCellValue: postCellValue,
        applyCellResultToTd: applyCellResultToTd,
        buildInput: buildInput,
        getChoices: getChoices,
        uploadAttachment: uploadAttachment,
        deleteAttachment: deleteAttachment,
        renderAttachmentChips: renderAttachmentChips,
        buildAttachmentManager: buildAttachmentManager,
        renumberRows: renumberRows,
        showToast: showToast,
    };
})();
