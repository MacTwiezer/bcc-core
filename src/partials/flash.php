<?php
// Ortak hata/başarı mesaj kutusu. Çağıran sayfa $error / $success değişkenlerini
// kendi scope'unda tanımlamış olmalı (null ise ilgili mesaj basılmaz).
?>
<?php if (isset($error) && $error !== null): ?>
    <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
<?php if (isset($success) && $success !== null): ?>
    <p class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
