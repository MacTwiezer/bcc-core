<?php
// Oturumsuz sayfaların ortak kabuğu — ALT yarısı. auth_shell_top.php'nin açtığı
// .login-card-body ve .login-card kutularını kapatır.
//
// Beklenen değişkenler (hepsi OPSİYONEL — hiçbiri verilmezse en sade kapanış):
//   $authShowLegal - bool (varsayılan true). Alttaki marka/tanıtım satırı.
//                    verify_email.php false verir: orası bir akışın ORTASI
//                    (şifre belirleme adımı), pazarlama cümlesi oraya ait değil.
//   $authScripts   - string[] (varsayılan boş). Sayfaya özel <script src>
//                    dosyaları, ör. array('password-toggle.js'). defer ile
//                    basılır; sıra dizideki sırayla korunur.
//
// ⚠️ SAYFAYA ÖZEL INLINE <script> BURAYA GİRMEZ: register.php'nin gönderim
// kilidi gibi satır içi betikler çağıran sayfada, bu require'dan ÖNCE kalır —
// partial'a taşımak onu "bazı sayfalarda çalışan gizli davranış" hâline
// getirirdi.

if (!isset($authShowLegal)) {
    $authShowLegal = true;
}
if (!isset($authScripts) || !is_array($authScripts)) {
    $authScripts = array();
}
?>
<?php if ($authShowLegal): ?>
        <div class="login-legal">
            <p class="login-tagline"><?php echo htmlspecialchars(bcc_brand_full(), ENT_QUOTES, 'UTF-8'); ?> — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
<?php endif; ?>
    </div>
</div>
<?php foreach ($authScripts as $authScript): ?>
<script src="<?php echo bcc_asset_url($authScript); ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
