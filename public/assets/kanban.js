(function () {
    'use strict';


    document.addEventListener('DOMContentLoaded', function () {
        var post = window.bcc_post;

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
                        view_id: settings.getAttribute('data-view-id'),
                        kanban_field_id: chosen ? chosen.value : '0',
                        kanban_card_fields: cardFields,
                    }).then(function (result) {
                        saveBtn.disabled = false;
                        if (result.httpOk && result.data && result.data.ok) {
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

        var board = document.querySelector('[data-kanban-board]');
        if (!board) {
            return;
        }

        var columnFieldId = board.getAttribute('data-column-field-id');
        var canEdit = board.getAttribute('data-can-edit') === '1';

        function refreshCounts() {
            Array.prototype.forEach.call(board.querySelectorAll('[data-kanban-column]'), function (col) {
                var cards = Array.prototype.slice.call(col.querySelectorAll('[data-kanban-card]'));
                var shown = cards.filter(function (c) { return !c.hidden; }).length;
                var badge = col.querySelector('[data-kanban-count]');
                if (badge) {
                    badge.textContent = (shown === cards.length) ? cards.length : (shown + '/' + cards.length);
                }

                var noMatch = col.querySelector('[data-kanban-nomatch]');
                if (noMatch) {
                    noMatch.hidden = !(cards.length > 0 && shown === 0);
                }
            });
        }

        board.addEventListener('wheel', function (e) {
            if (e.deltaY === 0 || e.ctrlKey || e.shiftKey) {
                return;
            }
            if (board.scrollWidth <= board.clientWidth) {
                return;
            }

            var body = e.target && e.target.closest ? e.target.closest('.kanban-column-body') : null;
            if (body && body.scrollHeight > body.clientHeight) {
                var atTop = body.scrollTop <= 0;
                var atBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 1;
                var wantsUp = e.deltaY < 0;
                if ((wantsUp && !atTop) || (!wantsUp && !atBottom)) {
                    return;
                }
            }

            board.scrollLeft += e.deltaY;
            e.preventDefault();
        }, { passive: false });

        function cardHaystack(card) {
            var cached = card.getAttribute('data-search-text');
            if (cached === null) {
                cached = (card.textContent || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase('tr');
                card.setAttribute('data-search-text', cached);
            }
            return cached;
        }

        Array.prototype.forEach.call(board.querySelectorAll('[data-kanban-search]'), function (input) {
            var col = input.closest('[data-kanban-column]');
            if (!col) {
                return;
            }

            input.addEventListener('input', function () {
                var q = input.value.trim().toLocaleLowerCase('tr');

                Array.prototype.forEach.call(col.querySelectorAll('[data-kanban-card]'), function (card) {
                    card.hidden = (q !== '' && cardHaystack(card).indexOf(q) === -1);
                });

                col.classList.toggle('is-filtering', q !== '');
                refreshCounts();
            });
        });

        var tableId = board.getAttribute('data-table-id');

        board.addEventListener('click', function (e) {
            var card = e.target.closest('[data-kanban-card]');
            if (!card) {
                return;
            }
            if (card.getAttribute('data-drag-moved') === '1') {
                card.removeAttribute('data-drag-moved');
                return;
            }
            window.location.href = '/grid.php?table_id=' + encodeURIComponent(tableId)
                + '&record_id=' + encodeURIComponent(card.getAttribute('data-record-id'));
        });


        if (!canEdit) {
            return;
        }

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
                        return;
                    }

                    var newValue = target.getAttribute('data-column-value');
                    var dropzone = target.querySelector('[data-kanban-dropzone]');
                    if (!dropzone) {
                        return;
                    }

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
                            var stale = state.card.querySelector('.kanban-card-stale');
                            if (stale) {
                                stale.remove();
                            }
                            return;
                        }

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
