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
$canEdit = ($role === 'owner' || $role === 'editor');

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

            // read-modify-write: views.config'te grid tarafına ait anahtarlar
            // (frozen_column_count, grid_state) EZİLMEZ — view_save_state.php ile
            // AYNI desen.
            $config = array();
            if ($view['config'] !== null && $view['config'] !== '') {
                $decoded = json_decode($view['config'], true);
                $config = is_array($decoded) ? $decoded : array();
            }
            foreach ($newConfig as $k => $v) {
                $config[$k] = $v;
            }

            bcc_execute(
                'UPDATE views SET config = :config, form_enabled = :enabled WHERE id = :id',
                array(
                    ':config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                    ':enabled' => $formEnabled,
                    ':id' => $view['id'],
                )
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

// Herkese açık form linki — grid.php'nin $bccShareOrigin deseniyle AYNI.
$bccShareScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$bccShareHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$publicFormUrl = $bccShareScheme . '://' . $bccShareHost . '/form.php?t=' . rawurlencode((string) $view['form_token']);

// Sayfa iskeleti: table_fields.php/slack_settings.php ile AYNI kabuk
// (home_shell_top/bottom) — yeni bir sayfa şablonu YAZILMADI.
$homeActiveNav = 'fields';
$homePageTitle = 'BCC-Core — Form: ' . $table['name'];
require __DIR__ . '/../src/partials/home_shell_top.php';
?>
        <div class="settings-breadcrumb">
            <a href="/grid.php?table_id=<?php echo (int) $table['id']; ?>">&larr; <?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?> tablosuna dön</a>
            <span>·</span> <a href="/table_fields.php?table_id=<?php echo (int) $table['id']; ?>">Alanları yönet</a>
        </div>
        <div class="home-main-header">
            <h1><?php echo htmlspecialchars($view['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="settings-hint">Form görünümü — bu tabloya dışarıdan kayıt toplar.</p>
        </div>

        <?php require __DIR__ . '/../src/partials/flash.php'; ?>

    <?php /* Paylaşım kutusu — share-popover.js'in zaten var olan
             data-share-url-input / data-share-copy-btn mekanizması yeniden
             kullanılıyor, yeni JS YAZILMADI.
             ⚠️ UYARI METNİ: grid.php'deki "yalnızca oturum açmış takım üyeleri
             için çalışır" notunun TAM TERSİ. Bu, projenin bugüne kadarki tek
             güvenlik sözünü tersine çeviren yer — kullanıcı bunu görmeden link
             paylaşmamalı. */ ?>
    <div class="settings-card">
        <h2>Form bağlantısı</h2>
        <?php /* .share-popover-form sınıfı ŞART: share-popover.js kopyalama
                 butonundan yukarı doğru bu sınıfı arıyor (btn.closest). */ ?>
        <div class="share-popover-form form-share-box">
            <div class="form-share-row">
                <input type="text" class="form-share-input" data-share-url-input readonly
                       value="<?php echo htmlspecialchars($publicFormUrl, ENT_QUOTES, 'UTF-8'); ?>"
                       onclick="this.select()">
                <button type="button" class="settings-btn settings-btn-sm" data-share-copy-btn>Kopyala</button>
            </div>
        </div>
        <p class="form-share-warning">
            ⚠️ Bu bağlantıya sahip HERKES giriş yapmadan bu tabloya kayıt ekleyebilir.
            Yalnızca paylaşmak istediğiniz kişilere gönderin.
        </p>
        <p class="settings-hint">
            Form şu an <strong><?php echo ((int) $view['form_enabled'] === 1) ? 'AÇIK' : 'KAPALI'; ?></strong>.
            Kapatmak bağlantıyı iptal etmez — tekrar açtığınızda aynı bağlantı çalışmaya devam eder.
        </p>
    </div>

    <?php if (!$canEdit): ?>
        <p class="settings-hint">Bu formu düzenlemek için editör yetkisi gerekir.</p>
    <?php else: ?>
    <form method="post" class="settings-card">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_form">
        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
        <input type="hidden" name="view_id" value="<?php echo (int) $view['id']; ?>">

        <label class="settings-field settings-field-checkbox">
            <input type="checkbox" name="form_enabled" value="1" <?php echo ((int) $view['form_enabled'] === 1) ? 'checked' : ''; ?>>
            Form açık (kapalıyken bağlantı 404 döner)
        </label>

        <label class="settings-field">Form başlığı
            <input type="text" name="form_title" maxlength="150"
                   value="<?php echo htmlspecialchars($formConfig['form_title'], ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="<?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?>">
        </label>

        <label class="settings-field">Açıklama (formun üstünde görünür)
            <textarea name="form_description" rows="3" maxlength="1000"><?php echo htmlspecialchars($formConfig['form_description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>

        <label class="settings-field">Gönderim sonrası teşekkür metni
            <input type="text" name="form_success_message" maxlength="500"
                   value="<?php echo htmlspecialchars($formConfig['form_success_message'], ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="Teşekkürler, kaydınız alındı.">
        </label>

        <label class="settings-field settings-field-checkbox">
            <input type="checkbox" name="form_slack_notify" value="1" <?php echo !empty($formConfig['form_slack_notify']) ? 'checked' : ''; ?>>
            Her gönderimde Slack'e bildirim gönder
        </label>
        <p class="settings-hint">
            Varsayılan KAPALI: form herkese açık olduğu için istenmeyen gönderimler
            doğrudan Slack kanalınıza düşebilir.
        </p>

        <h2>Formda görünecek alanlar</h2>
        <?php if (empty($formEligibleFields)): ?>
            <p class="settings-hint">Bu tabloda forma konulabilecek alan yok.</p>
        <?php else: ?>
            <p class="settings-hint">
                Otomatik alanlar (Oluşturulma zamanı, Oluşturan, Son değişiklik,
                Son değiştiren, Otomatik numara) ile Dosya eki ve Uzun metin bu
                listede BİLEREK yok — kullanıcı bunları dolduramaz.
            </p>
            <?php foreach ($formEligibleFields as $f):
                $isSelected = in_array((int) $f['id'], $formConfig['form_fields'], true);
            ?>
                <label class="settings-field settings-field-checkbox">
                    <input type="checkbox" name="form_fields[]" value="<?php echo (int) $f['id']; ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
                    <span class="field-badge field-badge--<?php echo htmlspecialchars($f['field_type'], ENT_QUOTES, 'UTF-8'); ?>"></span>
                    <?php echo htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ((int) $f['is_required'] === 1): ?><span class="req-mark" title="Zorunlu">*</span><?php endif; ?>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>

        <button type="submit" class="settings-btn settings-btn-primary">Kaydet</button>
    </form>
    <?php endif; ?>

<script src="<?php echo bcc_asset_url('share-popover.js'); ?>" defer></script>
<?php require __DIR__ . '/../src/partials/home_shell_bottom.php'; ?>
