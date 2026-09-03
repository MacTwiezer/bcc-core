<?php

if (!isset($fieldWizardShowRequired)) {
    $fieldWizardShowRequired = true;
}

if (!isset($fieldWizardSubmitLabel)) {
    $fieldWizardSubmitLabel = 'Alan Oluştur';
}
?>
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

<div id="new-field-details-step" hidden>
    <p class="hint">
        Seçilen tip: <strong id="new-field-type-chosen-label"></strong>
        · <button type="button" class="link-btn" id="new-field-type-change">Tip değiştir</button>
    </p>
    <label>Alan adı
        <input type="text" name="name" id="new-field-name-input">
    </label>
        <label id="new-field-options-row" hidden>Seçenekler (her satıra bir seçenek)
        <textarea name="options_text" rows="4" placeholder="Örn.&#10;Düşük&#10;Orta&#10;Yüksek"></textarea>
    </label>
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
                <label id="new-field-required-row">
            <input type="checkbox" name="is_required" value="1" style="display:inline-block;width:auto;">
            Zorunlu alan
        </label>
    <?php endif; ?>
        <button type="submit"><?php echo htmlspecialchars($fieldWizardSubmitLabel, ENT_QUOTES, 'UTF-8'); ?></button>
</div>
