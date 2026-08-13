(function () {
    'use strict';

    // grid.js'deki fileTypeBadge/renderAttachmentChips ile AYNI harita/DOM yapısı
    // (.attachment-chip/.attachment-thumb/.attachment-badge/.attachment-name,
    // style.css'teki AYNI kurallar) — burada window.BCC_GRID yok (grid.js hiç
    // yüklenmiyor), bu yüzden küçük bir kopya, ama salt-okunur (yükleme/silme YOK).
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
        var prevBtn = document.getElementById('if-detail-prev');
        var nextBtn = document.getElementById('if-detail-next');

        if (!recordList) {
            return;
        }

        var rows = Array.prototype.slice.call(recordList.querySelectorAll('.if-record-row'));
        var currentDetailRow = null;

        // E4 — grid.php'nin getAllDataRows() ile AYNI fikir, ama yalnızca GÖRÜNÜR
        // satırlar (grid.php arama sırasında satır GİZLEMEZ, sadece <mark> ekler —
        // burada arama gerçekten row.hidden yaptığı için (aşağıda applyVisibility)
        // gizli satırlar arasında ▲▼ atlama yapmamalı).
        function getVisibleRows() {
            return rows.filter(function (r) { return !r.hidden; });
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

        // Satır tıklama: sağ detay paneli, satırın data-detail-fields JSON'undan
        // (sunucu tarafında bir kez gömülmüş) kurulur — ikinci bir AJAX/sorgu YOK.
        function selectRow(row) {
            currentDetailRow = row;
            rows.forEach(function (r) { r.classList.remove('is-selected'); });
            row.classList.add('is-selected');

            // Seçim liste görünümünün dışına taşabilir (klavye okları ve ▲▼
            // düğmeleri sıradaki kaydı ekranda olup olmadığına bakmadan seçer).
            // Burada, selectRow'un İÇİNDE duruyor: her iki yol da (ve fare
            // tıklaması da) tek bir kaydırma davranışını paylaşsın, ikinci bir
            // kopya yazılmasın. 'nearest' zaten tamamen görünür olan satırda
            // HİÇBİR ŞEY yapmaz — yani fareyle tıklamada no-op, yalnızca
            // kısmen/tamamen dışarıda kalan satırı en az hareketle içeri alır.
            // behavior:'smooth' BİLEREK kullanılmadı: ok tuşu basılı tutulunca
            // yumuşak kaydırmalar kuyruğa girip listeyi seçimin gerisinde
            // bırakıyor, gezinme takip edilemez hale geliyor.
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
                    // Salt-okunur: küçük resim/rozet + indirme linki — grid.php'nin
                    // AYNI .attachment-* sınıflarıyla (style.css, burada da yüklü),
                    // yükleme/silme YOK (Duyuru ekranı hiçbir mutasyon çağırmaz).
                    value.className = 'if-detail-field-value attachment-cell-view';
                    renderAttachmentFiles(value, f.files || []);
                } else if (f.is_rich) {
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
            updateDetailNavState();
        }

        rows.forEach(function (row) {
            row.addEventListener('click', function () {
                selectRow(row);
            });
        });

        // ---- Klavye ile kayıt gezinme (↑/↓) --------------------------------
        // ▲▼ düğmeleriyle AYNI navigateDetail() yolunu kullanır — ikinci bir
        // seçim/render mekanizması YAZILMADI, yani sınır kontrolü (listenin
        // başında ↑ / sonunda ↓) ve "gizli satırı atlama" davranışı oradan
        // olduğu gibi devralınır.

        // Yazarken ok tuşları imleci hareket ettirir; listeyi gezdirmemeli.
        // SELECT de dahil: kapalı bir <select> üzerinde ok tuşu seçeneği
        // değiştirir, o da bir "yazma" eylemidir.
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

        // Paylaşım modalı açıkken liste ARKADA kalır — ok tuşu, kullanıcının
        // göremediği bir seçimi değiştirmemeli. Kimliğe göre değil
        // [aria-modal="true"] ile aranıyor: ileride eklenecek başka bir modal
        // da bu korumadan kendiliğinden yararlansın.
        // getClientRects() kullanılıyor, offsetParent DEĞİL: overlay
        // position:fixed olduğunda offsetParent görünür bir modalda da null
        // döner ve modal yanlışlıkla "kapalı" sayılırdı.
        function modalIsOpen() {
            var dlg = document.querySelector('[aria-modal="true"]');
            return !!(dlg && dlg.getClientRects().length);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') {
                return;
            }
            // Modifier'lı kombinasyonlar (ör. tarayıcının kendi kısayolları)
            // bu gezinmeye ait değil.
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

            // Sayfanın kendi dikey kaydırması devreye girmesin; konumlandırmayı
            // selectRow içindeki scrollIntoView yapıyor.
            e.preventDefault();

            // Henüz seçim yoksa — ya da arama seçili kaydı gizlediyse
            // (indexOf === -1, bu durumda navigateDetail hiçbir şey yapamazdı) —
            // yön ne olursa olsun görünür listenin ilk kaydından başla. Böylece
            // liste, önce fareyle bir kayda tıklamak gerekmeden gezilebilir.
            if (!currentDetailRow || visible.indexOf(currentDetailRow) === -1) {
                selectRow(visible[0]);
                return;
            }

            navigateDetail(e.key === 'ArrowDown' ? 1 : -1);
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
                updateDetailNavState();
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
