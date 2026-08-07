(function () {
    'use strict';

    // Kanban tahtası (Grup View-Kanban).
    //
    // SÜRÜKLE-BIRAK YENİ BİR UÇ NOKTA GEREKTİRMEZ: bir kartı başka sütuna
    // taşımak, o kaydın single_select hücresine yeni değeri yazmaktır — yani
    // cell_update.php'nin TAM OLARAK yaptığı iş. O uçnokta zaten CSRF + oturum +
    // require_role('editor') + kayıt↔alan sahipliği + soft-delete kontrolü
    // yapıyor VE normalize_cell_value() sayesinde değerin gerçekten bir
    // choices üyesi olduğunu doğruluyor. Özel bir "kartı taşı" uçnoktası yazmak
    // bu doğrulamaları KOPYALAMAK olurdu.
    //
    // İstemcideki canEdit kontrolü yalnızca KOZMETİK (tutamaç bağlamamak);
    // gerçek kapı sunucudaki require_role('editor').

    document.addEventListener('DOMContentLoaded', function () {
        var board = document.querySelector('[data-kanban-board]');
        if (!board) {
            return;
        }

        var columnFieldId = board.getAttribute('data-column-field-id');
        var canEdit = board.getAttribute('data-can-edit') === '1';

        function post(url, data) {
            var body = new URLSearchParams();
            Object.keys(data).forEach(function (k) {
                if (Array.isArray(data[k])) {
                    data[k].forEach(function (v) { body.append(k + '[]', v); });
                    return;
                }
                body.append(k, data[k]);
            });

            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            }).then(function (res) {
                return res.json().then(function (json) {
                    return { httpOk: res.ok, data: json };
                }).catch(function () {
                    return { httpOk: false, data: null };
                });
            });
        }

        function refreshCounts() {
            Array.prototype.forEach.call(board.querySelectorAll('[data-kanban-column]'), function (col) {
                var count = col.querySelectorAll('[data-kanban-card]').length;
                var badge = col.querySelector('[data-kanban-count]');
                if (badge) {
                    badge.textContent = count;
                }
            });
        }

        // ---- Kart tıklaması -> kayıt detayı ---------------------------------
        // KARAR: DERİN LİNK (grid.php?table_id=N&record_id=M), modali burada
        // yeniden kullanmak DEĞİL. Deneme yapıldı ve maliyeti ölçüldü:
        // grid-row-detail.js window.BCC_GRID'e 20 kez, grid'e özel eleman
        // id'lerine 33 kez bağlı; modalin DOM'u da grid.php'nin içinde
        // (#grid-detail-overlay). Kanban'a taşımak, GRID'İ OLMAYAN bir sayfaya
        // grid.js'in tamamını + modal DOM'unu yüklemek olurdu — ki kanban.php'yi
        // ayrı sayfa yapmamızın gerekçesiyle doğrudan çelişirdi.
        // Derin link ZATEN çalışan bir mekanizma: grid-row-detail.js:1338
        // sayfa yüklenirken URL'deki record_id'yi okuyup paneli açıyor.
        // (Modali paylaşılabilir bir partial'a çıkarmak ayrı bir tur işi.)
        var tableId = board.getAttribute('data-table-id');

        board.addEventListener('click', function (e) {
            var card = e.target.closest('[data-kanban-card]');
            if (!card) {
                return;
            }
            // Sürükleme SONRASI gelen click bastırılır — yoksa kartı taşıyan
            // kullanıcı istemeden kayıt detayına yönlendirilirdi.
            if (card.getAttribute('data-drag-moved') === '1') {
                card.removeAttribute('data-drag-moved');
                return;
            }
            window.location.href = '/grid.php?table_id=' + encodeURIComponent(tableId)
                + '&record_id=' + encodeURIComponent(card.getAttribute('data-record-id'));
        });

        // ---- Sütunlama ayarları paneli -------------------------------------
        var settings = document.querySelector('[data-kanban-settings]');
        if (settings) {
            var saveBtn = settings.querySelector('[data-kanban-save]');
            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    var chosen = settings.querySelector('input[name="kanban_field_id"]:checked');
                    var cardFields = Array.prototype.map.call(
                        settings.querySelectorAll('input[name="kanban_card_fields"]:checked'),
                        function (cb) { return cb.value; }
                    );

                    saveBtn.disabled = true;
                    post('/api/kanban_config_update.php', {
                        csrf_token: BCC_KANBAN_CSRF,
                        view_id: board.getAttribute('data-view-id'),
                        kanban_field_id: chosen ? chosen.value : '0',
                        kanban_card_fields: cardFields,
                    }).then(function (result) {
                        saveBtn.disabled = false;
                        if (result.httpOk && result.data && result.data.ok) {
                            // Sütun yapısı tamamen değişebilir (farklı alan =
                            // farklı seçenekler) — kısmi DOM güncellemesi yerine
                            // sayfayı yenilemek hem basit hem doğru.
                            window.location.reload();
                            return;
                        }
                        window.alert((result.data && result.data.error) || 'Ayarlar kaydedilemedi.');
                    }).catch(function () {
                        saveBtn.disabled = false;
                        window.alert('Ayarlar kaydedilemedi (bağlantı hatası).');
                    });
                });
            }
        }

        if (!canEdit) {
            return; // viewer/commenter: tahta salt-okunur, sürükleme bağlanmaz
        }

        // ---- Sürükle-bırak --------------------------------------------------
        // Ortak sürükleme iskeleti (bcc_bindColumnDrag) yeniden kullanılıyor:
        // mousedown/mousemove(rAF throttle)/mouseup/mouseleave ve clientY
        // taşıması ZATEN orada — ikinci bir sürükleme motoru YAZILMADI.
        var dragState = null;

        function columnUnder(clientX, clientY) {
            var found = null;
            Array.prototype.forEach.call(board.querySelectorAll('[data-kanban-column]'), function (col) {
                var r = col.getBoundingClientRect();
                if (clientX >= r.left && clientX <= r.right && clientY >= r.top && clientY <= r.bottom) {
                    found = col;
                }
            });
            return found;
        }

        function clearHover() {
            Array.prototype.forEach.call(board.querySelectorAll('[data-kanban-column]'), function (col) {
                col.classList.remove('is-drop-target');
            });
        }

        Array.prototype.forEach.call(board.querySelectorAll('[data-kanban-card]'), function (card) {
            window.bcc_bindColumnDrag(card, {
                onStart: function () {
                    dragState = {
                        card: card,
                        fromColumn: card.closest('[data-kanban-column]'),
                        target: null,
                    };
                    card.classList.add('is-dragging-card');
                },
                onMove: function (clientX, clientY) {
                    if (!dragState) {
                        return;
                    }
                    // Fare gerçekten hareket etti -> bunu bir SÜRÜKLEME say ve
                    // ardından gelecek click'i bastır (bkz. kart tıklama dalı).
                    card.setAttribute('data-drag-moved', '1');
                    clearHover();
                    var col = columnUnder(clientX, clientY);
                    dragState.target = col;
                    if (col && col !== dragState.fromColumn) {
                        col.classList.add('is-drop-target');
                    }
                },
                onEnd: function () {
                    if (!dragState) {
                        return;
                    }

                    var state = dragState;
                    dragState = null;
                    clearHover();
                    state.card.classList.remove('is-dragging-card');

                    var target = state.target;
                    if (!target || target === state.fromColumn) {
                        return; // aynı sütun ya da tahta dışı — istek atılmaz
                    }

                    var newValue = target.getAttribute('data-column-value');
                    var dropzone = target.querySelector('[data-kanban-dropzone]');
                    if (!dropzone) {
                        return;
                    }

                    // İYİMSER TAŞIMA: kart hemen yeni sütuna alınır, istek
                    // başarısız olursa GERİ ALINIR. Sunucu yanıtını beklemek
                    // sürüklemeyi tutuk hissettirirdi.
                    var originalDropzone = state.fromColumn.querySelector('[data-kanban-dropzone]');
                    var originalNext = state.card.nextSibling;
                    dropzone.appendChild(state.card);
                    refreshCounts();
                    state.card.classList.add('is-saving');

                    post('/api/cell_update.php', {
                        csrf_token: BCC_KANBAN_CSRF,
                        record_id: state.card.getAttribute('data-record-id'),
                        field_id: columnFieldId,
                        value: newValue,
                    }).then(function (result) {
                        state.card.classList.remove('is-saving');

                        if (result.httpOk && result.data && result.data.ok) {
                            // Seçenek artık geçerli bir choices üyesi — "seçenek
                            // listesinde yok" rozeti varsa kaldırılır.
                            var stale = state.card.querySelector('.kanban-card-stale');
                            if (stale) {
                                stale.remove();
                            }
                            return;
                        }

                        // GERİ AL — kart eski yerine döner.
                        if (originalDropzone) {
                            originalDropzone.insertBefore(state.card, originalNext);
                        }
                        refreshCounts();
                        window.alert((result.data && result.data.error) || 'Kart taşınamadı.');
                    }).catch(function () {
                        state.card.classList.remove('is-saving');
                        if (originalDropzone) {
                            originalDropzone.insertBefore(state.card, originalNext);
                        }
                        refreshCounts();
                        window.alert('Kart taşınamadı (bağlantı hatası).');
                    });
                },
            });
        });
    });
}());
