<?php
// Form görünümü TASARIMCISI (Grup View-Form) — oturumlu, editor+ rolü gerekir.
//
// ⚠️ BU DOSYA HERKESE AÇIK DEĞİL. Anonim doldurucunun gördüğü sayfa form.php.
// İkisi bilerek AYRI dosya: bu dosya require_role('editor') çağırıyor, form.php
// hiçbir kimlik doğrulaması yapmıyor. İkisini tek dosyada birleştirmek, "bu satıra
// kim ulaşabilir" sorusunu her if bloğunda yeniden sormak demek olurdu — projenin
// ilk auth-suz yolunda alınabilecek en kötü yapısal karar.
//
// Burada yapılanlar: hangi alanların formda görüneceği, başlık/açıklama/teşekkür
// metni, Slack bildirimi anahtarı, formu aç/kapat ve linki kopyalama.

require __DIR__ . '/../src/bootstrap.php';

require_login();
$user = current_user();

$tableId = isset($_GET['table_id']) ? (int) $_GET['table_id'] : (isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0);
$table = find_table_or_404($tableId);

// KVKK ekip izolasyonu — grid.php ile AYNI kapı.
require_team_access($table['team_id']);

$role = current_user_role_in_team($table['team_id']);
$canEdit = bcc_can_edit_records($role);  // form/görünüm — src/auth.php

$viewId = isset($_GET['view_id']) ? (int) $_GET['view_id'] : (isset($_POST['view_id']) ? (int) $_POST['view_id'] : 0);
$view = $viewId ? bcc_find_view($viewId, $table['id']) : null;

// Bu sayfa YALNIZCA form görünümleri içindir. Grid bir view_id ile gelinirse
// grid.php'nin erken yönlendirmesinin TERSİ uygulanır — tek yönlendirme
// noktası (bcc_view_route_for) ikisinde de aynı.
if (!$view) {
    http_response_code(404);
    die('Görünüm bulunamadı.');
}
if ($view['view_type'] !== 'form') {
    header('Location: ' . bcc_view_route_for($view['view_type'], $table['id'], $view['id']));
    exit;
}

$fields = bcc_fetch_all(
    'SELECT id, name, field_type, options, position, is_required FROM fields WHERE table_id = :table_id ORDER BY position, id',
    array(':table_id' => $table['id'])
);

// Formda GÖSTERİLEBİLECEK alanlar (birinci katman: tip bazlı). Salt-okunur
// tipler + attachment + long_text buraya HİÇ girmez — tasarımcı bile açamaz.
$formEligibleFields = array();
foreach ($fields as $f) {
    if (bcc_field_allowed_in_form($f['field_type'])) {
        $formEligibleFields[] = $f;
    }
}

$formConfig = bcc_form_config_from_view($view);

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    require_role($table['team_id'], 'editor');

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save_form') {
        // Alan seçimi: gelen id'ler UYGUN alanlar listesine karşı süzülür —
        // istemciden gelen bir id'nin gerçekten forma konulabilir bir alana ait
        // olduğu SUNUCUDA doğrulanır (form_submit.php'deki whitelist bu değere
        // güveniyor, yani burası o zincirin ilk halkası).
        $eligibleIds = array();
        foreach ($formEligibleFields as $f) {
            $eligibleIds[] = (int) $f['id'];
        }

        $selected = array();
        $postedFields = isset($_POST['form_fields']) && is_array($_POST['form_fields']) ? $_POST['form_fields'] : array();
        foreach ($postedFields as $rawId) {
            $fid = (int) $rawId;
            if (in_array($fid, $eligibleIds, true) && !in_array($fid, $selected, true)) {
                $selected[] = $fid;
            }
        }

        $newConfig = array(
            'form_fields' => $selected,
            'form_title' => mb_substr(trim((string) (isset($_POST['form_title']) ? $_POST['form_title'] : '')), 0, 150, 'UTF-8'),
            'form_description' => mb_substr(trim((string) (isset($_POST['form_description']) ? $_POST['form_description'] : '')), 0, 1000, 'UTF-8'),
            'form_success_message' => mb_substr(trim((string) (isset($_POST['form_success_message']) ? $_POST['form_success_message'] : '')), 0, 500, 'UTF-8'),
            // Slack bildirimi form gönderimlerinde VARSAYILAN KAPALI (güvenlik
            // tasarımı): anonim spam doğrudan ekibin Slack kanalına taşmasın diye
            // tasarımcı bunu AÇIKÇA açmalı.
            'form_slack_notify' => !empty($_POST['form_slack_notify']) ? 1 : 0,
        );

        // form_enabled: linki iptal ETMEDEN formu durdurma anahtarı.
        // ⚠️ Bu, migrations/015'te "ölü kolon olmayacak" diye söz verilen üç
        // okuma/yazma noktasından biri (diğerleri: form.php kapısı,
        // form_submit.php kapısı).
        $formEnabled = !empty($_POST['form_enabled']) ? 1 : 0;

        try {
            bcc_begin_transaction();

            // Oku-değiştir-yaz ortak yardımcıda (bcc_update_view_config):
            // views.config'te grid'e (frozen_column_count, grid_state) ve
            // Kanban'a (kanban_*) ait anahtarlar EZİLMEZ.
            bcc_update_view_config($view['id'], $newConfig);

            // form_enabled ayrı bir KOLON (config JSON'unda değil) — bu yüzden
            // ayrı UPDATE. Aynı transaction içinde.
            bcc_execute(
                'UPDATE views SET form_enabled = :enabled WHERE id = :id',
                array(':enabled' => $formEnabled, ':id' => $view['id'])
            );

            log_audit('view.form_config', 'view', $view['id'], array('table_id' => $table['id'], 'field_count' => count($selected), 'enabled' => $formEnabled), $table['team_id']);

            bcc_commit();
        } catch (Throwable $e) {
            bcc_rollback();
            $error = 'Kaydedilemedi (veritabanı hatası).';
        }

        if ($error === null) {
            $success = 'Form ayarları kaydedildi.';
            $view = bcc_find_view($view['id'], $table['id']);
            $formConfig = bcc_form_config_from_view($view);
        }
    }
}

// Herkese açık form linki. $_SERVER['HTTP_HOST'] YERİNE bcc_app_base_url()
// (config/app.php) — register.php'nin doğrulama linkinde yapılan AYNI düzeltme.
// Gerekçe orada ayrıntılı: bu link DIŞARIYA paylaşılmak için üretiliyor, yani
// istekten gelen host'a dayanması hem yanlış adres üretebilir hem host-header
// enjeksiyonuna açık kapı bırakır. $APP_BASE_URL boşsa eski davranışa düşer,
// dolayısıyla yerel kurulumda çıktı DEĞİŞMEZ.
$publicFormUrl = bcc_app_base_url() . '/form.php?t=' . rawurlencode((string) $view['form_token']);

$formIsOpen = ((int) $view['form_enabled'] === 1);

// Paylaşım kutusu iki yerde gösteriliyor (editör: sol sütun, salt-okunur:
// tek kart) — HTML iki kez yazılmasın diye küçük bir yardımcı.
function bcc_render_form_share_card($publicFormUrl, $formIsOpen)
{
    ?>
    <div class="settings-card">
        <div class="fe-share-head">
            <h2 style="margin:0;">Form bağlantısı</h2>
            <span class="sp-status <?php echo $formIsOpen ? 'sp-status-on' : ''; ?>"><?php echo $formIsOpen ? 'AÇIK' : 'KAPALI'; ?></span>
        </div>

        <?php // .share-popover-form sınıfı ŞART: paylaşılan share-popover.js
              // kopyalama butonundan yukarı doğru bu sınıfı arıyor (btn.closest).
              // Sınıf KORUNDU, görünüm kendi .fe-* sınıflarından geliyor. ?>
        <div class="share-popover-form">
            <div class="fe-share-row">
                <input type="text" class="fe-share-input" data-share-url-input readonly
                       value="<?php echo htmlspecialchars($publicFormUrl, ENT_QUOTES, 'UTF-8'); ?>"
                       aria-label="Herkese açık form bağlantısı">
                <?php // Buton İÇİNDE <svg> YOK — bilerek. share-popover.js kopyalamada
                      // btn.textContent'i "Kopyalandı" ile değiştiriyor; bir svg çocuk
                      // o anda silinirdi. İkon CSS ::before maskesiyle veriliyor
                      // (bkz. assets/form-edit.css), textContent'ten etkilenmiyor. ?>
                <button type="button" class="fe-copy-btn" data-share-copy-btn>Kopyala</button>
            </div>
        </div>

        <?php // ⚠️ Bu uyarı, projenin bugüne kadarki tek güvenlik sözünü TERSİNE
              // çeviren yer (grid.php'deki "yalnızca oturum açmış takım üyeleri"
              // notunun tam tersi) — kullanıcı bunu görmeden link paylaşmamalı.
              // Bu yüzden düz metin değil, amber uyarı kutusu. ?>
        <div class="sp-note sp-note--warn" style="margin: 0.85rem 0 0;">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3.5l7 12.5H3l7-12.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
            <span><strong>Bu bağlantıya sahip HERKES giriş yapmadan bu tabloya kayıt ekleyebilir.</strong> Yalnızca paylaşmak istediğiniz kişilere gönderin.</span>
        </div>

        <div class="sp-note" style="margin: 0.5rem 0 0;">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.5" r="0.9" fill="currentColor"/></svg>
            <span>Formu kapatmak bağlantıyı <strong>iptal etmez</strong> — tekrar açtığınızda aynı bağlantı çalışmaya devam eder.</span>
        </div>
    </div>
    <?php
}

// Sol panel "Yıldızlılar" listesi — table_fields.php/slack_settings.php/
// workspaces.php ile AYNI desen.
//
// ⚠️ Bu blok EKSİKTİ: kabuk $starredBases'i bekliyor ama bu sayfa hiç
// ayarlamıyordu, dolayısıyla sol paneldeki yıldızlı base listesi bu sayfada
// (ve YALNIZCA bu sayfada) HER ZAMAN boş görünüyordu — kardeş sayfalarda
// doluyken. display_errors kapalı olduğu için ortada bir hata mesajı da yoktu.
$starredBases = array();
$teamIdsForStar = current_user_team_ids();
if (!empty($teamIdsForStar)) {
    $starredPlaceholders = implode(',', array_fill(0, count($teamIdsForStar), '?'));
    $starredBases = bcc_fetch_all(
        "SELECT b.id, b.name FROM user_starred_bases usb
         INNER JOIN bases b ON b.id = usb.base_id AND b.team_id IN ($starredPlaceholders) AND b.deleted_at IS NULL
         WHERE usb.user_id = ? ORDER BY b.name",
        array_merge($teamIdsForStar, array((int) $user['id']))
    );
}

// Sayfa iskeleti: table_fields.php/slack_settings.php ile AYNI kabuk
// (home_shell_top/bottom) — yeni bir sayfa şablonu YAZILMADI.
$homeActiveNav = 'fields';
$homePageTitle = 'BCC-Core — Form: ' . $table['name'];
// Ortak tasarım sistemi + yalnızca bu sayfaya ait iki sütunlu oluşturucu.
$homeExtraCss = array('settings-page.css', 'form-edit.css');
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
<div class="sp-page fe-page">
        <div class="settings-breadcrumb">
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <span>·</span>
            <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h12M4 10h12M4 14h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                Alanları yönet
            </a>
        </div>
        <div class="home-main-header">
            <h1><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="settings-hint">Form görünümü — bu tabloya dışarıdan kayıt toplar.</p>
        </div>

        <?php require __DIR__ . '/../src/partials/flash.php'; ?>

    <?php if (!$canEdit): ?>
        <?php bcc_render_form_share_card($publicFormUrl, $formIsOpen); ?>
        <div class="sp-note">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.5" r="0.9" fill="currentColor"/></svg>
            <span>Bu formu düzenlemek için <strong>editör</strong> yetkisi gerekir. Sayfayı salt-okunur görüyorsunuz.</span>
        </div>
    <?php else: ?>
    <?php // TEK <form>, İKİ SÜTUN: sol ayarlar, sağ alan seçimi. "Kaydet"
          // hepsini birlikte gönderiyor — ikinci bir form YOK, alan adları
          // (form_enabled / form_title / form_description /
          // form_success_message / form_slack_notify / form_fields[])
          // AYNEN korundu, sunucu tarafı değişmedi. ?>
    <form method="post" class="fe-grid">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_form">
        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
        <input type="hidden" name="view_id" value="<?php echo (int) $view['id']; ?>">

        <?php // ---- SOL: ayarlar --------------------------------------------- ?>
        <div class="fe-col fe-col-left">
            <?php bcc_render_form_share_card($publicFormUrl, $formIsOpen); ?>

            <div class="settings-card">
                <h2>Form ayarları</h2>

                <label class="sp-toggle">
                    <input type="checkbox" name="form_enabled" value="1" <?php echo $formIsOpen ? 'checked' : ''; ?>>
                    <span class="sp-toggle-track"></span>
                    <span>Form açık <span class="sp-muted">— kapalıyken bağlantı 404 döner</span></span>
                </label>

                <div class="fe-section">
                    <label class="settings-field">Form başlığı
                        <input type="text" name="form_title" maxlength="150"
                               value="<?php echo htmlspecialchars($formConfig['form_title'], ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="<?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <?php // Satır içi margin YOK: alanlar arası boşluk tek yerden
                          // (.fe-section .settings-field + .settings-field, form-edit.css). ?>
                    <label class="settings-field">Açıklama <span class="sp-muted">— formun üstünde görünür</span>
                        <textarea name="form_description" rows="3" maxlength="1000"><?php echo htmlspecialchars($formConfig['form_description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </label>

                    <label class="settings-field">Gönderim sonrası teşekkür metni
                        <input type="text" name="form_success_message" maxlength="500"
                               value="<?php echo htmlspecialchars($formConfig['form_success_message'], ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Teşekkürler, kaydınız alındı.">
                    </label>
                </div>

                <div class="fe-section">
                    <h3 class="fe-section-title">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7.5 3v9a2 2 0 11-2-2h9a2 2 0 11-2 2V3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Slack bildirimi
                    </h3>
                    <label class="sp-toggle">
                        <input type="checkbox" name="form_slack_notify" value="1" <?php echo !empty($formConfig['form_slack_notify']) ? 'checked' : ''; ?>>
                        <span class="sp-toggle-track"></span>
                        <span>Her gönderimde Slack'e bildirim gönder</span>
                    </label>
                    <div class="sp-note sp-note--warn" style="margin: 0.75rem 0 0;">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3.5l7 12.5H3l7-12.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8v3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                        <span>Varsayılan <strong>kapalı</strong>: form herkese açık olduğu için istenmeyen gönderimler doğrudan Slack kanalınıza düşebilir.</span>
                    </div>
                </div>

                <div class="fe-save-row">
                    <button type="submit" class="settings-btn settings-btn-primary">Kaydet</button>
                    <p class="fe-save-hint">Sağdaki alan seçimi de bu düğmeyle kaydedilir.</p>
                </div>
            </div>
        </div>

        <?php // ---- SAĞ: forma girecek alanlar -------------------------------- ?>
        <div class="fe-col fe-col-right">
            <div class="settings-card">
                <h2>Formda görünecek alanlar <span class="sp-count"><?php echo count($formEligibleFields); ?></span></h2>

                <?php if (empty($formEligibleFields)): ?>
                    <p class="settings-empty">
                        <strong>Forma konulabilecek alan yok.</strong>
                        <span class="sp-muted">Önce tabloya doldurulabilir bir alan ekleyin.</span>
                    </p>
                <?php else: ?>
                    <div class="sp-note">
                        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 9v4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.5" r="0.9" fill="currentColor"/></svg>
                        <span>Otomatik alanlar (oluşturulma/değişiklik zamanı, oluşturan, otomatik numara) ile dosya eki ve uzun metin bu listede <strong>bilerek yok</strong> — kullanıcı bunları dolduramaz.</span>
                    </div>

                    <?php // Düz checkbox listesi yerine seçilebilir kart. Girdi adı
                          // (form_fields[]) ve değeri AYNEN korundu; seçili görünüm
                          // CSS :has(input:checked) ile, ek JS olmadan. ?>
                    <div class="fe-field-list">
                        <?php foreach ($formEligibleFields as $f):
                            $isSelected = in_array((int) $f['id'], $formConfig['form_fields'], true);
                        ?>
                            <label class="fe-field-card">
                                <input type="checkbox" name="form_fields[]" value="<?php echo (int) $f['id']; ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
                                <span class="fe-field-inner">
                                <span class="fe-check" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 20 20" fill="none"><path d="M4.5 10.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <span class="field-type-badge field-type-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                                <span class="fe-field-name"><?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ((int) $f['is_required'] === 1): ?><span class="req-mark" title="Zorunlu">*</span><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="fe-field-count" id="fe-field-count"></p>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
<script src="<?php echo bcc_asset_url('share-popover.js'); ?>" defer></script>
<?php // Yalnızca GÖRSEL: kopyalandı parlaması + seçili alan sayacı. Panoya
      // yazma işi paylaşılan share-popover.js'te KALDI, kopyalanmadı. ?>
<script src="<?php echo bcc_asset_url('form-edit.js'); ?>" defer></script>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
