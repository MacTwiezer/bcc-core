<?php
// "Yeni Çalışma Alanı" (= ekip) oluşturma modalı — PAYLAŞILAN markup.
//
// İKİ sayfa kullanıyor: public/workspaces.php (sol panel alt bilgisindeki
// "Yeni Çalışma Alanı" butonu) ve public/admin/index.php ("+ Yeni ekip
// oluştur"). Markup iki yere KOPYALANMADI — share_link_popover.php /
// share-popover.js ikilisiyle AYNI desen (ortak partial + ortak davranış).
//
// ⚠️ ÇAĞIRAN SAYFA YETKİYİ KENDİ KONTROL ETMELİ: bu dosya require_admin()
// ÇAĞIRMAZ, çünkü ikisi de modalı zaten yalnızca admine basıyor. Asıl kapı
// public/api/team_create.php'deki is_admin kontrolüdür — modal görünmese bile
// uçnokta kendini korur.
//
// Görünüm home.css'in .home-modal-* bileşeninden geliyor (kabuk her sayfaya
// home.css yüklüyor) — bu modal için İKİNCİ bir stil seti YAZILMADI.
//
// JS YOKSA NE OLUR: modal `hidden` olduğu için hiç görünmez ve tetikleyici
// bağlantı kendi href'iyle /admin/create_team.php sayfasına gider — yani akış
// JS'siz de tamamlanır. Aşağıdaki <form> de gerçek bir action/method taşır
// (dashboard.php'deki base modalıyla aynı gerekçe).
?>
<div class="home-modal-backdrop" id="create-team-modal" hidden>
    <div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="create-team-title">
        <div class="home-modal-head">
            <h2 id="create-team-title">Yeni Çalışma Alanı Oluştur</h2>
            <button type="button" class="home-modal-close" data-create-team-close aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <form class="home-modal-form" id="create-team-form" method="post" action="/admin/create_team.php">
            <?php echo csrf_field(); ?>

            <label class="home-modal-field">
                <span class="home-modal-label">Çalışma alanı adı</span>
                <input type="text" name="name" class="home-modal-input" maxlength="150" required autocomplete="off" placeholder="Örn. Pazarlama">
            </label>

            <?php // Oluşturan kişi otomatik olarak bu alanın Owner'ı olur
                  // (bcc_create_team). Kullanıcı bunu bilmeden "oluşturdum ama
                  // göremiyorum" durumuna düşmesin diye açıkça yazılıyor. ?>
            <p class="home-modal-hint">Bu alanın <strong>Owner</strong>'ı olarak eklenirsiniz.</p>

            <p class="home-modal-error" id="create-team-error" hidden></p>

            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" data-create-team-close>Vazgeç</button>
                <button type="submit" class="home-modal-btn home-modal-btn-primary">Oluştur</button>
            </div>
        </form>
    </div>
</div>
