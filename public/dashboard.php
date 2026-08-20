<?php

require __DIR__ . '/../src/bootstrap.php';

require_login();

$user = current_user();

// KVKK izolasyonu: kullanıcının üye olduğu ekipler -> yalnızca o ekiplerin base'leri.
// Sorgu deseni bases.php / eski dashboard.php ile aynıdır, sadece görünüm için
// tek düz bir listeye indirgenir.
$teams = bcc_fetch_all(
    'SELECT t.id, t.name, m.role
     FROM team_members m
     INNER JOIN teams t ON t.id = m.team_id
     WHERE m.user_id = :uid
     ORDER BY t.name',
    array('uid' => $user['id'])
);

// Trash: yalnızca 'owner' rolündeki kullanıcı bir base'i silebilir/geri
// yükleyebilir (OpsFlow davranışı) — kart "⋯" menüsündeki "Sil" öğesi bu
// haritaya bakarak gösterilir/gizlenir. Aynı harita kartın rol rozetini de besler.
$roleByTeamId = array();
foreach ($teams as $t) {
    $roleByTeamId[(int) $t['id']] = $t['role'];
}

// "+ Yeni Base Oluştur" — base EKLEME yalnızca Owner'a
// açıktır (bkz. src/auth.php bcc_can_manage_bases(); Editor kayıt/alan
// düzenler ama base ekleyemez). Kullanıcının BİRDEN ÇOK çalışma alanı olabilir ve
// her birinde farklı rolde olabilir; bu yüzden tek bir "yetkili mi" bayrağı
// yetmez — modaldaki çalışma alanı seçicisi YALNIZCA yetkili olduklarını
// listelemeli. $creatableTeams boşsa ne kutucuk ne modal basılır.
// Not: bu liste $teams'ten süzülür, YENİ SORGU açılmaz.
$creatableTeams = array();
foreach ($teams as $t) {
    if (bcc_can_manage_bases($t['role'])) {
        $creatableTeams[] = $t;
    }
}
$canCreateBase = !empty($creatableTeams);

// Tarih filtresi: timeframe GET parametresi ASLA doğrudan SQL'e girmez —
// yalnızca aşağıdaki sabit dizinin anahtarı olarak kullanılır (whitelist);
// dizide olmayan/eksik bir değer sessizce 'anytime'a düşer. Eklenen SQL parçası
// her zaman bu dizideki 4 sabit string'den biridir, kullanıcı girdisinden
// üretilmez.
$timeframeConditions = array(
    'today' => 'al.last_opened >= CURDATE()',
    '7days' => 'al.last_opened >= (NOW() - INTERVAL 7 DAY)',
    '30days' => 'al.last_opened >= (NOW() - INTERVAL 30 DAY)',
    'anytime' => null,
);
$timeframeButtonLabels = array(
    'today' => 'Bugün açıldı',
    '7days' => 'Son 7 günde açıldı',
    '30days' => 'Son 30 günde açıldı',
    'anytime' => 'Herhangi bir zamanda açıldı',
);
$timeframeOptionLabels = array(
    'today' => 'Bugün',
    '7days' => 'Son 7 gün',
    '30days' => 'Son 30 gün',
    'anytime' => 'Herhangi bir zaman',
);
$timeframe = (isset($_GET['timeframe']) && array_key_exists($_GET['timeframe'], $timeframeConditions)) ? $_GET['timeframe'] : 'anytime';

$bases = array();
$teamIds = array();
if (!empty($teams)) {
    foreach ($teams as $t) {
        $teamIds[] = (int) $t['id'];
    }

    // Ekip izolasyonu (team_id IN ...) her zaman ÖNCE gelir; tarih koşulu ancak
    // whitelist'ten geçerliyse (anytime hariç) buna EK olarak eklenir, onun
    // yerine geçmez. "Son açılma" kaydı olmayan (NULL) base'ler
    // today/7days/30days koşullarında otomatik elenir (NULL >= ... => NULL/false),
    // yalnızca 'anytime'da görünür.
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $sql = "SELECT b.id, b.team_id, b.name, b.description, b.created_at, al.last_opened
            FROM bases b
            LEFT JOIN (
                SELECT entity_id, MAX(created_at) AS last_opened
                FROM audit_log
                WHERE action = 'base.open' AND entity_type = 'base'
                GROUP BY entity_id
            ) al ON al.entity_id = b.id
            WHERE b.team_id IN ($placeholders) AND b.deleted_at IS NULL";

    if ($timeframeConditions[$timeframe] !== null) {
        $sql .= ' AND ' . $timeframeConditions[$timeframe];
    }

    $sql .= ' ORDER BY b.name';

    $bases = bcc_fetch_all($sql, $teamIds);
}

// Yıldızlı base'ler — sorgu src/schema.php'de TEK yerde
// (bcc_starred_bases_for_current_user); takım süzgeci ve deleted_at koşulunun
// gerekçesi orada yazılı. Bu sayfa listeyi kabuktan ÖNCE de istiyor, çünkü
// $starredBaseIds kart grid'ine yıldız durumunu dağıtıyor — fonksiyon statik
// önbellekli olduğu için kabuk aynı veriyi ikinci kez sorgulamaz.
$starredBases = bcc_starred_bases_for_current_user();
$starredBaseIds = bcc_starred_base_ids_for_current_user();

// Kart rozetindeki "N tablo" için tablo sayıları — TEK GROUP BY sorgusu
// (bcc_base_table_counts), kart başına ayrı sorgu YOK. Base yoksa hiç
// çalışmaz (fonksiyon boş dizide erken döner).
$baseTableCounts = bcc_base_table_counts(array_column($bases, 'id'));

// Liste görünümünün "Çalışma alanı" kolonu için — $teams zaten yukarıda çekildi,
// yeni sorgu yazılmıyor.
$teamNamesById = array();
foreach ($teams as $t) {
    $teamNamesById[(int) $t['id']] = $t['name'];
}

$homeActiveNav = 'home';
$homePageTitle = bcc_brand_domain() . ' — Ana Sayfa';
// home.css'ten SONRA yüklenir (bkz. home_shell_top.php). Bento IZGARASI artık
// açılmıyor (aşağıdaki grid çağrısında $bento=false), ama bu dosya ızgaradan
// ibaret değil: tipografi ölçeği, zemin ve kart cilası da burada. starred.php
// AYNI dosyayı AYNI gerekçeyle yüklüyor — iki sayfa birebir aynı görünsün diye
// bu satır ikisinde de duruyor.
$homeExtraCss = array('home-bento.css');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="home-main-header">
            <h1>Ana Sayfa</h1>
        </div>

        <div class="home-toolbar">
            <details class="home-filter" id="home-filter">
                <summary class="home-filter-btn">
                    <span><?php echo htmlspecialchars($timeframeButtonLabels[$timeframe], ENT_QUOTES, 'UTF-8'); ?></span>
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 4.5l3.5 3 3.5-3" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </summary>
                <ul class="home-filter-menu">
                    <?php foreach ($timeframeOptionLabels as $tfKey => $tfLabel): ?>
                        <li>
                            <a
                                href="/dashboard.php?timeframe=<?php echo urlencode($tfKey); ?>"
                                class="<?php echo $tfKey === $timeframe ? 'is-selected' : ''; ?>"
                            >
                                <span class="home-filter-check">
                                    <?php if ($tfKey === $timeframe): ?>
                                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6.5l2.5 2.5L10 3" stroke="#1a56db" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?php endif; ?>
                                </span>
                                <?php echo htmlspecialchars($tfLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <div class="home-view-toggle">
                <button type="button" class="home-icon-btn" data-view-mode-btn="list" aria-label="Liste görünümü" aria-pressed="false">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M4 5.5h12M4 10h12M4 14.5h12" stroke="#5f6368" stroke-width="1.4" stroke-linecap="round"/></svg>
                </button>
                <button type="button" class="home-icon-btn" data-view-mode-btn="card" aria-label="Kart görünümü" aria-pressed="true">
                    <svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="11" y="3" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="3" y="11" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/><rect x="11" y="11" width="6" height="6" rx="1" stroke="#5f6368" stroke-width="1.4"/></svg>
                </button>
            </div>
        </div>

        <?php
        // Hiç ekibi olmayan kullanıcı (ör. yeni e-posta doğrulaması yapmış ama
        // henüz bir yönetici tarafından bir ekibe eklenmemiş) için ayrı, daha
        // açıklayıcı bir mesaj — "base yok" genel mesajı bu durumda yanıltıcı
        // olurdu, çünkü ortada bakılacak bir ekip bile yok.
        $emptyMessage = empty($teams)
            ? 'Hesabınız etkin ama henüz bir ekibe eklenmediniz. Bir yöneticinin sizi bir ekibe eklemesini bekleyin.'
            : 'Henüz erişebileceğiniz bir base yok.';
        ?>

        <?php if (!empty($bases)): ?>
        <?php // Bölüm başlığı (Framer'daki "Featured Picks" satırının karşılığı):
              // küçük etiket solda, sayaç sağda, üstünde saç teli çizgi. ?>
        <div class="home-section-head">
            <h2 class="home-section-title">Base'leriniz</h2>
            <span class="home-section-meta"><?php echo count($bases); ?> base</span>
        </div>
        <?php endif; ?>

        <?php
        // $bento = false: bento ızgarası KAPALI. Bir dönem ilk kart (b.name'e
        // göre sıralı olduğu için "Demo CRM") 2x2 "feature" varyantıyla, kendi
        // kapak/açıklama/rozet düzeni ve vurgulu zeminiyle basılıyordu. Bu,
        // aynı ızgarada iki farklı kart boyutu ve iki farklı iç yapı demekti;
        // hangi base'in öne çıkacağı da alfabetik sıranın yan etkisiydi, yani
        // anlamlı bir "öne çıkan" seçimi değildi.
        //
        // Artık starred.php ile AYNI çağrı: tüm base kartları — ve $canCreateBase
        // ile basılan "Yeni Base Oluştur" kutucuğu — tek tip, eşit ölçüde.
        // Kart bileşeni ($variant='standard') ve "N tablo" rozeti iki sayfada da
        // aynı; ayrı bir stil dalı kalmadı.
        bcc_render_home_base_grid($bases, $starredBaseIds, $teamNamesById, $emptyMessage, $roleByTeamId, $canCreateBase, false, $baseTableCounts);
        ?>

        <?php if ($canCreateBase): ?>
        <?php
        // Base oluşturma modalı — SUNUCUDA koşullu basılır ($canCreateBase),
        // yalnızca CSS ile gizlenmez: yetkisi olmayan bir kullanıcının
        // kaynağında form/uçnokta adı hiç görünmez. Asıl yetki kararı yine de
        // api/base_create.php'de tekrar verilir (gizleme != yetkilendirme).
        //
        // <form> gerçek bir action/method taşır: JS yüklenmemişse (veya hata
        // verirse) modal <dialog>-vari değil düz bir form olarak /bases.php'ye
        // POST eder ve akış orada tamamlanır — home.js submit'i araya girip
        // AJAX'a çevirir (bkz. assets/home.js).
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
                        // Tek seçenek varsa açılır liste yerine sabit metin +
                        // hidden input — OpsFlow da tek çalışma alanı olan
                        // kullanıcıya seçici göstermiyor.
                        ?>
                        <?php if (count($creatableTeams) === 1): ?>
                            <input type="hidden" name="team_id" value="<?php echo (int) $creatableTeams[0]['id']; ?>">
                            <span class="home-modal-static"><?php echo htmlspecialchars($creatableTeams[0]['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                            <select name="team_id" class="home-modal-input" required>
                                <?php foreach ($creatableTeams as $ct): ?>
                                    <option value="<?php echo (int) $ct['id']; ?>"><?php echo htmlspecialchars($ct['name'], ENT_QUOTES, 'UTF-8'); ?></option>
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
        <?php endif; ?>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
