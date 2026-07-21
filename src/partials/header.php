<?php
// Ortak sayfa başlığı: <!doctype>, <head>, <body> açılışı.
// Beklenen değişkenler (include eden sayfa tarafından ayarlanır):
//   $pageTitle    - string, "BCC-Core — " öneki eklenmeden önceki başlık (opsiyonel)
//   $pageCssFiles - array, eklenecek stylesheet href'leri (opsiyonel, varsayılan: /assets/style.css)

if (!isset($pageTitle)) {
    $pageTitle = '';
}

if (!isset($pageCssFiles) || !is_array($pageCssFiles)) {
    $pageCssFiles = array('/assets/style.css');
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>BCC-Core<?php echo $pageTitle !== '' ? ' — ' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') : ''; ?></title>
<?php foreach ($pageCssFiles as $pageCssFile): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($pageCssFile, ENT_QUOTES, 'UTF-8'); ?>">
<?php endforeach; ?>
</head>
<body>
