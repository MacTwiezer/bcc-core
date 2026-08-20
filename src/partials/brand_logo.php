<?php
// Marka işareti — TEK KAYNAK. Beş auth sayfası kullanır (login, register,
// forgot-password, reset-password, verify_email).
//
// YALNIZCA kurumsal logo (assets/logo.png) — yanında ürün adı YAZILMAZ.
// Aynı görsel üst barda (src/partials/home_shell_top.php) ve sekme ikonunda
// (assets/favicon.svg, kare tuvale gömülü hâli) da kullanılıyor: üç yüzey tek
// görseli paylaşır.
//
// ⚠️ ÖNCEKİ İKİ HÂLİ: (1) satır içi SVG rozet + kelime işareti, (2) logo +
// "OpsFlow" yazısı yan yana. İkisi de bırakıldı — logo tek başına duruyor.
// Bu yüzden bcc_brand_name() BURADA KULLANILMIYOR; marka adı sayfa
// başlığında (<title>) ve alt bilgideki bcc_brand_full() satırında zaten var.
//
// $brandLogoClass: çağıran sayfanın vereceği sınıf. $brandLogoHeight: logonun
// piksel yüksekliği.
$brandLogoClass = isset($brandLogoClass) ? $brandLogoClass : '';
$brandLogoHeight = isset($brandLogoHeight) ? (int) $brandLogoHeight : 32;
?>
<?php // alt DOLU: yanında artık markayı söyleyen bir yazı YOK, o yüzden görsel
      // dekoratif değil — ekran okuyucu için tek marka bilgisi bu.
      // width/height GERÇEK orandan (94x44) hesaplanıyor, yoksa görsel
      // yüklenirken sayfa zıplardı. ?>
<img
    src="<?php echo bcc_asset_url('logo.png'); ?>"
    alt="<?php echo htmlspecialchars(bcc_brand_name(), ENT_QUOTES, 'UTF-8'); ?>"
    height="<?php echo $brandLogoHeight; ?>"
    width="<?php echo (int) round($brandLogoHeight * 94 / 44); ?>"
    class="brand-logo <?php echo htmlspecialchars($brandLogoClass, ENT_QUOTES, 'UTF-8'); ?>"
>
