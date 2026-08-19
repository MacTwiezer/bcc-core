(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var CSRF = meta ? meta.content : '';

    function post(url, params) {
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

    // post()'un multipart/form-data hâli — yalnızca dosya yükleme için (attachment_
    // upload.php). URLSearchParams değil FormData kullanır, Content-Type header'ı
    // BİLEREK elle set edilmez (tarayıcı boundary'yi kendisi ekler).
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

    // Color: tekli/çoklu seçim hücreleri düz metin değil renkli "chip" olarak
    // görüntülenir. Kullanıcı verisi (chip.text) yalnızca textContent ile
    // yazılır, innerHTML string birleştirme YOK — sunucudan gelen zaten
    // htmlspecialchars'lı metnin DOM'daki düz hâli güvenle enjekte edilir.
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

    // Grup A (url/email/phone): "yeni sekmede aç" ikonu. Sunucudaki
    // bcc_external_link_icon_svg() ile AYNI çizim — innerHTML KULLANILMAZ,
    // createElementNS ile gerçek SVG düğümleri kurulur (bu turda güvenlik
    // gözden geçirmesi yapıldığı için hiçbir yerde string->HTML yolu bırakılmadı).
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

    // Grup A hücresini yeniden çizer. $link null ise (değer artık
    // linkleştirilemiyor) ikon SİLİNİR — sunucu bu yüzden anahtarı null olarak
    // da gönderiyor, hiç göndermeseydi eski ikon ekranda asılı kalırdı.
    //
    // GÜVENLİK: link.href sunucudaki whitelist'ten (bcc_cell_link_href +
    // BCC_CELL_LINK_SCHEMES) geçmiş olarak gelir — "javascript:..." bu noktaya
    // ASLA ulaşamaz, sunucu o değeri linkleştirilemez sayıp null döndürür.
    // Metin her zaman textContent ile yazılır, innerHTML YOK.
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

    // Ek dosya listesini (küçük resim ya da rozet+ad "chip"leri, hepsi kendi
    // dosyasına indirme linki) çizer — sunucu tarafındaki bcc_render_grid_data_row()
    // ile AYNI DOM yapısı (aynı sınıf adları, style.css/grid-shell.css'teki
    // .attachment-* kuralları ikisinde de geçerli olsun diye). Kullanıcı verisi
    // (dosya adı) yalnızca title/textContent ile yazılır, innerHTML YOK.
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

    // bcc_attachment_type_badge() (src/schema.php) ile AYNI harita — yalnızca
    // görüntü DIŞI dosya tiplerinde kullanılır (resimler zaten küçük resim olarak basılır).
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

    // uploadAttachment/deleteAttachment: grid.js (hücre popover'ı) VE
    // grid-row-detail.js (satır genişletme paneli) window.BCC_GRID üzerinden AYNI
    // iki fonksiyonu paylaşır — attachment_upload/delete.php'yi ikinci kez
    // çağıran/yazan bir kod YOK.
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
        void td.offsetWidth; // reflow, animasyonu yeniden başlatmak için
        td.classList.add(ok ? 'cell-flash-ok' : 'cell-flash-error');
        setTimeout(function () {
            td.classList.remove('cell-flash-ok', 'cell-flash-error');
        }, 700);
    }

    // postCellValue/applyCellResultToTd: saveCell()'in iki katmana ayrılmış hâli —
    // grid-row-detail.js (satır genişletme paneli) AYNI kaydetme/DOM-güncelleme
    // mantığını window.BCC_GRID üzerinden yeniden kullanır, cell_update.php'yi
    // ikinci kez çağıran/yazan bir kod YOK. Panelde <td> her zaman yok (gizli
    // alanlar) — bu yüzden postCellValue tek başına da (td'siz) kullanılabilir.
    function postCellValue(recordId, fieldId, value) {
        return post('/api/cell_update.php', {
            csrf_token: CSRF,
            record_id: recordId,
            field_id: fieldId,
            value: value,
        });
    }

    // Rating hücresini yeniden çizmez (data.display "★★★☆☆" düz metnini
    // view.textContent'e yazmak tıklanabilir <span data-rating-star>'ları
    // YOK ederdi, sonraki tıklamalar çalışmazdı) — MEVCUT yıldız span'larının
    // sadece .rating-star-filled class'ını günceller.
    function updateRatingStars(view, value) {
        Array.prototype.forEach.call(view.querySelectorAll('.rating-star'), function (star) {
            var idx = parseInt(star.getAttribute('data-rating-star'), 10);
            star.classList.toggle('rating-star-filled', idx <= value);
        });
    }

    function applyCellResultToTd(td, data) {
        td.setAttribute('data-value', data.raw);
        var view = td.querySelector('.cell-view');
        if (view) {
            if (data.display_chips) {
                renderChips(view, data.display_chips);
            } else if (Object.prototype.hasOwnProperty.call(data, 'display_link')) {
                // Grup A: anahtarın VARLIĞINA bakılır, değerine değil —
                // display_link null olabilir ("link yapılamaz" demek) ve o durumda
                // da bu dala girip mevcut ikonun SİLİNMESİ gerekir.
                renderLinkifiedCell(view, data.display, data.display_link);
            } else if (td.getAttribute('data-field-type') === 'long_text') {
                // GÜVENLİ: data.display burada sunucuda bcc_sanitize_rich_text()
                // ile temizlenmiş HTML — ham kullanıcı girdisi DEĞİL, innerHTML
                // ile yazmak güvenlidir (bkz. src/schema.php).
                view.innerHTML = data.display;
            } else if (td.getAttribute('data-field-type') === 'rating') {
                updateRatingStars(view, parseInt(data.raw, 10) || 0);
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

    // Kayıt ekleme: (a) yuvarlak + butonu, (b) tablo tabanı + satırı ve (c)
    // Shift+Enter kısayolu ÜÇÜ DE bu TEK fonksiyonu çağırır (aşağıda wire edilir) —
    // ikinci bir "kayıt ekle" mekanizması yok.
    var addingRecord = false; // istek kilidi: hızlı tekrar tıklama/kısayol çoklu kayıt üretmesin

    function renumberRows() {
        var rows = document.querySelectorAll('table.grid tbody tr[data-record-id]');
        rows.forEach(function (tr, idx) {
            var cell = tr.querySelector('.grid-rownum');
            if (cell) {
                cell.textContent = idx + 1;
            }
        });

        var countEl = document.getElementById('grid-row-count');
        if (countEl) {
            countEl.textContent = rows.length + ' kayıt';
        }
    }

    // Toast: ikinci bir bildirim sistemi kurmak yerine projedeki mevcut .ok/.error
    // metin deseni (src/partials/flash.php) yeniden kullanılır — burada tek fark,
    // async bir fetch sonrası sayfa yenilenmediği için elemanın JS ile eklenip
    // birkaç saniye sonra kendiliğinden kaldırılmasıdır.
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

    function addRecord(afterRecordId, targetRow) {
        if (addingRecord) {
            return;
        }
        addingRecord = true;

        var tableId = new URLSearchParams(window.location.search).get('table_id') || '';
        var params = {
            csrf_token: CSRF,
            table_id: tableId,
            state_query_string: window.location.search.replace(/^\?/, ''),
        };

        // Sort/group aktifken after_record_id kasıtlı olarak GÖNDERİLMEZ — sunucu
        // sona ekler ((a)/(b) ile aynı davranış), çünkü görünen sıra zaten
        // position'dan değil sort/group kolonlarından geliyor.
        if (!window.BCC_SORT_OR_GROUP_ACTIVE && afterRecordId) {
            params.after_record_id = afterRecordId;
        }

        post('/api/record_add.php', params).then(function (result) {
            addingRecord = false;

            if (!(result.httpOk && result.data && result.data.ok)) {
                var message = (result.data && result.data.error) ? result.data.error : 'Kayıt eklenemedi.';
                window.alert(message);
                return; // DOM'a satır eklenmez.
            }

            var temp = document.createElement('tbody');
            temp.innerHTML = result.data.row_html;
            var newRow = temp.querySelector('tr[data-record-id]');
            if (!newRow) {
                return;
            }

            if (targetRow && targetRow.parentNode) {
                targetRow.insertAdjacentElement('afterend', newRow);
            } else {
                var addRowEl = document.querySelector('[data-grid-add-row]');
                if (addRowEl && addRowEl.parentNode) {
                    addRowEl.insertAdjacentElement('beforebegin', newRow);
                } else {
                    var tbody = document.querySelector('table.grid tbody');
                    if (tbody) {
                        tbody.appendChild(newRow);
                    }
                }
            }

            renumberRows();

            // Sütun dondurma: yeni satır da mevcut dondurma durumunu almalı —
            // ikinci bir pozisyonlama mekanizması yazmak yerine grid-freeze-columns.js'in
            // kendi apply fonksiyonu çağrılır (o script her zaman yüklenir).
            if (window.BCC_reapplyFreeze) {
                window.BCC_reapplyFreeze();
            }

            var firstCell = newRow.querySelector('td.editable');
            if (firstCell) {
                startEdit(firstCell);
            }

            if (window.BCC_SORT_OR_GROUP_ACTIVE || window.BCC_FILTER_ACTIVE) {
                showToast('Kayıt eklendi. Aktif filtre/sıralama/gruplama nedeniyle konumu sayfa yenilenince değişebilir.');
            }
        }).catch(function () {
            addingRecord = false;
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

    // choices: ÖNCEDEN PARSE EDİLMİŞ dizi (td'den data-options okunmuş VEYA
    // grid-row-detail.js'de data-fields JSON'undan gelen .options — ikisi de
    // aynı şekle sahip: select tipleri için string dizisi, 'user' için
    // [{"id":..,"name":..}]). buildInput artık bir <td>'ye bağlı değil, bu
    // yüzden gizli alanlar (satırda <td>'si olmayan) için de kullanılabilir.
    function buildInput(type, choices, raw) {
        var input;
        choices = choices || [];

        if (type === 'number' || type === 'currency' || type === 'percent') {
            // currency/percent: number ile AYNI native <input type=number> —
            // percent'in "45 yaz, DB'ye 0.45 yazılır" dönüşümü zaten raw'a
            // (cell_raw_value() ×100'lü döndürür) ve normalize_cell_value()'a
            // (÷100) uygulanıyor, burada ekstra bir şey yapılmaz.
            input = document.createElement('input');
            input.type = 'number';
            input.step = 'any';
            input.value = raw;
        } else if (type === 'url' || type === 'email' || type === 'phone') {
            // Grup A: native input tipi YALNIZCA mobil klavyeyi doğru açmak için
            // (url -> ".com" tuşu, email -> "@", phone -> tuş takımı). Tarayıcının
            // KENDİ doğrulaması devreye GİRMEZ: bunlar <form> içinde değil,
            // hücreye eklenen serbest input'lar — submit/validity kontrolü yok,
            // değer blur'da olduğu gibi gönderilir. Doğrulamanın yumuşak kalması
            // bilinçli (bkz. normalize_cell_value, src/schema.php).
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
            // data-options burada [{"id":..,"name":..}] şeklinde (single_select'in
            // düz string listesinden farklı — id ile görünen ad ayrı, bkz.
            // bcc_user_choices_from_map, src/schema.php).
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
            return; // checkbox doğrudan tıklanır, edit moduna girmez
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

    // Zengin metin (long_text — F6, "ilk aşama"): kalın/italik/link. Diğer
    // tiplerin startEdit()/buildInput() akışını KULLANMAZ — araç çubuğu
    // düğmeleri contenteditable'ın blur'unu tetikleyeceğinden ("blur = kaydet"
    // deseni burada işe yaramaz), ayrı bir popover + açık Kaydet/İptal
    // butonlarıyla çalışır.
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
        // GÜVENLİ: raw, data-value attribute'undan geliyor — sunucuda zaten
        // bcc_sanitize_rich_text() ile temizlenmiş HTML'in tarayıcı
        // tarafından otomatik decode edilmiş hâli (attribute'a
        // htmlspecialchars ile yazılmıştı, ham kullanıcı girdisi değil).
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
            // mousedown'da preventDefault: contenteditable'daki metin seçimi
            // buton tıklamasıyla kaybolmasın — execCommand mevcut seçime
            // uygulanır, seçim korunmalı.
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

        // Emoji (🔗) yerine SVG ikon — B/i metin glifleriyle (Google Docs/Notion
        // davranışı, tanıdık bir konvansiyon) tutarlı olsun diye tarayıcıya/işletim
        // sistemine göre renkli/farklı render olan emoji yerine, uygulamanın geri
        // kalanındaki ince çizgili ikon diliyle (bkz. grid.php'deki diğer SVG'ler)
        // aynı stil kullanılıyor.
        var linkIconSvg = '<svg width="13" height="13" viewBox="0 0 20 20" fill="none">'
            + '<path d="M8.5 11.5a3 3 0 004.24 0l2.5-2.5a3 3 0 10-4.24-4.24l-1 1" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
            + '<path d="M11.5 8.5a3 3 0 00-4.24 0l-2.5 2.5a3 3 0 104.24 4.24l1-1" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
            + '</svg>';

        var linkBtn = makeToolbarButton(linkIconSvg, 'Link ekle', function () {
            // Açıksa ikinci tıklama kapatır (araç çubuğu düğmelerinin geri kalanı
            // gibi "aç/kapa" davranışı).
            if (linkBar.hidden) {
                openLinkBar();
            } else {
                cancelLinkBar();
            }
        }, true);

        toolbar.appendChild(boldBtn);
        toolbar.appendChild(italicBtn);
        toolbar.appendChild(linkBtn);

        // ---- Satır içi link çubuğu (araç çubuğunun HEMEN ALTINDA) ----
        // window.prompt() KULLANILMAZ: native prompt sayfayı bloklar, uygulamanın
        // temasına/diline uymaz ve —asıl sorun— contenteditable'ın seçimini
        // tarayıcıya göre değişen biçimde bozar. Hata mesajı da window.alert
        // değil, çubuğun İÇİNDE (aşağıdaki linkError).
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
        // Kaydet/İptal ikilisiyle AYNI birincil buton sınıfı (grid-shell.css).
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

        // Seçim URL girişine odaklanınca KAYBOLUR (contenteditable'ın seçimi
        // yalnızca kendisi odaktayken korunur) — bu yüzden çubuk AÇILIRKEN Range
        // saklanır ve ekleme anında geri yüklenir. prompt() döneminde bu gerekli
        // değildi çünkü prompt odağı DOM'dan hiç almıyordu.
        var savedRange = null;
        var editingAnchor = null; // imleç mevcut bir <a>'nın içindeyse: href güncellenir

        function editableSelectionRange() {
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) {
                return null;
            }
            var range = sel.getRangeAt(0);

            // Node.contains kendisini de kapsar (seçim doğrudan editable'daysa).
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
            // Mevcut bir linkin üzerindeyken URL'si doldurulur ve buton "Kaydet"
            // olur (düzenleme), aksi hâlde boş giriş + "Ekle".
            linkInput.value = editingAnchor ? editingAnchor.getAttribute('href') : '';
            linkAddBtn.textContent = editingAnchor ? 'Kaydet' : 'Ekle';
            linkBar.hidden = false;
            // Popover büyüdü: aşağı sığmıyorsa yeniden konumlanmalı.
            positionPopover();
            linkInput.focus();
            linkInput.select();
        }

        // Çubuğu kapatır ve odağı editöre geri verir. Seçimi GERİ YÜKLEMEZ —
        // ekleme sonrası imleç zaten yeni linkin arkasındadır; iptalde geri
        // yükleme cancelLinkBar()'ın işi.
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
                selectRange(range); // kullanıcının vurgusu kaybolmasın
            }
        }

        function applyLink() {
            var url = linkInput.value.trim();

            if (!/^https?:\/\//i.test(url)) {
                // Sunucudaki whitelist (bcc_build_safe_link) ile AYNI kural —
                // burada yalnızca kullanıcıya erken/anlaşılır geri bildirim için.
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
                // Seçim bir şekilde editable dışında kaldı: imleci sona al —
                // link ASLA editörün dışına yazılmaz.
                var endRange = document.createRange();
                endRange.selectNodeContents(editable);
                endRange.collapse(false);
                selectRange(endRange);
            }

            if (sel.isCollapsed) {
                // Seçili metin YOK: URL'nin kendisi link metni olur (Notion/
                // Google Docs davranışı). execCommand('createLink') boş seçimde
                // hiçbir şey yapmazdı, bu yüzden <a> elle kurulur.
                var anchor = document.createElement('a');
                anchor.href = url;
                anchor.textContent = url;
                sel.getRangeAt(0).insertNode(anchor);

                // İmleç linkin ARKASINA alınır — yazmaya devam eden kullanıcı
                // metni linkin İÇİNE eklemesin.
                var afterRange = document.createRange();
                afterRange.setStartAfter(anchor);
                afterRange.collapse(true);
                selectRange(afterRange);
            } else {
                // Seçili metni <a> ile SARAR. DOM'u elle kurmak yerine
                // execCommand: tarayıcının geri-al (undo) yığını korunur.
                document.execCommand('createLink', false, url);
            }

            closeLinkBar();
        }

        // mousedown'da preventDefault: araç çubuğu düğmeleriyle AYNI gerekçe —
        // buton tıklaması URL girişinin/editörün odağını çalmasın.
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
                // Yalnızca link çubuğunu kapatır — TÜM hücre düzenlemesini
                // iptal ETMEZ (o, editable üzerindeki Escape'in işi).
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
        // gs-view-desc-save vb. diğer "Kaydet" butonlarıyla AYNI sınıf
        // (grid-shell.css) — önceden İptal ile aynı nötr .btn-sm'ydi, birincil/
        // ikincil aksiyon ayrımı yoktu.
        saveBtn.className = 'gs-btn-primary';
        saveBtn.textContent = 'Kaydet';
        actions.appendChild(cancelBtn);
        actions.appendChild(saveBtn);

        popover.appendChild(toolbar);
        popover.appendChild(linkBar); // araç çubuğunun HEMEN ALTINDA açılır
        popover.appendChild(editable);
        popover.appendChild(actions);

        if (view) {
            view.style.display = 'none';
        }
        td.appendChild(popover);

        // .grid-wrap overflow:auto taşıyor — position:absolute popover satır
        // tablonun alt/sağ kenarına yakınsa KIRPILIRDI (bkz. grid-shell.css'teki
        // .gs-view-row-menu-panel / .grid-add-field-panel'de uygulanan AYNI ders).
        // position:fixed + burada hesaplanan konum bunu atlıyor.
        //
        // Konum matematiği ORTAK yardımcıdan (bcc_positionFloating) geliyor —
        // burada ikinci bir kopya YOK. Eskiden bu fonksiyon koşulsuz
        // "tdRect.bottom + 4" yazıyordu; alt satırlardaki hücrelerde (8./9.)
        // popover ekranın altından taşıyordu. Yardımcı aşağı sığmıyorsa YUKARI
        // çeviriyor, sağa taşarsa içeri çekiyor.
        function positionPopover() {
            window.bcc_positionFloating(popover, td.getBoundingClientRect());
        }
        positionPopover();

        // Bulunan gerçek bug: position:fixed konumu yalnızca AÇILIŞTA
        // hesaplanıyordu — popover açıkken sayfa kaydırılırsa (grid uzun bir
        // tabloda çok olağan) hücre kayarken popover ekranda SABİT kalıp
        // hücresinden tamamen kopuyordu. Scroll'da yeniden konumlandırılır
        // (capture: true — iç içe kaydırılabilir bir üst öğeden de yakalar).
        window.addEventListener('scroll', positionPopover, true);
        // resize de gerekli: pencere küçülünce "aşağı sığıyor mu" kararı
        // değişir, yeniden ölçülmezse popover yine ekran dışında kalırdı.
        window.addEventListener('resize', positionPopover);

        editable.focus();

        var done = false;

        function endEdit() {
            td.classList.remove('editing', 'richtext-editing');
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
            // Enter: taslak satır sonu olarak bırakılır (tarayıcı varsayılanı) —
            // kaydetme yalnızca "Kaydet" butonuyla, yanlışlıkla Enter'da
            // erken kaydetme yok.
        });

        // Dışarı tıklayınca İptal (kaydetmeden kapan) — aynı tetikleyici click'in
        // hemen kendisini yakalamaması için bir sonraki turda bağlanır.
        function outsideClickHandler(e) {
            if (!popover.contains(e.target)) {
                cancel();
            }
        }
        setTimeout(function () {
            document.addEventListener('mousedown', outsideClickHandler, true);
        }, 0);
    }

    // Dosya listesi + "dosya seç" girişini içeren, tek başına kullanılabilir
    // widget — grid.js'nin hücre popover'ı (startAttachmentEdit, aşağıda) VE
    // grid-row-detail.js'nin genişletme paneli AYNI bu fonksiyonu window.BCC_GRID
    // üzerinden çağırır, liste/yükle/sil mantığı iki yerde ayrı ayrı yazılmaz.
    // Her yükleme/silme KENDİ AJAX isteğiyle ANINDA etkili (kayıt ekleme/silmeyle
    // AYNI felsefe) — ayrı bir "Kaydet" adımı yok. onChange(files), çağıran
    // tarafın kendi görünümünü (canlı <td> ise data-attachments + .cell-view,
    // panelde ise yalnızca liveTd varsa) senkron tutması için her değişiklikte çağrılır.
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
        // Bulunan gerçek bug: input DOĞRUDAN görünür eklendiğinde tarayıcının
        // kendi native "Dosya Seç" butonu ÇOK küçük, dolgulu kesikli çerçevenin
        // geri kalanı tıklanmıyordu. Artık gizli (hidden) — <label> ile sarmalanıp
        // (aşağıda) kutunun HERHANGİ bir yerine tıklamak native olarak dosya
        // diyaloğunu açıyor, ayrı bir onclick/.click() JS'i gerekmiyor.
        fileInput.hidden = true;

        // change (native seçim) VE drop (sürükle-bırak) AYNI upload mantığını
        // çağırır — uploadAttachment()'a HİÇ dokunulmadı, ikinci bir kopya yok.
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

        // OpsFlow'daki "Drop files here or click to browse" davranışı: TÜM kutu
        // tıklanabilir (native <label> davranışı) + sürükle-bırak. Projede daha
        // önce hiç drag/drop altyapısı yoktu (grep ile doğrulandı), burada
        // eklendi — yalnızca preventDefault + görsel .is-dragover durumu,
        // gerçek yükleme yine handleFileSelected() üzerinden.
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

    // Hücreye tıklayınca açılan popover — buildAttachmentManager()'ı kapatma
    // çerçevesiyle (dışarı tık/Escape/Kapat butonu) sarar, td'nin
    // data-attachments'ını VE görünür .cell-view'ını senkron tutar.
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

        // Ek dosya popover'ı da AYNI kırpılma bugunu taşıyordu: CSS'te
        // position:absolute idi, yani .grid-wrap { overflow:auto } kutusuna
        // kırpılıyordu — alt satırlarda ve en sağdaki sütunlarda görünmez
        // oluyordu. Richtext popover'ıyla AYNI çözüme bağlandı (CSS artık
        // position:fixed; konum ortak yardımcıdan).
        function positionPopover() {
            window.bcc_positionFloating(popover, td.getBoundingClientRect());
        }
        positionPopover();
        window.addEventListener('scroll', positionPopover, true);
        window.addEventListener('resize', positionPopover);

        var done = false;

        function endEdit() {
            td.classList.remove('editing', 'attachment-editing');
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
                return; // change olayı hallediyor
            }
            // Rating: checkbox İLE AYNI "girmeden doğrudan tıkla-kaydet" deseni —
            // startEdit()'in input-aç/blur-ile-kaydet akışına HİÇ girmez. Aynı
            // yıldıza TEKRAR tıklamak değerlendirmeyi TEMİZLER (0'a döner) —
            // OpsFlow'un kendi davranışı, "yanlışlıkla verdiğim puanı nasıl
            // sileceğim" sorusuna native bir cevap.
            var star = e.target.closest('.rating-star');
            if (star) {
                var ratingView = star.closest('.rating-view-editable');
                if (!ratingView) {
                    return; // salt-okunur (canEdit=false) — .rating-view-editable class'ı yok
                }
                var ratingTd = ratingView.closest('td');
                var clickedValue = parseInt(star.getAttribute('data-rating-star'), 10);
                var currentValue = parseInt(ratingTd.getAttribute('data-value'), 10) || 0;
                var nextValue = (clickedValue === currentValue) ? 0 : clickedValue;
                saveCell(ratingTd, String(nextValue));
                return;
            }
            // Grup A: "yeni sekmede aç" ikonuna tıklama düzenlemeyi AÇMAZ —
            // tarayıcı <a>'nın kendi navigasyonunu yapar, biz sadece bu
            // dinleyicinin devamını (startEdit) durdururuz. Hücrenin GERİ KALANINA
            // tıklamak her zamanki gibi düzenlemeyi açar; bu yüzden metnin kendisi
            // bilerek <a> DEĞİL (bkz. bcc_render_linkified_cell yorumu).
            if (e.target.closest('.cell-link-icon')) {
                e.stopPropagation();
                return;
            }
            // Zengin metnin İÇİNE gömülü link (long_text): AYNI desen — tıklama
            // düzenlemeyi AÇMAZ, tarayıcı linki kendi açar. Grup A'dan farkı,
            // burada linkleşen şeyin metnin KENDİSİ olması (kullanıcı <a>'yı
            // seçtiği metnin üzerine kuruyor); hücrenin link DIŞINDAKİ kısmına
            // tıklamak her zamanki gibi düzenlemeyi açar.
            var richLink = e.target.closest('.rich-text-view a');
            if (richLink) {
                // Sunucu (bcc_build_safe_link) zaten target/rel yazıyor; bu iki
                // satır, o attribute'lar eklenmeden ÖNCE kaydedilmiş eski
                // satırların da yeni sekmede açılmasını garantiler.
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
                return; // yıldızlara tıklama yukarıda ele alındı, boş alana tıklamak hiçbir şey yapmaz
            } else {
                startEdit(td);
            }
        });

        // Rating hover-önizleme: fareyle üzerine gelince o yıldıza kadar
        // GEÇİCİ olarak dolu gösterir (OpsFlow davranışı), fare ayrılınca
        // GERÇEK değere (data-value) geri döner — mouseover/mouseout event
        // delegation ile (grid'e TEK dinleyici, satır sayısına göre çoğalmaz).
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

        // Tablo tabanı "+" satırı: addRecord() fonksiyonunu tetikler.
        var addRow = document.querySelector('[data-grid-add-row]');
        if (addRow) {
            addRow.addEventListener('click', function () {
                addRecord(null, null);
            });
        }

        // Shift+Enter: herhangi bir hücredeyken (input/select/td, textarea VE
        // zengin metin editörü HARİÇ — orada satır atlamalı) aktif kaydın hemen
        // altına ekler — yukarıdaki "+" satırıyla AYNI addRecord() fonksiyonu,
        // ikinci bir mekanizma yok.
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

        // Kaydedilebilir görünümler: "Save view" menü öğesi, ekranda görünen
        // sort/filter/group/hidden fields/row height/wrap headers durumunu
        // views.config'e yazar (view_save_state.php). Sonraki ziyarette grid.php
        // parametresiz açılırsa bu state'e otomatik yönlendirir (redirect).
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
                        showToast('Görünüm kaydedildi.');
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

    // grid-row-detail.js (satır genişletme paneli) için paylaşılan yüzey —
    // cell_update.php'yi ikinci kez çağıran/yazan kod olmasın diye. grid.php'de
    // bu script HER ZAMAN grid-row-detail.js'den ÖNCE yüklenir (defer sırası
    // doküman sırasına uyar), bu yüzden window.BCC_GRID orada hazır olur.
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
    };
})();
