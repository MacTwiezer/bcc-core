<?php
// "Yeni Base Oluştur" modalı — PAYLAŞILAN markup.
//
// İKİ sayfa kullanıyor: public/dashboard.php ("+ Yeni Base Oluştur" kutucuğu ve
// boş durum butonu) ve public/workspaces.php (çalışma alanı başlığındaki
// "Base oluştur" + "Base'ler" kartındaki "+ Yeni base"). Markup iki yere
// KOPYALANMADI — create_team_modal.php ile AYNI desen.
//
// Değişkenler (çağıran sayfa hazırlar):
//   $creatableTeams             — [{id, name, role}] : kullanıcının base
//                                 EKLEYEBİLDİĞİ çalışma alanları
//                                 (bcc_can_manage_bases ile süzülmüş)
//   $createBaseSelectedTeamId   — (OPSİYONEL) açılır listede ÖN SEÇİLİ gelecek
//                                 çalışma alanı. workspaces.php verir: kullanıcı
//                                 zaten O alanın sayfasında, varsayılanın başka
//                                 bir alan olması şaşırtıcı olurdu. Liste yine
//                                 de kilitlenmez — istenirse başka alan seçilir.
//
// ⚠️ ÇAĞIRAN SAYFA YETKİYİ KENDİ KONTROL ETMELİ: bu dosya rol kontrolü
// ÇAĞIRMAZ; iki sayfa da modalı zaten yalnızca yetkiliye basıyor
// ($canCreateBase). Asıl kapı public/api/base_create.php'dir — modal görünmese
// bile uçnokta kendini korur (gizleme != yetkilendirme).
//
// Görünüm home.css'in .home-modal-* bileşeninden geliyor (kabuk her sayfaya
// home.css yüklüyor) — bu modal için İKİNCİ bir stil seti YAZILMADI.
//
// JS YOKSA NE OLUR: <form> gerçek bir action/method taşıdığı için düz bir form
// olarak /bases.php'ye POST eder ve akış orada tamamlanır; home.js submit'i
// araya girip AJAX'a çevirir (bkz. assets/home.js).

$createBaseSelected = isset($createBaseSelectedTeamId) ? (int) $createBaseSelectedTeamId : 0;
?>
<div class="home-modal-backdrop" id="home-create-base-modal" hidden>
    <div class="home-modal" role="dialog" aria-modal="true" aria-labelledby="home-create-base-title">
        <div class="home-modal-head">
            <h2 id="home-create-base-title">Yeni Base Oluştur</h2>
            <button type="button" class="home-modal-close" id="home-create-base-close" aria-label="Kapat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <form class="home-modal-form" id="home-create-base-form" method="post" action="/bases.php">
            <?php echo csrf_field(); ?>

            <label class="home-modal-field">
                <span class="home-modal-label">Çalışma alanı</span>
                <?php
                // Tek seçenek varsa açılır liste yerine sabit metin + hidden
                // input — OpsFlow da tek çalışma alanı olan kullanıcıya seçici
                // göstermiyor.
                ?>
                <?php if (count($creatableTeams) === 1): ?>
                    <input type="hidden" name="team_id" value="<?php echo (int) $creatableTeams[0]['id']; ?>">
                    <span class="home-modal-static"><?php echo htmlspecialchars($creatableTeams[0]['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php else: ?>
                    <select name="team_id" class="home-modal-input" required>
                        <?php foreach ($creatableTeams as $ct): ?>
                            <option
                                value="<?php echo (int) $ct['id']; ?>"
                                <?php echo ((int) $ct['id'] === $createBaseSelected) ? ' selected' : ''; ?>
                            ><?php echo htmlspecialchars($ct['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </label>

            <label class="home-modal-field">
                <span class="home-modal-label">Base adı</span>
                <input type="text" name="name" class="home-modal-input" maxlength="150" required autocomplete="off" placeholder="Örn. Satış CRM">
            </label>

            <label class="home-modal-field">
                <span class="home-modal-label">Açıklama <span class="home-modal-optional">(opsiyonel)</span></span>
                <input type="text" name="description" class="home-modal-input" maxlength="500" autocomplete="off">
            </label>

            <p class="home-modal-error" id="home-create-base-error" hidden></p>

            <div class="home-modal-actions">
                <button type="button" class="home-modal-btn" id="home-create-base-cancel">Vazgeç</button>
                <button type="submit" class="home-modal-btn home-modal-btn-primary">Oluştur</button>
            </div>
        </form>
    </div>
</div>
