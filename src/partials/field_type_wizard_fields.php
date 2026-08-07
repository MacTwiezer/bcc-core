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
//                              (tip rozeti artık field-type-badge--{type} CSS sınıfıyla
//                              basılan bir ikon — metin rozet gerekmiyor)
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
                <span class="field-type-badge field-type-badge--<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>"></span>
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
    <!-- Currency/Percent/Rating (Grup C1) — field-type-wizard.js seçilen tipe göre
         gösterir/gizler, select'in #new-field-options-row'uyla AYNI desen.
         Bulunan gerçek bug (önce fark edilip düzeltildi): currency VE percent'in
         ondalık basamak input'ları AYNI name="decimal_places" kullansaydı, biri
         hidden olsa bile İKİSİ DE forma dahil olurdu (hidden yalnızca GÖRÜNÜRLÜĞÜ
         etkiler, form gönderimini DEĞİL) — hangisinin $_POST'a gireceği belirsiz
         olurdu. Bu yüzden İKİSİNİN de kendi tipine özgü, benzersiz adı var.
         Varsayılan değerler bcc_build_field_options()'taki (₺, 2/0 ondalık basamak,
         5 max yıldız) AYNI varsayılanlarla eşleşiyor. -->
    <div id="new-field-currency-row" hidden>
        <label>Para birimi sembolü
            <input type="text" name="currency_symbol" maxlength="5" value="₺">
        </label>
        <label>Ondalık basamak
            <input type="number" name="currency_decimal_places" min="0" max="6" value="2">
        </label>
    </div>
    <div id="new-field-percent-row" hidden>
        <label>Ondalık basamak
            <input type="number" name="percent_decimal_places" min="0" max="6" value="0">
        </label>
    </div>
    <div id="new-field-rating-row" hidden>
        <label>Maksimum yıldız
            <input type="number" name="max_rating" min="1" max="10" value="5">
        </label>
    </div>
    <?php if ($fieldWizardShowRequired): ?>
        <label>
            <input type="checkbox" name="is_required" value="1" style="display:inline-block;width:auto;">
            Zorunlu alan
        </label>
    <?php endif; ?>
    <button type="submit">Alan Oluştur</button>
</div>
