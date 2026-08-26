<?php
// "Paylaş" popover'ının GÖVDESİ — grid.php ve interface.php TARAFINDAN PAYLAŞILIR.
//
// NEDEN VAR (denetimde bulundu): iki sayfada 20 satır birebir aynıydı — başlık,
// "Katılımcı ekle" düğmesi / yetkisiz notu, avatar önizlemesi ve "N kişinin
// erişimi var" özeti. Yanı başındaki share_link_popover.php ve share_modal.php
// zaten partial'dı; bu blok atlanmıştı. İki kopyanın yorumları bile birbirine
// "grid.php ile AYNI" diye atıf yapıyordu, yani ayrışma riski biliniyordu.
//
// DIŞ SARMALAYICI BURAYA GİRMEZ: grid.php'de metin düğmeli bir <details>,
// interface.php'de ikonlu ve farklı konumlanan bir <details> var — onlar
// GERÇEKTEN farklı, ortaklaştırmak ikisini de bozardı. Ortak olan yalnızca
// gövde.
//
// Beklenen değişkenler (çağıran sayfa hazırlar):
//   $collabPopoverTitle          - string, başlıkta tırnak içinde geçecek ad
//                                  (grid: tablonun base adı, interface: base adı)
//   $canManageMembers            - bool, "Katılımcı ekle" yolunun basılıp
//                                  basılmayacağı (sunucu tarafı gate)
//   $shareCollaboratorPreview    - bcc_share_modal_payload()'dan gelen ilk N
//                                  katılımcı ('name' / 'initial' anahtarlarıyla)
//   $shareCollaborators          - tüm katılımcılar (yalnızca sayılır)
//   $shareCollaboratorExtraCount - önizlemeye sığmayan katılımcı sayısı
?>
<div class="collab-popover-form">
    <div class="collab-popover-title">"<?php echo htmlspecialchars($collabPopoverTitle, ENT_QUOTES, 'UTF-8'); ?>" paylaş</div>

    <?php if ($canManageMembers): ?>
        <?php // Eskiden burada team_members.php'ye TAM SAYFA POST eden bir kullanıcı
              // seçici + rol <select> vardı; gönderim sayfayı terk ediyordu. Aynı iş
              // (e-posta + rol + Davet Et) artık modalın davet kutusunda, yönlendirme
              // olmadan yapılıyor. ?>
        <button type="button" class="collab-popover-add-btn" data-share-modal-open>Katılımcı ekle</button>
    <?php else: ?>
        <?php // Owner değil: ekleme yolu HİÇ basılmaz (sunucu tarafı gate, CSS ile
              // gizlenmiş bir form değil). Katılımcı listesi görünür kalır — kimin
              // erişimi olduğunu görmek yetki gerektirmez, modal da salt-okunur açılır. ?>
        <p class="collab-popover-note">Katılımcı eklemek için Owner yetkisi gerekir.</p>
    <?php endif; ?>

    <?php // ARTIK YÖNLENDİRME YOK: bu satır eskiden team_members.php'ye giden bir
          // <a> idi ve kullanıcıyı ekrandan çıkarıyordu. Şimdi aynı sayfada "Paylaş"
          // modalını açıyor (share_modal.php + assets/share-modal.js). <a href>
          // yerine <button>: yönlendirme kalkınca gidilecek adresi olmayan bir
          // bağlantı bırakmak yanlış olurdu. Tam yönetim ekranı kaybolmadı —
          // modalın altındaki "Tüm üye ayarları →" hâlâ oraya gidiyor. ?>
    <button type="button" class="collab-popover-people" data-share-modal-open>
        <div class="collab-popover-avatars">
            <?php // Satırlar bcc_share_modal_payload()'dan geliyor: 'name' / 'initial'
                  // anahtarları orada hazırlanmış (modaldakiyle AYNI kaynak). ?>
            <?php foreach ($shareCollaboratorPreview as $c): ?>
                <div class="ws-collab-avatar collab-popover-avatar" title="<?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c['initial'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
        <?php // data-share-people-label: modalda biri eklenip çıkarıldığında
              // share-modal.js bu özeti de tazeliyor (arkadaki sayı bayatlamasın). ?>
        <span class="collab-popover-people-label" data-share-people-label>
            <?php echo count($shareCollaborators); ?> kişinin erişimi var<?php echo $shareCollaboratorExtraCount > 0 ? ' (+' . (int) $shareCollaboratorExtraCount . ')' : ''; ?>
        </span>
    </button>
</div>
