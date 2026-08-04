(function () {
    'use strict';

    // Satır seçimi (checkbox) + satır genişletme paneli. Panel artık TÜM takım
    // rollerine açık (Airtable paritesi: kayıt görüntüleme herkese açık, yorum
    // commenter+, hücre düzenleme editor+) — bu yüzden window.BCC_GRID (grid.js)
    // varlığına SERTÇE bağımlı değil: grid.js yalnızca editor/owner'da yüklenir
    // (bkz. grid.php script sırası), viewer/commenter'da hiç yoktur. Düzenleme
    // yolları (commitFieldValue, buildEditableFieldWidget) window.BCC_CAN_EDIT
    // true olduğunda (dolayısıyla window.BCC_GRID de var olduğunda) çalışır;
    // aksi hâlde buildReadOnlyFieldWidget kullanılır (yeniden yazma YOK, canlı
    // <td>'nin zaten sunucuda render edilmiş içeriği kopyalanır).
    //
    // Görünür alanlar: gerçek <td data-field-id> DOM'da var — widget ondan
    // (data-value/data-options) kurulur, kaydedince applyCellResultToTd o
    // td'yi de günceller (gridle panel her zaman senkron kalır).
    // Gizli alanlar: <td> yok — satırın data-fields JSON'undaki
    // {id, name, field_type, options, raw} kullanılır, kaydetme doğrudan
    // postCellValue(recordId, fieldId, value) ile (senkronlanacak <td> yok).
    //
    // Yorumlar (comment_list/add/update/delete.php): window.BCC_GRID'den TAMAMEN
    // bağımsız, kendi küçük fetch sarmalayıcısını kullanır (grid.js'deki post()
    // dışa açılmıyor, ve bu dosya grid.js YOKKEN de çalışmalı) — bkz. commentPost().

    function getRowFields(tr) {
        var raw = tr.getAttribute('data-fields');
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

    function findLiveTd(tr, fieldId) {
        return tr.querySelector('td.grid-cell[data-field-id="' + fieldId + '"]');
    }

    // grid.js'deki post()'un küçük bir kopyası — BİLEREK: window.BCC_GRID'i
    // (dolayısıyla grid.js'i) hiç yüklemeyen viewer/commenter'da da yorum
    // gönderebilmek için bu dosyanın grid.js'e bağımlı OLMAMASI gerekiyor.
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = csrfMeta ? csrfMeta.content : '';

    function commentPost(url, params) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString(),
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
            }).then(function (data) {
                return { httpOk: res.ok, data: data };
            });
        });
    }

    // long_text'in ham değeri (raw) sunucuda zaten temizlenmiş (whitelist)
    // HTML — burada yalnızca DÜZ METNİ okumak için DOM'a hiç eklenmeyen bir
    // <div>'e yazılıp .textContent okunuyor (script YÜRÜTÜLMEZ, hiçbir zaman
    // sayfaya eklenmedi); panelde tam zengin metin araç çubuğu YOK, "input/
    // textarea" isteğine uygun düz bir <textarea>.
    function htmlToPlainText(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return tmp.textContent || '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('table.grid');
        var overlay = document.getElementById('grid-detail-overlay');
        // window.BCC_GRID artık ZORUNLU değil (bkz. dosya başındaki not) —
        // yalnızca editor/owner'da yüklenir, viewer/commenter'da panel yine
        // açılır ama salt-okunur render'a düşer.
        if (!grid || !overlay) {
            return;
        }

        var canEditFields = window.BCC_CAN_EDIT === true;
        var canComment = window.BCC_CAN_COMMENT === true;

        var fieldsContainer = document.getElementById('grid-detail-fields');
        var titleEl = document.getElementById('grid-detail-title');
        var closeBtn = document.getElementById('grid-detail-close');
        var prevBtn = document.getElementById('grid-detail-prev');
        var nextBtn = document.getElementById('grid-detail-next');
        var selectAll = document.getElementById('grid-rownum-selectall');
        var deleteSelectedBtn = document.getElementById('gs-delete-selected-btn');
        var deleteSelectedCountEl = document.getElementById('gs-delete-selected-count');
        var commentsList = document.getElementById('grid-detail-comments-list');
        var commentsForm = document.getElementById('grid-detail-comments-form');
        var commentsInput = document.getElementById('grid-detail-comments-input');

        var currentDetailRow = null;

        function getAllDataRows() {
            return Array.prototype.slice.call(grid.querySelectorAll('tbody tr[data-record-id]'));
        }

        function commitFieldValue(tr, field, liveTd, value) {
            var recordId = tr.getAttribute('data-record-id');

            window.BCC_GRID.postCellValue(recordId, field.id, value).then(function (result) {
                var ok = result.httpOk && result.data && result.data.ok;

                if (!ok) {
                    var message = (result.data && result.data.error) ? result.data.error : 'Kaydedilemedi.';
                    window.alert(message);
                    return;
                }

                if (liveTd) {
                    window.BCC_GRID.applyCellResultToTd(liveTd, result.data);
                }

                // Birincil alan (panel başlığı) düzenlendiyse başlık da güncellensin.
                var fields = getRowFields(tr);
                if (tr === currentDetailRow && fields.length && fields[0].id === field.id) {
                    updateDetailTitle(tr);
                }
            });
        }

        // Salt-okunur (viewer/commenter): canlı <td> varsa (görünür alan) sunucuda
        // zaten doğru biçimde render edilmiş içeriği (chip/checkbox/rich-text/ek
        // dosyası) AYNEN kopyalar — ikinci bir render fonksiyonu YAZILMAZ. Canlı
        // <td> yoksa (gizli alan, panel TÜM alanları gösterir) düz metne düşülür;
        // bu nadir bir durumdur (görünümde gizlenmiş bir alanı genişletme
        // panelinde okumak), chip/ek dosyası biçimlendirmesi olmadan kabul edildi.
        function buildReadOnlyFieldWidget(tr, field) {
            var liveTd = findLiveTd(tr, field.id);
            var wrap = document.createElement('div');
            wrap.className = 'grid-detail-field-value grid-detail-field-value-readonly';

            if (liveTd) {
                wrap.innerHTML = liveTd.innerHTML;
                var liveCheckbox = wrap.querySelector('.cell-checkbox');
                if (liveCheckbox) {
                    liveCheckbox.disabled = true;
                }
                return wrap;
            }

            if (field.field_type === 'checkbox') {
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.disabled = true;
                cb.checked = field.raw === '1';
                wrap.appendChild(cb);
                return wrap;
            }

            var span = document.createElement('span');
            span.className = 'cell-view';
            if (field.field_type === 'attachment') {
                var names = (field.files || []).map(function (f) { return f.name; });
                span.textContent = names.length ? names.join(', ') : '—';
            } else {
                span.textContent = field.raw || '—';
            }
            wrap.appendChild(span);
            return wrap;
        }

        function buildFieldWidget(tr, field) {
            if (!canEditFields) {
                return buildReadOnlyFieldWidget(tr, field);
            }

            var liveTd = findLiveTd(tr, field.id);
            var wrap = document.createElement('div');
            wrap.className = 'grid-detail-field-value';

            if (field.field_type === 'checkbox') {
                var rawChecked = liveTd ? liveTd.getAttribute('data-value') : field.raw;
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = rawChecked === '1';
                cb.addEventListener('change', function () {
                    commitFieldValue(tr, field, liveTd, cb.checked ? '1' : '0');
                });
                wrap.appendChild(cb);
                return wrap;
            }

            if (field.field_type === 'long_text') {
                var rawHtml = liveTd ? liveTd.getAttribute('data-value') : field.raw;
                var ta = document.createElement('textarea');
                ta.className = 'cell-input grid-detail-textarea';
                var initialPlainText = htmlToPlainText(rawHtml || '');
                ta.value = initialPlainText;
                // Bulunan gerçek bug: panelde zengin metin (kalın/link) araç çubuğu
                // YOK, bu yüzden düzenleme alanı yalnızca DÜZ METİN gösteriyor —
                // dokunulmadan panel kapatılsa bile blur, bu düz metni sunucuya
                // geri gönderip mevcut biçimlendirmeyi sessizce siliyordu. Artık
                // yalnızca kullanıcı gerçekten metni değiştirdiyse kaydediliyor.
                ta.addEventListener('blur', function () {
                    if (ta.value === initialPlainText) {
                        return;
                    }
                    commitFieldValue(tr, field, liveTd, ta.value);
                });
                wrap.appendChild(ta);
                return wrap;
            }

            // Dosya eki: cell_update.php'ye hiç gitmez (attachment_upload/delete.php
            // üzerinden kendi AJAX'ı) — liste/yükle/sil arayüzü grid.js'nin
            // window.BCC_GRID.buildAttachmentManager() ile PAYLAŞILIR, ikinci bir
            // kopya yazılmaz. Görünür alan ise canlı <td>'nin data-attachments'ı da
            // (grid'deki hücreyle senkron kalsın diye) güncellenir.
            if (field.field_type === 'attachment') {
                var recordId = tr.getAttribute('data-record-id');
                var initialFiles = liveTd
                    ? (function () {
                        try {
                            return JSON.parse(liveTd.getAttribute('data-attachments') || '[]');
                        } catch (e) {
                            return [];
                        }
                    })()
                    : (field.files || []);

                var manager = window.BCC_GRID.buildAttachmentManager(recordId, field.id, initialFiles, function (files) {
                    if (liveTd) {
                        liveTd.setAttribute('data-attachments', JSON.stringify(files));
                        var liveView = liveTd.querySelector('.cell-view');
                        if (liveView) {
                            window.BCC_GRID.renderAttachmentChips(liveView, files);
                        }
                    }
                });
                wrap.appendChild(manager);
                return wrap;
            }

            var choices = liveTd ? window.BCC_GRID.getChoices(liveTd) : (field.options || []);
            var raw = liveTd ? liveTd.getAttribute('data-value') : field.raw;
            var input = window.BCC_GRID.buildInput(field.field_type, choices, raw || '');

            var commit = function () {
                var value;
                if (field.field_type === 'multiple_select') {
                    var selected = [];
                    for (var i = 0; i < input.options.length; i++) {
                        if (input.options[i].selected) {
                            selected.push(input.options[i].value);
                        }
                    }
                    value = JSON.stringify(selected);
                } else {
                    value = input.value;
                }
                commitFieldValue(tr, field, liveTd, value);
            };

            input.addEventListener('blur', commit);
            if (input.tagName === 'SELECT' && !input.multiple) {
                input.addEventListener('change', commit);
            }

            wrap.appendChild(input);
            return wrap;
        }

        function primaryFieldTitle(tr) {
            var fields = getRowFields(tr);
            if (!fields.length) {
                return '';
            }
            var primary = fields[0];
            var liveTd = findLiveTd(tr, primary.id);
            if (liveTd) {
                var view = liveTd.querySelector('.cell-view');
                if (view) {
                    return view.textContent;
                }
            }
            return primary.raw || '(başlıksız kayıt)';
        }

        function updateDetailTitle(tr) {
            titleEl.textContent = primaryFieldTitle(tr);
        }

        function renderDetailFields(tr) {
            fieldsContainer.textContent = '';
            getRowFields(tr).forEach(function (field) {
                var row = document.createElement('div');
                row.className = 'grid-detail-field';

                var label = document.createElement('label');
                label.className = 'grid-detail-field-label';
                label.textContent = field.name;
                row.appendChild(label);

                row.appendChild(buildFieldWidget(tr, field));
                fieldsContainer.appendChild(row);
            });
        }

        function updateNavState() {
            var rows = getAllDataRows();
            var idx = rows.indexOf(currentDetailRow);
            if (prevBtn) {
                prevBtn.disabled = idx <= 0;
            }
            if (nextBtn) {
                nextBtn.disabled = idx === -1 || idx >= rows.length - 1;
            }
        }

        // --- Yorumlar (comment_list/add/update/delete.php) ----------------------
        // Airtable paritesi: görüntüleme herkese açık (bkz. grid.php $canComment
        // yalnızca FORM/hint'i belirler, listeyi DEĞİL), ekleme/kendi yorumunu
        // düzenleme-silme yalnızca commenter+ — sunucu zaten require_role('commenter')
        // + sahiplik kontrolü ile aynı kuralı uyguluyor, burası yalnızca UI.

        function formatCommentDate(mysqlDatetime) {
            return mysqlDatetime ? mysqlDatetime.substr(0, 16).replace('T', ' ') : '';
        }

        // Avatar: .ws-collab-avatar (home.css) yeniden kullanılır — grid.php zaten
        // home.css'i yüklüyor (collab-popover-avatar de aynı sınıfı paylaşıyor),
        // ikinci bir avatar bileşeni YAZILMAZ. İlk harf, bcc_user_initial()
        // (src/auth.php) ile AYNI mantık: ad'ın ilk karakteri, büyük harf.
        function buildCommentItem(c) {
            var item = document.createElement('div');
            item.className = 'grid-detail-comment';
            item.setAttribute('data-comment-id', c.id);

            var authorName = c.author_name || 'Silinmiş kullanıcı';

            var avatar = document.createElement('div');
            avatar.className = 'ws-collab-avatar grid-detail-comment-avatar';
            avatar.textContent = authorName.charAt(0).toUpperCase();
            item.appendChild(avatar);

            var main = document.createElement('div');
            main.className = 'grid-detail-comment-main';

            var meta = document.createElement('div');
            meta.className = 'grid-detail-comment-meta';
            var author = document.createElement('span');
            author.className = 'grid-detail-comment-author';
            author.textContent = authorName;
            meta.appendChild(author);
            var date = document.createElement('span');
            date.className = 'grid-detail-comment-date';
            date.textContent = formatCommentDate(c.created_at);
            meta.appendChild(date);
            main.appendChild(meta);

            var body = document.createElement('div');
            body.className = 'grid-detail-comment-body';
            body.textContent = c.body;
            main.appendChild(body);

            if (c.is_own) {
                var actions = document.createElement('div');
                actions.className = 'grid-detail-comment-actions';

                var editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'grid-detail-comment-edit';
                editBtn.textContent = 'Düzenle';
                editBtn.addEventListener('click', function () {
                    startEditComment(body, c);
                });
                actions.appendChild(editBtn);

                var delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'grid-detail-comment-delete';
                delBtn.textContent = 'Sil';
                delBtn.addEventListener('click', function () {
                    deleteComment(c.id, item);
                });
                actions.appendChild(delBtn);

                main.appendChild(actions);
            }

            item.appendChild(main);

            return item;
        }

        function startEditComment(bodyEl, c) {
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'cell-input grid-detail-comment-edit-input';
            input.maxLength = 4000;
            input.value = c.body;
            bodyEl.replaceWith(input);
            input.focus();

            var done = false;
            function finish(save) {
                if (done) {
                    return;
                }
                done = true;

                var newValue = input.value.trim();
                if (save && newValue !== '' && newValue !== c.body) {
                    commentPost('/api/comment_update.php', { comment_id: c.id, body: newValue, csrf_token: CSRF }).then(function (result) {
                        var ok = result.httpOk && result.data && result.data.ok;
                        if (!ok) {
                            window.alert((result.data && result.data.error) ? result.data.error : 'Kaydedilemedi.');
                            input.replaceWith(bodyEl);
                            return;
                        }
                        c.body = result.data.comment.body;
                        bodyEl.textContent = c.body;
                        input.replaceWith(bodyEl);
                    });
                } else {
                    input.replaceWith(bodyEl);
                }
            }

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    finish(true);
                } else if (e.key === 'Escape') {
                    finish(false);
                }
            });
            input.addEventListener('blur', function () {
                finish(true);
            });
        }

        function deleteComment(commentId, item) {
            if (!window.confirm('Bu yorumu silmek istediğinize emin misiniz?')) {
                return;
            }
            commentPost('/api/comment_delete.php', { comment_id: commentId, csrf_token: CSRF }).then(function (result) {
                var ok = result.httpOk && result.data && result.data.ok;
                if (!ok) {
                    window.alert((result.data && result.data.error) ? result.data.error : 'Silinemedi.');
                    return;
                }
                item.remove();
                if (commentsList && commentsList.children.length === 0) {
                    renderComments([]);
                }
            });
        }

        function renderComments(comments) {
            if (!commentsList) {
                return;
            }
            commentsList.textContent = '';

            if (!comments.length) {
                var empty = document.createElement('div');
                empty.className = 'grid-detail-comments-empty';
                empty.textContent = 'Bir konuşma başlatın';
                commentsList.appendChild(empty);
                return;
            }

            comments.forEach(function (c) {
                commentsList.appendChild(buildCommentItem(c));
            });
        }

        function loadComments(recordId) {
            if (!commentsList) {
                return;
            }
            commentsList.textContent = '';
            fetch('/api/comment_list.php?record_id=' + encodeURIComponent(recordId))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        renderComments(data.comments);
                    }
                })
                .catch(function () {});
        }

        if (commentsForm && commentsInput) {
            commentsForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var body = commentsInput.value.trim();
                if (body === '' || !currentDetailRow) {
                    return;
                }
                var recordId = currentDetailRow.getAttribute('data-record-id');
                commentPost('/api/comment_add.php', { record_id: recordId, body: body, csrf_token: CSRF }).then(function (result) {
                    var ok = result.httpOk && result.data && result.data.ok;
                    if (!ok) {
                        window.alert((result.data && result.data.error) ? result.data.error : 'Gönderilemedi.');
                        return;
                    }
                    commentsInput.value = '';
                    var emptyEl = commentsList.querySelector('.grid-detail-comments-empty');
                    if (emptyEl) {
                        emptyEl.remove();
                    }
                    commentsList.appendChild(buildCommentItem(result.data.comment));
                });
            });
        }

        function openDetail(tr) {
            currentDetailRow = tr;
            updateDetailTitle(tr);
            renderDetailFields(tr);
            updateNavState();
            loadComments(tr.getAttribute('data-record-id'));
            overlay.hidden = false;
        }

        function closeDetail() {
            overlay.hidden = true;
            currentDetailRow = null;
            fieldsContainer.textContent = '';
            if (commentsList) {
                commentsList.textContent = '';
            }
        }

        function navigate(delta) {
            if (!currentDetailRow) {
                return;
            }
            var rows = getAllDataRows();
            var idx = rows.indexOf(currentDetailRow);
            var next = rows[idx + delta];
            if (next) {
                openDetail(next);
            }
        }

        function getSelectedRows() {
            return getAllDataRows().filter(function (tr) {
                var cb = tr.querySelector('.grid-row-select');
                return cb && cb.checked;
            });
        }

        // Ölü delete_record handler'ının yerini alan toplu silme — checkbox
        // seçimi zaten vardı (yalnızca .is-row-selected görsel vurgusu yapıyordu),
        // burada bir "Seçilenleri sil" butonuna bağlanıyor. Buton grid.php'de
        // yalnızca $canEdit iken render edilir (bkz. gs-delete-selected-btn).
        function updateDeleteButtonState() {
            if (!deleteSelectedBtn) {
                return;
            }
            var count = getSelectedRows().length;
            deleteSelectedBtn.hidden = count === 0;
            if (deleteSelectedCountEl) {
                deleteSelectedCountEl.textContent = String(count);
            }
        }

        function updateSelectAllState() {
            if (!selectAll) {
                return;
            }
            var rows = getAllDataRows();
            var checkedCount = rows.filter(function (tr) {
                var cb = tr.querySelector('.grid-row-select');
                return cb && cb.checked;
            }).length;
            selectAll.checked = rows.length > 0 && checkedCount === rows.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < rows.length;
        }

        grid.addEventListener('click', function (e) {
            var expandBtn = e.target.closest('.grid-row-expand');
            if (!expandBtn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var tr = expandBtn.closest('tr[data-record-id]');
            if (tr) {
                openDetail(tr);
            }
        });

        grid.addEventListener('change', function (e) {
            if (!e.target.matches('.grid-row-select')) {
                return;
            }
            var tr = e.target.closest('tr[data-record-id]');
            if (tr) {
                tr.classList.toggle('is-row-selected', e.target.checked);
            }
            updateSelectAllState();
            updateDeleteButtonState();
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checked = selectAll.checked;
                getAllDataRows().forEach(function (tr) {
                    var cb = tr.querySelector('.grid-row-select');
                    if (cb) {
                        cb.checked = checked;
                    }
                    tr.classList.toggle('is-row-selected', checked);
                });
                selectAll.indeterminate = false;
                updateDeleteButtonState();
            });
        }

        if (deleteSelectedBtn) {
            deleteSelectedBtn.addEventListener('click', function () {
                var selectedRows = getSelectedRows();
                if (!selectedRows.length) {
                    return;
                }

                var confirmMsg = selectedRows.length === 1
                    ? 'Seçili 1 kaydı silmek istediğinize emin misiniz?'
                    : 'Seçili ' + selectedRows.length + ' kaydı silmek istediğinize emin misiniz?';
                if (!window.confirm(confirmMsg)) {
                    return;
                }

                var tableId = deleteSelectedBtn.getAttribute('data-table-id');
                var body = new URLSearchParams();
                body.append('csrf_token', CSRF);
                body.append('table_id', tableId);
                selectedRows.forEach(function (tr) {
                    body.append('record_ids[]', tr.getAttribute('data-record-id'));
                });

                deleteSelectedBtn.disabled = true;

                fetch('/api/record_delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                    });
                }).then(function (data) {
                    deleteSelectedBtn.disabled = false;

                    if (!data || !data.ok) {
                        window.alert((data && data.error) || 'Kayıtlar silinemedi.');
                        return;
                    }

                    var deletedIds = (data.deleted_record_ids || []).map(String);
                    selectedRows.forEach(function (tr) {
                        if (deletedIds.indexOf(tr.getAttribute('data-record-id')) !== -1 && tr.parentNode) {
                            tr.parentNode.removeChild(tr);
                        }
                    });

                    // Tam sayfa reload YOK — record_add.php'nin AJAX satır-ekleme
                    // deseniyle simetrik. Satır numaraları + "X kayıt" sayacı
                    // grid.js'nin zaten sahip olduğu renumberRows() ile güncellenir
                    // (window.BCC_GRID üzerinden, ikinci bir sayaç mantığı YAZILMAZ).
                    if (window.BCC_GRID && window.BCC_GRID.renumberRows) {
                        window.BCC_GRID.renumberRows();
                    }

                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    }
                    updateDeleteButtonState();
                }).catch(function () {
                    deleteSelectedBtn.disabled = false;
                    window.alert('Kayıtlar silinemedi (bağlantı hatası).');
                });
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', closeDetail);
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function () { navigate(-1); });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () { navigate(1); });
        }

        // Backdrop'a (modal içeriğine değil) tıklayınca / Escape ile kapanma —
        // assets/dismissable-panel.js, isOpen/close .hidden'a göre override edilir.
        window.bcc_bindDismissable(overlay, {
            isOpen: function () { return !overlay.hidden; },
            close: closeDetail,
            isClickOutside: function (target) { return target === overlay; },
        });
    });
})();
