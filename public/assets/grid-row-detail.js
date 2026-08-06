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
    // dışa açılmıyor, ve bu dosya grid.js YOKKEN de çalışmalı) — bkz. apiPost().

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
    // Adı "commentPost" iken artık "Kaydı gönder" (record_send.php) de AYNI
    // fonksiyonu çağırıyor — genel bir POST+JSON yardımcısı olduğu için
    // ikinci bir kopya yazılmadı, ismi buna göre güncellendi.
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = csrfMeta ? csrfMeta.content : '';

    function apiPost(url, params) {
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
        var copyLinkBtn = document.getElementById('grid-detail-copy-link');
        var commentsToggleBtn = document.getElementById('grid-detail-comments-toggle');
        var commentsPanel = document.querySelector('.grid-detail-comments');
        var printBtn = document.getElementById('grid-detail-print-btn');
        var printMetaTop = document.getElementById('grid-detail-print-meta-top');
        var printMetaBottom = document.getElementById('grid-detail-print-meta-bottom');
        var sendBtn = document.getElementById('grid-detail-send-btn');
        var duplicateBtn = document.getElementById('grid-detail-duplicate-btn');
        var deleteRecordBtn = document.getElementById('grid-detail-delete-btn');
        var sendOverlay = document.getElementById('grid-send-overlay');
        var sendHeader = document.querySelector('.grid-send-header');
        var sendCloseBtn = document.getElementById('grid-send-close');
        var sendCancelBtn = document.getElementById('grid-send-cancel');
        var sendSubmitBtn = document.getElementById('grid-send-submit');
        var sendToInput = document.getElementById('grid-send-to');
        var sendToError = document.getElementById('grid-send-to-error');
        var sendSubjectInput = document.getElementById('grid-send-subject');
        var sendMessageInput = document.getElementById('grid-send-message');
        var sendPreview = document.getElementById('grid-send-preview');
        var sendUseGridLayoutToggle = document.getElementById('grid-send-use-grid-layout');
        var sendCopySelfToggle = document.getElementById('grid-send-copy-self');
        var sendFormError = document.getElementById('grid-send-form-error');

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
            getRowFields(tr).forEach(function (field, index) {
                var row = document.createElement('div');
                // Birincil alan (index 0): mevcut tam genişlik/etiket-üstte
                // şablonda kalır, dokunulmadı. Diğer TÜM alanlar Airtable'daki
                // gibi iki sütuna (sol dar etiket / sağ değer) geçer.
                row.className = index === 0 ? 'grid-detail-field grid-detail-field-primary' : 'grid-detail-field grid-detail-field-inline';

                var label = document.createElement('label');
                label.className = 'grid-detail-field-label';
                // .field-badge / --field-icon: theme.css'te zaten var olan, tüm
                // alan tiplerini kapsayan ikon seti (grid başlığı/alan sihirbazında
                // kullanılan AYNI ikonlar) — Airtable parite isteği için ikinci
                // bir ikon seti icat edilmedi, burada yeniden kullanılıyor.
                var badge = document.createElement('span');
                badge.className = 'field-badge field-badge--' + field.field_type;
                label.appendChild(badge);
                label.appendChild(document.createTextNode(field.name));
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
                    apiPost('/api/comment_update.php', { comment_id: c.id, body: newValue, csrf_token: CSRF }).then(function (result) {
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
            apiPost('/api/comment_delete.php', { comment_id: commentId, csrf_token: CSRF }).then(function (result) {
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
                // Statik ikon (kullanıcı verisi YOK) — innerHTML burada güvenli.
                var icon = document.createElement('div');
                icon.className = 'grid-detail-comments-empty-icon';
                icon.innerHTML = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-4 4v-4H6a2 2 0 0 1-2-2V5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
                empty.appendChild(icon);
                var emptyText = document.createElement('p');
                emptyText.textContent = 'Bir konuşma başlatın';
                empty.appendChild(emptyText);
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
                apiPost('/api/comment_add.php', { record_id: recordId, body: body, csrf_token: CSRF }).then(function (result) {
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

        // Bağlantı kopyalama bildirimi: grid.js'teki showToast() ile AYNI ".ok"
        // (yeşil başarı metni, style.css) rengi kullanılıyor — ikinci bir
        // bildirim sistemi DEĞİL, o rengin modal'a uygun bir yerleşimi.
        // grid.js'teki showToast() burada KULLANILAMAZ: bu dosya (grid-row-detail.js)
        // BİLEREK window.BCC_GRID'e/grid.js'e bağımlı değil (viewer/commenter'da
        // grid.js hiç yüklenmez), ve o fonksiyon zaten modal açıkken görünmeyen
        // .gs-grid-footer'a ekleniyor. headerEl opsiyonel — gönder modalının
        // "Gönderme Adım 4'te bağlanacak" bildirimi de AYNI fonksiyonu kendi
        // header'ıyla (.grid-send-header) çağırıyor, ikinci bir toast yazılmadı.
        function showDetailToast(message, headerEl) {
            var header = headerEl || document.querySelector('.grid-detail-header');
            if (!header) {
                return;
            }
            var existing = header.querySelector('.grid-detail-toast');
            if (existing && existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }
            var toast = document.createElement('span');
            toast.className = 'ok grid-detail-toast';
            toast.textContent = message;
            header.appendChild(toast);
            setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 2500);
        }

        if (copyLinkBtn) {
            copyLinkBtn.addEventListener('click', function () {
                if (!currentDetailRow) {
                    return;
                }
                var recordId = currentDetailRow.getAttribute('data-record-id');
                var url = new URL(window.location.href);
                url.searchParams.set('record_id', recordId);
                var text = url.toString();

                function done() {
                    showDetailToast('Kayıt bağlantısı kopyalandı');
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(function () {
                        legacyCopy(text, done);
                    });
                } else {
                    legacyCopy(text, done);
                }
            });
        }

        // navigator.clipboard bazı bağlamlarda (ör. http, eski tarayıcı)
        // hiç yoktur/reddeder — eski execCommand yöntemine sessizce düşülür.
        function legacyCopy(text, onDone) {
            var input = document.createElement('textarea');
            input.value = text;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.focus();
            input.select();
            try {
                document.execCommand('copy');
            } catch (e) {}
            document.body.removeChild(input);
            onDone();
        }

        if (commentsToggleBtn && commentsPanel) {
            commentsToggleBtn.addEventListener('click', function () {
                commentsPanel.classList.toggle('is-collapsed');
            });
        }

        // "Kaydı yazdır" — bir alanın widget'ından (input/select/textarea/
        // checkbox/dosya eki yöneticisi/salt-okunur .cell-view) o anki
        // GÖRÜNEN değeri düz metne çevirir. Print CSS'in (style.css @media
        // print) widget'ları gizleyip yerine gösterdiği .grid-detail-print-value
        // span'ı BUNUNLA doldurulur — textarea/select gibi kendi scroll/dropdown
        // alanı olan elemanlar print'te boş/kırpılmış çıkabildiği için, gerçek
        // metni DOM'dan okuyup ayrı bir düz-metin elemanına taşımak, tarayıcı
        // print motoruna güvenmekten daha güvenilir.
        function fieldPrintText(valueWrap) {
            var checkbox = valueWrap.querySelector('input[type="checkbox"]');
            if (checkbox) {
                return checkbox.checked ? 'Evet' : 'Hayır';
            }
            var attachmentChips = valueWrap.querySelectorAll('.attachment-chip');
            if (attachmentChips.length) {
                return Array.prototype.map.call(attachmentChips, function (chip) {
                    return chip.title || chip.textContent.trim();
                }).join(', ');
            }
            if (valueWrap.querySelector('.attachment-manager')) {
                return '—';
            }
            var select = valueWrap.querySelector('select');
            if (select) {
                var selected = Array.prototype.filter.call(select.options, function (opt) {
                    return opt.selected;
                });
                return selected.length ? selected.map(function (opt) { return opt.textContent; }).join(', ') : '—';
            }
            var textarea = valueWrap.querySelector('textarea');
            if (textarea) {
                return textarea.value || '—';
            }
            var input = valueWrap.querySelector('input');
            if (input) {
                return input.value || '—';
            }
            var cellView = valueWrap.querySelector('.cell-view');
            if (cellView) {
                return cellView.textContent.trim() || '—';
            }
            return valueWrap.textContent.trim() || '—';
        }

        function preparePrintView() {
            Array.prototype.forEach.call(fieldsContainer.querySelectorAll('.grid-detail-field'), function (row) {
                var valueWrap = row.querySelector('.grid-detail-field-value');
                if (!valueWrap) {
                    return;
                }
                var printSpan = valueWrap.querySelector('.grid-detail-print-value');
                if (!printSpan) {
                    printSpan = document.createElement('span');
                    printSpan.className = 'grid-detail-print-value';
                    valueWrap.appendChild(printSpan);
                }
                printSpan.textContent = fieldPrintText(valueWrap);
            });

            if (printMetaTop) {
                var baseNameEl = document.querySelector('.gs-base-name');
                var baseName = baseNameEl ? baseNameEl.textContent.trim() : '';
                var today = new Date();
                var dd = String(today.getDate()).padStart(2, '0');
                var mm = String(today.getMonth() + 1).padStart(2, '0');
                var dateStr = dd + '.' + mm + '.' + today.getFullYear();
                printMetaTop.textContent = baseName ? (baseName + ' — ' + dateStr) : dateStr;
            }

            if (printMetaBottom && currentDetailRow) {
                var recordId = currentDetailRow.getAttribute('data-record-id');
                var url = new URL(window.location.href);
                url.searchParams.set('record_id', recordId);
                printMetaBottom.textContent = url.toString();
            }
        }

        if (printBtn) {
            printBtn.addEventListener('click', function () {
                if (moreMenu) {
                    moreMenu.removeAttribute('open');
                }
                preparePrintView();
                window.print();
            });
        }

        // "Kaydı gönder" modalı (Airtable "Send record" paritesi) — alan
        // önizlemesi YAZDIRDAKİ fieldPrintText()'i AYNEN çağırır (KOPYALAMA
        // yok, fonksiyon zaten print'e özel bir şey içermiyordu). Bu tek
        // döngü hem EKRANDAKİ önizlemeyi (renderSendPreview, ikonlu DOM
        // clone'u) hem "Gönder"de backend'e giden payload'ı (submit handler,
        // düz {label,value} — sunucu bunu yeniden ÇIKARMAZ, yalnızca
        // escape'leyip biçimlendirir) besler, ikinci bir alan-okuma kodu YOK.
        function collectFieldPreviewData() {
            var items = [];
            Array.prototype.forEach.call(fieldsContainer.querySelectorAll('.grid-detail-field'), function (row) {
                var labelEl = row.querySelector('.grid-detail-field-label');
                var valueWrap = row.querySelector('.grid-detail-field-value');
                if (!labelEl || !valueWrap) {
                    return;
                }
                items.push({
                    labelEl: labelEl,
                    labelText: labelEl.textContent.trim(),
                    value: fieldPrintText(valueWrap),
                });
            });
            return items;
        }

        function renderSendPreview() {
            if (!sendPreview) {
                return;
            }
            sendPreview.textContent = '';
            collectFieldPreviewData().forEach(function (item) {
                var field = document.createElement('div');
                field.className = 'grid-send-preview-field';

                var label = document.createElement('div');
                label.className = 'grid-send-preview-label';
                // Etiket (ikon+metin) yazdırdaki gibi yeniden ÜRETİLMEZ,
                // mevcut .grid-detail-field-label DOM'u cloneNode ile taşınır.
                label.appendChild(item.labelEl.cloneNode(true));
                field.appendChild(label);

                var value = document.createElement('div');
                value.className = 'grid-send-preview-value';
                value.textContent = item.value;
                field.appendChild(value);

                sendPreview.appendChild(field);
            });
        }

        function validateSendRecipients() {
            if (!sendToInput) {
                return true;
            }
            var count = sendToInput.value.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; }).length;
            var overLimit = count > 15;
            if (sendToError) {
                sendToError.hidden = !overLimit;
            }
            if (sendSubmitBtn) {
                sendSubmitBtn.disabled = overLimit;
            }
            return !overLimit;
        }

        function openSendModal() {
            if (!sendOverlay || !currentDetailRow) {
                return;
            }
            var userName = window.BCC_CURRENT_USER_NAME || '';
            var tableName = window.BCC_TABLE_NAME || '';
            if (sendToInput) {
                sendToInput.value = '';
            }
            if (sendToError) {
                sendToError.hidden = true;
            }
            if (sendSubjectInput) {
                sendSubjectInput.value = userName + " '" + tableName + "' tablosundan 1 kayıt paylaştı";
            }
            if (sendMessageInput) {
                sendMessageInput.value = "İşte '" + tableName + "' tablosundan bu kaydın son hali:";
            }
            if (sendUseGridLayoutToggle) {
                sendUseGridLayoutToggle.checked = false;
            }
            if (sendCopySelfToggle) {
                sendCopySelfToggle.checked = false;
            }
            if (sendSubmitBtn) {
                sendSubmitBtn.disabled = false;
            }
            if (sendFormError) {
                sendFormError.hidden = true;
            }
            renderSendPreview();
            sendOverlay.hidden = false;
        }

        function closeSendModal() {
            if (sendOverlay) {
                sendOverlay.hidden = true;
            }
        }

        if (sendBtn) {
            sendBtn.addEventListener('click', function () {
                if (moreMenu) {
                    moreMenu.removeAttribute('open');
                }
                openSendModal();
            });
        }

        // "Kaydı çoğalt" — record_duplicate.php'ye POST. Rol kontrolü BACKEND'de
        // zorunlu (require_role('editor')), burada yalnızca UX (buton zaten
        // $canEdit değilse DOM'da yok). Dönen row_html record_add.php'nin
        // addRecord()'daki AYNI ekleme deseniyle orijinalin hemen altına konur
        // (window.BCC_GRID.renumberRows/window.BCC_reapplyFreeze zaten dışa
        // açık, ikinci bir kopyası yazılmadı), sonra openDetail() ile modal
        // KOPYAYA geçirilir (orijinal değil — kullanıcı hangisinin kopya
        // olduğunu görsün).
        if (duplicateBtn) {
            duplicateBtn.addEventListener('click', function () {
                if (moreMenu) {
                    moreMenu.removeAttribute('open');
                }
                if (!currentDetailRow) {
                    return;
                }
                var recordId = currentDetailRow.getAttribute('data-record-id');
                apiPost('/api/record_duplicate.php', {
                    csrf_token: CSRF,
                    record_id: recordId,
                    state_query_string: window.location.search.replace(/^\?/, ''),
                }).then(function (result) {
                    var ok = result.httpOk && result.data && result.data.ok;
                    if (!ok) {
                        var message = (result.data && result.data.error) ? result.data.error : 'Kayıt çoğaltılamadı.';
                        window.alert(message);
                        return;
                    }

                    var temp = document.createElement('tbody');
                    temp.innerHTML = result.data.row_html;
                    var newRow = temp.querySelector('tr[data-record-id]');
                    if (!newRow || !currentDetailRow.parentNode) {
                        return;
                    }

                    currentDetailRow.insertAdjacentElement('afterend', newRow);

                    if (window.BCC_GRID && window.BCC_GRID.renumberRows) {
                        window.BCC_GRID.renumberRows();
                    }
                    if (window.BCC_reapplyFreeze) {
                        window.BCC_reapplyFreeze();
                    }

                    openDetail(newRow);
                });
            });
        }

        // "Kaydı sil" — Adım 3b: SADECE soft-delete işaretleme. Grid/filtre/
        // arama sorgularının silinmiş kayıtları gizlemesi Adım 3c'nin işi —
        // bu adımdan sonra kayıt hâlâ grid'de görünebilir, bu BEKLENEN.
        // Onay diyaloğu: projede ÖZEL bir modal deseni YOK, her yerde
        // (record_delete/home.js/grid-view-manage.js/team-members.js) native
        // window.confirm() kullanılıyor — burada da AYNI, yeni bir modal
        // icat edilmedi. "Çöp kutusundan geri yükleyebilirsiniz" ifadesi
        // home.js'teki base silme onayıyla BİREBİR AYNI ton.
        if (deleteRecordBtn) {
            deleteRecordBtn.addEventListener('click', function () {
                if (moreMenu) {
                    moreMenu.removeAttribute('open');
                }
                if (!currentDetailRow) {
                    return;
                }
                var title = primaryFieldTitle(currentDetailRow) || '(başlıksız kayıt)';
                var confirmMsg = "'" + title + "' kaydını silmek istediğinizden emin misiniz? Çöp kutusundan geri yükleyebilirsiniz.";
                if (!window.confirm(confirmMsg)) {
                    return;
                }

                var recordId = currentDetailRow.getAttribute('data-record-id');
                apiPost('/api/record_soft_delete.php', {
                    csrf_token: CSRF,
                    record_id: recordId,
                }).then(function (result) {
                    var ok = result.httpOk && result.data && result.data.ok;
                    if (!ok) {
                        var message = (result.data && result.data.error) ? result.data.error : 'Kayıt silinemedi.';
                        window.alert(message);
                        return;
                    }

                    // Toast'ın (henüz açık olan) modal header'ında görünmesi
                    // için kapatma kısa bir gecikmeyle yapılır — showDetailToast
                    // AYNEN yeniden kullanılıyor, yeni bir bildirim yolu yok.
                    showDetailToast('Kayıt silindi');
                    setTimeout(closeDetail, 700);
                });
            });
        }

        if (sendCloseBtn) {
            sendCloseBtn.addEventListener('click', closeSendModal);
        }
        if (sendCancelBtn) {
            sendCancelBtn.addEventListener('click', closeSendModal);
        }
        if (sendToInput) {
            sendToInput.addEventListener('input', validateSendRecipients);
        }
        // "Kaydı gönder" — record_send.php'ye POST. Rol/alıcı-domain/15-limit
        // kontrolü BACKEND'de (frontend'deki validateSendRecipients() sadece
        // UX için, tek gerçek karar sunucuda). Alan önizlemesi zaten ekranda
        // görüneni (collectFieldPreviewData()) JSON olarak taşır — backend
        // BUNU YENİDEN ÇIKARMAZ, yalnızca escape'leyip biçimlendirir.
        function showSendFormError(message) {
            if (!sendFormError) {
                return;
            }
            sendFormError.textContent = message;
            sendFormError.hidden = false;
        }

        if (sendSubmitBtn) {
            sendSubmitBtn.addEventListener('click', function () {
                if (!validateSendRecipients() || !currentDetailRow) {
                    return;
                }
                if (sendFormError) {
                    sendFormError.hidden = true;
                }
                var recordId = currentDetailRow.getAttribute('data-record-id');
                var previewFields = collectFieldPreviewData().map(function (item) {
                    return { label: item.labelText, value: item.value };
                });

                sendSubmitBtn.disabled = true;
                apiPost('/api/record_send.php', {
                    csrf_token: CSRF,
                    record_id: recordId,
                    recipients: sendToInput ? sendToInput.value : '',
                    subject: sendSubjectInput ? sendSubjectInput.value : '',
                    message: sendMessageInput ? sendMessageInput.value : '',
                    use_grid_layout: sendUseGridLayoutToggle && sendUseGridLayoutToggle.checked ? '1' : '0',
                    send_copy_to_self: sendCopySelfToggle && sendCopySelfToggle.checked ? '1' : '0',
                    preview_fields: JSON.stringify(previewFields),
                }).then(function (result) {
                    sendSubmitBtn.disabled = false;
                    var ok = result.httpOk && result.data && result.data.ok;
                    if (ok) {
                        closeSendModal();
                        showDetailToast('Kayıt gönderildi', document.querySelector('.grid-detail-header'));
                        return;
                    }
                    var message = (result.data && result.data.error) ? result.data.error : 'Gönderilemedi.';
                    showSendFormError(message);
                });
            });
        }

        // Backdrop'a (modal içeriğine değil) tıklayınca / Escape ile kapanma —
        // assets/dismissable-panel.js, isOpen/close .hidden'a göre override edilir.
        window.bcc_bindDismissable(overlay, {
            isOpen: function () { return !overlay.hidden; },
            close: closeDetail,
            isClickOutside: function (target) { return target === overlay; },
        });

        // "..." (Diğer seçenekler) menüsü: native <details> — varsayılan
        // isOpen/close/isClickOutside (el.hasAttribute('open') vb.) native
        // <details> için zaten doğru, hiçbir option geçmeye gerek yok. Menü
        // kalemleri bu adımda BİLEREK no-op (işlev sonraki adımda bağlanacak).
        var moreMenu = document.getElementById('grid-detail-more-menu');
        if (moreMenu) {
            window.bcc_bindDismissable(moreMenu);

            // ESC katmanlaması: bcc_bindDismissable her çağrıda document'a
            // BAĞIMSIZ bir keydown dinleyicisi ekliyor (dismissable-panel.js) —
            // overlay'in kendi ESC dinleyicisi menü açık mı diye bakmadan her
            // zaman kapanıyordu, tek ESC ikisini birden kapatırdı. Helper 5
            // BAŞKA yerde de kullanıldığı için (account-menu/grid-table-data/
            // grid-view-manage/home.js) global DEĞİŞTİRİLMEDİ — yalnızca bu
            // menüye özel, capture fazında (bubble fazındaki overlay/menü
            // dinleyicilerinden HER ZAMAN önce çalışır, kayıt sırasından
            // bağımsız) bir dinleyici: menü açıksa ESC'i kendisi kapatıp
            // stopPropagation ile olayın overlay'e ulaşmasını engelliyor; menü
            // kapalıysa hiçbir şey yapmaz, olay normal akıp modalı kapatır.
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && moreMenu.hasAttribute('open')) {
                    e.stopPropagation();
                    moreMenu.removeAttribute('open');
                }
            }, true);
        }

        // "Kaydı gönder" modalı — kayıt detay overlay'inin ÜSTÜNDE ayrı bir
        // overlay (z-index, style.css). Backdrop-tık için AYNI kanıtlanmış
        // desen (isClickOutside: target===overlay). ESC katmanlaması "..."
        // menüsüyle BİREBİR AYNI mantık: gönder modalı açıkken ESC SADECE onu
        // kapatmalı, alttaki kayıt modalını DEĞİL — capture fazında önce
        // çalışan özel bir dinleyici, açıksa kendisi kapatıp stopPropagation
        // ile olayın overlay'in bubble-fazlı ESC dinleyicisine ulaşmasını
        // engelliyor; kapalıyken hiçbir şey yapmaz.
        if (sendOverlay) {
            window.bcc_bindDismissable(sendOverlay, {
                isOpen: function () { return !sendOverlay.hidden; },
                close: closeSendModal,
                isClickOutside: function (target) { return target === sendOverlay; },
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !sendOverlay.hidden) {
                    e.stopPropagation();
                    closeSendModal();
                }
            }, true);
        }
    });
})();
