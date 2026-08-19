<?php
// OpsFlow marka işareti — TEK KAYNAK.
//
// Eskiden burada assets/logo.png vardı: içinde "bcc" harfleri BASILI olan bir
// bitmap. Marka adı değiştiğinde o dosyanın pikselleri değişmediği için üst
// barda ve giriş/kayıt/doğrulama sayfalarında ESKİ ad görünmeye devam ediyordu
// — sayfa başlıkları çoktan "opsflow.bcccrm.com" iken logo hâlâ "bcc"ydi.
//
// Neden <img> değil de SATIR İÇİ <svg>: kelime işareti currentColor ile
// çiziliyor, yani .home-logo'nun devraldığı --bcc-text rengini alıyor ve KOYU
// TEMADA da okunur kalıyor. Sabit renkli bir PNG/SVG dosyası koyu zeminde
// kaybolurdu (eski logo bunu yalnızca çok renkli olduğu için atlatıyordu).
//
// Ad metni bcc_brand_name()'den gelir (config/app.php) — bu dosyada da literal
// marka yazmaz.
//
// $brandLogoClass: çağıran sayfanın vereceği sınıf (ör. topbar'da yok, giriş
// sayfalarında ölçüyü login.css sürüyor). $brandLogoHeight: piksel yüksekliği.
$brandLogoClass = isset($brandLogoClass) ? $brandLogoClass : '';
$brandLogoHeight = isset($brandLogoHeight) ? (int) $brandLogoHeight : 32;
$brandLogoName = bcc_brand_name();
?>
<svg
    class="brand-logo <?php echo htmlspecialchars($brandLogoClass, ENT_QUOTES, 'UTF-8'); ?>"
    viewBox="0 0 150 32"
    height="<?php echo $brandLogoHeight; ?>"
    width="<?php echo (int) round($brandLogoHeight * 150 / 32); ?>"
    role="img"
    aria-label="<?php echo htmlspecialchars($brandLogoName, ENT_QUOTES, 'UTF-8'); ?>"
    xmlns="http://www.w3.org/2000/svg"
>
    <?php // Rozet: marka aksanı. Renk temayla değişsin diye --bcc-accent. ?>
    <rect x="0" y="2" width="28" height="28" rx="8" fill="var(--bcc-accent, #2d7ff9)"></rect>
    <?php // İçindeki "akış" glifi: üst üste iki yay — "ops" akışını temsil eder.
          // Beyaz, çünkü dolu aksan zeminin üstünde her iki temada da aynı. ?>
    <g fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round">
        <path d="M8 12h9a3.5 3.5 0 0 1 0 7H8"></path>
        <path d="M8 23h12"></path>
    </g>
    <?php // Kelime işareti: currentColor -> .home-logo'nun metin rengi. ?>
    <text
        x="38"
        y="22"
        fill="currentColor"
        font-family="system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"
        font-size="19"
        font-weight="600"
        letter-spacing="-0.3"
    ><?php echo htmlspecialchars($brandLogoName, ENT_QUOTES, 'UTF-8'); ?></text>
</svg>
