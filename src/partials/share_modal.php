<?php
// "Paylaş" modalı — Airtable'ın Collaborators diyaloğunun karşılığı.
// grid.php'nin "Paylaş" popover'ındaki "N kişinin erişimi var" bağlantısı ARTIK
// team_members.php'ye YÖNLENDİRMİYOR; bu overlay'i açıyor (assets/share-modal.js).
//
// Değişkenler (çağıran sayfa hazırlar):
//   $shareModalTeamName  — başlıkta gösterilecek çalışma alanı/base adı
//   $shareModalPayload   — bcc_share_modal_payload() sonucu (JSON'a çevrilip
//                          BCC_SHARE_MODAL global'ine yazılır)
//   $shareModalTeamId    — "Tüm üye ayarları" bağlantısı için
//
// BURADA LİSTE BASILMAZ: katılımcı/bekleyen satırlarını share-modal.js'in
// renderLists()'i basar — hem ilk açılışta hem her mutasyondan sonra AYNI
// fonksiyon. İkinci bir şablon olsaydı (PHP'de bir kez, JS'te bir kez) ilk
// render ile güncellenmiş render ilk değişiklikte ayrışırdı.
//
// BACKDROP SINIFI YENİDEN KULLANILIYOR: .gs-view-desc-overlay (grid-shell.css)
// — "Görünüm açıklaması" ve "Veri içe aktar" modallarının AYNI kanıtlanmış
// kutusu. Böylece yazdırma/PNG'de gizlenmesi de bedavaya geliyor
// (grid-export.css zaten bu sınıfı gizliyor). Yalnızca iç kutu farklı
// (.gs-share-modal — daha geniş, sekmeli).
?>
<div class="gs-view-desc-overlay gs-share-overlay" id="gs-share-overlay" hidden>
    <div class="gs-share-modal" role="dialog" aria-modal="true" aria-labelledby="gs-share-modal-title">
        <div class="gs-share-head">
            <div>
                <div class="gs-share-title" id="gs-share-modal-title">
                    "<?php echo htmlspecialchars($shareModalTeamName, ENT_QUOTES, 'UTF-8'); ?>" paylaşım ayarları
                </div>
                <div class="gs-share-subtitle" data-share-subtitle></div>
            </div>
            <button type="button" class="gs-share-close" id="gs-share-close" aria-label="Kapat">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>
        </div>

        <?php // Davet kutusu YALNIZCA yetkiliye basılır (sunucu hiç göndermez —
              // CSS ile gizlenmiş bir form değil). Yetkisiz kullanıcı için
              // yerine tek satırlık gerekçe: sessizce eksik bir arayüz "bozuk"
              // gibi okunur (team_members.php'deki .tm-readonly-note ile AYNI karar). ?>
        <div class="gs-share-invite" data-share-invite hidden>
            <div class="gs-share-invite-row">
                <input
                    type="email"
                    class="gs-share-invite-email"
                    data-share-invite-email
                    list="gs-share-invite-suggestions"
                    placeholder="E-posta adresi ekleyin"
                    aria-label="Davet edilecek e-posta"
                    autocomplete="off"
                >
                <?php // <datalist>: takımda HENÜZ olmayan aktif kullanıcılar —
                      // Airtable'ın typeahead'i gibi davranır ama serbest metin
                      // girişini de engellemez (sunucu e-postayı yine çözer). ?>
                <datalist id="gs-share-invite-suggestions" data-share-suggestions></datalist>
                <select class="gs-share-invite-role" data-share-invite-role aria-label="Rol"></select>
                <button type="button" class="gs-btn-primary gs-share-invite-btn" data-share-invite-btn>Davet Et</button>
            </div>
            <p class="gs-share-invite-note">
                Yalnızca sistemde <strong>hesabı olan</strong> kullanıcılar eklenebilir.
                Yeni hesap oluşturma platform yöneticisindedir.
            </p>
        </div>

        <p class="gs-share-readonly-note" data-share-readonly-note hidden></p>

        <div class="gs-share-status" data-share-status hidden></div>

        <div class="gs-share-tabs" role="tablist">
            <button type="button" class="gs-share-tab is-active" data-share-tab="collaborators" role="tab" aria-selected="true">
                Katılımcılar <span class="gs-share-tab-count" data-share-count-collaborators>0</span>
            </button>
            <button type="button" class="gs-share-tab" data-share-tab="pending" role="tab" aria-selected="false">
                Bekleyen davetler <span class="gs-share-tab-count" data-share-count-pending>0</span>
            </button>
        </div>

        <div class="gs-share-list" data-share-panel="collaborators" role="tabpanel"></div>
        <div class="gs-share-list" data-share-panel="pending" role="tabpanel" hidden></div>

        <div class="gs-share-foot">
            <?php // Tam yönetim ekranı (arama, rol filtresi, toplu çıkarma,
                  // Excel indir, "Ekleyen"/"Eklenme tarihi" kolonları) hâlâ
                  // team_members.php'de YAŞIYOR — modal onun yerini almıyor,
                  // sık yapılan işi sayfadan çıkmadan yapılabilir kılıyor. ?>
            <a class="gs-share-foot-link" href="/team_members.php?team_id=<?php echo (int) $shareModalTeamId; ?>">Tüm üye ayarları →</a>
        </div>
    </div>
</div>
<script>
    var BCC_SHARE_MODAL = <?php echo json_encode($shareModalPayload, JSON_UNESCAPED_UNICODE); ?>;
    var BCC_SHARE_CANDIDATES = <?php echo json_encode($shareModalCandidates, JSON_UNESCAPED_UNICODE); ?>;
</script>
