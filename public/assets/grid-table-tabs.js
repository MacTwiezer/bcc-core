(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Sekme seçenekleri menüsü, "All tables" menüsü VE toolbar panelleri
        // (Hide fields/Filter/Group/Sort/Row height, class="gs-tool-details") —
        // hepsi <details name="gs-table-tab-menu">. name özniteliği modern
        // tarayıcılarda zaten aynı anda tek birini açık tutuyor, ama eski
        // Firefox/Safari bunu desteklemiyor — bu yüzden "toggle" olayıyla da
        // aynı davranış JS tarafında garanti ediliyor. Ayrıca projedeki ortak
        // "dışarı tıklayınca / Escape ile kapanma" deseni burada uygulanıyor.
        // ⚠️ SEÇİCİ SINIF ADIYLA DEĞİL, name ÖZNİTELİĞİYLE — grup üyeliğini
        // ZATEN tanımlayan şey o. Sınıf listesi tutmak aynı hatanın ÜÇ KEZ
        // tekrarlanmasına yol açtı:
        //   1) Toolbar panelleri (.gs-tool-details) gruba dahil değildi — Group
        //      ve Sort aynı anda açılabiliyor, dışarı tıklayınca kapanmıyordu.
        //   2) .grid-th-menu (sütun başlığı ▾ — Sırala/Filtrele/Grupla) hiç
        //      eklenmemişti; grid-column-menu.js'nin yorumu "bu grupta dışarı-tık
        //      dinleyicisi zaten hiçbirinde yok" derken doğruydu, sonra grup
        //      yönetimi eklendi ama o sınıf seçiciye alınmadı.
        //   3) .gs-view-create-menu ("+ Yeni oluştur..." tip seçici) — Grup
        //      View-Form ile eklendi, aynı sebeple kapsam dışı kaldı.
        // name="gs-table-tab-menu" taşıyan HER <details> artık otomatik dahil;
        // ileride eklenecek bir menü bu listeye yazılmayı UNUTAMAZ.
        // KARŞILIKLI DIŞLAMA + DIŞARI-TIK + ESCAPE BURADAN KALDIRILDI.
        //
        // Aynı davranış artık assets/dismissable-panel.js'te GENEL olarak
        // uygulanıyor ve sayfadaki HER <details>'i kayıt gerektirmeden
        // kapsıyor. Buradaki sürüm yalnızca name="gs-table-tab-menu" grubunu
        // tanıyordu ve bu dosya grid.php/kanban.php dışında yüklenmiyordu —
        // ölçülen sonuç: interface.php'deki üç popover'ın (nav menüsü,
        // "Paylaş", "Bağlantı") dışarı-tık ve Escape davranışı HİÇ YOKTU.
        //
        // Erken `if (!menus.length) return;` çıkışı da kalktı: bu dosyanın geri
        // kalanı (tüm-tablolar araması, görünüm yeniden adlandırma, Ctrl+J)
        // menü SAYISINA bağlı değildi, ama o guard menü yokken hepsini birden
        // devre dışı bırakıyordu.

        // "All tables" paneli: açılınca odak arama kutusuna gider, yazdıkça
        // istemci tarafında anlık filtreler (sunucuya gitmeden — liste zaten
        // $siblingTables'tan tek kaynaktan basılmış DOM'da).
        var allTablesMenu = document.querySelector('.gs-all-tables-menu');
        var searchInput = document.querySelector('[data-all-tables-search]');
        var rows = document.querySelectorAll('[data-all-tables-row]');
        var emptyRow = document.querySelector('[data-all-tables-empty]');

        if (allTablesMenu && searchInput) {
            allTablesMenu.addEventListener('toggle', function () {
                if (allTablesMenu.open) {
                    searchInput.focus();
                }
            });
        }

        // Mac'te rozet metni "⌘ J", diğerlerinde "Ctrl J" (bkz. .gs-kbd-mac / .gs-kbd-other CSS'i).
        if (/Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '')) {
            document.documentElement.classList.add('is-mac');
        }

        document.addEventListener('keydown', function (e) {
            // Escape dalı KALDIRILDI — menüleri artık ortak otomatik dismiss
            // kapatıyor (assets/dismissable-panel.js). Bu dinleyici yalnızca
            // Ctrl+J kısayolu için duruyor.

            // Ctrl+J (Windows/Linux) veya ⌘+J (Mac): "All tables" panelini aç/kapat.
            // Hücre düzenlerken (input/textarea/contenteditable odaktayken) tetiklenmemeli.
            if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'j') {
                return;
            }

            var targetTag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
            if (targetTag === 'input' || targetTag === 'textarea' || (e.target && e.target.isContentEditable)) {
                return;
            }

            if (!allTablesMenu) {
                return;
            }

            e.preventDefault();
            allTablesMenu.open = !allTablesMenu.open;
        });

        if (searchInput && rows.length) {
            searchInput.addEventListener('input', function () {
                // Türkçe I/ı, İ/i tuzağı: varsayılan toLowerCase() yerine
                // toLocaleLowerCase('tr') kullanılır (bkz. is/İstanbul örneği).
                var q = searchInput.value.trim().toLocaleLowerCase('tr');
                var anyVisible = false;

                rows.forEach(function (row) {
                    var name = row.textContent.trim().toLocaleLowerCase('tr');
                    var match = q === '' || name.indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) {
                        anyVisible = true;
                    }
                });

                if (emptyRow) {
                    emptyRow.hidden = anyVisible;
                }
            });
        }

        // Görünüm adını satır içi düzenleme — PAYLAŞILAN fonksiyon
        // (window.bcc_startViewRename, bcc_bindColumnDrag/bcc_bindDismissable
        // ile AYNI "global yardımcı" deseni): hem araç çubuğundaki dblclick
        // (aşağıda) HEM sol paneldeki her satırın "Yeniden adlandır" menü
        // öğesi (grid-view-manage.js) AYNI fonksiyonu çağırır — ikinci bir
        // düzenleme akışı YOK. Bir view'ın adı değiştiğinde, o view_id'yi
        // taşıyan TÜM elemanlar (data-view-sync-id — araç çubuğu etiketi,
        // bilgi popover başlığı, sol paneldeki satır) senkron güncellenir;
        // önceki "tek mirror" hardcode'u yerine evrensel bir eşleşme.
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        window.bcc_startViewRename = function (nameEl, viewId) {
            var editing = false;
            var cancelled = false;

            if (nameEl.getAttribute('data-view-renaming') === '1') {
                return; // zaten düzenleniyor
            }
            nameEl.setAttribute('data-view-renaming', '1');
            editing = true;

            var originalName = nameEl.textContent;
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'gs-view-name-input';
            input.value = originalName;

            nameEl.replaceWith(input);
            input.focus();
            input.select();

            function applyName(name) {
                nameEl.textContent = name;
                Array.prototype.forEach.call(document.querySelectorAll('[data-view-sync-id="' + viewId + '"]'), function (el) {
                    el.textContent = name;
                });
            }

            function finishEditing(save) {
                if (!editing) {
                    return;
                }
                editing = false;
                nameEl.removeAttribute('data-view-renaming');

                var newValue = input.value.trim();
                input.replaceWith(nameEl);

                if (!save || newValue === '' || newValue === originalName) {
                    nameEl.textContent = originalName;
                    return;
                }

                nameEl.textContent = newValue; // iyimser güncelleme

                fetch('/api/view_rename.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: CSRF, view_id: viewId, name: newValue }).toString(),
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { ok: false, error: 'Sunucu beklenmeyen bir yanıt döndürdü.' };
                    }).then(function (data) {
                        return { httpOk: res.ok, data: data };
                    });
                }).then(function (result) {
                    if (result.httpOk && result.data && result.data.ok) {
                        applyName(result.data.name);
                    } else {
                        nameEl.textContent = originalName;
                        window.alert((result.data && result.data.error) || 'Görünüm adı kaydedilemedi.');
                    }
                }).catch(function () {
                    nameEl.textContent = originalName;
                    window.alert('Görünüm adı kaydedilemedi (bağlantı hatası).');
                });
            }

            input.addEventListener('keydown', function (ke) {
                if (ke.key === 'Enter') {
                    ke.preventDefault();
                    finishEditing(true);
                } else if (ke.key === 'Escape') {
                    // Escape'te ÖNCE bayrak koy: replaceWith input'u DOM'dan
                    // kaldırırken tetiklediği blur, kaydetmeyi tekrar denememeli.
                    ke.preventDefault();
                    cancelled = true;
                    finishEditing(false);
                }
            });

            input.addEventListener('blur', function () {
                if (cancelled) {
                    return;
                }
                finishEditing(true);
            });
        };

        // data-view-id yalnızca editor+ rolünde grid.php tarafından basılır
        // (bkz. $canEdit) — viewer'da bu eleman hiç bulunmaz, dblclick
        // dinleyicisi hiç bağlanmaz. Sunucu tarafında da /api/view_rename.php
        // require_role('editor') ile ayrıca reddeder.
        // Bulunan kırılgan kod: seçici yalnızca "[data-view-id]" olduğunda
        // sol paneldeki her satırın yıldız/yeniden adlandır/sil butunları da
        // AYNI özniteliği taşıdığından (bkz. grid.php ~1103/1119/1121) yanlışlıkla
        // BUNLARDAN birini seçebilirdi — şu an yalnızca DOM sırası (araç çubuğu
        // etiketi dosyada önce geliyor) sayesinde doğru çalışıyor. .gs-view-name
        // ile daraltılarak bu kırılganlık ortadan kaldırıldı.
        var viewNameEl = document.querySelector('.gs-view-name[data-view-id]');
        if (viewNameEl) {
            var toolbarViewId = viewNameEl.getAttribute('data-view-id');
            viewNameEl.addEventListener('dblclick', function () {
                window.bcc_startViewRename(viewNameEl, toolbarViewId);
            });
        }
    });
})();
