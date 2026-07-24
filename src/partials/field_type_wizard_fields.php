<?php
// Tip-önce-isim-sonra alan ekleme sihirbazının DOM iskeleti (Adım 1: tip grid'i,
// Adım 2: isim/seçenekler formu) — table_fields.php'nin tam sayfa formu VE
// grid.php'nin "+" popup'ı (Bölüm A madde 3 / Bölüm B madde 7) TARAFINDAN
// PAYLAŞILIR, ikinci bir kopya YOK. Adım geçişi field-type-wizard.js'de (o da
// paylaşılıyor); bu partial yalnızca statik DOM'u basar. <form> etiketi,
// action/hidden input'lar (table_id, csrf_token vb.) ve gönderim davranışı
// (tam sayfa POST vs. fetch) çağıran tarafta kalır — o kısım context'e göre
// farklı, burada değil.
//
// Beklenen değişkenler (include eden dosya ayarlar):
//   $fieldTypeLabels        - array, field_type => Türkçe etiket ($GLOBALS['BCC_FIELD_TYPES'])
//   $fieldTypeBadges        - array, field_type => rozet metni ($GLOBALS['BCC_FIELD_TYPE_BADGE'])
//   $fieldWizardShowRequired - (opsiyonel, varsayılan true) "Zorunlu alan" onay kutusu
//                              gösterilsin mi (grid.php'nin kompakt popup'ı bunu
//                              GÖSTERMEZ — davranış tabloya taşınmadan önceki hâliyle
//                              AYNI kalsın diye, table_fields.php ise gösterir).

if (!isset($fieldWizardShowRequired)) {
    $fieldWizardShowRequired = true;
}
?>
<!-- Adım 1: önce TİP (Airtable gibi) — liste $fieldTypeLabels'tan gelir, elle tekrar yazılmaz. -->
<div id="new-field-type-step">
    <p class="hint">Alan tipini seçin</p>
    <div class="field-type-grid">
        <?php foreach ($fieldTypeLabels as $typeKey => $typeLabel): ?>
            <button
                type="button"
                class="field-type-option"
                data-field-type="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>"
                data-field-type-label="<?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?>"
            >
                <span class="field-type-badge"><?php echo htmlspecialchars($fieldTypeBadges[$typeKey], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="field-type-label"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Adım 2: TİP seçilince görünür — başlık burada sorulur. -->
<div id="new-field-details-step" hidden>
    <p class="hint">
        Seçilen tip: <strong id="new-field-type-chosen-label"></strong>
        · <button type="button" class="link-btn" id="new-field-type-change">Tip değiştir</button>
    </p>
    <label>Alan adı
        <input type="text" name="name" id="new-field-name-input">
    </label>
    <label id="new-field-options-row" hidden>Seçenekler (her satıra bir seçenek)
        <textarea name="options_text" rows="4"></textarea>
    </label>
    <?php if ($fieldWizardShowRequired): ?>
        <label>
            <input type="checkbox" name="is_required" value="1" style="display:inline-block;width:auto;">
            Zorunlu alan
        </label>
    <?php endif; ?>
    <button type="submit">Alan Oluştur</button>
</div>
