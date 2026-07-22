<?php
// _seed_phase2_test.php ile kurulan test kullanıcılarını ve oluşturdukları
// base/tablo/alanları temizler.
// Çalıştırma: C:\php73\php.exe scripts\_cleanup_phase2_test.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';

// created_by, silinen test kullanıcılarına işaret eden base'leri bul ve sil
// (tables_meta/fields/records ON DELETE CASCADE ile birlikte gider).
$baseIds = array_column(bcc_fetch_all(
    "SELECT b.id FROM bases b
     INNER JOIN users u ON u.id = b.created_by
     WHERE u.email IN (:e1, :e2)",
    array(':e1' => 'faz2.test.editor@bcc-test.local', ':e2' => 'faz2.test.viewer@bcc-test.local')
), 'id');

foreach ($baseIds as $id) {
    bcc_execute('DELETE FROM bases WHERE id = :id', array(':id' => $id));
}

bcc_execute('DELETE FROM users WHERE email IN (:e1, :e2)', array(':e1' => 'faz2.test.editor@bcc-test.local', ':e2' => 'faz2.test.viewer@bcc-test.local'));

echo 'Temizlendi: ' . count($baseIds) . " base + test kullanicilari silindi.\n";
