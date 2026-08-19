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
            // DOM'dan TAZE okunuyor, önbellekteki `rows` dizisinden DEĞİL.
            // Bulunan gerçek bug: filtre/sıralama/gruplama satırları DOM'da
            // yeniden SIRALIYOR (renderItems), ama `rows` sayfa yüklenirken
            // yakalanmış ESKİ sırayı taşıyor. Ondan türetilen liste yüzünden
            // ▲▼ düğmeleri ve ok tuşları ekranda görünenden BAŞKA bir sırada
            // geziyordu (canlı testte: gruplanmış listede ilk ok "Beta
            // Yazilim" yerine "Ceta Lojistik"e gidiyordu).
            // querySelectorAll belge sırasını döndürür — yeniden sıralamadan
            // sonra bu, kullanıcının GÖRDÜĞÜ sıradır.
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

        // ---- Temsilci not inceleme takibi ---------------------------------
        // api/note_view_start.php + note_view_end.php. Tek bağlanma noktası
        // selectRow(): notu açmanın ÜÇ yolu da (fare, ▲▼ düğmeleri, ↑↓ ok
        // tuşları) oradan geçtiği için ikinci bir kopya YAZILMADI.
        //
        // Rol kararı SUNUCUDAN gelir (BCC_IF_TRACK_VIEWS, interface.php) —
        // burada rol mantığı ÇÖZÜLMEZ. Bayrak yoksa (bu betiği yükleyen başka
        // bir sayfa) izleme tamamen kapalıdır.
        var trackViews = (typeof BCC_IF_TRACK_VIEWS !== 'undefined') && BCC_IF_TRACK_VIEWS === true;
        var auditCsrfMeta = document.querySelector('meta[name="csrf-token"]');
        var auditCsrf = auditCsrfMeta ? auditCsrfMeta.content : '';

        // Açık incelemenin sunucudaki satır id'si (yoksa null).
        var currentViewId = null;
        // "Açılış" isteği GECİKMELİ gönderilir (aşağıya bakın); bekleyen zamanlayıcı.
        var viewStartTimer = null;

        // Ok tuşuyla listede hızlı gezinmek saniyede birkaç selectRow tetikler.
        // Eşik olmasaydı her basış bir INSERT olurdu ve "inceleme" sayılmayacak
        // 200 ms'lik geçişler tabloyu doldururdu. 2 saniyeden kısa bakışlar
        // HİÇ kaydedilmez — istek bile atılmaz.
        var VIEW_START_DELAY_MS = 2000;

        function endNoteView() {
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

            // sendBeacon: sayfa KAPANIRKEN de teslim edilir (fetch iptal edilir).
            // FormData ile gönderildiği için istek multipart/form-data olur ve
            // PHP $_POST'u doldurur — mevcut api_require_csrf() değişmeden çalışır.
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/api/note_view_end.php', body);
                return;
            }
            // Yedek yol (sendBeacon yoksa): keepalive ile sayfa kapanışına dayan.
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
                        // view_id null gelebilir: sunucu "temsilci değilsin"
                        // dediğinde sessiz no-op döner (bkz. uçnokta yorumu).
                        if (data && data.ok && data.view_id) {
                            currentViewId = data.view_id;
                        }
                    })
                    .catch(function () {});
            }, VIEW_START_DELAY_MS);
        }

        // ---- "Temsilci İnceleme Geçmişi" paneli ----------------------------
        // api/note_view_list.php. Blok interface.php'de YALNIZCA yetkili role
        // basılır, yani auditEl yoksa bu bölümün tamamı sessizce no-op olur
        // (home.js'in bu sayfadaki diğer blokları gibi — null-check deseni).
        //
        // TEMBEL YÜKLEME: liste satır seçilince DEĞİL, panel AÇILINCA çekilir.
        // Aksi hâlde kullanıcının hiç bakmadığı her not tıklaması bir istek
        // daha üretirdi.
        var auditEl = document.getElementById('if-audit');
        var auditList = auditEl ? auditEl.querySelector('[data-audit-list]') : null;
        var auditEmpty = auditEl ? auditEl.querySelector('[data-audit-empty]') : null;
        var auditError = auditEl ? auditEl.querySelector('[data-audit-error]') : null;
        var auditCount = auditEl ? auditEl.querySelector('[data-audit-count]') : null;
        // Yüklenen geçmişin hangi kayda ait olduğu — aynı notta paneli kapatıp
        // açmak ikinci bir istek atmasın diye.
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
        }

        function renderAuditRows(views) {
            auditList.textContent = '';

            views.forEach(function (v) {
                var row = document.createElement('div');
                row.className = 'if-audit-item';

                var name = document.createElement('span');
                name.className = 'if-audit-item-name';
                // textContent: isim kullanıcı verisidir, innerHTML KULLANILMAZ.
                name.textContent = v.user_name;
                row.appendChild(name);

                var date = document.createElement('span');
                date.className = 'if-audit-item-date';
                date.textContent = v.opened_at_display;
                row.appendChild(date);

                var dur = document.createElement('span');
                dur.className = 'if-audit-item-duration';
                if (v.is_open) {
                    // Süresi olmayan satır GİZLENMEZ: "baktı ama ne kadar
                    // baktığı bilinmiyor" da bir denetim bilgisidir (tarayıcı
                    // kapanmış olabilir, bkz. uçnokta yorumu).
                    dur.classList.add('is-open');
                    dur.textContent = 'süre kaydedilmedi';
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

                var recordId = currentDetailRow.getAttribute('data-record-id');
                if (!recordId || auditLoadedFor === recordId) {
                    return; // Aynı notun geçmişi zaten yüklü — istek atma.
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
                        auditLoadedFor = null; // Tekrar denenebilsin.
                        auditError.hidden = false;
                    });
            });
        }

        // Sekme gizlenince/sayfa terk edilince açık incelemeyi kapat.
        // beforeunload BİLEREK kullanılmadı: mobil tarayıcılarda güvenilmez ve
        // içindeki fetch iptal edilir. visibilitychange + pagehide ikilisi
        // sekme kapatma, pencere kapatma ve başka sayfaya gitmeyi kapsar.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                endNoteView();
            } else if (currentDetailRow) {
                // Kullanıcı sekmeye GERİ döndü ve hâlâ bir not açık — bu YENİ
                // bir incelemedir (istenen davranış: her açılış ayrı kayıt).
                startNoteView(currentDetailRow);
            }
        });
        window.addEventListener('pagehide', endNoteView);

        // Satır tıklama: sağ detay paneli, satırın data-detail-fields JSON'undan
        // (sunucu tarafında bir kez gömülmüş) kurulur — ikinci bir AJAX/sorgu YOK.
        function selectRow(row) {
            // ÖNCE önceki incelemeyi kapat, SONRA yenisini başlat — sıra önemli:
            // tersi olsaydı iki satır aynı anda açık kalırdı.
            endNoteView();

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

            // Geçmiş paneli KAPANIR ve içeriği atılır: açık kalsaydı yeni
            // seçilen notun altında ESKİ notun geçmişi görünmeye devam ederdi.
            resetAuditPanel();

            // Yeni incelemeyi başlat (2 sn eşiğinden sonra, yukarıya bakın).
            startNoteView(row);
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

        // ---- Grupla / Filtrele / Sırala ------------------------------------
        //
        // Kurallar burada YALNIZCA toplanıyor; anlamlandırma (hangi operatör
        // hangi tipte geçerli, SQL nasıl kurulur, gruplar nasıl ağaçlanır)
        // TAMAMEN sunucuda ve grid.php ile AYNI fonksiyonlarda
        // (parse_grid_*_rules -> bcc_build_grid_records_query ->
        // bcc_build_grouped_tree, bkz. public/api/interface_records.php).
        // Üretilen parametre adları da grid.php'ninkiyle birebir aynı.
        var toolsWrap = document.getElementById('if-tools');
        var groupHeaders = []; // istemcide üretilen grup başlığı düğümleri

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
                // Filtre/sıralama için operatör tablosunda karşılığı olmayan
                // tipler (ör. attachment) atlanır — sunucu da onları sessizce
                // eliyor (parse_grid_*_rules), liste boşuna göstermesin.
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

                // Operatör listesi SEÇİLEN ALANIN TİPİNE bağlı — sunucudaki
                // BCC_FILTER_OPERATORS'ın aynısı, ikinci bir tablo yok.
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
                    // empty / not_empty değer almaz.
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

        // Panel position:fixed (bkz. interface.css'teki not: .if-list-panel'in
        // overflow:hidden'ı absolute bir paneli kırpıyordu). Konumu açılışta
        // burada hesaplanıyor — düğmenin altına hizalanır, sağdan taşacaksa
        // pencere içine çekilir.
        function positionToolPanel(details) {
            var panel = details.querySelector('.if-tool-panel');
            var btn = details.querySelector('.if-tool-btn');
            if (!panel || !btn) { return; }

            var r = btn.getBoundingClientRect();
            panel.style.top = (r.bottom + 4) + 'px';
            panel.style.left = r.left + 'px';

            // Genişliği ölçmek için önce yerleştirildi; taşma varsa sola kaydır.
            var pr = panel.getBoundingClientRect();
            var overflowRight = pr.right - (window.innerWidth - 8);
            if (overflowRight > 0) {
                panel.style.left = Math.max(8, r.left - overflowRight) + 'px';
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
                    // Filtrede yeni satır tek başına sonucu değiştirmez (değer
                    // boş) ama sıralama/gruplama hemen etkilidir.
                    if (kind !== 'filter') { apply(); }
                });

                // Filtre paneli VE/VEYA bağlacı — sunucuda tek değer olarak
                // tüm kurallara uygulanır (grid.php ile aynı kısıt).
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

        // Toplanan kuralları grid.php'nin parametre adlarına çevirir.
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

        // Sunucudan gelen sırayı DOM'a uygular: satırlar YENİDEN ÜRETİLMEZ,
        // mevcut düğümler taşınır (detay panelindeki data-detail-fields ve
        // dinleyiciler korunur). Grup başlıkları istemcide üretilir.
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
                    label.textContent = it.label;           // kullanıcı verisi
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

            // noResults kutusu listenin sonunda kalmalı.
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
                    // Yarış koruması: yalnızca EN SON isteğin sonucu uygulanır
                    // (arama kutusundaki debounce deseniyle aynı).
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

        // Arama artık AYNI apply() yolundan geçiyor (public/api/
        // interface_records.php, q parametresi). Eskiden ayrı bir uç nokta
        // (interface_search.php) çağırıp yalnızca satır gizliyordu; filtre/
        // sıralama eklenince iki yol BİRBİRİNİ EZERDİ — arama sonucu sıralamayı,
        // sıralama aramayı geri alırdı. Tek yol olunca arama ile filtre KESİŞİR
        // ve sonuç her zaman aktif sıralama/gruplamayla tutarlı kalır.
        //
        // interface_search.php SİLİNMEDİ: hâlâ kendi başına geçerli bir
        // uç nokta ve interface_records.php aramayı ONUN üzerinden yapıyor
        // (bcc_interface_fetch_records), yani arama mantığı tek yerde.
        if (searchInput && recordList) {
            searchInput.addEventListener('input', debounceApply);
        }

        // İlk yükleme: sunucu satırları zaten doğru sırada bastı, bu yüzden
        // açılışta istek YAPILMIYOR — apply() yalnızca kullanıcı bir kural
        // değiştirdiğinde çalışır.
    });
})();
