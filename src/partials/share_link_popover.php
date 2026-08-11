<?php
// "Bağlantıyı paylaş" popover'ının GÖVDESİ — kopyalanabilir URL kutusu.
//
// TEK KAYNAK: bu blok daha önce ÜÇ yerde birebir kopyalanmıştı
// (grid.php "Bağlantı", grid.php "Paylaş ve Senkronize Et", interface.php
// "Bağlantı") ve yalnızca etiket metniyle URL değişkeni farklıydı. Kutunun
// genişliği/yerleşimi (assets/style.css .share-popover-*) ve kopyalama
// davranışı (assets/share-popover.js, [data-share-url-input] /
// [data-share-copy-btn] kancaları) zaten paylaşılıyordu; artık MARKUP da
// paylaşılıyor — dördüncü bir paylaşım noktası eklendiğinde bu satırlar
// yeniden yazılmayacak.
//
// SARMALAYICI <details> BURADA DEĞİL, ÇAĞIRANDA: üç tetikleyicinin <summary>'si
// birbirinden farklı (grid.php'de metin butonu, grid araç çubuğunda ikonlu
// buton, interface.php'de dikey nav ikonu) ve <details> sınıfları o kabuğa ait.
// Paylaşılan şey yalnızca .share-popover-form gövdesi.
//
// Değişkenler (çağıran hazırlar):
//   $shareLinkUrl   — kutuda gösterilecek/kopyalanacak tam URL (zorunlu)
//   $shareLinkLabel — kutunun üstündeki başlık (opsiyonel, varsayılan aşağıda)
//
// NOT: input readonly ve otomatik-seçme davranışı share-popover.js'te
// [data-share-url-input] üzerinden bağlanıyor — satır içi onclick YOK.

$shareLinkLabel = isset($shareLinkLabel) && $shareLinkLabel !== '' ? $shareLinkLabel : 'Bağlantıyı paylaş';
?>
<div class="share-popover-form">
    <div class="share-popover-label"><?php echo htmlspecialchars($shareLinkLabel, ENT_QUOTES, 'UTF-8'); ?></div>
    <div class="share-popover-row">
        <input type="text" class="share-popover-input" data-share-url-input readonly value="<?php echo htmlspecialchars($shareLinkUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($shareLinkLabel, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="btn-sm" data-share-copy-btn>Kopyala</button>
    </div>
    <p class="share-popover-note">Bu bağlantı yalnızca oturum açmış takım üyeleri için çalışır.</p>
</div>
