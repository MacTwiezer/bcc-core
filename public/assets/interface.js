(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Sol nav daralt/genişlet (« / ») — dashboard.php'nin #home-sidebar-toggle
        // deseniyle AYNI fikir (class toggle), burada yalnızca iki ayrı buton
        // (daralt/genişlet) görünürlüğü de birlikte değişiyor (CSS bu ikisini
        // .if-nav.is-collapsed altında otomatik gösterip/gizliyor).
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

        if (!recordList) {
            return;
        }

        var rows = Array.prototype.slice.call(recordList.querySelectorAll('.if-record-row'));

        // Satır tıklama: sağ detay paneli, satırın data-detail-fields JSON'undan
        // (sunucu tarafında bir kez gömülmüş) kurulur — ikinci bir AJAX/sorgu YOK.
        function selectRow(row) {
            rows.forEach(function (r) { r.classList.remove('is-selected'); });
            row.classList.add('is-selected');

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
                if (f.is_rich) {
                    // GÜVENLİ: f.value sunucuda bcc_sanitize_rich_text() ile
                    // temizlenmiş HTML (cell_display_text long_text çıktısı) —
                    // JSON.parse zaten HTML-entity çözümünü yapmış hâliyle
                    // geldi, ham kullanıcı girdisi DEĞİL (grid.js'in aynı
                    // data-value -> innerHTML deseniyle özdeş).
                    value.innerHTML = f.value;
                } else {
                    value.textContent = f.value;
                }
                wrap.appendChild(value);

                detailFields.appendChild(wrap);
            });

            detailPlaceholder.hidden = true;
            detailContent.hidden = false;
        }

        rows.forEach(function (row) {
            row.addEventListener('click', function () {
                selectRow(row);
            });
        });

        // Arama: TAM içerikte (yalnızca listede görünen kırpılmış önizlemede
        // değil) eşleşmesi gerektiği için sunucuya gider (public/api/
        // interface_search.php, bcc_interface_fetch_records() — sayfanın ilk
        // yüklemesiyle AYNI fonksiyon). 200ms debounce ile istek sayısı sınırlı;
        // yanıt yalnızca eşleşen record_id listesi, HTML DEĞİL — zaten basılı
        // satırlar gösterilip/gizlenir, ikinci bir render yolu yok.
        if (searchInput && recordList) {
            var tableId = recordList.getAttribute('data-table-id') || '';
            var debounceTimer = null;
            var activeRequestId = 0;

            function applyVisibility(matchIds) {
                var visibleCount = 0;
                rows.forEach(function (row) {
                    var id = parseInt(row.getAttribute('data-record-id'), 10);
                    var matches = matchIds === null || matchIds.indexOf(id) !== -1;
                    row.hidden = !matches;
                    if (matches) {
                        visibleCount++;
                    }
                });
                if (noResults) {
                    noResults.hidden = !(matchIds !== null && visibleCount === 0);
                }
            }

            function runSearch() {
                var q = searchInput.value.trim();

                if (q === '') {
                    applyVisibility(null);
                    return;
                }

                var requestId = ++activeRequestId;

                fetch('/api/interface_search.php?table_id=' + encodeURIComponent(tableId) + '&q=' + encodeURIComponent(q))
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        // Yarışı önle: yalnızca EN SON isteğin sonucu uygulanır
                        // (yavaş bir yanıt, daha yeni bir yanıtı EZMESİN).
                        if (requestId !== activeRequestId) {
                            return;
                        }
                        if (data && data.ok) {
                            applyVisibility(data.record_ids);
                        }
                    })
                    .catch(function () {});
            }

            searchInput.addEventListener('input', function () {
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                }
                debounceTimer = setTimeout(runSearch, 200);
            });
        }
    });
})();
