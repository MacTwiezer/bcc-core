(function () {
    'use strict';

    // workspaces.php'ye ÖZEL: katılımcı ızgarasına arama kutusu ekler.
    //
    // table-fields.js'teki AYNI desen (kutuyu çalışma anında oluştur, eşleşmeyeni
    // ayrı bir sınıfla gizle) — orada alan tipleri, burada katılımcılar. İki
    // liste farklı DOM'da ve farklı sayfalarda yaşadığı için ortak bir "liste
    // filtresi" soyutlaması YAZILMADI; iki küçük dosya, tek bir erken
    // soyutlamadan daha okunur.

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.getElementById('wsx-collab-grid');
        var head = document.getElementById('wsx-collab-head');
        if (!grid || !head) {
            return;
        }

        var members = Array.prototype.slice.call(grid.querySelectorAll('.wsx-member'));
        // Kısa listede arama kutusu gürültüdür.
        if (members.length < 8) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'wsx-search';
        wrap.innerHTML = '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
            + '<circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.5"/>'
            + '<path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

        var input = document.createElement('input');
        input.type = 'search';
        input.autocomplete = 'off';
        input.placeholder = 'Katılımcı ara…';
        input.setAttribute('aria-label', 'Katılımcı ara');
        wrap.appendChild(input);
        head.appendChild(wrap);

        var empty = document.createElement('p');
        empty.className = 'wsx-collab-empty';
        empty.textContent = 'Eşleşen katılımcı yok.';
        empty.hidden = true;
        grid.parentNode.insertBefore(empty, grid.nextSibling);

        // Ad + e-posta bir kez okunup küçük harfe çevriliyor (her tuş vuruşunda
        // DOM'dan tekrar okunmuyor). Türkçe için toLocaleLowerCase('tr').
        var haystacks = members.map(function (row) {
            var name = row.querySelector('.wsx-member-name');
            var mail = row.querySelector('.wsx-member-mail');
            return ((name ? name.textContent : '') + ' ' + (mail ? mail.textContent : '')).toLocaleLowerCase('tr');
        });

        input.addEventListener('input', function () {
            var q = input.value.trim().toLocaleLowerCase('tr');
            var visible = 0;

            members.forEach(function (row, i) {
                var match = q === '' || haystacks[i].indexOf(q) !== -1;
                // [hidden] DEĞİL ayrı bir sınıf: .wsx-collab-grid display:grid,
                // ve grid öğelerine uygulanan display [hidden]'ın display:none'ını
                // ezer (projede birkaç kez yaşanan tuzak).
                row.classList.toggle('wsx-hidden', !match);
                if (match) {
                    visible++;
                }
            });

            empty.hidden = visible !== 0;
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                e.stopPropagation();
                input.value = '';
                input.dispatchEvent(new Event('input'));
            }
        });
    });
})();

(function () {
    'use strict';

    // workspaces.php "Hızlı davet" kutusu.
    //
    // KENDİ UÇ NOKTASI YOK: api/team_member_assign.php'ye POST ediyor — grid.php'nin
    // "Paylaş" modalının kullandığı AYNI uç nokta. Yani hiyerarşi kapısı
    // (rank(hedef) <= rank(ben)), atanabilir rol whitelist'i, "hesap yok / hesap
    // doğrulanmamış" ayrımı ve audit kaydı ikinci kez YAZILMADI; hepsi
    // bcc_team_member_assign() (src/schema.php) üzerinden geliyor.
    //
    // Kutu yalnızca Owner'a basılıyor (sunucu tarafında, CSS ile gizlenmiyor) —
    // buradaki kod da o yüzden eleman yoksa sessizce çıkar.
    //
    // BAŞARIDA SAYFA YENİLENİYOR: katılımcı ızgarası, sayaçlar ve hareket akışı
    // hep sunucudan basılıyor; bunları JS'te tek tek güncellemek üç ayrı render
    // yolu (ve üç ayrı ayrışma riski) demekti. Tek bir reload hepsini tutarlı
    // kılıyor — davet nadir bir eylem, maliyeti önemsiz.
    document.addEventListener('DOMContentLoaded', function () {
        var box = document.querySelector('[data-ws-invite]');
        if (!box) {
            return;
        }

        var email = box.querySelector('[data-ws-invite-email]');
        var role = box.querySelector('[data-ws-invite-role]');
        var btn = box.querySelector('[data-ws-invite-btn]');
        var note = document.querySelector('[data-ws-invite-note]');
        var teamId = box.getAttribute('data-team-id');
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var CSRF = csrfMeta ? csrfMeta.content : '';

        // Kutunun altındaki açıklama satırı aynı zamanda sonuç satırı — ikinci
        // bir "durum" elemanı eklenmedi. Varsayılan metin geri dönebilsin diye
        // bir kez saklanıyor (grid'deki kopyala butonunun etiket hatasıyla AYNI
        // ders: gerçek varsayılan yalnızca BİR KEZ okunmalı).
        var defaultNote = note ? note.textContent : '';

        function setNote(message, state) {
            if (!note) {
                return;
            }
            note.textContent = message;
            note.classList.toggle('is-error', state === 'error');
            note.classList.toggle('is-ok', state === 'ok');
        }

        function invite() {
            var value = (email.value || '').trim();
            if (value === '') {
                setNote('Bir e-posta adresi girin.', 'error');
                email.focus();
                return;
            }

            box.classList.add('is-busy');
            setNote('Ekleniyor...', null);

            fetch('/api/team_member_assign.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: CSRF,
                    team_id: teamId,
                    email: value,
                    role: role.value,
                }).toString(),
            }).then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            }).then(function (result) {
                box.classList.remove('is-busy');

                if (!result.ok || !result.data || !result.data.ok) {
                    // Reddedilen istekte sayfa DEĞİŞMEZ: sunucu hiçbir şey
                    // yazmadı, ekrandaki liste hâlâ doğru.
                    setNote((result.data && result.data.error) || 'Katılımcı eklenemedi.', 'error');
                    return;
                }

                setNote(result.data.message || 'Katılımcı eklendi.', 'ok');
                email.value = '';
                window.location.reload();
            }).catch(function () {
                box.classList.remove('is-busy');
                setNote('Katılımcı eklenemedi (bağlantı hatası).', 'error');
            });
        }

        btn.addEventListener('click', invite);

        email.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                invite();
            }
        });

        // Kullanıcı yazmaya başlayınca eski hata mesajı kalkar.
        email.addEventListener('input', function () {
            if (note && note.textContent !== defaultNote) {
                setNote(defaultNote, null);
            }
        });
    });
})();

(function () {
    'use strict';

    // Sol paneldeki ÇALIŞMA ALANI araması.
    //
    // Katılımcı aramasından (bu dosyanın üstündeki blok) AYRI tutuldu: o kutu
    // çalışma anında ÜRETİLİYOR ve yalnızca liste 8'den uzunsa görünüyor
    // ("kısa listede arama kutusu gürültüdür"); bu kutu ise panelin KALICI bir
    // parçası — sunucu her zaman basıyor, çünkü panelin üst kenarını tanımlayan
    // ve tek/iki çalışma alanında bile sütunu "bitmiş" gösteren yapı ondan
    // geçiyor. İki farklı liste, iki farklı gereksinim; ortak bir soyutlama
    // YAZILMADI (iki küçük blok, erken bir soyutlamadan okunur).
    document.addEventListener('DOMContentLoaded', function () {
        var input = document.querySelector('[data-wsx-team-search]');
        var list = document.querySelector('[data-wsx-team-list]');
        if (!input || !list) {
            return;
        }

        var rows = Array.prototype.slice.call(list.querySelectorAll('.wsx-card'));
        var empty = list.querySelector('[data-wsx-team-empty]');

        // Arama anahtarı SUNUCUDAN geliyor (data-wsx-team-name, küçük harfe
        // çevrilmiş) — DOM metnini okuyup her tuşta normalize etmek yerine.
        var keys = rows.map(function (row) {
            return row.getAttribute('data-wsx-team-name') || '';
        });

        function apply() {
            var q = input.value.trim().toLowerCase();
            var visible = 0;

            rows.forEach(function (row, i) {
                var match = q === '' || keys[i].indexOf(q) !== -1;
                // [hidden] DEĞİL ayrı sınıf: liste flex, flex öğesine uygulanan
                // display [hidden]'ın display:none'ını ezer.
                row.classList.toggle('wsx-team-hidden', !match);
                if (match) {
                    visible++;
                }
            });

            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        input.addEventListener('input', apply);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value !== '') {
                // stopPropagation: sayfadaki diğer Escape dinleyicileri
                // (ör. bcc_bindDismissable) bu tuşu ayrıca yorumlamasın.
                e.stopPropagation();
                input.value = '';
                apply();
            }
        });
    });
})();
